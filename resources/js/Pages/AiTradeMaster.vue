<script setup>
/**
 * TPIX TRADE — หน้าแนะนำตัวมาสเตอร์ Copy Trade (/ai-trade/master/{id})
 *
 * หน้าสาธารณะ เปิดได้โดยไม่ต้องเชื่อมกระเป๋า: ใครคือมาสเตอร์คนนี้ ผลเงินจริงเป็นอย่างไร
 * คลิปแนะนำตัว (ไม่เกิน 3 คลิป จากช่องของมาสเตอร์เอง) ค่าบริการ และรีวิวจากผู้ตามจริง
 *
 * ถ้อยคำบนจอต้องไม่ทำให้เข้าใจผิดเรื่องเงิน:
 *  - ผลงานติดป้ายว่าเป็นเงินจริงจากไม้ที่ปิดแล้ว และบอกว่าผลในอดีตไม่รับประกันผลในอนาคต
 *  - คลิปเป็นของมาสเตอร์ ไม่ใช่เนื้อหาหรือคำแนะนำการลงทุนของ TPIX TRADE
 *  - ป้าย "แนะนำ" บอกว่ามาจากส่วนแบ่งที่ให้แพลตฟอร์ม ไม่ใช่อันดับผลงาน
 * การกดตามทำที่แผง Copy Trade ในหน้า AI TRADE (ต้องตั้งกรอบความเสี่ยงและผ่านด่านของเซิร์ฟเวอร์ที่นั่น)
 *
 * ข้อความจากผู้ใช้ทั้งหมด (ชื่อ · แนะนำตัว · ชื่อคลิป · รีวิว) แสดงแบบ escape — ไม่มี v-html ในหน้านี้
 *
 * Developed by Xman Studio
 */

import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AiCopyClip from '@/Components/AiTrade/AiCopyClip.vue';
import { useTranslation } from '@/Composables/useTranslation';

const props = defineProps({
    masterId: { type: Number, required: true },
});

const { t, locale } = useTranslation();

const state = ref('loading');     // loading | ready | missing | error
const settings = ref({});
const master = ref(null);
const reviews = ref([]);
const reviewsState = ref('idle');  // idle (no reviews to load) | loading | ready | error

const fees = computed(() => settings.value.fees ?? {});
const record = computed(() => master.value?.record ?? {});
const clips = computed(() => master.value?.featured ?? []);
const rankName = computed(() => {
    const rank = master.value?.rank;
    if (!rank) return t('aiTrade.copy.rankNone');

    return (locale.value === 'th' ? rank.name_th : rank.name_en) || rank.key;
});
const ratingText = computed(() => {
    const rating = master.value?.rating;
    if (!rating?.count) return t('aiTrade.copy.ratingNone');

    return t('aiTrade.copy.ratingText', { avg: Number(rating.avg).toFixed(1), count: rating.count });
});
/** ลิงก์ไปแผง Copy Trade พร้อมบอกว่ามาจากมาสเตอร์คนไหน — แผงเลื่อนไปที่การ์ดของคนนี้ให้ */
const followHref = computed(() => `/ai-trade?master=${props.masterId}#copy-trade`);

function signed(value, digits = 2) {
    const number = Number(value);
    if (!Number.isFinite(number)) return '—';

    return `${number > 0 ? '+' : ''}${number.toFixed(digits)}`;
}

async function load() {
    state.value = 'loading';

    try {
        const { data } = await axios.get(`/api/v1/ai-bot/copy/masters/${props.masterId}`);
        const { master: profile, ...rest } = data?.data ?? {};

        if (!profile) {
            state.value = 'missing';
            return;
        }

        settings.value = rest;
        master.value = profile;
        state.value = 'ready';
    } catch (err) {
        // ปิดระบบ · โปรไฟล์ถูกปิด · ไม่มีรหัสนี้ = "ไม่พบ" · อย่างอื่น (เน็ตหลุด/เซิร์ฟเวอร์ล่ม) = ให้ลองใหม่
        state.value = err?.response?.status === 404 ? 'missing' : 'error';
        return;
    }

    reviews.value = [];
    reviewsState.value = 'idle';

    if (master.value.rating?.count) {
        reviewsState.value = 'loading';

        try {
            const { data } = await axios.get(`/api/v1/ai-bot/copy/masters/${props.masterId}/reviews`);
            reviews.value = data?.data?.reviews ?? [];
            reviewsState.value = 'ready';
        } catch {
            // รีวิวโหลดไม่ได้ไม่ควรทำให้ทั้งหน้าพัง — โชว์ส่วนที่เหลือต่อ
            reviews.value = [];
            reviewsState.value = 'error';
        }
    }
}

onMounted(load);
</script>

<template>
    <Head :title="master ? `${master.display_name} — Copy Trade` : t('aiTrade.copy.profileTitle')" />

    <AppLayout>
        <div class="max-w-4xl mx-auto space-y-4" data-testid="master-profile">
            <Link href="/ai-trade" class="text-[11px] text-primary-400 hover:text-primary-300">{{ t('aiTrade.copy.profileBack') }}</Link>

            <p v-if="state === 'loading'" class="glass-dark rounded-2xl p-6 text-sm text-dark-400" data-testid="master-loading">{{ t('aiTrade.copy.profileLoading') }}</p>

            <div v-else-if="state === 'missing'" class="glass-dark rounded-2xl p-6" data-testid="master-missing">
                <p class="text-sm text-white">{{ t('aiTrade.copy.profileNotFound') }}</p>
            </div>

            <div v-else-if="state === 'error'" class="glass-dark rounded-2xl p-6" data-testid="master-error">
                <p class="text-sm text-trading-red">{{ t('aiTrade.copy.loadFailed') }}</p>
                <button type="button" class="btn-secondary px-3 py-1.5 text-xs mt-2" @click="load">{{ t('aiTrade.copy.retry') }}</button>
            </div>

            <template v-else>
                <!-- หัวโปรไฟล์ -->
                <section class="glass-dark rounded-2xl p-5 space-y-3" data-testid="master-header">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <h1 class="text-xl font-bold text-white">{{ master.display_name }}</h1>
                        <span
                            :class="['text-[11px] px-2 py-0.5 rounded font-bold', master.rank ? 'bg-primary-500/15 text-primary-200' : 'bg-dark-700 text-dark-400']"
                            :title="t('aiTrade.copy.rankNote')"
                            data-testid="master-rank"
                        >
                            {{ rankName }}
                        </span>
                        <span class="text-[11px] text-amber-200/90" data-testid="master-rating">★ {{ ratingText }}</span>
                        <span v-if="master.promoted" class="text-[10px] px-1.5 rounded bg-amber-500/15 text-amber-300" :title="t('aiTrade.copy.profilePromotedNote')" data-testid="master-promoted">
                            {{ t('aiTrade.copy.promotedBadge') }}
                        </span>
                        <span class="text-[11px] text-dark-500 font-mono ml-auto">{{ master.wallet_short }} · {{ master.pair }} · {{ master.timeframe }}</span>
                    </div>
                    <p v-if="master.bio" class="text-sm text-dark-300 leading-relaxed">{{ master.bio }}</p>
                    <p v-if="master.promoted" class="text-[10px] text-dark-500 leading-relaxed" data-testid="master-promoted-note">{{ t('aiTrade.copy.profilePromotedNote') }}</p>
                    <p v-if="!master.meets_floor" class="text-[11px] text-amber-300" data-testid="master-below-floor">{{ t('aiTrade.copy.belowFloor') }}</p>
                </section>

                <!-- ผลงานเงินจริง -->
                <section class="glass-dark rounded-2xl p-5 space-y-2" data-testid="master-record">
                    <h2 class="text-sm font-bold text-white">{{ t('aiTrade.copy.profileStats') }}</h2>
                    <p class="text-[11px] text-amber-200/90 leading-relaxed">{{ t('aiTrade.copy.realOnly') }}</p>
                    <dl class="grid grid-cols-2 sm:grid-cols-6 gap-3 text-sm">
                        <div><dt class="text-[10px] text-dark-400">{{ t('aiTrade.copy.colReturn') }}</dt><dd :class="['font-mono', Number(record.return_pct) < 0 ? 'text-trading-red' : 'text-trading-green']">{{ signed(record.return_pct) }}%</dd></div>
                        <div><dt class="text-[10px] text-dark-400">{{ t('aiTrade.copy.colTrades') }}</dt><dd class="font-mono text-white">{{ record.closed ?? 0 }}</dd></div>
                        <div><dt class="text-[10px] text-dark-400">{{ t('aiTrade.copy.colWin') }}</dt><dd class="font-mono text-white">{{ record.win_rate ?? '—' }}%</dd></div>
                        <div><dt class="text-[10px] text-dark-400">{{ t('aiTrade.copy.colDrawdown') }}</dt><dd class="font-mono text-white">-{{ Number(record.max_drawdown_pct ?? 0).toFixed(2) }}%</dd></div>
                        <div><dt class="text-[10px] text-dark-400">{{ t('aiTrade.copy.colDays') }}</dt><dd class="font-mono text-white">{{ t('aiTrade.copy.days', { n: Math.floor(record.days ?? 0) }) }}</dd></div>
                        <div><dt class="text-[10px] text-dark-400">{{ t('aiTrade.copy.colFollowers') }}</dt><dd class="font-mono text-white">{{ master.followers }}</dd></div>
                    </dl>
                    <p class="text-[10px] text-dark-500 leading-relaxed">{{ t('aiTrade.copy.profilePast') }}</p>
                </section>

                <!-- แนะนำตัว -->
                <section class="glass-dark rounded-2xl p-5 space-y-2" data-testid="master-intro">
                    <h2 class="text-sm font-bold text-white">{{ t('aiTrade.copy.profileIntro') }}</h2>
                    <p v-if="master.intro" class="text-sm text-dark-200 leading-relaxed whitespace-pre-line break-words">{{ master.intro }}</p>
                    <p v-else class="text-xs text-dark-400">{{ t('aiTrade.copy.profileNoIntro') }}</p>
                </section>

                <!-- คลิปแนะนำ — ไม่เกิน 3 คลิป จากช่องของมาสเตอร์เอง -->
                <section class="glass-dark rounded-2xl p-5 space-y-2" data-testid="master-clips">
                    <h2 class="text-sm font-bold text-white">{{ t('aiTrade.copy.profileClips') }}</h2>
                    <p v-if="!clips.length" class="text-xs text-dark-400" data-testid="master-no-clips">{{ t('aiTrade.copy.profileNoClips') }}</p>
                    <template v-else>
                        <p class="text-[10px] text-dark-500 leading-relaxed">{{ t('aiTrade.copy.mediaExternalNote') }}</p>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <AiCopyClip v-for="clip in clips" :key="clip.id" :item="clip" :embed="master.embed === true" card />
                        </div>
                    </template>
                </section>

                <!-- ค่าบริการ + ปุ่มไปกดตาม -->
                <section class="glass-dark rounded-2xl p-5 space-y-2" data-testid="master-fee">
                    <h2 class="text-sm font-bold text-white">{{ t('aiTrade.copy.profileFee') }}</h2>
                    <p class="text-xs text-dark-200 leading-relaxed">{{ t('aiTrade.copy.followTerms', { rate: master.fee_rate_pct, currency: fees.currency ?? 'TPIX' }) }}</p>
                    <p v-if="fees.collect" class="text-[11px] text-amber-200/90 leading-relaxed" data-testid="master-fee-prepaid">
                        {{ t('aiTrade.copy.profileFeePrepaid', { min: fees.min_balance_tpix ?? 0 }) }}
                    </p>
                    <p class="text-[11px] text-amber-300 leading-relaxed">{{ t('aiTrade.copy.followRealMoney') }}</p>

                    <p v-if="!master.accepting" class="text-xs text-dark-400" data-testid="master-not-accepting">{{ t('aiTrade.copy.notAccepting') }}</p>
                    <template v-else>
                        <p v-if="!settings.live_ready" class="text-[11px] text-amber-200 leading-relaxed" data-testid="master-needs-live">{{ t('aiTrade.copy.needsLive') }}</p>
                        <Link :href="followHref" class="btn-brand inline-block px-4 py-2 text-xs" data-testid="master-follow-link">{{ t('aiTrade.copy.profileFollow') }}</Link>
                        <p class="text-[10px] text-dark-500">{{ t('aiTrade.copy.profileFollowNote') }}</p>
                    </template>
                </section>

                <!-- รีวิวจากผู้ตามจริง -->
                <section class="glass-dark rounded-2xl p-5 space-y-2" data-testid="master-reviews">
                    <h2 class="text-sm font-bold text-white">{{ t('aiTrade.copy.reviewsFrom') }}</h2>
                    <!-- ระหว่างโหลดห้ามบอกว่า "ยังไม่มีรีวิว" — มาสเตอร์ที่มีรีวิวจะดูเหมือนไม่มีไปชั่วขณะ -->
                    <p v-if="reviewsState === 'loading'" class="text-xs text-dark-400" data-testid="master-reviews-loading">{{ t('aiTrade.copy.profileLoading') }}</p>
                    <p v-else-if="reviewsState === 'error'" class="text-xs text-dark-400" data-testid="master-reviews-error">{{ t('aiTrade.copy.profileReviewsFailed') }}</p>
                    <p v-else-if="!reviews.length" class="text-xs text-dark-400" data-testid="master-no-reviews">{{ t('aiTrade.copy.noReviews') }}</p>
                    <ul v-else class="space-y-1.5">
                        <li v-for="r in reviews" :key="r.id" class="text-xs leading-relaxed" :data-testid="`master-review-${r.id}`">
                            <span class="text-amber-200">{{ '★'.repeat(r.stars) }}</span>
                            <span class="text-dark-500 font-mono ml-1">{{ r.reviewer }}</span>
                            <span v-if="r.comment" class="text-dark-200 ml-1">{{ r.comment }}</span>
                        </li>
                    </ul>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
