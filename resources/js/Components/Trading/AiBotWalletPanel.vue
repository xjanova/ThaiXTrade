<script setup>
/**
 * TPIX TRADE — กระเป๋าบอท: กระเป๋าแยกที่บอทใช้ในโหมดจริง
 *
 * เจ้าของสั่ง: "บอทจะล็อกกระเป๋าแยกไปต่างหาก ผู้ใช้ต้องโอนไปใส่กระเป๋าบอทก่อน
 * เปิดบอทแล้วยังเทรดเองได้ปกติ ไม่กระทบกัน — คนละกระเป๋ากับที่ผู้ใช้เทรด"
 *
 * แผงนี้ทำสามอย่าง: โชว์ที่อยู่ให้โอนเข้า · โชว์ยอดของบอท · ถอนกลับกระเป๋าของตัวเอง
 * (ปลายทางเดียว ไม่มีช่องให้กรอกที่อยู่อื่น — เซิร์ฟเวอร์ก็ไม่รับอยู่แล้ว)
 *
 * Developed by Xman Studio
 */
import { ref, computed, onMounted, watch } from 'vue';
import { useAiBot } from '@/Composables/useAiBot';
import { useTranslation } from '@/Composables/useTranslation';

const bot = useAiBot();
const { t } = useTranslation();

const asset = ref('USDT');
const amount = ref('');
const copied = ref(false);
const message = ref(null);   // { ok, text }

const data = computed(() => bot.botWallet.value);
const wallet = computed(() => data.value?.wallet ?? null);
const enabled = computed(() => data.value?.enabled ?? false);
const transfers = computed(() => data.value?.transfers ?? []);
const assets = computed(() => wallet.value?.assets ?? []);
const selectedAsset = computed(() => assets.value.find(a => a.symbol === asset.value) ?? assets.value[0] ?? null);

const shortOwner = computed(() => {
    const a = bot.wallet.value || '';
    return a ? `${a.slice(0, 6)}…${a.slice(-4)}` : '';
});

function fmt(value, digits = 4) {
    const n = Number(value);
    if (!Number.isFinite(n)) return '0';
    return n.toLocaleString('en-US', { maximumFractionDigits: digits });
}

async function copyAddress() {
    if (!wallet.value?.address) return;
    try {
        await navigator.clipboard.writeText(wallet.value.address);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1500);
    } catch (_) { /* เบราว์เซอร์ไม่ให้เข้าคลิปบอร์ด — ผู้ใช้ก๊อปเองจากช่องได้ */ }
}

function useMax() {
    if (!selectedAsset.value) return;
    let max = Number(selectedAsset.value.balance || 0);
    // เหรียญหลักต้องเหลือไว้จ่ายแก๊ส
    if (selectedAsset.value.type === 'native') max = Math.max(0, max - Number(wallet.value?.gas_reserve || 0));
    amount.value = max > 0 ? String(Math.floor(max * 1e6) / 1e6) : '';
}

async function run(action) {
    message.value = null;
    const result = await action();
    message.value = result.ok
        ? { ok: true, text: t('aiTrade.botWallet.done') }
        : { ok: false, text: result.error?.message || t('aiTrade.botWallet.failed') };
    return result;
}

async function submitWithdraw() {
    const value = Number(amount.value);
    if (!Number.isFinite(value) || value <= 0) {
        message.value = { ok: false, text: t('aiTrade.botWallet.amountInvalid') };
        return;
    }
    const result = await run(() => bot.withdrawBotWallet(asset.value, value));
    if (result.ok) amount.value = '';
}

const statusClass = (status) => ({
    queued: 'text-amber-300 bg-amber-400/10',
    signing: 'text-sky-300 bg-sky-400/10',
    broadcasting: 'text-sky-300 bg-sky-400/10',
    confirmed: 'text-trading-green bg-trading-green/10',
    failed: 'text-trading-red bg-trading-red/10',
    cancelled: 'text-dark-400 bg-white/5',
}[status] || 'text-dark-300 bg-white/5');

onMounted(() => { if (bot.isConnected.value) bot.loadBotWallet(); });
watch(() => bot.wallet.value, (v) => { if (v) bot.loadBotWallet(); });
watch(assets, (list) => { if (list.length && !list.some(a => a.symbol === asset.value)) asset.value = list[0].symbol; });
</script>

<template>
    <section class="glass-dark rounded-2xl p-5 space-y-4">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="text-sm font-bold text-white flex items-center gap-2">
                    <span aria-hidden="true">👛</span>{{ t('aiTrade.botWallet.title') }}
                    <span v-if="wallet" class="px-1.5 py-px rounded text-[10px] font-medium bg-white/5 text-dark-300">{{ wallet.chain_name || 'BSC' }}</span>
                </h3>
                <p class="text-[11px] text-dark-400 mt-1 leading-relaxed">{{ t('aiTrade.botWallet.pitch') }}</p>
            </div>
            <button
                v-if="wallet"
                type="button"
                class="shrink-0 px-2.5 py-1 rounded-md text-[11px] bg-white/5 text-dark-300 hover:text-white hover:bg-white/10 disabled:opacity-40"
                :disabled="bot.isWorking.value"
                @click="run(() => bot.refreshBotWallet())"
            >{{ t('aiTrade.botWallet.refresh') }}</button>
        </div>

        <!-- ยังไม่เปิดฟีเจอร์ -->
        <p v-if="!enabled" class="text-[12px] text-dark-300 rounded-xl bg-white/5 px-3 py-2.5 leading-relaxed">
            {{ t('aiTrade.botWallet.disabled') }}
        </p>

        <!-- เปิดแล้ว แต่ยังไม่ได้สร้าง -->
        <div v-else-if="!wallet" class="space-y-3">
            <p class="text-[12px] text-dark-300 leading-relaxed">{{ t('aiTrade.botWallet.createHint') }}</p>
            <button
                type="button"
                class="btn-brand px-4 py-2 text-xs disabled:opacity-40"
                :disabled="bot.isWorking.value || !bot.isConnected.value"
                @click="run(() => bot.createBotWallet())"
            >{{ t('aiTrade.botWallet.create') }}</button>
        </div>

        <template v-else>
            <!-- ที่อยู่สำหรับโอนเข้า -->
            <div class="rounded-xl bg-white/5 p-3 space-y-2">
                <p class="text-[10px] uppercase tracking-wide text-dark-500">{{ t('aiTrade.botWallet.depositAddress') }}</p>
                <div class="flex items-center gap-2">
                    <code class="flex-1 min-w-0 truncate text-[12px] font-mono text-white">{{ wallet.address }}</code>
                    <button type="button" class="shrink-0 px-2 py-1 rounded-md text-[11px] bg-white/5 text-dark-300 hover:text-white" @click="copyAddress">
                        {{ copied ? t('aiTrade.botWallet.copied') : t('aiTrade.botWallet.copy') }}
                    </button>
                    <a v-if="wallet.explorer_url" :href="wallet.explorer_url" target="_blank" rel="noopener" class="shrink-0 text-[11px] text-primary-400 hover:underline">BscScan</a>
                </div>
                <p class="text-[11px] text-dark-400 leading-relaxed">{{ t('aiTrade.botWallet.depositHint', { gas: wallet.native_symbol }) }}</p>
            </div>

            <!-- ยอดของบอท -->
            <div class="grid grid-cols-2 gap-2">
                <div v-for="a in assets" :key="a.symbol" class="rounded-xl bg-white/5 px-3 py-2">
                    <p class="text-[10px] text-dark-500">{{ a.symbol }}<span v-if="a.type === 'native'" class="ml-1 text-dark-600">· {{ t('aiTrade.botWallet.gas') }}</span></p>
                    <p class="text-sm font-mono font-semibold text-white">{{ fmt(a.balance, a.type === 'native' ? 5 : 2) }}</p>
                </div>
            </div>
            <p v-if="wallet.balances_at" class="text-[10px] text-dark-500">{{ t('aiTrade.botWallet.balancesAt') }} {{ new Date(wallet.balances_at).toLocaleTimeString() }}</p>

            <!-- ถอนกลับกระเป๋าของฉัน -->
            <form class="space-y-2" @submit.prevent="submitWithdraw">
                <p class="text-[10px] uppercase tracking-wide text-dark-500">{{ t('aiTrade.botWallet.withdrawTitle', { owner: shortOwner }) }}</p>
                <div class="flex gap-2">
                    <select v-model="asset" class="trading-input w-24 text-xs">
                        <option v-for="a in assets" :key="a.symbol" :value="a.symbol">{{ a.symbol }}</option>
                    </select>
                    <div class="relative flex-1">
                        <input v-model="amount" type="number" step="any" min="0" inputmode="decimal" class="trading-input w-full text-xs pr-12" :placeholder="t('aiTrade.botWallet.amount')" />
                        <button type="button" class="absolute right-1.5 top-1/2 -translate-y-1/2 px-1.5 py-0.5 rounded text-[10px] bg-white/5 text-dark-300 hover:text-white" @click="useMax">MAX</button>
                    </div>
                    <button
                        type="submit"
                        class="btn-brand px-3 py-1.5 text-xs disabled:opacity-40"
                        :disabled="bot.isWorking.value || wallet.has_pending_withdraw"
                    >{{ t('aiTrade.botWallet.withdraw') }}</button>
                </div>
                <p class="text-[10px] text-dark-500 leading-relaxed">{{ t('aiTrade.botWallet.withdrawHint') }}</p>
            </form>

            <p v-if="message" :class="['text-[11px] rounded-lg px-3 py-2', message.ok ? 'bg-trading-green/10 text-trading-green' : 'bg-trading-red/10 text-trading-red']">
                {{ message.text }}
            </p>

            <!-- รายการโอน -->
            <div v-if="transfers.length" class="space-y-1.5">
                <p class="text-[10px] uppercase tracking-wide text-dark-500">{{ t('aiTrade.botWallet.history') }}</p>
                <div v-for="tr in transfers" :key="tr.id" class="flex items-center gap-2 text-[11px] rounded-lg bg-white/5 px-3 py-2">
                    <span :class="['px-1.5 py-px rounded text-[10px] font-medium shrink-0', statusClass(tr.status)]">{{ t(`aiTrade.botWallet.status.${tr.status}`) }}</span>
                    <span class="font-mono text-white">{{ fmt(tr.amount, 6) }} {{ tr.asset }}</span>
                    <span class="text-dark-500 truncate flex-1" :title="tr.failure_reason || ''">
                        {{ tr.failure_reason || new Date(tr.created_at).toLocaleString() }}
                    </span>
                    <a v-if="tr.tx_url" :href="tr.tx_url" target="_blank" rel="noopener" class="text-primary-400 hover:underline shrink-0">tx</a>
                    <button
                        v-if="tr.cancellable"
                        type="button"
                        class="shrink-0 text-dark-400 hover:text-trading-red"
                        :disabled="bot.isWorking.value"
                        @click="run(() => bot.cancelBotWalletWithdraw(tr.id))"
                    >{{ t('aiTrade.botWallet.cancel') }}</button>
                </div>
            </div>
        </template>
    </section>
</template>
