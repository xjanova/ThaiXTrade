<script setup>
/**
 * TPIX TRADE - Admin MasterNode Allowlist Dashboard
 *
 * จัดการ Cloudflare auto-allowlist สำหรับ masternode/validator operators:
 *   - List entries แยกตาม status (active/expired/revoked)
 *   - Revoke entry → ลบ CF rule + mark inactive
 *   - Force cleanup ad-hoc
 *   - Detect drift: orphan CF rules (มี CF rule แต่ไม่มีใน DB) / missing rules (มี DB แต่ CF rule หาย)
 *
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    entries: { type: Object, required: true },          // paginator
    stats: { type: Object, default: () => ({}) },
    cf_rule_count: { type: Number, default: 0 },
    cf_configured: { type: Boolean, default: false },
    orphan_rules: { type: Array, default: () => [] },
    missing_rules: { type: Array, default: () => [] },
    filter_status: { type: String, default: 'active' },
});

const cleaningUp = ref(false);
const revokingId = ref(null);

const fmt = (n) => Number(n || 0).toLocaleString();
const fmtTime = (t) => t ? new Date(typeof t === 'string' ? t : t * 1000).toLocaleString() : '—';

const tierBadgeClass = computed(() => ({
    Validator: 'bg-red-500/20 text-red-300 border-red-500/40',
    Guardian: 'bg-yellow-500/20 text-yellow-300 border-yellow-500/40',
    Sentinel: 'bg-purple-500/20 text-purple-300 border-purple-500/40',
    Light: 'bg-cyan-500/20 text-cyan-300 border-cyan-500/40',
}));

const statusBadgeClass = (s) => ({
    active: 'bg-green-500/20 text-green-300 border-green-500/40',
    expired: 'bg-gray-500/20 text-gray-300 border-gray-500/40',
    revoked: 'bg-orange-500/20 text-orange-300 border-orange-500/40',
}[s] || 'bg-gray-500/20 text-gray-300 border-gray-500/40');

function setFilter(status) {
    router.get(route('admin.masternode.allowlist.index'), { status }, {
        preserveState: true,
        preserveScroll: true,
    });
}

async function revokeEntry(entry) {
    if (!confirm(`Revoke allowlist for ${entry.wallet}?\nIP ${entry.ip} (${entry.tier}) will be removed from Cloudflare.`)) {
        return;
    }
    const reason = prompt('Reason (will be in audit log):', 'admin revoke') || 'no reason';

    revokingId.value = entry.id;
    router.post(route('admin.masternode.allowlist.revoke', entry.id), { reason }, {
        preserveScroll: true,
        onFinish: () => revokingId.value = null,
    });
}

async function deleteEntry(entry) {
    if (!confirm(`PERMANENTLY DELETE entry for ${entry.wallet}?\nThis is irreversible.`)) return;
    router.delete(route('admin.masternode.allowlist.destroy', entry.id), { preserveScroll: true });
}

async function runCleanup() {
    cleaningUp.value = true;
    router.post(route('admin.masternode.allowlist.cleanup'), {}, {
        preserveScroll: true,
        onFinish: () => cleaningUp.value = false,
    });
}
</script>

<template>
    <Head title="Masternode Allowlist" />
    <AdminLayout>
        <div class="p-6 space-y-6">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-white">Masternode Auto-Allowlist</h1>
                    <p class="text-sm text-gray-400 mt-1">
                        Operator IPs ที่ผ่านการ verify ผ่าน wallet signature → Cloudflare allowlist
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <button
                        @click="runCleanup"
                        :disabled="cleaningUp"
                        class="px-4 py-2 rounded-lg bg-blue-500/20 hover:bg-blue-500/30 border border-blue-500/40 text-blue-300 text-sm transition disabled:opacity-50"
                    >
                        {{ cleaningUp ? 'Cleaning…' : 'Run cleanup now' }}
                    </button>
                </div>
            </div>

            <!-- CF status banner -->
            <div v-if="!cf_configured" class="rounded-lg bg-orange-500/10 border border-orange-500/40 p-4 text-orange-200">
                ⚠️ Cloudflare API not configured — allowlist creation will succeed in DB but won't actually update Cloudflare.
                Set <code class="px-1 bg-black/30 rounded">CF_API_TOKEN</code> + <code class="px-1 bg-black/30 rounded">CF_ZONE_ID</code> in .env
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div class="rounded-lg bg-white/5 border border-white/10 p-4">
                    <div class="text-xs text-gray-400 uppercase">Active</div>
                    <div class="text-2xl font-bold text-green-400">{{ fmt(stats.active) }}</div>
                </div>
                <div class="rounded-lg bg-white/5 border border-white/10 p-4">
                    <div class="text-xs text-gray-400 uppercase">Expired</div>
                    <div class="text-2xl font-bold text-gray-400">{{ fmt(stats.expired) }}</div>
                </div>
                <div class="rounded-lg bg-white/5 border border-white/10 p-4">
                    <div class="text-xs text-gray-400 uppercase">Revoked</div>
                    <div class="text-2xl font-bold text-orange-400">{{ fmt(stats.revoked) }}</div>
                </div>
                <div class="rounded-lg bg-white/5 border border-white/10 p-4">
                    <div class="text-xs text-gray-400 uppercase">CF Rules</div>
                    <div class="text-2xl font-bold text-blue-400">{{ fmt(cf_rule_count) }}</div>
                </div>
                <div class="rounded-lg bg-white/5 border border-white/10 p-4">
                    <div class="text-xs text-gray-400 uppercase">By Tier</div>
                    <div class="flex flex-wrap gap-1 mt-1">
                        <span
                            v-for="(count, tier) in stats.by_tier"
                            :key="tier"
                            class="text-xs px-2 py-0.5 rounded border"
                            :class="tierBadgeClass[tier] || 'bg-gray-500/20 text-gray-300 border-gray-500/40'"
                        >
                            {{ tier }}: {{ count }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Drift warnings -->
            <div v-if="orphan_rules.length || missing_rules.length" class="rounded-lg bg-yellow-500/10 border border-yellow-500/40 p-4">
                <div class="font-semibold text-yellow-200 mb-2">⚠️ Drift detected — DB vs Cloudflare out of sync</div>
                <div v-if="orphan_rules.length" class="text-sm text-yellow-100/80">
                    {{ orphan_rules.length }} orphan CF rule(s) (exist on Cloudflare but not in DB) — run cleanup to remove
                </div>
                <div v-if="missing_rules.length" class="text-sm text-yellow-100/80">
                    {{ missing_rules.length }} missing CF rule(s) (exist in DB but not on Cloudflare) — wallet next heartbeat will recreate
                </div>
            </div>

            <!-- Filter tabs -->
            <div class="flex gap-2">
                <button
                    v-for="s in ['active', 'expired', 'revoked', 'all']"
                    :key="s"
                    @click="setFilter(s)"
                    class="px-4 py-2 rounded-lg text-sm transition"
                    :class="filter_status === s
                        ? 'bg-blue-500/30 border border-blue-500/50 text-blue-200'
                        : 'bg-white/5 border border-white/10 text-gray-400 hover:bg-white/10'"
                >
                    {{ s.charAt(0).toUpperCase() + s.slice(1) }}
                </button>
            </div>

            <!-- Entries table -->
            <div class="rounded-lg bg-white/5 border border-white/10 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-white/5 text-gray-400 text-xs uppercase">
                        <tr>
                            <th class="text-left px-4 py-3">Wallet</th>
                            <th class="text-left px-4 py-3">Tier</th>
                            <th class="text-left px-4 py-3">IP</th>
                            <th class="text-left px-4 py-3">Status</th>
                            <th class="text-left px-4 py-3">Last Heartbeat</th>
                            <th class="text-right px-4 py-3">Allowed Until</th>
                            <th class="text-right px-4 py-3">HB Count</th>
                            <th class="text-right px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <tr v-for="entry in entries.data" :key="entry.id" class="hover:bg-white/5 transition">
                            <td class="px-4 py-3 font-mono text-xs text-gray-300">
                                {{ entry.wallet?.slice(0, 10) }}…{{ entry.wallet?.slice(-4) }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2 py-0.5 rounded border" :class="tierBadgeClass[entry.tier]">
                                    {{ entry.tier }}
                                </span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-300">{{ entry.ip }}</td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2 py-0.5 rounded border" :class="statusBadgeClass(entry.status)">
                                    {{ entry.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-400 text-xs">{{ fmtTime(entry.last_heartbeat) }}</td>
                            <td class="px-4 py-3 text-right text-gray-400 text-xs">{{ fmtTime(entry.allowed_until) }}</td>
                            <td class="px-4 py-3 text-right text-gray-400 text-xs">{{ fmt(entry.heartbeat_count) }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex justify-end gap-2">
                                    <button
                                        v-if="entry.status === 'active'"
                                        @click="revokeEntry(entry)"
                                        :disabled="revokingId === entry.id"
                                        class="text-xs px-2 py-1 rounded bg-orange-500/20 hover:bg-orange-500/30 border border-orange-500/40 text-orange-300 disabled:opacity-50"
                                    >
                                        Revoke
                                    </button>
                                    <button
                                        @click="deleteEntry(entry)"
                                        class="text-xs px-2 py-1 rounded bg-red-500/20 hover:bg-red-500/30 border border-red-500/40 text-red-300"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!entries.data.length">
                            <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                                No entries with status: {{ filter_status }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination (simplified) -->
            <div v-if="entries.last_page > 1" class="flex items-center justify-between text-sm text-gray-400">
                <div>Page {{ entries.current_page }} of {{ entries.last_page }} · {{ entries.total }} entries</div>
                <div class="flex gap-2">
                    <a v-if="entries.prev_page_url" :href="entries.prev_page_url"
                        class="px-3 py-1 rounded border border-white/10 hover:bg-white/5">← Prev</a>
                    <a v-if="entries.next_page_url" :href="entries.next_page_url"
                        class="px-3 py-1 rounded border border-white/10 hover:bg-white/5">Next →</a>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
