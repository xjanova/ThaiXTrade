<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MasternodeAllowlist;
use App\Services\MasterNode\CloudflareWafService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin Dashboard — Masternode Auto-Allowlist
 *
 * URL: /admin/masternode/allowlist
 *
 * Features:
 *   - List active allowlist entries (wallet, IP, tier, last heartbeat, TTL)
 *   - Revoke (mark inactive + delete CF rule)
 *   - Force-cleanup (run masternode:cleanup ad-hoc)
 *   - Cross-check Cloudflare rules (detect drift)
 *
 * Developed by Xman Studio
 */
class MasternodeAllowlistController extends Controller
{
    public function __construct(private CloudflareWafService $cf)
    {
    }

    public function index(Request $request): Response
    {
        $status = $request->query('status', 'active'); // active | expired | revoked | all

        $query = MasternodeAllowlist::query()->orderByDesc('last_heartbeat');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $entries = $query->paginate(50)->through(fn ($r) => $r->toAdminArray());

        // Aggregate stats
        $stats = [
            'active' => MasternodeAllowlist::active()->count(),
            'expired' => MasternodeAllowlist::where('status', 'expired')->count(),
            'revoked' => MasternodeAllowlist::where('status', 'revoked')->count(),
            'by_tier' => MasternodeAllowlist::active()
                ->selectRaw('tier, count(*) as c')
                ->groupBy('tier')
                ->pluck('c', 'tier'),
        ];

        // Cross-check CF rules — detect drift (rule on CF but not in DB / vice versa)
        $cfRules = $this->cf->listAutoRules(50);
        $dbRuleIds = MasternodeAllowlist::active()->pluck('cf_rule_id')->filter()->all();

        $orphanRules = collect($cfRules)
            ->filter(fn ($r) => !in_array($r['id'], $dbRuleIds, true))
            ->values()
            ->all();

        // หา DB rows ที่ active แต่ CF rule หาย (drift) — wrap closure เพื่อ preserve active() scope
        // ไม่งั้น orWhereNotIn จะ escape WHERE group → return rows expired/revoked ปนมาด้วย
        $cfRuleIds = collect($cfRules)->pluck('id')->all();
        $missingRules = MasternodeAllowlist::active()
            ->where(function ($q) use ($cfRuleIds) {
                $q->whereNull('cf_rule_id');
                if (! empty($cfRuleIds)) {
                    $q->orWhereNotIn('cf_rule_id', $cfRuleIds);
                }
            })
            ->get()
            ->map(fn ($r) => $r->toAdminArray());

        return Inertia::render('Admin/MasterNode/Allowlist', [
            'entries' => $entries,
            'stats' => $stats,
            'cf_rule_count' => count($cfRules),
            'cf_configured' => $this->cf->configured(),
            'orphan_rules' => $orphanRules,
            'missing_rules' => $missingRules,
            'filter_status' => $status,
        ]);
    }

    public function revoke(int $id, Request $request)
    {
        $entry = MasternodeAllowlist::findOrFail($id);

        if ($entry->cf_rule_id) {
            $this->cf->deleteRule($entry->cf_rule_id);
        }

        $entry->update([
            'status' => 'revoked',
            'cf_rule_id' => null,
            'notes' => 'Revoked by admin: '.($request->input('reason', 'no reason given')),
        ]);

        Log::info('masternode.allowlist.revoke', [
            'id' => $id,
            'wallet' => $entry->wallet_address,
            'admin_id' => $request->user('admin')?->id,
        ]);

        return back()->with('success', "Allowlist #{$id} revoked");
    }

    public function destroy(int $id)
    {
        $entry = MasternodeAllowlist::findOrFail($id);

        if ($entry->cf_rule_id && $entry->status === 'active') {
            $this->cf->deleteRule($entry->cf_rule_id);
        }

        $entry->delete();

        return back()->with('success', "Allowlist #{$id} deleted");
    }

    public function runCleanup(Request $request)
    {
        Artisan::call('masternode:cleanup');
        $output = Artisan::output();

        return back()->with('success', "Cleanup ran:\n".$output);
    }
}
