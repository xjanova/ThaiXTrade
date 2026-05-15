<?php

namespace App\Console\Commands;

use App\Models\MasternodeAllowlist;
use App\Services\MasterNode\CloudflareWafService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * CleanupMasternodeAllowlist — เก็บกวาด entries ที่หมดอายุ
 *
 * Workflow:
 *   1. หา entries ที่ status=active แต่ allowed_until < now()
 *   2. ลบ Cloudflare rule (ถ้ามี cf_rule_id)
 *   3. mark status=expired (เก็บ row ไว้สำหรับ audit)
 *   4. หา entries เก่าเกิน 30 วัน + status=expired → ลบทิ้ง
 *
 * Schedule: ทุก 5 นาที (ลงทะเบียนใน routes/console.php)
 *
 * Usage:
 *   php artisan masternode:cleanup
 *   php artisan masternode:cleanup --dry-run
 *
 * Developed by Xman Studio
 */
class CleanupMasternodeAllowlist extends Command
{
    protected $signature = 'masternode:cleanup
        {--dry-run : Show what would be cleaned without making changes}';

    protected $description = 'Clean up expired masternode allowlist entries (and their Cloudflare rules)';

    public function handle(CloudflareWafService $cf): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $expired = MasternodeAllowlist::expired()->get();
        $this->info("Found {$expired->count()} expired entries");

        $expiredOk = 0;
        $cfRuleErrors = 0;

        foreach ($expired as $entry) {
            $line = sprintf(
                ' - %s (%s, IP %s, expired %s)',
                substr($entry->wallet_address, 0, 10).'…',
                $entry->tier,
                $entry->ip_address,
                $entry->allowed_until?->diffForHumans() ?? '?'
            );

            if ($dryRun) {
                $this->line('  [dry-run]'.$line);
                continue;
            }

            $this->line($line);

            // ลบ CF rule
            if ($entry->cf_rule_id) {
                $deleted = $cf->deleteRule($entry->cf_rule_id);
                if (!$deleted) {
                    $cfRuleErrors++;
                    Log::warning('masternode.cleanup: failed to delete CF rule', [
                        'wallet' => $entry->wallet_address,
                        'cf_rule_id' => $entry->cf_rule_id,
                    ]);
                }
            }

            // Mark expired (เก็บ row ไว้ audit)
            $entry->update([
                'status' => 'expired',
                'cf_rule_id' => null,
            ]);

            $expiredOk++;
        }

        // Hard-delete row เก่าเกิน 30 วันที่ status=expired/revoked
        $hardDeleted = 0;
        if (!$dryRun) {
            $hardDeleted = MasternodeAllowlist::whereIn('status', ['expired', 'revoked'])
                ->where('updated_at', '<', now()->subDays(30))
                ->delete();
        }

        $this->newLine();
        $this->info("Expired marked: {$expiredOk}");
        $this->info("CF rule delete errors: {$cfRuleErrors}");
        $this->info("Old rows hard-deleted: {$hardDeleted}");

        return self::SUCCESS;
    }
}
