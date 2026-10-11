<script setup>
/**
 * TPIX TRADE - คลิปแนะนำเว็บ (น้อง TPIX พากย์อังกฤษ ซับไทย ~2 นาที)
 * ใช้ทั้งหน้าแรกแบบธรรมดา (HomeClassic) และหน้าแรก 3D (Home3D)
 *
 * โหลดไฟล์วิดีโอเมื่อกดเล่นเท่านั้น — ก่อนกดเป็นแค่ภาพปก (หน้าแรกแบบธรรมดามีไว้ให้เครื่องอ่อน
 * ห้ามดาวน์โหลดวิดีโอหลายสิบ MB ตอนเปิดหน้า)
 * ไฟล์วิดีโออยู่นอก git (public_html/videos/intro/ บนเซิร์ฟเวอร์ ดู .gitignore) — ไฟล์หาย/เน็ตหลุด = แสดงข้อความแทน ไม่ค้างจอดำ
 *
 * emit play / pause ให้หน้า 3D ปิดเสียงฉากระหว่างเล่นคลิป
 *
 * Developed by Xman Studio
 */
import { ref, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { useTranslation } from '@/Composables/useTranslation';

const props = defineProps({
    // ไฟล์ชื่อเดิมแก้เมื่อไหร่ต้องบวก ?v= (Cloudflare แคชไฟล์ใน public_html นาน)
    src: { type: String, default: '/videos/intro/tpix-intro-1080p.mp4?v=1' },
    poster: { type: String, default: '/images/home/intro-poster.webp?v=1' },
    // เล่นทันทีที่แสดง (หน้าต่างวิดีโอของหน้า 3D — ผู้ใช้เพิ่งกดปุ่มเปิดมา)
    autoplay: { type: Boolean, default: false },
});

const emit = defineEmits(['play', 'pause']);
const { t } = useTranslation();

const started = ref(false);
const failed = ref(false);
const videoEl = ref(null);

async function start() {
    const retry = failed.value;
    failed.value = false;
    started.value = true;
    await nextTick();
    // ลองใหม่หลังโหลดล้ม: ต้องสั่ง load() ก่อน ไม่งั้น play() เล่นจากสถานะ error เดิม
    if (retry) videoEl.value?.load();
    // บางเบราว์เซอร์ปฏิเสธ play() อัตโนมัติ — ผู้ใช้ยังกดปุ่มเล่นของตัว video เองได้
    videoEl.value?.play().catch(() => {});
}

onMounted(() => {
    if (props.autoplay) start();
});

function onError() {
    failed.value = true;
    emit('pause');
}

onBeforeUnmount(() => {
    // ออกจากหน้าระหว่างเล่น → หยุดเสียง และคืนสถานะให้หน้า 3D
    if (videoEl.value && !videoEl.value.paused) {
        videoEl.value.pause();
        emit('pause');
    }
});
</script>

<template>
    <div class="intro-video">
        <button
            v-if="!started"
            type="button"
            class="intro-video__poster group"
            :aria-label="t('introVideo.play')"
            @click="start"
        >
            <img :src="poster" alt="" loading="lazy" decoding="async" width="1920" height="1080" />
            <span class="intro-video__shade" aria-hidden="true"></span>
            <span class="intro-video__play" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.5v13a1 1 0 001.5.86l10.5-6.5a1 1 0 000-1.72L9.5 4.64A1 1 0 008 5.5z" /></svg>
            </span>
            <span class="intro-video__meta">{{ t('introVideo.meta') }}</span>
        </button>

        <video
            v-else
            ref="videoEl"
            :src="src"
            :poster="poster"
            controls
            playsinline
            preload="metadata"
            class="intro-video__player"
            @play="emit('play')"
            @pause="emit('pause')"
            @ended="emit('pause')"
            @error="onError"
        ></video>

        <div v-if="failed" class="intro-video__error" role="alert">
            <p>{{ t('introVideo.error') }}</p>
            <button type="button" class="intro-video__retry" @click="start">{{ t('introVideo.retry') }}</button>
        </div>
    </div>
</template>

<style scoped>
.intro-video {
    position: relative;
    aspect-ratio: 16 / 9;
    width: 100%;
    overflow: hidden;
    border-radius: 1.25rem;
    background: #050914;
    box-shadow: 0 0 0 1px rgb(34 211 238 / 0.22), 0 30px 80px -30px rgb(34 211 238 / 0.45);
}

.intro-video__poster {
    position: absolute;
    inset: 0;
    display: block;
    width: 100%;
    height: 100%;
    padding: 0;
    border: 0;
    cursor: pointer;
    background: none;
}

.intro-video__poster img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.7s cubic-bezier(0.4, 0, 0.2, 1);
}

.intro-video__poster:hover img {
    transform: scale(1.03);
}

.intro-video__poster:focus-visible {
    outline: 3px solid #22d3ee;
    outline-offset: -3px;
}

.intro-video__shade {
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at center, rgb(5 9 20 / 0.05) 30%, rgb(5 9 20 / 0.55) 100%);
}

.intro-video__play {
    position: absolute;
    left: 50%;
    top: 50%;
    display: grid;
    place-items: center;
    width: clamp(4rem, 9vw, 6rem);
    height: clamp(4rem, 9vw, 6rem);
    border-radius: 9999px;
    color: #fff;
    background: linear-gradient(135deg, #06b6d4, #3b82f6 55%, #8b5cf6);
    box-shadow: 0 0 0 10px rgb(255 255 255 / 0.12), 0 18px 50px -10px rgb(59 130 246 / 0.8);
    transform: translate(-50%, -50%);
    transition: transform 0.25s ease, box-shadow 0.25s ease;
}

.intro-video__play svg {
    width: 42%;
    height: 42%;
    margin-left: 8%;
}

.intro-video__poster:hover .intro-video__play {
    transform: translate(-50%, -50%) scale(1.08);
    box-shadow: 0 0 0 14px rgb(255 255 255 / 0.14), 0 22px 60px -10px rgb(139 92 246 / 0.85);
}

.intro-video__meta {
    position: absolute;
    right: 1rem;
    bottom: 1rem;
    padding: 0.35rem 0.8rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
    color: #e2e8f0;
    background: rgb(5 9 20 / 0.75);
    border: 1px solid rgb(255 255 255 / 0.14);
}

.intro-video__player {
    width: 100%;
    height: 100%;
    background: #000;
}

.intro-video__error {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    padding: 1.5rem;
    text-align: center;
    color: #e2e8f0;
    background: rgb(5 9 20 / 0.88);
}

.intro-video__retry {
    padding: 0.5rem 1.2rem;
    border-radius: 0.75rem;
    font-weight: 600;
    color: #fff;
    background: linear-gradient(135deg, #06b6d4, #3b82f6);
}

@media (prefers-reduced-motion: reduce) {
    .intro-video__poster img,
    .intro-video__play {
        transition: none;
    }
    .intro-video__poster:hover img {
        transform: none;
    }
}
</style>
