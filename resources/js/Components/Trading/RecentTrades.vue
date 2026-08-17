<script setup>
/**
 * TPIX TRADE - Recent Trades Component
 * รายการเทรดล่าสุดแบบเรียลไทม์ — คลิกแถวเพื่อส่งราคาเข้าฟอร์มเทรด
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { useTranslation } from '@/Composables/useTranslation';

const props = defineProps({
    symbol: { type: String, default: 'BTC/USDT' },
    trades: { type: Array, default: () => [] },
    isLoading: { type: Boolean, default: false },
});

const emit = defineEmits(['select-price']);

const { t } = useTranslation();

const filter = ref('all'); // all | buy | sell

const quoteSymbol = computed(() => props.symbol.split('/')[1] || 'USDT');

const filtered = computed(() => {
    if (filter.value === 'buy') return props.trades.filter(t => t.isBuy);
    if (filter.value === 'sell') return props.trades.filter(t => !t.isBuy);
    return props.trades;
});

const formatPrice = (price) => {
    if (price >= 1000) return price.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (price >= 1) return price.toFixed(2);
    if (price >= 0.01) return price.toFixed(4);
    return price.toFixed(8);
};

const formatAmount = (amount) => (amount >= 1 ? amount.toFixed(4) : amount.toFixed(6));

function pick(trade) {
    emit('select-price', {
        price: trade.price,
        amount: trade.amount,
        side: trade.isBuy ? 'buy' : 'sell',
    });
}
</script>

<template>
    <div class="flex flex-col h-full min-h-0 p-3 pt-2">
        <!-- ตัวกรองฝั่ง -->
        <div class="flex items-center gap-1 mb-2">
            <button
                v-for="f in [{ id: 'all', key: 'trade.recent.all' }, { id: 'buy', key: 'trade.recent.buy' }, { id: 'sell', key: 'trade.recent.sell' }]"
                :key="f.id"
                type="button"
                :class="['px-2 py-0.5 rounded text-[10px] font-medium transition-colors',
                    filter === f.id ? 'bg-primary-500/20 text-primary-300' : 'text-dark-500 hover:text-white']"
                @click="filter = f.id"
            >
                {{ t(f.key) }}
            </button>
            <span class="ml-auto w-2 h-2 rounded-full bg-trading-green animate-pulse" :title="t('trade.recent.live')" :aria-label="t('trade.recent.live')"></span>
        </div>

        <!-- หัวคอลัมน์ -->
        <div class="grid grid-cols-3 gap-1 text-[10px] text-dark-400 mb-1 px-0.5 flex-shrink-0">
            <span>{{ t('trade.book.priceCol', { quote: quoteSymbol }) }}</span>
            <span class="text-right">{{ t('trade.form.amount') }}</span>
            <span class="text-right">{{ t('trade.recent.time') }}</span>
        </div>

        <!-- กำลังโหลด -->
        <div v-if="isLoading && !trades.length" class="flex-1 flex items-center justify-center">
            <div class="text-dark-400 text-sm animate-pulse">{{ t('trade.recent.loading') }}</div>
        </div>

        <!-- รายการ -->
        <div v-else class="flex-1 min-h-0 overflow-y-auto custom-scrollbar">
            <button
                v-for="trade in filtered"
                :key="trade.id"
                type="button"
                class="w-full grid grid-cols-3 gap-1 text-[11px] py-[3px] px-0.5 hover:bg-white/10 rounded transition-colors text-left"
                :title="t('trade.book.fillHint', { price: formatPrice(trade.price) })"
                @click="pick(trade)"
            >
                <span :class="['font-mono', trade.isBuy ? 'text-trading-green' : 'text-trading-red']">
                    {{ formatPrice(trade.price) }}
                </span>
                <span class="font-mono text-right text-dark-200">{{ formatAmount(trade.amount) }}</span>
                <span class="font-mono text-right text-dark-400">{{ trade.time }}</span>
            </button>

            <p v-if="!filtered.length" class="text-dark-500 text-xs text-center py-8">
                {{ t('trade.recent.empty') }}
            </p>
        </div>
    </div>
</template>
