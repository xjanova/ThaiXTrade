<script setup>
/**
 * TPIX TRADE — โปรไฟล์ของผู้ใช้ที่ใช้เลขกระเป๋าเป็นไอดี
 *
 * มีหน้านี้เพราะเราซ่อนปุ่ม Sign In เมื่อเชื่อมกระเป๋าแล้ว — ถ้าไม่มี ผู้ใช้ที่ใช้
 * กระเป๋าอย่างเดียวจะไม่มีทางเข้าถึงโปรไฟล์ของตัวเองเลย
 *
 * ไม่ต้องมี session: ข้อมูลทุกอย่างผูกกับที่อยู่กระเป๋าซึ่งอ่านจาก walletStore
 *
 * Developed by Xman Studio
 */
import { ref, computed, onMounted, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageArt from '@/Components/PageArt.vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useAiBot } from '@/Composables/useAiBot';
import { useTranslation } from '@/Composables/useTranslation';

const walletStore = useWalletStore();
const bot = useAiBot();
const { t, locale } = useTranslation();

const copied = ref(false);

const isConnected = computed(() => walletStore.isConnected);
const address = computed(() => walletStore.address || '');
const plan = computed(() => bot.status.value?.subscription ?? null);

/** ผูกบัญชีอีเมลไว้แล้วหรือยัง (มาจาก Inertia shared props) */
const linkedAccount = computed(() => bot.status.value?.linked_account ?? null);

async function copyAddress() {
    if (!address.value) return;

    try {
        await navigator.clipboard.writeText(address.value);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1800);
    } catch { /* เบราว์เซอร์ไม่ให้เขียนคลิปบอร์ด — ผู้ใช้เลือกคัดลอกเองได้ */ }
}

function loadAll() {
    if (!walletStore.address) return;
    bot.loadStatus();
    bot.loadDemo();
}

onMounted(() => {
    bot.loadCatalog();
    loadAll();
});

watch(() => walletStore.address, loadAll);

function money(value) {
    return Number(value || 0).toLocaleString(locale.value === 'th' ? 'th-TH' : 'en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('walletProfile.title')" />

        <div class="max-w-3xl mx-auto px-4 py-6 space-y-4">
            <!-- หัวเรื่อง -->
            <section class="relative overflow-hidden rounded-3xl border border-white/10 bg-dark-900/50 p-6">
                <PageArt art="hero-aitrade" :opacity="14" fade="edges" rounded="rounded-3xl" />

                <div class="relative">
                    <h1 class="text-2xl font-black text-white">{{ t('walletProfile.title') }}</h1>
                    <p class="text-sm text-dark-400 mt-1">{{ t('walletProfile.subtitle') }}</p>
                </div>
            </section>

            <!-- ยังไม่ได้เชื่อมกระเป๋า -->
            <section v-if="!isConnected" class="glass-dark rounded-2xl p-8 text-center">
                <p class="text-sm text-dark-300 mb-4">{{ t('walletProfile.connectFirst') }}</p>
                <button type="button" class="btn-brand px-5 py-2 text-sm" @click="walletStore.openConnectModal()">
                    {{ t('wallet.connect') }}
                </button>
            </section>

            <template v-else>
                <!-- ที่อยู่กระเป๋า = ไอดีของคุณ -->
                <section class="glass-dark rounded-2xl p-5">
                    <p class="text-[11px] uppercase tracking-wide text-dark-500">{{ t('walletProfile.yourId') }}</p>

                    <div class="flex flex-wrap items-center gap-2 mt-1.5">
                        <p class="font-mono text-sm text-white break-all flex-1 min-w-0">{{ address }}</p>
                        <button
                            type="button"
                            class="px-2.5 py-1 rounded-lg text-[11px] font-medium bg-white/5 text-dark-300 hover:text-white transition-colors shrink-0"
                            @click="copyAddress"
                        >
                            {{ copied ? t('walletProfile.copied') : t('walletProfile.copy') }}
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-3 text-[11px] text-dark-400 font-mono">
                        <span>{{ t('walletProfile.chainId') }} {{ walletStore.chainId ?? '—' }}</span>
                        <a
                            v-if="walletStore.explorerAddressUrl"
                            :href="walletStore.explorerAddressUrl"
                            target="_blank"
                            rel="noopener"
                            class="text-primary-300 hover:text-primary-200"
                        >{{ t('nav.viewOnExplorer') }} ↗</a>
                    </div>

                    <p class="text-[11px] text-dark-500 leading-relaxed mt-3 border-t border-white/5 pt-3">
                        {{ t('walletProfile.idExplain') }}
                    </p>
                </section>

                <!-- ผูกบัญชีอีเมล (ไม่บังคับ) -->
                <section class="glass-dark rounded-2xl p-5">
                    <h2 class="text-sm font-bold text-white">{{ t('walletProfile.linkTitle') }}</h2>

                    <template v-if="linkedAccount">
                        <p class="text-[12px] text-dark-300 mt-1.5">
                            {{ t('walletProfile.linkedTo') }}
                            <span class="text-white font-medium">{{ linkedAccount }}</span>
                        </p>
                        <Link href="/profile" class="inline-block mt-3 text-[12px] text-primary-300 hover:text-primary-200">
                            {{ t('walletProfile.openAccount') }} →
                        </Link>
                    </template>

                    <template v-else>
                        <p class="text-[12px] text-dark-400 leading-relaxed mt-1.5">
                            {{ t('walletProfile.linkBody') }}
                        </p>
                        <Link href="/login" class="inline-block mt-3 btn-brand px-4 py-1.5 text-xs">
                            {{ t('nav.linkAccount') }}
                        </Link>
                    </template>
                </section>

                <!-- สถานะบอท AI TRADE -->
                <section class="glass-dark rounded-2xl p-5">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <h2 class="text-sm font-bold text-white">{{ t('walletProfile.botTitle') }}</h2>
                        <Link href="/ai-trade" class="text-[11px] text-primary-300 hover:text-primary-200">
                            {{ t('aiTrade.openBoard') }} →
                        </Link>
                    </div>

                    <div class="grid gap-3 grid-cols-2 lg:grid-cols-4 mt-3">
                        <div class="rounded-xl border border-white/10 bg-dark-950/40 p-3">
                            <p class="text-[10px] uppercase tracking-wide text-dark-500">{{ t('walletProfile.currentPlan') }}</p>
                            <p class="text-sm font-bold text-white mt-1">
                                {{ plan ? bot.planLabel(plan) : t('aiTrade.notRented') }}
                            </p>
                            <p v-if="plan" class="text-[10px] mt-0.5" :class="plan.execution === 'cloud' ? 'text-primary-300' : 'text-amber-300'">
                                {{ plan.execution === 'cloud' ? t('aiTrade.runsInCloud') : t('aiTrade.runsInBrowser') }}
                            </p>
                        </div>

                        <div class="rounded-xl border border-white/10 bg-dark-950/40 p-3">
                            <p class="text-[10px] uppercase tracking-wide text-dark-500">{{ t('aiTrade.credits') }}</p>
                            <p class="text-sm font-bold font-mono text-white mt-1">{{ bot.credits.value.toLocaleString() }}</p>
                        </div>

                        <div class="rounded-xl border border-white/10 bg-dark-950/40 p-3">
                            <p class="text-[10px] uppercase tracking-wide text-dark-500">{{ t('aiTrade.running') }}</p>
                            <p class="text-sm font-bold font-mono text-white mt-1">{{ bot.runningBots.value.length }}</p>
                        </div>

                        <div class="rounded-xl border border-white/10 bg-dark-950/40 p-3">
                            <p class="text-[10px] uppercase tracking-wide text-dark-500">{{ t('aiTrade.demoBalance') }}</p>
                            <p class="text-sm font-bold font-mono text-white mt-1">
                                ${{ money(bot.demo.value?.account?.balance) }}
                            </p>
                        </div>
                    </div>
                </section>

                <!-- ตัดการเชื่อมต่อ -->
                <section class="glass-dark rounded-2xl p-5 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-white">{{ t('nav.disconnect') }}</p>
                        <p class="text-[11px] text-dark-500 mt-0.5">{{ t('walletProfile.disconnectHint') }}</p>
                    </div>
                    <button
                        type="button"
                        class="px-4 py-1.5 rounded-lg text-xs font-medium bg-trading-red/10 text-trading-red hover:bg-trading-red/20 transition-colors"
                        @click="walletStore.disconnect()"
                    >
                        {{ t('nav.disconnect') }}
                    </button>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
