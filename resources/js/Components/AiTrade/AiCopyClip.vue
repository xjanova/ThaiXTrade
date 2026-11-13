<script setup>
/**
 * TPIX TRADE — ไลฟ์/คลิปหนึ่งรายการของมาสเตอร์ Copy Trade
 *
 * วิดีโออยู่บนช่องของมาสเตอร์เอง เราแค่ลิงก์ไปหรือฝังเครื่องเล่นของผู้ให้บริการ:
 *  - ลิงก์/เครื่องเล่นถูกวาดเฉพาะเมื่อขึ้นต้นด้วยต้นทางที่รู้จัก (utils/copyMedia)
 *  - เครื่องเล่นไม่ถูกโหลดจนกว่าผู้ชมจะกดเล่นเอง (ไม่มีคำขอไปหาบุคคลที่สามตอนเปิดหน้า) และอยู่ใน sandbox
 *  - ชื่อเรื่องแสดงแบบ escape เสมอ (interpolation ธรรมดา ห้ามใช้ v-html กับข้อความจากผู้ใช้)
 *
 * Developed by Xman Studio
 */

import { computed, ref } from 'vue';
import { useTranslation } from '@/Composables/useTranslation';
import { providerName, safeEmbed, safeLink } from '@/utils/copyMedia';

const props = defineProps({
    /** { id, kind, provider, title, url, embed_url } จากเซิร์ฟเวอร์ */
    item: { type: Object, required: true },
    /** เซิร์ฟเวอร์อนุญาตให้ฝังเครื่องเล่นในหน้าไหม */
    embed: { type: Boolean, default: false },
    /** การ์ดใหญ่บนหน้าโปรไฟล์ (ปริยาย = แถวเล็กในแผง) */
    card: { type: Boolean, default: false },
});

const { t } = useTranslation();

const playing = ref(false);
const link = computed(() => safeLink(props.item.url));
const player = computed(() => (props.embed ? safeEmbed(props.item.embed_url) : null));
const kindLabel = computed(() => (props.item.kind === 'live' ? t('aiTrade.copy.mediaLive') : t('aiTrade.copy.mediaClip')));
</script>

<template>
    <!-- ลิงก์ไม่ผ่านการตรวจต้นทาง = ไม่วาดรายการนี้เลย -->
    <div v-if="link && card" class="rounded-xl border border-white/10 bg-white/5 overflow-hidden" :data-testid="`copy-media-item-${item.id}`">
        <div class="aspect-video bg-dark-900/70 flex items-center justify-center">
            <iframe
                v-if="player && playing"
                :src="player"
                :title="item.title"
                class="w-full h-full"
                sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"
                referrerpolicy="strict-origin-when-cross-origin"
                loading="lazy"
                allowfullscreen
                :data-testid="`copy-media-frame-${item.id}`"
            />
            <button
                v-else-if="player"
                type="button"
                class="btn-secondary px-4 py-2 text-xs"
                :data-testid="`copy-media-play-${item.id}`"
                @click="playing = true"
            >
                ▶ {{ t('aiTrade.copy.mediaPlay') }}
            </button>
            <span v-else class="text-[11px] text-dark-400">{{ providerName(item.provider) }}</span>
        </div>
        <div class="p-2.5 space-y-1">
            <p class="text-xs text-white leading-snug">{{ item.title }}</p>
            <a :href="link" target="_blank" rel="noopener noreferrer nofollow" class="text-[11px] text-primary-300 hover:text-primary-200 underline">
                {{ t('aiTrade.copy.mediaOpen', { provider: providerName(item.provider) }) }}
            </a>
        </div>
    </div>

    <div v-else-if="link" class="text-[11px]" :data-testid="`copy-media-item-${item.id}`">
        <span class="px-1 rounded bg-dark-700 text-dark-200 text-[9px]">{{ kindLabel }}</span>
        <span class="text-white ml-1">{{ item.title }}</span>
        <a :href="link" target="_blank" rel="noopener noreferrer nofollow" class="ml-2 text-primary-300 hover:text-primary-200 underline">
            {{ t('aiTrade.copy.mediaOpen', { provider: providerName(item.provider) }) }}
        </a>
        <button
            v-if="player && !playing"
            type="button"
            class="ml-2 text-primary-300 hover:text-primary-200"
            :data-testid="`copy-media-play-${item.id}`"
            @click="playing = true"
        >
            {{ t('aiTrade.copy.mediaPlay') }}
        </button>
        <iframe
            v-if="player && playing"
            :src="player"
            :title="item.title"
            class="mt-1.5 w-full aspect-video rounded-lg border border-white/10"
            sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"
            referrerpolicy="strict-origin-when-cross-origin"
            loading="lazy"
            allowfullscreen
            :data-testid="`copy-media-frame-${item.id}`"
        />
    </div>
</template>
