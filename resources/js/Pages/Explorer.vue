<script setup>
/**
 * TPIX TRADE - Block Explorer Page
 * หน้า Blockscout integration — แสดง link ไป explorer + stats
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

// URL ของ Blockscout explorer (ดึงจาก env หรือ hardcode)
const explorerUrl = ref(import.meta.env.VITE_TPIX_EXPLORER_URL || 'https://explorer.tpix.online');

// เก็บคีย์ไว้ แล้วแปลใน computed — สลับภาษาแล้วการ์ด/สถิติเปลี่ยนตามทันที
const features = computed(() => [
    { id: 'txs', icon: '⟐', link: '/txs' },
    { id: 'blocks', icon: '▣', link: '/blocks' },
    { id: 'tokens', icon: '◎', link: '/tokens' },
    { id: 'accounts', icon: '◈', link: '/accounts' },
    { id: 'contracts', icon: '⬡', link: '/verified-contracts' },
    { id: 'api', icon: '⟁', link: '/api-docs' },
].map((f) => ({
    ...f,
    title: t(`page.explorer.features.${f.id}.title`),
    description: t(`page.explorer.features.${f.id}.desc`),
})));

const chainStats = computed(() => [
    { id: 'chainId', label: t('explorer.chainId'), value: '4289' },
    { id: 'consensus', label: t('page.explorer.stats.consensus'), value: 'IBFT PoA' },
    { id: 'blockTime', label: t('explorer.blockTime'), value: t('page.explorer.stats.blockTimeValue') },
    { id: 'gasPrice', label: t('explorer.gasPrice'), value: t('page.explorer.stats.gasPriceValue') },
]);
</script>

<template>
    <Head title="TPIX Chain Explorer" />

    <AppLayout :hide-sidebar="true">
        <!-- Hero -->
        <section class="relative py-16 overflow-hidden">
            <div class="absolute inset-0 pointer-events-none">
                <div class="absolute top-1/3 left-1/3 w-80 h-80 rounded-full bg-primary-500/10 blur-[100px]" />
            </div>

            <div class="relative max-w-4xl mx-auto px-4 text-center">
                <img src="/images/logotpixexplorer.webp" alt="TPIX Explorer" class="h-28 sm:h-40 w-auto mx-auto mb-8 object-contain rounded-2xl drop-shadow-[0_0_32px_rgba(6,182,212,0.5)]" />
                <h1 class="text-4xl sm:text-5xl font-bold text-white mb-3">{{ t('explorer.title') }}</h1>
                <p class="text-lg text-gray-400 mb-8">
                    {{ t('page.explorer.subtitle') }}
                </p>

                <!-- Search -->
                <div class="max-w-xl mx-auto mb-8">
                    <form :action="explorerUrl + '/search'" method="GET" target="_blank" class="flex gap-2">
                        <input
                            name="q"
                            type="text"
                            :placeholder="t('page.explorer.searchPlaceholder')"
                            class="trading-input flex-1"
                        />
                        <button type="submit" class="btn-primary px-6 py-2.5 font-semibold">
                            {{ t('common.search') }}
                        </button>
                    </form>
                </div>

                <!-- Chain Stats -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 max-w-2xl mx-auto">
                    <div v-for="stat in chainStats" :key="stat.id" class="glass-dark p-3 rounded-lg border border-white/10">
                        <span class="text-xs text-gray-400">{{ stat.label }}</span>
                        <p class="text-lg font-bold text-white">{{ stat.value }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features Grid -->
        <section class="max-w-5xl mx-auto px-4 sm:px-6 py-12">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <a
                    v-for="feature in features"
                    :key="feature.id"
                    :href="explorerUrl + feature.link"
                    target="_blank"
                    rel="noopener"
                    class="glass-card p-6 rounded-xl border border-white/10 hover:border-primary-500/50 transition-all group"
                >
                    <span class="text-3xl mb-3 block">{{ feature.icon }}</span>
                    <h3 class="text-lg font-bold text-white mb-1 group-hover:text-primary-400 transition-colors">
                        {{ feature.title }}
                    </h3>
                    <p class="text-sm text-gray-400">{{ feature.description }}</p>
                </a>
            </div>
        </section>

        <!-- Open Explorer CTA -->
        <section class="max-w-4xl mx-auto px-4 sm:px-6 py-12 text-center">
            <a
                :href="explorerUrl"
                target="_blank"
                rel="noopener"
                class="btn-brand px-10 py-4 text-lg font-bold inline-flex items-center gap-2"
            >
                {{ t('page.explorer.openFull') }}
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                </svg>
            </a>
        </section>
    </AppLayout>
</template>
