<?php

namespace App\Services;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Web3DeploymentService.
 *
 * เรียกสคริปต์ node (scripts/blockchain/create-token.cjs) ที่เซ็นและส่งธุรกรรมสร้างเหรียญ
 * ผ่านแฟกทอรีบน TPIX Chain (4289)
 *
 * ⚠️ อ่าน stdout "ระหว่างที่สคริปต์ยังรันอยู่" ไม่ใช่รอจบ
 *    สคริปต์พิมพ์ {"event":"signed","txHash":...} ก่อนส่งธุรกรรมขึ้นเชน
 *    ตัวนี้เรียก $onSigned ทันที → DeployTokenJob บันทึก hash ลง DB
 *    ต่อให้ worker ตายระหว่างรอ receipt งานถัดไปก็รู้ว่ามีธุรกรรมแล้ว และไม่ส่งซ้ำ
 *    (เดิมได้ hash ตอนสคริปต์จบเท่านั้น — timeout กลางทาง = ไม่รู้ว่าส่งไปแล้ว = ส่งใหม่ = เหรียญซ้ำ)
 *
 * Developed by Xman Studio
 */
class Web3DeploymentService
{
    /** ต้องน้อยกว่า DeployTokenJob::$timeout และมากกว่า DEPLOY_WAIT_SECONDS + เวลาต่อ RPC */
    public const PROCESS_TIMEOUT = 150;

    /**
     * โหมดตรวจผล: รอ receipt 20 วินาที + ต่อ RPC — ต้องน้อยกว่า CheckFactoryDeployReceiptJob::$timeout (90)
     * ไม่งั้น worker ถูกฆ่าก่อน process แล้ว node ค้างเป็นกำพร้า
     */
    public const RECEIPT_PROCESS_TIMEOUT = 60;

    /**
     * สคริปต์รอ receipt นานสุดเท่านี้ แล้วรายงาน pending (งานตรวจผลรับช่วงต่อ)
     * งบเวลา: ต่อ RPC ~15 + ค้นซ้ำบนเชน ~30 + เซ็น ~10 + รอ 75 = ~130 < PROCESS_TIMEOUT 150
     */
    public const DEPLOY_WAIT_SECONDS = 75;

    public function __construct(private ContractRegistry $contracts) {}

    /**
     * Deploy เหรียญใหม่ผ่านแฟกทอรี.
     *
     * @param  array  $params  name, symbol, decimals, total_supply (หน่วยเต็ม), creator_address,
     *                         token_type, token_category, sub_options, logo_url
     * @param  callable|null  $onSigned  fn(string $txHash, ?int $nonce, ?string $rawTx): void
     *                                   ถูกเรียกก่อนธุรกรรมถูกส่งขึ้นเชน
     * @return array ['success' => bool, 'pending' => bool, 'reverted' => bool, 'contractAddress' => ?string,
     *               'txHash' => ?string, 'signed' => ?array, 'error' => ?string, ...]
     */
    public function deployToken(array $params, ?callable $onSigned = null): array
    {
        $tokenType = $params['token_type'] ?? 'standard';
        $subOptions = $params['sub_options'] ?? [];

        // NFT นับเป็น "จำนวนใบ" ไม่ใช่ยอดที่คูณทศนิยม — ถ้าคูณไปด้วยจะได้เพดาน
        // จำนวนใบเป็นเลข 10^18 เท่าของที่ผู้ใช้ตั้ง ทำให้เพดานไร้ความหมาย
        $decimals = (int) ($params['decimals'] ?? 18);
        $isNft = in_array($params['token_type'] ?? '', ['nft', 'nft_collection'], true)
            || ($params['token_category'] ?? '') === 'nft';

        $totalSupplyWei = $isNft
            ? $this->toWholeNumber((string) $params['total_supply'])
            : $this->toWei((string) $params['total_supply'], $decimals);

        $input = json_encode([
            'mode' => 'deploy',
            'name' => $params['name'],
            'symbol' => $params['symbol'],
            'decimals' => $decimals,
            'totalSupply' => $totalSupplyWei,
            'tokenOwner' => $params['creator_address'],
            'tokenType' => $tokenType,
            'subOptions' => $subOptions,
            'logoUrl' => $params['logo_url'] ?? '',
        ]);

        $env = $this->environment();

        if (empty($env['DEPLOYER_PRIVATE_KEY'])) {
            return ['success' => false, 'error' => 'DEPLOYER_PRIVATE_KEY not configured'];
        }

        if (empty($env['TOKEN_FACTORY_ADDRESS']) && empty($env['TOKEN_FACTORY_V2_ADDRESS']) && empty($env['NFT_FACTORY_ADDRESS'])) {
            return ['success' => false, 'error' => 'No factory address configured'];
        }

        Log::info('Deploying token via factory', [
            'name' => $params['name'],
            'symbol' => $params['symbol'],
            'type' => $tokenType,
            'creator' => $params['creator_address'],
            'has_sub_options' => ! empty($subOptions),
        ]);

        return $this->runScript($input, $env, $onSigned);
    }

    /**
     * ตรวจผลธุรกรรมที่ส่งไปแล้ว — ไม่เซ็นธุรกรรมใหม่เด็ดขาด.
     *
     * ถ้าโหนดลืมธุรกรรมไปแล้วและ nonce ยังว่าง สคริปต์ส่ง rawTx เดิมซ้ำ (hash เดิม)
     */
    public function checkDeployment(string $txHash, ?string $rawTx, string $tokenType, array $subOptions = [], int $waitSeconds = 20): array
    {
        $input = json_encode([
            'mode' => 'receipt',
            'txHash' => $txHash,
            'rawTx' => $rawTx,
            'tokenType' => $tokenType,
            'subOptions' => $subOptions,
            'waitSeconds' => $waitSeconds,
        ]);

        return $this->runScript($input, $this->environment(withKey: false), null, self::RECEIPT_PROCESS_TIMEOUT);
    }

    /**
     * รันสคริปต์แล้วอ่านผลทีละบรรทัด.
     */
    private function runScript(string $input, array $env, ?callable $onSigned = null, int $timeout = self::PROCESS_TIMEOUT): array
    {
        $command = [config('blockchain.node_path', 'node'), base_path('scripts/blockchain/create-token.cjs')];

        $buffer = '';
        $signed = null;
        $final = null;
        $callbackError = null;

        $handleLine = function (string $line) use (&$signed, &$final, &$callbackError, $onSigned): void {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                return;
            }

            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                return;
            }

            if (($decoded['event'] ?? null) === 'signed' && $signed === null) {
                $signed = $decoded;
                if ($onSigned && is_string($decoded['txHash'] ?? null)) {
                    try {
                        $onSigned(
                            strtolower($decoded['txHash']),
                            isset($decoded['nonce']) ? (int) $decoded['nonce'] : null,
                            $decoded['rawTx'] ?? null,
                        );
                    } catch (\Throwable $e) {
                        $callbackError = $e;
                        Log::critical('บันทึก tx hash ของการสร้างเหรียญไม่สำเร็จ', [
                            'tx' => $decoded['txHash'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                return;
            }

            if (array_key_exists('success', $decoded)) {
                $final = $decoded;
            }
        };

        $handler = function (string $type, string $output) use (&$buffer, $handleLine): void {
            if ($type !== 'out') {
                return;
            }

            $buffer .= $output;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $handleLine(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
            }
        };

        try {
            $process = Process::timeout($timeout)
                ->env($env)
                ->input($input)
                ->start($command, $handler);

            $result = $process->wait();
        } catch (ProcessTimedOutException $e) {
            $handleLine($buffer);
            Log::error('Token deployment script timed out', ['signed' => $signed['txHash'] ?? null]);

            return [
                'success' => false,
                'pending' => $signed !== null,
                'txHash' => $signed['txHash'] ?? null,
                'signed' => $signed,
                'error' => 'สคริปต์ deploy ทำงานเกินเวลา',
            ];
        } catch (\Throwable $e) {
            Log::error('Token deployment exception', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'txHash' => $signed['txHash'] ?? null,
                'signed' => $signed,
                'error' => $e->getMessage(),
            ];
        }

        $handleLine($buffer);

        if ($final === null) {
            // บางกรณี (เช่น fake ในเทสต์ หรือ stdout ไม่มีขึ้นบรรทัดใหม่) — ลองอ่านทั้งก้อน
            $decoded = json_decode(trim($result->output()), true);
            $final = is_array($decoded) && array_key_exists('success', $decoded) ? $decoded : null;
        }

        if ($final === null) {
            Log::error('Invalid deployment script output', [
                'exit_code' => $result->exitCode(),
                'stderr' => mb_substr($result->errorOutput(), 0, 2000),
            ]);

            return [
                'success' => false,
                'txHash' => $signed['txHash'] ?? null,
                'signed' => $signed,
                'error' => 'สคริปต์ deploy ไม่ได้รายงานผล'.($result->errorOutput() !== '' ? ' (ดู log)' : ''),
            ];
        }

        $final['signed'] = $signed;
        $final['callback_failed'] = $callbackError !== null;
        $final['pending'] = (bool) ($final['pending'] ?? false);
        $final['reverted'] = (bool) ($final['reverted'] ?? false);
        $final['dropped'] = (bool) ($final['dropped'] ?? false);
        if (empty($final['txHash']) && $signed !== null) {
            $final['txHash'] = $signed['txHash'];
        }

        if ($final['success']) {
            Log::info('Token deployed successfully', [
                'contract' => $final['contractAddress'] ?? null,
                'txHash' => $final['txHash'] ?? null,
                'factoryVersion' => $final['factoryVersion'] ?? 'unknown',
                'already_existed' => $final['alreadyExisted'] ?? false,
            ]);
        }

        return $final;
    }

    /**
     * ตัวแปรแวดล้อมของสคริปต์ — คีย์ส่งทาง env เท่านั้น ไม่เคยอยู่ใน argv/stdin/log.
     *
     * ที่อยู่แฟกทอรีมาจาก ContractRegistry (SiteSetting ที่สคริปต์ deploy ลงทะเบียนเอง → .env)
     */
    private function environment(bool $withKey = true): array
    {
        $env = [
            'DEPLOYER_PRIVATE_KEY' => $withKey ? (string) config('blockchain.deployer_private_key') : '',
            'TOKEN_FACTORY_ADDRESS' => $this->contracts->address('token_factory_v1') ?? '',
            'TOKEN_FACTORY_V2_ADDRESS' => $this->contracts->address('token_factory_v2') ?? '',
            'NFT_FACTORY_ADDRESS' => $this->contracts->address('nft_factory') ?? '',
            'TPIX_RPC_URL' => (string) config('blockchain.tpix_rpc_url'),
            'TPIX_CHAIN_ID' => (string) config('blockchain.tpix_chain_id', 4289),
            'DEPLOY_WAIT_SECONDS' => (string) self::DEPLOY_WAIT_SECONDS,
        ];

        // RPC สำรองต้องผ่าน config — หลัง config:cache ตัวแปรใน .env ไม่ถึงสคริปต์เอง
        $fallbacks = config('blockchain.tpix_rpc_fallback_urls');
        if ($fallbacks !== null) {
            $env['TPIX_RPC_FALLBACK_URLS'] = (string) $fallbacks;
        }

        return $env;
    }

    /**
     * ตัดเป็นจำนวนเต็ม — ใช้กับจำนวนใบของ NFT ที่ไม่มีทศนิยม
     */
    private function toWholeNumber(string $amount): string
    {
        $integer = explode('.', trim($amount))[0];
        $integer = preg_replace('/\D/', '', $integer) ?? '';
        $integer = ltrim($integer, '0');

        return $integer === '' ? '0' : $integer;
    }

    /**
     * Convert human-readable amount to wei (smallest unit).
     */
    private function toWei(string $amount, int $decimals): string
    {
        $parts = explode('.', $amount);
        $integer = $parts[0];
        $fraction = $parts[1] ?? '';

        $fraction = str_pad(substr($fraction, 0, $decimals), $decimals, '0');

        return ltrim($integer.$fraction, '0') ?: '0';
    }
}
