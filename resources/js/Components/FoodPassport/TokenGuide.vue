<script setup>
/**
 * TokenGuide — สอนสร้างเหรียญ (Token) บน TPIX Chain ละเอียด
 * อธิบายว่าเหรียญถูกผลิตอย่างไร เก็บไว้ที่ไหน ใช้งานอย่างไร
 */
import { ref } from 'vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

const activeSection = ref('create');

// เก็บเฉพาะ id/icon — ข้อความเรียก t() ใน template เพื่อให้เปลี่ยนภาษาได้ทันที
const sections = [
    { id: 'create', icon: '🏭' },
    { id: 'where', icon: '📍' },
    { id: 'use', icon: '🔄' },
    { id: 'foodpassport', icon: '🌾' },
];

const steps = [
    { id: 's1', num: 1, icon: '🏭', color: 'from-blue-500/20 to-blue-600/20' },
    { id: 's2', num: 2, icon: '📝', color: 'from-green-500/20 to-green-600/20' },
    { id: 's3', num: 3, icon: '⚙️', color: 'from-purple-500/20 to-purple-600/20' },
    { id: 's4', num: 4, icon: '💰', color: 'from-amber-500/20 to-amber-600/20' },
    { id: 's5', num: 5, icon: '🚀', color: 'from-pink-500/20 to-pink-600/20' },
];

const useCases = [
    { id: 'transfer', icon: '💸' },
    { id: 'trade', icon: '💱' },
    { id: 'airdrop', icon: '🎁' },
    { id: 'governance', icon: '🗳️' },
    { id: 'staking', icon: '🔒' },
    { id: 'burn', icon: '🔥' },
];

const k = (key) => `ui.foodPassportTokenGuide.${key}`;
</script>

<template>
    <div class="space-y-6">
        <!-- Header -->
        <div class="glass-card rounded-2xl p-6 bg-gradient-to-r from-amber-500/5 to-purple-500/5">
            <div class="flex items-center gap-3">
                <span class="text-4xl">🪙</span>
                <div>
                    <h2 class="text-2xl font-bold text-white">{{ t(k('header.title')) }}</h2>
                    <p class="text-dark-400 text-sm">{{ t(k('header.subtitle')) }}</p>
                </div>
            </div>
        </div>

        <!-- Section Tabs -->
        <div class="flex gap-2 overflow-x-auto">
            <button v-for="sec in sections" :key="sec.id"
            @click="activeSection = sec.id"
            :class="[
                'flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm whitespace-nowrap transition-all',
                activeSection === sec.id ? 'bg-primary-500 text-white' : 'bg-dark-800 text-dark-400 hover:text-white'
            ]">
                <span>{{ sec.icon }}</span>
                <span>{{ t(k(`tabs.${sec.id}`)) }}</span>
            </button>
        </div>

        <!-- Section: วิธีสร้างเหรียญ -->
        <template v-if="activeSection === 'create'">
            <div class="glass-card rounded-2xl p-6">
                <h3 class="text-lg font-bold text-white mb-4">{{ t(k('create.title')) }}</h3>

                <div class="space-y-6">
                    <div v-for="step in steps" :key="step.id" :class="['rounded-2xl p-5 bg-gradient-to-br border border-white/5', step.color]">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center text-xl flex-shrink-0">
                                {{ step.icon }}
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-full bg-white/10 flex items-center justify-center text-xs font-bold text-white">{{ step.num }}</span>
                                    <h4 class="text-white font-semibold">{{ t(k(`create.steps.${step.id}.title`)) }}</h4>
                                </div>
                                <p class="text-dark-300 text-sm mt-1">{{ t(k(`create.steps.${step.id}.desc`)) }}</p>
                                <p class="text-dark-500 text-xs mt-2">{{ t(k(`create.steps.${step.id}.detail`)) }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- Section: เก็บไว้ที่ไหน -->
        <template v-if="activeSection === 'where'">
            <div class="glass-card rounded-2xl p-6">
                <h3 class="text-lg font-bold text-white mb-4">{{ t(k('where.title')) }}</h3>

                <div class="space-y-4">
                    <div class="p-5 rounded-xl bg-gradient-to-br from-cyan-500/10 to-blue-500/10 border border-cyan-500/10">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">⛓️</span>
                            <div>
                                <h4 class="text-white font-semibold">{{ t(k('where.contract.title')) }}</h4>
                                <p class="text-dark-300 text-sm mt-1">
                                    {{ t(k('where.contract.introBefore')) }} <strong class="text-cyan-400">{{ t(k('where.contract.introStrong')) }}</strong> {{ t(k('where.contract.introAfter')) }}
                                </p>
                                <p class="text-dark-500 text-xs mt-2">
                                    {{ t(k('where.contract.note1')) }}
                                    <br/>{{ t(k('where.contract.note2')) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="p-5 rounded-xl bg-gradient-to-br from-green-500/10 to-emerald-500/10 border border-green-500/10">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">👛</span>
                            <div>
                                <h4 class="text-white font-semibold">{{ t(k('where.wallet.title')) }}</h4>
                                <p class="text-dark-300 text-sm mt-1">
                                    {{ t(k('where.wallet.introBefore')) }} <strong class="text-green-400">{{ t(k('where.wallet.introStrong')) }}</strong> {{ t(k('where.wallet.introAfter')) }}
                                </p>
                                <p class="text-dark-500 text-xs mt-2">
                                    {{ t(k('where.wallet.note1')) }}
                                    <br/>{{ t(k('where.wallet.note2')) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="p-5 rounded-xl bg-gradient-to-br from-purple-500/10 to-violet-500/10 border border-purple-500/10">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">🔍</span>
                            <div>
                                <h4 class="text-white font-semibold">{{ t(k('where.explorer.title')) }}</h4>
                                <p class="text-dark-300 text-sm mt-1">
                                    {{ t(k('where.explorer.introBefore')) }} <strong class="text-purple-400">TPIX Chain Block Explorer</strong> {{ t(k('where.explorer.introAfter')) }}
                                </p>
                                <p class="text-dark-500 text-xs mt-2">
                                    {{ t(k('where.explorer.note1')) }}
                                    <br/>{{ t(k('where.explorer.note2')) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="p-5 rounded-xl bg-gradient-to-br from-amber-500/10 to-orange-500/10 border border-amber-500/10">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">💱</span>
                            <div>
                                <h4 class="text-white font-semibold">{{ t(k('where.dex.title')) }}</h4>
                                <p class="text-dark-300 text-sm mt-1">
                                    {{ t(k('where.dex.introBefore')) }} <strong class="text-amber-400">Liquidity Pool</strong> {{ t(k('where.dex.introAfter')) }}
                                </p>
                                <p class="text-dark-500 text-xs mt-2">
                                    {{ t(k('where.dex.note1')) }}
                                    <br/>{{ t(k('where.dex.note2')) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Visual Diagram -->
                <div class="mt-6 p-4 rounded-xl bg-dark-800/50 border border-white/5">
                    <p class="text-white font-medium text-sm mb-3 text-center">{{ t(k('where.flow.title')) }}</p>
                    <div class="flex items-center justify-center gap-2 text-sm flex-wrap">
                        <span class="px-3 py-1.5 rounded-lg bg-blue-500/10 text-blue-400">Token Factory</span>
                        <span class="text-dark-600">→</span>
                        <span class="px-3 py-1.5 rounded-lg bg-cyan-500/10 text-cyan-400">{{ t(k('where.flow.deploy')) }}</span>
                        <span class="text-dark-600">→</span>
                        <span class="px-3 py-1.5 rounded-lg bg-green-500/10 text-green-400">{{ t(k('where.flow.mint')) }}</span>
                        <span class="text-dark-600">→</span>
                        <span class="px-3 py-1.5 rounded-lg bg-amber-500/10 text-amber-400">{{ t(k('where.flow.trade')) }}</span>
                    </div>
                </div>
            </div>
        </template>

        <!-- Section: ใช้งานอย่างไร -->
        <template v-if="activeSection === 'use'">
            <div class="glass-card rounded-2xl p-6">
                <h3 class="text-lg font-bold text-white mb-4">{{ t(k('use.title')) }}</h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div v-for="useCase in useCases" :key="useCase.id"
                    class="p-4 rounded-xl bg-dark-800/50 border border-white/5">
                        <span class="text-2xl">{{ useCase.icon }}</span>
                        <h4 class="text-white font-semibold text-sm mt-2">{{ t(k(`use.cases.${useCase.id}.title`)) }}</h4>
                        <p class="text-dark-400 text-xs mt-1">{{ t(k(`use.cases.${useCase.id}.desc`)) }}</p>
                    </div>
                </div>
            </div>
        </template>

        <!-- Section: เหรียญ FoodPassport -->
        <template v-if="activeSection === 'foodpassport'">
            <div class="glass-card rounded-2xl p-6">
                <h3 class="text-lg font-bold text-white mb-4">{{ t(k('foodpassport.title')) }}</h3>

                <div class="space-y-4">
                    <div class="p-5 rounded-xl bg-gradient-to-br from-green-500/10 to-cyan-500/10 border border-green-500/10">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">🏆</span>
                            <div>
                                <h4 class="text-white font-semibold">NFT Certificate (ERC-721)</h4>
                                <p class="text-dark-300 text-sm mt-1">
                                    <strong class="text-green-400">{{ t(k('foodpassport.nft.introStrong')) }}</strong> {{ t(k('foodpassport.nft.introAfter')) }}
                                </p>
                                <p class="text-dark-500 text-xs mt-2">
                                    • {{ t(k('foodpassport.nft.item1')) }}
                                    <br/>• {{ t(k('foodpassport.nft.item2')) }}
                                    <br/>• {{ t(k('foodpassport.nft.item3')) }}
                                    <br/>• {{ t(k('foodpassport.nft.item4')) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="p-5 rounded-xl bg-gradient-to-br from-amber-500/10 to-orange-500/10 border border-amber-500/10">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">🪙</span>
                            <div>
                                <h4 class="text-white font-semibold">TPIX Token (Native Coin)</h4>
                                <p class="text-dark-300 text-sm mt-1">
                                    <strong class="text-amber-400">{{ t(k('foodpassport.tpix.introStrong')) }}</strong> {{ t(k('foodpassport.tpix.introAfter')) }}
                                </p>
                                <p class="text-dark-500 text-xs mt-2">
                                    • {{ t(k('foodpassport.tpix.item1')) }}
                                    <br/>• {{ t(k('foodpassport.tpix.item2')) }}
                                    <br/>• {{ t(k('foodpassport.tpix.item3')) }}
                                    <br/>• {{ t(k('foodpassport.tpix.item4')) }}
                                    <br/>• {{ t(k('foodpassport.tpix.item5')) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="p-5 rounded-xl bg-gradient-to-br from-purple-500/10 to-pink-500/10 border border-purple-500/10">
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">🌾</span>
                            <div>
                                <h4 class="text-white font-semibold">{{ t(k('foodpassport.custom.title')) }}</h4>
                                <p class="text-dark-300 text-sm mt-1">
                                    {{ t(k('foodpassport.custom.introBefore')) }} <strong class="text-purple-400">{{ t(k('foodpassport.custom.introStrong')) }}</strong> {{ t(k('foodpassport.custom.introAfter')) }}
                                </p>
                                <p class="text-dark-500 text-xs mt-2">
                                    {{ t(k('foodpassport.custom.example')) }}
                                    <br/>• {{ t(k('foodpassport.custom.item1')) }}
                                    <br/>• {{ t(k('foodpassport.custom.item2')) }}
                                    <br/>• {{ t(k('foodpassport.custom.item3')) }}
                                    <br/>• {{ t(k('foodpassport.custom.item4')) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CTA -->
                <div class="mt-6 p-4 rounded-xl bg-primary-500/10 border border-primary-500/20 text-center">
                    <p class="text-white font-medium text-sm mb-2">{{ t(k('cta.title')) }}</p>
                    <a href="/token-factory" class="inline-block px-6 py-2.5 rounded-xl bg-primary-500 text-white text-sm font-medium hover:bg-primary-600 transition-all">
                        {{ t(k('cta.button')) }}
                    </a>
                </div>
            </div>
        </template>
    </div>
</template>
