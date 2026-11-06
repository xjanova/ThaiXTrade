<?php

namespace App\Services\Launchpad;

use App\Models\LaunchpadSale;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\EvmRpc;
use App\Services\ContractRegistry;
use App\Support\Wei;
use Illuminate\Support\Carbon;

/**
 * ลงทะเบียนหน้าขายจากเชน — ทุกค่าอ่านจากสัญญา ไม่มีช่องให้ใครพิมพ์ราคา/ที่อยู่เอง.
 *
 * รับเฉพาะ sale ที่ LaunchpadFactory ตัวที่ลงทะเบียนไว้สร้างเอง (launchIdOfSale ≠ 0)
 * แล้วตรวจไขว้ว่าชิ้นส่วนตรงกันหมด: sale.payToken = USDT_TPIX · sale.treasury = feeder · feeder.sale = sale
 * · sale.saleToken = เหรียญของ launch · tokenCheck(token) = 0 (มาจาก Token Factory และไม่มีสวิตช์เสี่ยง)
 *
 * อนุมัติ (ขึ้นหน้าเว็บ) แยกจากลงทะเบียนเสมอ — และกันชื่อ/สัญลักษณ์ซ้ำกับหน้าขายที่อนุมัติแล้ว (กันเหรียญเลียนแบบ)
 */
class LaunchpadRegistrar
{
    public function __construct(
        private BridgeConfig $bridge,
        private ContractRegistry $registry,
    ) {}

    /**
     * @return array{sale: LaunchpadSale, created: bool}
     */
    public function register(string $saleAddress, ?EvmRpc $rpc = null): array
    {
        $saleAddress = strtolower(trim($saleAddress));
        if (! preg_match('/^0x[0-9a-f]{40}$/', $saleAddress)) {
            throw new LaunchpadOrderException('INVALID_ADDRESS', 'ที่อยู่สัญญาขายไม่ถูกต้อง');
        }

        $factory = $this->registry->address('launchpad_factory');
        $usdt = $this->bridge->usdtTpixAddress();
        if ($factory === null || $usdt === null) {
            throw new LaunchpadOrderException('NOT_CONFIGURED', 'ยังไม่ได้ลงทะเบียน launchpad_factory หรือ usdt_tpix');
        }
        $factory = strtolower($factory);
        $usdt = strtolower($usdt);

        $rpc ??= $this->bridge->tpixRpc();
        $read = fn (?string $v, string $what) => $v ?? throw new LaunchpadOrderException('CHAIN_UNAVAILABLE', "อ่าน {$what} จากเชนไม่ได้", 503);

        $idPlusOne = $read($rpc->callUint($factory, 'launchIdOfSale(address)', Abi::encAddress($saleAddress)), 'launchIdOfSale');
        if (bccomp($idPlusOne, '0', 0) === 0) {
            throw new LaunchpadOrderException('NOT_FACTORY_SALE', 'สัญญานี้ไม่ได้สร้างจาก LaunchpadFactory ของระบบ — ไม่รับ');
        }
        $launchId = (int) bcsub($idPlusOne, '1', 0);

        // Launch { token, sale, feeder, vesting, creator, createdAt } — struct แบบ static คืนเป็นคำเรียงกัน
        $words = Abi::words($rpc->call($factory, Abi::selector('launchAt(uint256)').Abi::encUint((string) $launchId)));
        if (count($words) < 6) {
            throw new LaunchpadOrderException('CHAIN_UNAVAILABLE', 'อ่าน launchAt จากเชนไม่ได้', 503);
        }
        $addr = fn (string $w) => '0x'.substr($w, 24);
        [$token, $launchSale, $feeder, $vesting, $creator] = [$addr($words[0]), $addr($words[1]), $addr($words[2]), $addr($words[3]), $addr($words[4])];

        if ($launchSale !== $saleAddress) {
            throw new LaunchpadOrderException('MISMATCH', 'ข้อมูล launch ไม่ตรงกับสัญญาขาย');
        }

        $readAddr = fn (string $to, string $sig) => Abi::decAddress($rpc->call($to, Abi::selector($sig)))
            ?? throw new LaunchpadOrderException('CHAIN_UNAVAILABLE', "อ่าน {$sig} จากเชนไม่ได้", 503);

        $checks = [
            'payToken = USDT_TPIX' => $readAddr($saleAddress, 'payToken()') === $usdt,
            'saleToken = launch token' => $readAddr($saleAddress, 'saleToken()') === $token,
            'treasury = feeder' => $readAddr($saleAddress, 'treasury()') === $feeder,
            'feeder.sale = sale' => $readAddr($feeder, 'sale()') === $saleAddress,
        ];
        if (in_array(false, $checks, true)) {
            throw new LaunchpadOrderException('MISMATCH', 'ชิ้นส่วนของ launch ไม่ตรงกัน: '.implode(', ', array_keys(array_filter($checks, fn ($ok) => ! $ok))));
        }

        $tokenCheck = $read($rpc->callUint($factory, 'tokenCheck(address)', Abi::encAddress($token)), 'tokenCheck');
        if ($tokenCheck !== '0') {
            throw new LaunchpadOrderException('RISKY_TOKEN', $tokenCheck === '1'
                ? 'เหรียญนี้ไม่ได้สร้างจาก TPIX Token Factory'
                : 'เหรียญนี้เปิดสวิตช์เสี่ยง (mint/pause/blacklist/auto-burn)');
        }

        $uint = fn (string $to, string $sig) => $read($rpc->callUint($to, $sig), $sig);
        $time = fn (string $v) => Carbon::createFromTimestamp((int) $v);

        $values = [
            'chain_id' => $this->bridge->tpixChainId(),
            'factory_address' => $factory,
            'launch_id' => $launchId,
            'feeder_address' => $feeder,
            'vesting_address' => $vesting === '0x'.str_repeat('0', 40) ? null : $vesting,
            'token_address' => $token,
            'token_name' => mb_substr($this->readString($rpc, $token, 'name()') ?? '', 0, 120),
            'token_symbol' => mb_substr($this->readString($rpc, $token, 'symbol()') ?? '', 0, 32),
            'token_decimals' => (int) $uint($token, 'decimals()'),
            'quote_address' => $usdt,
            'rate_numerator' => $uint($saleAddress, 'rateNumerator()'),
            'rate_denominator' => $uint($saleAddress, 'rateDenominator()'),
            'sale_cap' => $uint($saleAddress, 'saleCap()'),
            'min_feed' => $uint($feeder, 'minFeed()'),
            'pool_share_bps' => (int) $uint($feeder, 'poolShareBps()'),
            'starts_at' => $time($uint($saleAddress, 'startTime()')),
            'ends_at' => $time($uint($saleAddress, 'endTime()')),
            'lp_unlock_at' => $time($uint($feeder, 'lpUnlockTime()')),
            'creator_address' => $creator,
        ];

        if ($values['token_symbol'] === '' || $values['token_name'] === '') {
            throw new LaunchpadOrderException('CHAIN_UNAVAILABLE', 'อ่านชื่อ/สัญลักษณ์เหรียญจากเชนไม่ได้', 503);
        }

        $sale = LaunchpadSale::where('sale_address', $saleAddress)->first();
        if ($sale) {
            $sale->update($values);

            return ['sale' => $sale->fresh(), 'created' => false];
        }

        return ['sale' => LaunchpadSale::create($values + ['sale_address' => $saleAddress, 'status' => LaunchpadSale::STATUS_PENDING]), 'created' => true];
    }

    /** อนุมัติขึ้นหน้าเว็บ — กันชื่อ/สัญลักษณ์ซ้ำกับหน้าขายที่อนุมัติแล้วของเหรียญตัวอื่น */
    public function approve(LaunchpadSale $sale, string $by, ?string $note = null): LaunchpadSale
    {
        $duplicate = $this->duplicateOf($sale);
        if ($duplicate) {
            throw new LaunchpadOrderException('DUPLICATE_TOKEN', "ชื่อหรือสัญลักษณ์ซ้ำกับหน้าขายที่อนุมัติแล้ว #{$duplicate->id} ({$duplicate->token_symbol} {$duplicate->token_address})");
        }

        $sale->update([
            'status' => LaunchpadSale::STATUS_APPROVED,
            'status_note' => $note ? mb_substr($note, 0, 300) : null,
            'reviewed_by' => mb_substr($by, 0, 80),
            'reviewed_at' => now(),
        ]);

        return $sale->fresh();
    }

    public function setStatus(LaunchpadSale $sale, string $status, string $by, ?string $note = null): LaunchpadSale
    {
        if (! in_array($status, [LaunchpadSale::STATUS_REJECTED, LaunchpadSale::STATUS_HIDDEN, LaunchpadSale::STATUS_PENDING], true)) {
            throw new LaunchpadOrderException('INVALID_STATUS', 'สถานะไม่ถูกต้อง');
        }

        $sale->update([
            'status' => $status,
            'status_note' => $note ? mb_substr($note, 0, 300) : null,
            'reviewed_by' => mb_substr($by, 0, 80),
            'reviewed_at' => now(),
        ]);

        return $sale->fresh();
    }

    public function duplicateOf(LaunchpadSale $sale): ?LaunchpadSale
    {
        $symbol = mb_strtolower(trim($sale->token_symbol));
        $name = mb_strtolower(trim($sale->token_name));

        return LaunchpadSale::query()
            ->where('status', LaunchpadSale::STATUS_APPROVED)
            ->where('id', '!=', $sale->id)
            ->where('token_address', '!=', $sale->token_address)
            ->get()
            ->first(fn (LaunchpadSale $other) => mb_strtolower(trim($other->token_symbol)) === $symbol
                || mb_strtolower(trim($other->token_name)) === $name);
    }

    /** ถอด string ที่สัญญาคืนมา (abi.encode(string)) — null = อ่านไม่ได้ */
    private function readString(EvmRpc $rpc, string $to, string $signature): ?string
    {
        $words = Abi::words($rpc->call($to, Abi::selector($signature)));
        if (count($words) < 2) {
            return null;
        }

        $length = (int) Wei::hexToInt('0x'.$words[1]);
        $bytes = hex2bin(substr(implode('', array_slice($words, 2)), 0, $length * 2));

        if (! is_string($bytes) || ! mb_check_encoding($bytes, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $bytes)) {
            return null;
        }

        return trim($bytes);
    }
}
