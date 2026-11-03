<script setup>
/**
 * FDPTokenPanel — แสดงข้อมูลเหรียญ FDP (FoodPassport Token)
 * Tokenomics, rewards, staking, use cases
 */
import { ref, onMounted } from 'vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

// ชื่อ use case ที่มีคำแปล — key อื่นจากเซิร์ฟเวอร์แสดงตามเดิม
const KNOWN_USE_CASES = ['reward', 'payment', 'staking', 'governance', 'trade'];
const useCaseLabel = (key) => (KNOWN_USE_CASES.includes(key) ? t(`ui.foodPassportFDPTokenPanel.useCase.${key}`) : key);

const tokenInfo = ref(null);
const loading = ref(true);

const tokenomicsColors = [
    '#22C55E', // Farmer Rewards
    '#3B82F6', // Ecosystem
    '#F59E0B', // Liquidity
    '#8B5CF6', // Team
    '#EC4899', // Community
    '#6B7280', // Reserve
];

onMounted(async () => {
    try {
        const res = await fetch('/api/v1/food-passport/fdp-token');
        const json = await res.json();
        if (json.success) tokenInfo.value = json.data;
    } catch (e) { console.error(e); }
    loading.value = false;
});
</script>

<template>
    <div v-if="loading" class="glass-card rounded-2xl p-12 text-center">
        <div class="spinner mx-auto"></div>
    </div>

    <div v-else-if="tokenInfo" class="space-y-6">
        <!-- Hero -->
        <div class="glass-card rounded-2xl p-6 bg-gradient-to-r from-amber-500/5 via-dark-900 to-green-500/5">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-amber-500/20 to-green-500/20 flex items-center justify-center">
                    <span class="text-4xl">🪙</span>
                </div>
                <div class="flex-1">
                    <h2 class="text-2xl font-bold text-white">{{ tokenInfo.name }}</h2>
                    <div class="flex items-center gap-3 mt-1">
                        <span class="px-2 py-0.5 rounded-lg bg-amber-500/10 text-amber-400 text-sm font-medium">{{ tokenInfo.symbol }}</span>
                        <span class="text-dark-400 text-sm">ERC-20 | {{ tokenInfo.chain }}</span>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-dark-400 text-xs">{{ t('ui.foodPassportFDPTokenPanel.totalSupply') }}</p>
                    <p class="text-white font-bold text-lg">{{ tokenInfo.total_supply }} FDP</p>
                </div>
            </div>
        </div>

        <!-- Rewards -->
        <div class="glass-card rounded-2xl p-6">
            <h3 class="text-lg font-bold text-white mb-4">{{ t('ui.foodPassportFDPTokenPanel.rewards.title') }}</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-5 rounded-xl bg-gradient-to-br from-green-500/10 to-emerald-500/10 border border-green-500/10">
                    <div class="flex items-center gap-3 mb-3">
                        <span class="text-3xl">🏆</span>
                        <div>
                            <p class="text-white font-semibold">{{ t('ui.foodPassportFDPTokenPanel.rewards.certTitle') }}</p>
                            <p class="text-dark-400 text-xs">{{ t('ui.foodPassportFDPTokenPanel.rewards.certDesc') }}</p>
                        </div>
                    </div>
                    <div class="p-3 rounded-lg bg-dark-800/50">
                        <p class="text-3xl font-bold text-trading-green">+{{ tokenInfo.rewards.per_certificate }} FDP</p>
                        <p class="text-dark-500 text-xs mt-1">{{ t('ui.foodPassportFDPTokenPanel.rewards.perCert') }}</p>
                    </div>
                </div>

                <div class="p-5 rounded-xl bg-gradient-to-br from-cyan-500/10 to-blue-500/10 border border-cyan-500/10">
                    <div class="flex items-center gap-3 mb-3">
                        <span class="text-3xl">📡</span>
                        <div>
                            <p class="text-white font-semibold">{{ t('ui.foodPassportFDPTokenPanel.rewards.traceTitle') }}</p>
                            <p class="text-dark-400 text-xs">{{ t('ui.foodPassportFDPTokenPanel.rewards.traceDesc') }}</p>
                        </div>
                    </div>
                    <div class="p-3 rounded-lg bg-dark-800/50">
                        <p class="text-3xl font-bold text-cyan-400">+{{ tokenInfo.rewards.per_trace }} FDP</p>
                        <p class="text-dark-500 text-xs mt-1">{{ t('ui.foodPassportFDPTokenPanel.rewards.perTrace') }}</p>
                    </div>
                </div>
            </div>
            <div class="mt-4 p-3 rounded-xl bg-amber-500/5 border border-amber-500/10">
                <p class="text-amber-300 text-sm">{{ t('ui.foodPassportFDPTokenPanel.rewards.budgetLabel') }} <strong>{{ tokenInfo.rewards.budget }}</strong> {{ t('ui.foodPassportFDPTokenPanel.rewards.budgetSuffix') }}</p>
            </div>
        </div>

        <!-- Use Cases -->
        <div class="glass-card rounded-2xl p-6">
            <h3 class="text-lg font-bold text-white mb-4">{{ t('ui.foodPassportFDPTokenPanel.useCases.title') }}</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div v-for="(desc, key) in tokenInfo.use_cases" :key="key"
                    class="p-4 rounded-xl bg-dark-800/50 border border-white/5">
                    <span class="text-2xl">{{ {reward:'🎁',payment:'💳',staking:'🔒',governance:'🗳️',trade:'💱'}[key] || '🪙' }}</span>
                    <h4 class="text-white font-semibold text-sm mt-2 capitalize">{{ useCaseLabel(key) }}</h4>
                    <p class="text-dark-400 text-xs mt-1">{{ desc }}</p>
                </div>
            </div>
        </div>

        <!-- Tokenomics -->
        <div class="glass-card rounded-2xl p-6">
            <h3 class="text-lg font-bold text-white mb-4">{{ t('ui.foodPassportFDPTokenPanel.tokenomics.title') }}</h3>
            <div class="space-y-3">
                <div v-for="(item, i) in tokenInfo.tokenomics" :key="i" class="flex items-center gap-4">
                    <div class="w-3 h-3 rounded-full flex-shrink-0" :style="{ background: tokenomicsColors[i] }"></div>
                    <div class="flex-1">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-white text-sm">{{ item.label }}</span>
                            <span class="text-dark-400 text-xs">{{ item.amount }} FDP ({{ item.percent }}%)</span>
                        </div>
                        <div class="h-2 rounded-full bg-dark-800 overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-700"
                                :style="{ width: item.percent + '%', background: tokenomicsColors[i] }"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- How FDP vs NFT -->
        <div class="glass-card rounded-2xl p-6">
            <h3 class="text-lg font-bold text-white mb-4">{{ t('ui.foodPassportFDPTokenPanel.compare.title') }}</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-4 rounded-xl bg-amber-500/5 border border-amber-500/10">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-2xl">🪙</span>
                        <h4 class="text-amber-400 font-semibold">FDP Token (ERC-20)</h4>
                    </div>
                    <ul class="space-y-1.5 text-xs text-dark-300">
                        <li>{{ t('ui.foodPassportFDPTokenPanel.compare.ft.item1') }}</li>
                        <li>{{ t('ui.foodPassportFDPTokenPanel.compare.ft.item2Pre') }} <strong class="text-white">{{ t('ui.foodPassportFDPTokenPanel.compare.ft.item2Strong') }}</strong></li>
                        <li>{{ t('ui.foodPassportFDPTokenPanel.compare.ft.item3') }}</li>
                        <li>{{ t('ui.foodPassportFDPTokenPanel.compare.ft.item4') }}</li>
                        <li>{{ t('ui.foodPassportFDPTokenPanel.compare.ft.item5') }}</li>
                    </ul>
                </div>
                <div class="p-4 rounded-xl bg-purple-500/5 border border-purple-500/10">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-2xl">🏆</span>
                        <h4 class="text-purple-400 font-semibold">NFT Certificate (ERC-721)</h4>
                    </div>
                    <ul class="space-y-1.5 text-xs text-dark-300">
                        <li>{{ t('ui.foodPassportFDPTokenPanel.compare.nft.item1') }}</li>
                        <li>{{ t('ui.foodPassportFDPTokenPanel.compare.nft.item2Pre') }} <strong class="text-white">{{ t('ui.foodPassportFDPTokenPanel.compare.nft.item2Strong') }}</strong> {{ t('ui.foodPassportFDPTokenPanel.compare.nft.item2Post') }}</li>
                        <li>{{ t('ui.foodPassportFDPTokenPanel.compare.nft.item3') }}</li>
                        <li>{{ t('ui.foodPassportFDPTokenPanel.compare.nft.item4') }}</li>
                        <li>{{ t('ui.foodPassportFDPTokenPanel.compare.nft.item5') }}</li>
                    </ul>
                </div>
            </div>
            <div class="mt-4 p-3 rounded-xl bg-dark-800/50 border border-white/5 text-center text-sm text-dark-300">
                {{ t('ui.foodPassportFDPTokenPanel.compare.combinedPre') }} <strong class="text-amber-400">NFT + 100 FDP</strong> {{ t('ui.foodPassportFDPTokenPanel.compare.combinedPost') }}
            </div>
        </div>

        <!-- Smart Contract Info -->
        <div class="glass-card rounded-2xl p-6">
            <h3 class="text-lg font-bold text-white mb-3">{{ t('ui.foodPassportFDPTokenPanel.contract.title') }}</h3>
            <div class="bg-dark-900 rounded-xl p-4 border border-white/5">
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-dark-400">{{ t('ui.foodPassportFDPTokenPanel.contract.contract') }}</span>
                        <span class="text-white font-mono text-xs">FoodPassportToken.sol</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-dark-400">{{ t('ui.foodPassportFDPTokenPanel.contract.standard') }}</span>
                        <span class="text-primary-400">{{ t('ui.foodPassportFDPTokenPanel.contract.standardValue') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-dark-400">{{ t('ui.foodPassportFDPTokenPanel.contract.chain') }}</span>
                        <span class="text-cyan-400">{{ t('ui.foodPassportFDPTokenPanel.contract.chainValue') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-dark-400">{{ t('ui.foodPassportFDPTokenPanel.contract.features') }}</span>
                        <span class="text-white">{{ t('ui.foodPassportFDPTokenPanel.contract.featuresValue') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
