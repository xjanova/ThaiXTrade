<?php

namespace App\Console\Commands;

use App\Models\TreasuryLedger;
use App\Support\Wei;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * เก็บรายการเคลื่อนไหวของกระเป๋าคลังจากเชนเข้าสมุดบัญชีอัตโนมัติ.
 *
 * ทำไมต้องมี:
 * ตัวกระทบยอดเทียบ "ยอดจริงบนเชน" กับ "genesis + สิ่งที่สมุดบันทึก"
 * ถ้าไม่มีตัวนี้ พอมีใครโอนเงินออกจากคลังผ่าน Masternode UI (ซึ่งเป็นทางเดียว
 * ที่โอนได้ เพราะระบบเว็บไม่มีคีย์ของคลัง) สมุดจะไม่รู้เรื่อง แล้วตัวกระทบยอด
 * จะฟ้องว่าไม่ตรงทั้งที่เป็นการโอนที่ถูกต้อง
 *
 * เคสแรกที่จะเจอคือ **ตอนเติมเงินเข้ากระเป๋าร้อน** ซึ่งต้องโอนจากคลังใบใดใบหนึ่ง
 *
 * รันซ้ำได้ตลอด — unique (tx_hash, wallet_key, direction) กันบันทึกซ้ำ
 */
class TreasurySync extends Command
{
    protected $signature = 'tpix:treasury-sync
                            {--wallet= : ทำเฉพาะกระเป๋าเดียว (ใส่ key เช่น token_sale หรือ hot_wallet)}
                            {--pages=5 : จำนวนหน้าสูงสุดที่ดึงต่อกระเป๋า กันวิ่งยาวเกินไป}
                            {--dry-run : ดูอย่างเดียว ไม่บันทึกลงฐานข้อมูล}';

    protected $description = 'ดึงรายการเคลื่อนไหวของกระเป๋าคลังจาก explorer มาบันทึกลงสมุดบัญชี';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('wallet');
        $maxPages = max(1, (int) $this->option('pages'));

        $wallets = $this->walletList();

        if ($only) {
            $wallets = array_filter($wallets, fn ($w) => $w['key'] === $only);
            if ($wallets === []) {
                $this->error("ไม่รู้จักกระเป๋า '{$only}'");

                return self::FAILURE;
            }
        }

        $explorer = rtrim((string) config('chains.chains.4289.explorer', 'https://explorer.tpix.online'), '/');
        $this->line("explorer: {$explorer}");
        if ($dryRun) {
            $this->warn('โหมดดูอย่างเดียว — จะไม่บันทึกอะไรลงฐานข้อมูล');
        }

        $totalNew = 0;
        $totalSeen = 0;
        $failed = [];

        foreach ($wallets as $wallet) {
            $result = $this->syncWallet($explorer, $wallet, $maxPages, $dryRun);

            if ($result === null) {
                $failed[] = $wallet['key'];
                $this->line(sprintf('  %-22s <fg=red>ดึงข้อมูลไม่ได้</>', $wallet['key']));

                continue;
            }

            $totalNew += $result['new'];
            $totalSeen += $result['seen'];

            $this->line(sprintf(
                '  %-22s เจอ %3d รายการ · บันทึกใหม่ %d',
                $wallet['key'],
                $result['seen'],
                $result['new'],
            ));
        }

        $this->newLine();
        $this->info("รวม: เจอ {$totalSeen} รายการ · บันทึกใหม่ {$totalNew}");

        if ($failed !== []) {
            // ดึงไม่ได้ = ไม่รู้ว่ามีอะไรเปลี่ยนไหม ต้องถือว่าล้มเหลว ไม่ใช่ "ไม่มีอะไรใหม่"
            // ไม่งั้น cron จะรายงานเขียวทั้งที่ข้อมูลค้าง
            $this->error('ดึงไม่ได้ '.count($failed).' กระเป๋า: '.implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * กระเป๋าทั้งหมดที่ต้องตาม — คลัง 6 ใบ + กระเป๋าร้อน.
     */
    private function walletList(): array
    {
        $wallets = array_map(fn ($p) => [
            'key' => $p['key'],
            'address' => strtolower($p['address']),
        ], config('treasury.pools', []));

        $hot = config('treasury.hot_wallet.address');
        if (filled($hot)) {
            $wallets[] = ['key' => 'hot_wallet', 'address' => strtolower($hot)];
        }

        return $wallets;
    }

    /**
     * @return array{new: int, seen: int}|null  null = ดึงข้อมูลไม่สำเร็จ
     */
    private function syncWallet(string $explorer, array $wallet, int $maxPages, bool $dryRun): ?array
    {
        $new = 0;
        $seen = 0;
        $params = [];

        for ($page = 0; $page < $maxPages; $page++) {
            $body = $this->fetchTransactions($explorer, $wallet['address'], $params);

            if ($body === null) {
                return null;
            }

            foreach ($body['items'] ?? [] as $tx) {
                $seen++;
                if ($this->recordTransaction($wallet, $tx, $dryRun)) {
                    $new++;
                }
            }

            // ไม่มีหน้าถัดไปก็จบ
            if (empty($body['next_page_params'])) {
                break;
            }

            $params = $body['next_page_params'];
        }

        return ['new' => $new, 'seen' => $seen];
    }

    /**
     * ดึงรายการธุรกรรมจาก Blockscout — คืน null เมื่อดึงไม่สำเร็จ.
     *
     * ต้องส่ง User-Agent ไม่งั้น Cloudflare WAF ตอบ 403 กลับมาเป็น HTML
     *
     * **404 ไม่ใช่ความล้มเหลว** — Blockscout ไม่รู้จักที่อยู่ที่ไม่เคยมีทั้งยอดและ
     * ธุรกรรม แล้วตอบ `{"message":"Not found"}` กลับมา ซึ่งกรณีนี้แปลว่า
     * "ยังไม่มีอะไรให้เก็บ" ไม่ใช่ "ดึงไม่ได้" ถ้าไม่แยกสองอย่างนี้ cron จะ
     * รายงานล้มเหลวทุกรอบจนกว่ากระเป๋าร้อนจะถูกใช้งานครั้งแรก
     */
    private function fetchTransactions(string $explorer, string $address, array $params): ?array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('treasury.rpc_user_agent', 'TPIX-Treasury/1.0'),
            ])
                ->timeout((int) config('treasury.rpc_timeout', 10))
                ->get("{$explorer}/api/v2/addresses/{$address}/transactions", $params);

            // 404 ที่ยอมรับได้ต้องเป็นของ Blockscout เองเท่านั้น คือ JSON
            // {"message":"Not found"} — ถ้า 404 มาจาก URL ผิดหรือ proxy จะได้
            // HTML หรือ body หน้าตาอื่น ซึ่งต้องถือว่าล้มเหลว ไม่ใช่ "ไม่มีธุรกรรม"
            // ไม่งั้นตั้ง explorer ผิดแล้วระบบจะรายงานเขียวว่าไม่มีอะไรตลอดกาล
            if ($response->status() === 404) {
                $body = $response->json();

                if (is_array($body) && array_key_exists('message', $body)) {
                    return ['items' => [], 'next_page_params' => null];
                }

                Log::warning('treasury-sync: ได้ 404 ที่ไม่ใช่ของ Blockscout — น่าจะตั้ง explorer ผิด', [
                    'address' => $address,
                    'url' => "{$explorer}/api/v2/addresses/{$address}/transactions",
                ]);

                return null;
            }

            if (! $response->successful()) {
                Log::warning('treasury-sync: explorer ตอบไม่สำเร็จ', [
                    'address' => $address,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $body = $response->json();

            return is_array($body) ? $body : null;
        } catch (\Throwable $e) {
            Log::warning('treasury-sync: เรียก explorer ไม่สำเร็จ', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * บันทึกธุรกรรมหนึ่งรายการ — คืน true ถ้าเป็นรายการใหม่.
     */
    private function recordTransaction(array $wallet, array $tx, bool $dryRun): bool
    {
        $hash = $tx['hash'] ?? null;
        $value = (string) ($tx['value'] ?? '0');

        if (! $hash || bccomp($value, '0', 0) <= 0) {
            // ไม่มี hash หรือไม่ได้ย้ายเงิน native (เช่นเรียก contract เฉย ๆ) — ข้าม
            return false;
        }

        $from = strtolower($tx['from']['hash'] ?? $tx['from'] ?? '');
        $to = strtolower($tx['to']['hash'] ?? $tx['to'] ?? '');

        if ($from === $wallet['address']) {
            $direction = TreasuryLedger::DIRECTION_DEBIT;
        } elseif ($to === $wallet['address']) {
            $direction = TreasuryLedger::DIRECTION_CREDIT;
        } else {
            // explorer คืน tx ที่กระเป๋านี้ไม่ได้เป็นทั้งต้นทางและปลายทาง
            // (เช่นเป็น internal tx) — ยังไม่รองรับ ข้ามไปก่อนดีกว่าเดาผิด
            return false;
        }

        $exists = TreasuryLedger::where('tx_hash', $hash)
            ->where('wallet_key', $wallet['key'])
            ->where('direction', $direction)
            ->exists();

        if ($exists) {
            return false;
        }

        if ($dryRun) {
            $this->line(sprintf(
                '      [ดูอย่างเดียว] %s %s %s TPIX  %s',
                $wallet['key'],
                $direction === TreasuryLedger::DIRECTION_DEBIT ? 'ออก' : 'เข้า',
                Wei::format($value),
                substr($hash, 0, 14).'…',
            ));

            return true;
        }

        try {
            TreasuryLedger::create([
                'wallet_key' => $wallet['key'],
                'wallet_address' => $wallet['address'],
                'direction' => $direction,
                'amount_wei' => $value,
                'source' => 'chain-sync',
                'tx_hash' => $hash,
                'block_number' => $tx['block_number'] ?? $tx['block'] ?? null,
                'note' => 'เก็บอัตโนมัติจาก explorer',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // ชนกับ unique = มีคนบันทึกไปแล้วระหว่างที่เรากำลังทำ ไม่ใช่ error
            return false;
        }

        return true;
    }
}
