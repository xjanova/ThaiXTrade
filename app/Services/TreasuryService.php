<?php

namespace App\Services;

use App\Models\TreasuryLedger;
use App\Models\TreasuryPayout;
use App\Models\TreasuryWhitelist;
use App\Support\TreasuryWallet;
use App\Support\Wei;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TreasuryService — ชั้นคลัง TPIX.
 *
 * หน้าที่: อ่านยอดกระเป๋าคลัง 6 ใบ + กระเป๋าร้อนจากเชนจริง, กระทบยอดกับสมุดบัญชี,
 * และเป็น **ด่านเดียว** ที่ตัดสินว่าระบบจ่ายเงินได้หรือยัง
 *
 * ยอดทุกค่าที่ออกจากคลาสนี้เป็น string หน่วย wei เว้นแต่ระบุ `_tpix`
 * ห้ามแปลงเป็น int/float ที่ไหนเลย — 1 TPIX = 1e18 wei เกิน PHP_INT_MAX
 * ตั้งแต่ 10 TPIX (ดู App\Support\Wei)
 */
class TreasuryService
{
    /**
     * ยอดคงเหลือของที่อยู่หนึ่ง (wei) อ่านจาก RPC ตรง.
     *
     * ไม่ผ่าน Blockscout เพราะมันไม่ index กระเป๋าที่ไม่เคยมีธุรกรรม —
     * กระเป๋าคลังที่ยังไม่เคยจ่ายจะหายไปทั้งใบ
     *
     * คืน null เมื่ออ่านไม่ได้ — **ไม่คืน '0'** เพราะ "อ่านไม่ได้" กับ
     * "ยอดเป็นศูนย์" ต่างกันคนละเรื่อง ถ้ากลืนเป็น 0 หน้าแดชบอร์ดจะโชว์
     * คลังว่างเปล่าทั้งที่จริงแค่ RPC ล่ม
     */
    public function balanceWei(string $address): ?string
    {
        $cacheKey = 'treasury:balance:'.strtolower($address);

        return Cache::remember($cacheKey, config('treasury.balance_cache_seconds', 15), function () use ($address) {
            $result = $this->rpc('eth_getBalance', [$address, 'latest']);

            if ($result === null) {
                return null;
            }

            try {
                return Wei::hexToInt($result);
            } catch (\Throwable $e) {
                Log::warning('treasury: แปลงยอดจาก hex ไม่ได้', [
                    'address' => $address,
                    'raw' => $result,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    /**
     * ยอดกระเป๋าคลังทั้ง 6 ใบ พร้อมสัดส่วนและส่วนต่างจาก genesis.
     */
    public function pools(): array
    {
        $out = [];

        foreach (config('treasury.pools', []) as $pool) {
            $balance = $this->balanceWei($pool['address']);
            $genesisWei = Wei::toWei($pool['genesis']);

            $out[] = [
                'key' => $pool['key'],
                'role' => $pool['role'],
                'role_th' => $pool['role_th'],
                'address' => $pool['address'],
                'path' => $pool['path'],
                'color' => $pool['color'],
                'genesis_tpix' => $pool['genesis'],
                'genesis_wei' => $genesisWei,
                'balance_wei' => $balance,
                'balance_tpix' => $balance === null ? null : Wei::format($balance),
                'readable' => $balance !== null,

                // จ่ายออกไปแล้วเท่าไหร่เทียบกับตอน genesis (บวก = จ่ายออก)
                // ติดลบได้ถ้ามีคนโอนเงินเข้ากระเป๋าคลังเพิ่ม ซึ่งก็ควรเห็นตรง ๆ
                'spent_wei' => $balance === null ? null : bcsub($genesisWei, $balance, 0),
                'spent_tpix' => $balance === null ? null : Wei::format(bcsub($genesisWei, $balance, 0)),
                'share_pct' => $this->sharePercent($balance),
            ];
        }

        return $out;
    }

    /**
     * ยอดกระเป๋าร้อน + สถานะว่าพร่องหรือยัง.
     */
    public function hotWallet(): array
    {
        // กระเป๋าเดียวกับที่รับค่าบริการ — ดู App\Support\TreasuryWallet
        $address = TreasuryWallet::address();
        $balance = $this->balanceWei($address);
        $warnAt = (string) config('treasury.hot_wallet.low_balance_warning', '0');
        $warnWei = $warnAt === '0' ? '0' : Wei::toWei($warnAt);

        return [
            'address' => $address,
            'balance_wei' => $balance,
            'balance_tpix' => $balance === null ? null : Wei::format($balance),
            'readable' => $balance !== null,
            'low_warning_tpix' => $warnAt,
            'is_low' => $balance !== null && $warnWei !== '0' && Wei::compare($balance, $warnWei) < 0,
            'is_empty' => $balance !== null && Wei::compare($balance, '0') === 0,
        ];
    }

    /**
     * ด่านความพร้อม — ระบบจ่ายเงินได้หรือยัง และถ้ายัง ขาดอะไรบ้าง.
     *
     * ทุกเงื่อนไขต้องผ่านหมดถึงจะจ่ายได้ ตัวที่ไม่ผ่านจะถูกรายงานกลับไป
     * ให้หน้าจอแสดงตรง ๆ ว่าค้างอะไรอยู่ ไม่ใช่แค่บอกว่า "ปิดอยู่"
     *
     * เมธอดนี้ถูกเรียกทั้งตอนเรนเดอร์หน้าและตอนก่อนเซ็นจริง — ห้ามเชื่อ
     * ฝั่งหน้าจออย่างเดียว
     */
    public function readiness(): array
    {
        $checks = [];

        // 1) สวิตช์หลัก
        $enabled = (bool) config('treasury.payouts_enabled', false);
        $checks[] = [
            'key' => 'flag',
            'label' => 'เปิดสวิตช์จ่ายเงินใน .env',
            'hint' => 'TPIX_TREASURY_PAYOUTS_ENABLED=true',
            'ok' => $enabled,
        ];

        // 2) keystore ต้องมีจริงและอ่านได้
        $keystorePath = (string) config('treasury.hot_wallet.keystore_path');
        $keystore = $this->inspectKeystorePath($keystorePath);
        $checks[] = [
            'key' => 'keystore',
            'label' => 'วางไฟล์ keystore ของกระเป๋าร้อนบนเซิร์ฟเวอร์',
            'hint' => $keystore['hint'],
            'ok' => $keystore['ok'],
        ];

        /*
         * 2b) keystore ต้องเป็นของ "กระเป๋าที่ระบบจะจ่ายเหรียญออก" ใบเดียวกัน
         *
         * ที่อยู่กระเป๋าจ่ายเหรียญอ่านจากหลังบ้าน (revenue.tpix_wallet) แต่ keystore
         * เป็นไฟล์ที่วางไว้เอง — สองอย่างนี้หลุดจากกันได้ง่ายมาก และเคยหลุดมาแล้ว
         * (prod ตั้ง 0x2112b98e… ขณะที่ค่าปริยายในโค้ดเป็น 0x78B81dF…)
         *
         * ไม่ตรง = HotWalletSigner ปฏิเสธการเซ็นตอนจ่ายจริง ซึ่งไปรู้เอาตอนลูกค้าจ่ายเงินแล้ว
         * ด่านนี้ทำให้รู้ตั้งแต่ตอนตรวจความพร้อม โดยไม่ต้องถอดรหัส keystore
         * (อ่านแค่ฟิลด์ address ในไฟล์ ไม่ต้องใช้ passphrase)
         */
        $payoutWallet = TreasuryWallet::address();
        $keystoreOwner = $keystore['ok'] ? $this->keystoreAddress($keystorePath) : null;
        $sameWallet = $keystoreOwner !== null
            && $payoutWallet !== ''
            && strtolower($keystoreOwner) === strtolower($payoutWallet);

        $checks[] = [
            'key' => 'keystore_matches_wallet',
            'label' => 'keystore เป็นกระเป๋าใบเดียวกับที่จ่ายเหรียญ',
            'hint' => match (true) {
                ! $keystore['ok'] => 'ยังไม่มี keystore ให้ตรวจ',
                $keystoreOwner === null => 'อ่านที่อยู่จากไฟล์ keystore ไม่ได้',
                $sameWallet => $payoutWallet,
                default => "keystore เป็นของ {$keystoreOwner} แต่ระบบจ่ายจาก {$payoutWallet} — เซ็นไม่ผ่านแน่นอน",
            },
            'ok' => $sameWallet,
        ];

        // 3) passphrase ต้องอยู่ใน .env (ไม่ใช่ใน database)
        $passOk = filled(config('treasury.hot_wallet.passphrase'));
        $checks[] = [
            'key' => 'passphrase',
            'label' => 'ตั้ง passphrase ของ keystore ใน .env',
            'hint' => 'TPIX_HOT_WALLET_PASS=… (ห้ามเก็บใน database ห้าม commit)',
            'ok' => $passOk,
        ];

        // 4) กระเป๋าร้อนต้องมีเงิน
        $hot = $this->hotWallet();
        $funded = $hot['readable'] && ! $hot['is_empty'];
        $checks[] = [
            'key' => 'funded',
            'label' => 'เติมเงินเข้ากระเป๋าร้อน',
            'hint' => $hot['readable']
                ? 'ยอดตอนนี้ '.$hot['balance_tpix'].' TPIX'
                : 'อ่านยอดจากเชนไม่ได้',
            'ok' => $funded,
        ];

        // 5) ต่อเชนได้
        $chainOk = $hot['readable'];
        $checks[] = [
            'key' => 'rpc',
            'label' => 'ต่อ RPC ของเชนได้',
            'hint' => (string) config('treasury.rpc_url'),
            'ok' => $chainOk,
        ];

        $blocking = array_values(array_filter($checks, fn ($c) => ! $c['ok']));

        return [
            'ready' => $blocking === [],
            'checks' => $checks,
            'blocking' => $blocking,
        ];
    }

    /**
     * ตรวจว่าไฟล์ keystore อ่านได้ไหม โดย **ห้ามโยน error ออกไปเด็ดขาด**.
     *
     * `is_readable()` บนพาธที่อยู่นอก `open_basedir` ไม่ได้คืน false เฉย ๆ —
     * มันปล่อย PHP warning ออกมา ซึ่ง Laravel แปลงเป็น ErrorException แล้ว
     * หน้าคลังทั้งหน้ากลายเป็น 500 ทั้งที่แค่ยังไม่ได้วางไฟล์
     *
     * เซิร์ฟเวอร์นี้ตั้ง open_basedir ไว้ที่ `/home/admin/` เป็นต้น การวาง
     * keystore ไว้ที่ `/etc/tpix/` จึงอ่านจากฝั่งเว็บไม่ได้เลย ต้องวางใน
     * `/home/admin/` แต่ **นอก document root** (เช่น `/home/admin/.tpix/`)
     * ซึ่งยังปลอดภัยเท่าเดิมเพราะเว็บเข้าไม่ถึงอยู่ดี
     *
     * @return array{ok: bool, hint: string}
     */
    private function inspectKeystorePath(string $path): array
    {
        if ($path === '') {
            return ['ok' => false, 'hint' => 'ยังไม่ได้ตั้ง TPIX_HOT_WALLET_KEYSTORE'];
        }

        // เช็ค open_basedir ก่อน เพื่อบอกเหตุผลที่แท้จริงแทนที่จะบอกแค่ "ไม่พบไฟล์"
        $baseDirs = array_filter(explode(PATH_SEPARATOR, (string) ini_get('open_basedir')));

        if ($baseDirs !== []) {
            $allowed = false;
            foreach ($baseDirs as $dir) {
                if (str_starts_with($path, rtrim($dir, '/').'/') || $path === rtrim($dir, '/')) {
                    $allowed = true;
                    break;
                }
            }

            if (! $allowed) {
                return [
                    'ok' => false,
                    'hint' => $path.' — อยู่นอก open_basedir ('.implode(', ', $baseDirs).') '
                        .'PHP ฝั่งเว็บอ่านไม่ได้ ต้องย้ายไปไว้ใน /home/admin/ แต่นอก public_html',
                ];
            }
        }

        // กันทุกกรณีที่เหลือ (permission, path แปลก) ไม่ให้ warning หลุดออกไปเป็น 500
        set_error_handler(static fn () => true);

        try {
            $readable = is_readable($path);
        } finally {
            restore_error_handler();
        }

        return [
            'ok' => $readable,
            'hint' => $readable ? $path : $path.' — ยังไม่พบไฟล์หรืออ่านไม่ได้',
        ];
    }

    /**
     * ที่อยู่ที่ไฟล์ keystore เป็นเจ้าของ — อ่านจากฟิลด์ address ในไฟล์ (ไม่ต้องใช้ passphrase).
     *
     * keystore V3 เก็บ address แบบไม่มี 0x นำหน้า และบางตัวสร้างมาไม่มีฟิลด์นี้เลย
     * (ยังใช้เซ็นได้ แต่เราตรวจล่วงหน้าไม่ได้) — คืน null ให้ผู้เรียกไปบอกผู้ใช้ตามจริง
     */
    private function keystoreAddress(string $path): ?string
    {
        set_error_handler(static fn () => true);

        try {
            $raw = file_get_contents($path);
        } catch (\Throwable) {
            $raw = false;
        } finally {
            restore_error_handler();
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $address = trim((string) ($json['address'] ?? ''));

        if ($address === '') {
            return null;
        }

        $address = str_starts_with($address, '0x') ? $address : '0x'.$address;

        return preg_match('/^0x[a-fA-F0-9]{40}$/', $address) === 1 ? $address : null;
    }

    /**
     * กระทบยอด — เทียบยอดจริงบนเชนกับที่สมุดบัญชีบันทึกไว้.
     *
     * ยอดที่สมุดคาดว่าควรเป็น = ยอด genesis + ยอดสุทธิที่สมุดบันทึก
     * ถ้าไม่ตรงกับยอดจริงบนเชนเกิน tolerance แปลว่ามีเงินเคลื่อนโดยที่
     * ระบบไม่รู้เห็น — ต้องดัง
     */
    public function reconcile(): array
    {
        $tolerance = (string) config('treasury.reconcile_tolerance_wei', '0');
        $rows = [];
        $driftCount = 0;
        $unreadable = 0;

        foreach (config('treasury.pools', []) as $pool) {
            $onChain = $this->balanceWei($pool['address']);

            if ($onChain === null) {
                $unreadable++;
                $rows[] = [
                    'key' => $pool['key'],
                    'role' => $pool['role'],
                    'role_th' => $pool['role_th'],
                    'address' => $pool['address'],
                    'readable' => false,
                    'on_chain_wei' => null,
                    'expected_wei' => null,
                    'drift_wei' => null,
                    'drift_tpix' => null,
                    'in_sync' => null,
                ];

                continue;
            }

            $expected = bcadd(
                Wei::toWei($pool['genesis']),
                TreasuryLedger::netMovementWei($pool['key']),
                0,
            );

            $drift = bcsub($onChain, $expected, 0);
            $absDrift = ltrim($drift, '-');
            $inSync = bccomp($absDrift, $tolerance, 0) <= 0;

            if (! $inSync) {
                $driftCount++;
            }

            $rows[] = [
                'key' => $pool['key'],
                'role' => $pool['role'],
                'role_th' => $pool['role_th'],
                'address' => $pool['address'],
                'readable' => true,
                'on_chain_wei' => $onChain,
                'on_chain_tpix' => Wei::format($onChain),
                'expected_wei' => $expected,
                'expected_tpix' => Wei::format($expected),
                'drift_wei' => $drift,
                'drift_tpix' => Wei::format($drift),
                'in_sync' => $inSync,
            ];
        }

        return [
            'rows' => $rows,
            'drift_count' => $driftCount,
            'unreadable_count' => $unreadable,
            'tolerance_wei' => $tolerance,
            'all_in_sync' => $driftCount === 0 && $unreadable === 0,
        ];
    }

    /**
     * ยอดที่ใช้ไปแล้ววันนี้ (wei) — นับเฉพาะรายการที่ยังไม่ถูกปฏิเสธ/ล้มเหลว.
     *
     * รวม pending ด้วยโดยตั้งใจ: ถ้ามีรายการรออนุมัติค้างอยู่ 9 ล้าน
     * แล้วยอมให้สร้างเพิ่มอีก 9 ล้านเพราะ "ยังไม่จ่าย" พออนุมัติพร้อมกัน
     * ก็ทะลุวงเงินทันที
     */
    public function spentTodayWei(): string
    {
        $rows = TreasuryPayout::whereIn('status', TreasuryPayout::COUNTS_TOWARD_LIMIT)
            ->whereDate('created_at', now()->toDateString())
            ->pluck('amount_wei');

        return Wei::sum($rows->all());
    }

    /**
     * วัตถุประสงค์ที่ยกเว้นการบังคับ whitelist.
     *
     * `token_sale` คือการส่งเหรียญให้ผู้ซื้อที่ claim vesting ปลายทางเป็นกระเป๋า
     * ของลูกค้าซึ่งเป็นคนละใบทุกครั้ง จะเอาเข้า whitelist ล่วงหน้าไม่ได้
     *
     * ที่ยกเว้นได้เพราะปลายทางไม่ได้มาจากคนกรอก — มันถูกผูกกับ
     * `sale_transactions.wallet_address` ของรายการซื้อที่ยืนยันแล้ว ซึ่งต้องผ่าน
     * การตรวจการชำระเงินบน BSC มาก่อน และวงเงินต่อครั้ง/ต่อวันยังบังคับอยู่ครบ
     */
    private const WHITELIST_EXEMPT_PURPOSES = ['token_sale'];

    /**
     * ตรวจว่ารายการจ่ายเงินนี้ผ่านกฎทุกข้อไหม.
     *
     * เรียกทั้งตอนสร้างรายการและตอนก่อนเซ็น — เพราะวงเงินหรือ whitelist
     * อาจถูกแก้ระหว่างที่รายการรออนุมัติอยู่
     *
     * @return array{ok: bool, errors: array<int, string>}
     */
    public function validatePayout(
        string $toAddress,
        string $amountWei,
        ?int $ignorePayoutId = null,
        ?string $purpose = null,
    ): array {
        $errors = [];

        if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $toAddress)) {
            $errors[] = 'ที่อยู่ปลายทางไม่ถูกรูปแบบ';
        }

        if (bccomp($amountWei, '0', 0) <= 0) {
            $errors[] = 'จำนวนเงินต้องมากกว่าศูนย์';
        }

        // whitelist
        $entry = TreasuryWhitelist::findActiveByAddress($toAddress);
        $needsWhitelist = config('treasury.limits.require_whitelist', true)
            && ! in_array($purpose, self::WHITELIST_EXEMPT_PURPOSES, true);

        if ($needsWhitelist && ! $entry) {
            $errors[] = 'ปลายทางนี้ไม่อยู่ใน whitelist';
        }

        // วงเงินต่อครั้ง — ของปลายทางนี้ก่อน ถ้าไม่ตั้งไว้ใช้ค่ากลาง
        $maxPerTx = $entry?->max_per_tx_wei ?: Wei::toWei((string) config('treasury.limits.per_transaction', '0'));
        if (bccomp($maxPerTx, '0', 0) > 0 && bccomp($amountWei, $maxPerTx, 0) > 0) {
            $errors[] = 'เกินวงเงินต่อครั้ง ('.Wei::format($maxPerTx).' TPIX)';
        }

        // วงเงินต่อวัน
        $maxPerDay = Wei::toWei((string) config('treasury.limits.per_day', '0'));
        if (bccomp($maxPerDay, '0', 0) > 0) {
            $spent = $this->spentTodayWei();

            if ($ignorePayoutId !== null) {
                $existing = TreasuryPayout::find($ignorePayoutId);
                if ($existing && in_array($existing->status, TreasuryPayout::COUNTS_TOWARD_LIMIT, true)) {
                    $spent = bcsub($spent, (string) $existing->amount_wei, 0);
                }
            }

            if (bccomp(bcadd($spent, $amountWei, 0), $maxPerDay, 0) > 0) {
                $errors[] = 'เกินวงเงินต่อวัน (ใช้ไปแล้ว '.Wei::format($spent).' จาก '.Wei::format($maxPerDay).' TPIX)';
            }
        }

        // เงินในกระเป๋าร้อนต้องพอ
        $hot = $this->hotWallet();
        if ($hot['readable'] && bccomp($hot['balance_wei'], $amountWei, 0) < 0) {
            $errors[] = 'กระเป๋าร้อนมีเงินไม่พอ (มี '.$hot['balance_tpix'].' TPIX)';
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * บล็อกล่าสุดของเชน — ใช้ดูว่า RPC ยังเดินอยู่ไหม.
     */
    public function blockNumber(): ?int
    {
        $result = $this->rpc('eth_blockNumber', []);

        if ($result === null) {
            return null;
        }

        try {
            return (int) Wei::hexToInt($result);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * สัดส่วนของยอดเทียบ total supply เป็นเปอร์เซ็นต์ (ทศนิยม 2 ตำแหน่ง).
     */
    private function sharePercent(?string $balanceWei): ?string
    {
        if ($balanceWei === null) {
            return null;
        }

        $totalWei = Wei::toWei((string) config('treasury.total_supply', '0'));

        if (bccomp($totalWei, '0', 0) === 0) {
            return null;
        }

        // คูณ 10000 ก่อนหาร ได้เป็น basis point แล้วค่อยหาร 100 เป็นเปอร์เซ็นต์
        // ทำแบบนี้เพื่อเก็บทศนิยม 2 ตำแหน่งโดยไม่ต้องแตะ float เลย
        //
        // บวกครึ่งตัวหารก่อนหารเพื่อ **ปัดใกล้สุด** ไม่ใช่ปัดลง — ไม่งั้น
        // 1.71B/7B = 24.4285% จะกลายเป็น 24.42% ซึ่งไม่ตรงกับไวท์เปเปอร์ที่เขียน 24.43%
        // แล้วคนอ่านจะนึกว่าคำนวณผิด (ตรงนี้เป็นแค่ตัวเลขแสดงผล ไม่ใช่ยอดเงิน
        // ยอดเงินจริงยังปัดลงเสมอตามเดิม)
        $basisPoints = bcdiv(
            bcadd(bcmul($balanceWei, '10000', 0), bcdiv($totalWei, '2', 0), 0),
            $totalWei,
            0,
        );

        return bcdiv($basisPoints, '100', 2);
    }

    /**
     * เรียก JSON-RPC — คืน result ดิบ หรือ null เมื่อพลาด.
     *
     * ต้องส่ง User-Agent เสมอ ไม่งั้น Cloudflare WAF ตอบ 403 กลับมาเป็น HTML
     * (เคยทำให้ Masternode app ต่อเชนไม่ได้มาแล้ว)
     */
    private function rpc(string $method, array $params): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('treasury.rpc_user_agent'),
                'Content-Type' => 'application/json',
            ])
                ->timeout((int) config('treasury.rpc_timeout', 10))
                ->post((string) config('treasury.rpc_url'), [
                    'jsonrpc' => '2.0',
                    'method' => $method,
                    'params' => $params,
                    'id' => 1,
                ]);

            if (! $response->successful()) {
                Log::warning('treasury: RPC ตอบไม่สำเร็จ', [
                    'method' => $method,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $data = $response->json();

            if (! is_array($data) || isset($data['error'])) {
                Log::warning('treasury: RPC คืน error', [
                    'method' => $method,
                    'error' => $data['error']['message'] ?? 'ไม่ทราบสาเหตุ',
                ]);

                return null;
            }

            return isset($data['result']) ? (string) $data['result'] : null;
        } catch (\Throwable $e) {
            Log::warning('treasury: เรียก RPC ไม่สำเร็จ', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
