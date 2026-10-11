<script setup>
/**
 * TPIX TRADE — Proof of Reserves ของสะพาน USDT
 *
 * แสดงผลกระทบยอดล่าสุด (bridge:reconcile ทุก 5 นาที): supply ของ USDT บน TPIX Chain
 * เทียบ USDT จริงในที่อยู่รับฝาก + กระเป๋าจ่ายทุกเชน พร้อมลิงก์ explorer ให้ตรวจเองได้ทุกยอด
 * ตัวเลขบนหน้านี้ไม่ต้องเชื่อเรา — ทุกบรรทัดกดไปดูบนเชนได้
 *
 * Developed by Xman Studio
 */
import { ref, computed, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';
import { USDT_TPIX_DECIMALS, formatUnitsPlain, explorerTokenHolder, explorerAddress } from '@/utils/bridge';

const { t } = useTranslation();
const data = ref(null);
const failed = ref(false);

onMounted(async () => {
    try {
        const res = await axios.get('/api/v1/bridge/reserves');
        data.value = res.data.data;
    } catch {
        failed.value = true;
    }
});

const snap = computed(() => data.value?.snapshot ?? null);
const tpixExplorer = computed(() => data.value?.tpix?.explorer ?? 'https://explorer.tpix.online');
const deficitIsSurplus = computed(() => snap.value && String(snap.value.deficit ?? '0').startsWith('-'));

const statusTone = computed(() => ({
    ok: 'border-trading-green/40 bg-trading-green/10 text-trading-green',
    deficit: 'border-trading-red/40 bg-trading-red/10 text-trading-red',
    incomplete: 'border-amber-500/40 bg-amber-500/10 text-amber-300',
}[snap.value?.status] ?? 'border-white/10 bg-white/5 text-dark-300'));

const units = (v) => (v === null || v === undefined ? null : formatUnitsPlain(BigInt(v), USDT_TPIX_DECIMALS));
const fmtTime = (iso) => (iso ? new Date(iso).toLocaleString() : '—');
</script>

<template>
    <Head :title="t('bridge.reserves.title')" />
    <AppLayout>
        <div class="max-w-4xl mx-auto space-y-6 px-1">
            <div class="text-center">
                <h1 class="text-2xl md:text-3xl font-bold text-white mb-2">🛡️ {{ t('bridge.reserves.title') }}</h1>
                <p class="text-dark-400 text-sm">{{ t('bridge.reserves.subtitle') }}</p>
                <Link href="/bridge" class="inline-block mt-2 text-xs text-primary-400 hover:underline">← {{ t('bridge.reserves.backToBridge') }}</Link>
            </div>

            <div v-if="failed || (data && !snap)" class="glass-card text-center text-sm text-dark-400">{{ t('bridge.reserves.noSnapshot') }}</div>

            <template v-if="snap">
                <div :class="['rounded-xl border p-4 flex flex-wrap items-center justify-between gap-2', statusTone]">
                    <span class="font-semibold">{{ t(`bridge.reserves.status_${snap.status}`) }}</span>
                    <span class="text-xs opacity-80">{{ t('bridge.reserves.checkedAt') }}: {{ fmtTime(snap.checked_at) }}</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="glass-card">
                        <p class="text-xs text-dark-400">{{ t('bridge.reserves.supply') }}</p>
                        <p class="text-xl font-bold text-white font-mono mt-1">{{ snap.supply ?? '—' }}</p>
                    </div>
                    <div class="glass-card">
                        <p class="text-xs text-dark-400">{{ t('bridge.reserves.backing') }}</p>
                        <p class="text-xl font-bold text-trading-green font-mono mt-1">{{ snap.backing }}</p>
                    </div>
                    <div class="glass-card">
                        <p class="text-xs text-dark-400">{{ deficitIsSurplus ? t('bridge.reserves.surplus') : t('bridge.reserves.deficit') }}</p>
                        <p :class="['text-xl font-bold font-mono mt-1', deficitIsSurplus || snap.deficit === '0' ? 'text-white' : 'text-trading-red']">
                            {{ deficitIsSurplus ? String(snap.deficit).slice(1) : snap.deficit }}
                        </p>
                    </div>
                </div>

                <div class="glass-card text-xs text-dark-300 space-y-1">
                    <div class="flex justify-between"><span>{{ t('bridge.reserves.vault') }}</span><span class="font-mono text-white">{{ snap.vault }}</span></div>
                    <div class="flex justify-between"><span>{{ t('bridge.reserves.payout') }}</span><span class="font-mono text-white">{{ snap.payout_wallets }}</span></div>
                    <div class="flex justify-between"><span>{{ t('bridge.reserves.owed') }}</span><span class="font-mono text-white">− {{ snap.owed }}</span></div>
                    <p class="text-dark-500 pt-2">{{ t('bridge.reserves.explain') }}</p>
                </div>

                <div class="glass-card overflow-x-auto">
                    <h3 class="text-sm font-semibold text-white mb-3">{{ t('bridge.reserves.perChain') }}</h3>
                    <table class="w-full text-xs min-w-[480px]">
                        <thead class="text-dark-400">
                            <tr>
                                <th class="text-left py-1.5">{{ t('bridge.reserves.chain') }}</th>
                                <th class="text-right py-1.5">{{ t('bridge.reserves.vaultBalance') }}</th>
                                <th class="text-right py-1.5">{{ t('bridge.reserves.payoutBalance') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <tr v-for="c in snap.chains" :key="c.chain_id">
                                <td class="py-2 text-white">{{ c.name }} <span class="text-dark-500">({{ c.symbol }})</span></td>
                                <td class="py-2 text-right font-mono">
                                    <a v-if="c.vault_url" :href="c.vault_url" target="_blank" rel="noopener" class="text-primary-300 hover:underline">
                                        {{ c.readable ? units(c.vault_units) : t('bridge.reserves.unreadable') }}
                                    </a>
                                </td>
                                <td class="py-2 text-right font-mono">
                                    <a v-if="c.payout_url" :href="c.payout_url" target="_blank" rel="noopener" class="text-primary-300 hover:underline">
                                        {{ c.readable ? units(c.payout_units) : t('bridge.reserves.unreadable') }}
                                    </a>
                                    <span v-else class="text-dark-500">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <div v-if="data" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="glass-card text-xs space-y-2">
                    <h3 class="text-sm font-semibold text-white">{{ t('bridge.reserves.contracts') }}</h3>
                    <div>
                        <p class="text-dark-400">{{ t('bridge.vaultAddress') }}</p>
                        <code class="font-mono text-white break-all">{{ data.vault_address }}</code>
                    </div>
                    <div v-if="data.payout_address">
                        <p class="text-dark-400">{{ t('bridge.reserves.payoutWallet') }}</p>
                        <code class="font-mono text-white break-all">{{ data.payout_address }}</code>
                    </div>
                    <div v-if="data.tpix?.usdt_tpix">
                        <p class="text-dark-400">{{ t('bridge.reserves.usdtTpix') }}</p>
                        <a :href="explorerAddress(tpixExplorer, data.tpix.usdt_tpix)" target="_blank" rel="noopener" class="font-mono text-primary-300 break-all hover:underline">{{ data.tpix.usdt_tpix }}</a>
                    </div>
                    <div v-if="data.minter_address">
                        <p class="text-dark-400">{{ t('bridge.reserves.minter') }}</p>
                        <a :href="explorerAddress(tpixExplorer, data.minter_address)" target="_blank" rel="noopener" class="font-mono text-primary-300 break-all hover:underline">{{ data.minter_address }}</a>
                    </div>
                </div>

                <div class="glass-card text-xs space-y-2">
                    <h3 class="text-sm font-semibold text-white">{{ t('bridge.reserves.caps') }}</h3>
                    <p v-if="data.minter?.paused" class="text-trading-red">{{ t('bridge.reserves.minterPaused') }}</p>
                    <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.reserves.maxPerTx') }}</span><span class="text-white font-mono">{{ data.minter?.max_per_tx ?? '—' }}</span></div>
                    <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.reserves.dailyCap') }}</span><span class="text-white font-mono">{{ data.minter?.daily_cap ?? '—' }}</span></div>
                    <div class="flex justify-between"><span class="text-dark-400">{{ t('bridge.reserves.remainingToday') }}</span><span class="text-white font-mono">{{ data.minter?.remaining_today ?? '—' }}</span></div>

                    <h3 class="text-sm font-semibold text-white pt-2">{{ t('bridge.reserves.fees') }}</h3>
                    <div v-for="c in data.chains" :key="c.chain_id" class="flex justify-between">
                        <span class="text-dark-400">{{ c.name }}</span>
                        <a :href="explorerTokenHolder(c.explorer, c.usdt_address, data.vault_address)" target="_blank" rel="noopener" class="text-white font-mono hover:text-primary-300">{{ c.withdraw_fee }} USDT</a>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
