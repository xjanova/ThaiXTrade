<script setup>
/**
 * TPIX TRADE — ตัวเล่นวิดีโอบรรยายประจำหัวข้อไวท์เปเปอร์
 *
 * ทำไมต้อง lazy: หน้าไวท์เปเปอร์มี 19 หัวข้อ ถ้าใส่ <video> ทั้งหมดตั้งแต่แรก
 * เบราว์เซอร์จะไล่ดึง metadata ทุกไฟล์พร้อมกัน (รวมกว่า 40 นาที) หน้าจะอืดทันที
 * จึงขึ้นเป็นแถบก่อน แล้วค่อยสร้าง element ตอนกด
 *
 * `start` = วินาทีที่หัวข้อนี้เริ่มในไฟล์ตอนนั้น — หลายหัวข้ออยู่ในตอนเดียวกัน
 * จึงต้องกระโดดไปจุดที่ตรงกับเนื้อหาที่ผู้อ่านกำลังอ่านอยู่
 *
 * Developed by Xman Studio.
 */
import { ref, computed, nextTick } from 'vue';

const props = defineProps({
    /** ชื่อไฟล์ใน /videos/whitepaper/ เช่น 'ep06-ai-trade.mp4' */
    file: { type: String, required: true },
    /** ป้ายตอน เช่น 'Part Six — AI TRADE' */
    part: { type: String, required: true },
    /** วินาทีเริ่มของหัวข้อนี้ในไฟล์ */
    start: { type: Number, default: 0 },
    /** ความยาวทั้งตอน (วินาที) ไว้โชว์บนแถบ */
    duration: { type: Number, default: 0 },
    lang: { type: String, default: 'en' },
});

const open = ref(false);
const el = ref(null);

const mmss = (s) => {
    const m = Math.floor(s / 60);
    return `${m}:${String(Math.round(s % 60)).padStart(2, '0')}`;
};

const src = computed(() => `/videos/whitepaper/${props.file}#t=${props.start}`);
const label = computed(() =>
    props.lang === 'th' ? 'ดูวิดีโอบรรยายหัวข้อนี้' : 'Watch the narrated chapter');
const hint = computed(() =>
    props.lang === 'th' ? 'บรรยายอังกฤษ · ซับไทย' : 'English narration · Thai subtitles');

async function toggle() {
    open.value = !open.value;
    if (!open.value) return;
    await nextTick();
    const v = el.value;
    if (!v) return;

    // ตั้ง currentTime ก่อน metadata มาถึงจะไม่มีผล (และ #t= ก็ถูกมองข้ามได้)
    // จึงต้องรอ loadedmetadata ก่อนเสมอ ไม่งั้นวิดีโอจะเริ่มที่วินาที 0
    //
    // เซิร์ฟเวอร์ที่ไม่ตอบ 206 Partial Content (เช่น `php artisan serve` ตอนพัฒนา)
    // จะทำให้กระโดดไปจุดที่ยังไม่ถูกดาวน์โหลดไม่สำเร็จ แล้วเด้งกลับไปที่ 0
    // จึงลองซ้ำอีกไม่กี่ครั้งตามที่บัฟเฟอร์มาถึง — บน nginx ครั้งแรกก็ติดแล้ว
    let tries = 0;
    const seek = () => {
        if (props.start <= 0) { v.play().catch(() => {}); return; }
        if (Math.abs(v.currentTime - props.start) > 1.5) {
            v.currentTime = props.start;
            if (++tries < 6) setTimeout(seek, 700);
        }
        v.play().catch(() => {});
    };
    if (v.readyState >= 1) seek();
    else v.addEventListener('loadedmetadata', seek, { once: true });
}
</script>

<template>
    <div class="wp-video">
        <button type="button" class="wp-video-bar" @click="toggle">
            <span class="wp-video-play" :class="{ 'is-open': open }">
                <svg v-if="!open" viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                    <path d="M8 5v14l11-7z" />
                </svg>
                <svg v-else viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                    <path d="M6 6h4v12H6zm8 0h4v12h-4z" />
                </svg>
            </span>
            <span class="wp-video-text">
                <span class="wp-video-label">{{ label }}</span>
                <span class="wp-video-meta">{{ part }}<template v-if="duration"> · {{ mmss(duration) }}</template> · {{ hint }}</span>
            </span>
        </button>

        <div v-if="open" class="wp-video-frame">
            <video
                ref="el"
                :src="src"
                controls
                preload="metadata"
                playsinline
                class="w-full rounded-xl"
            ></video>
        </div>
    </div>
</template>

<style scoped>
.wp-video { margin: 0 0 1.75rem; }

.wp-video-bar {
    display: flex; align-items: center; gap: 0.9rem; width: 100%;
    padding: 0.85rem 1.1rem; text-align: left; cursor: pointer;
    border: 1px solid rgba(233, 174, 40, 0.28);
    background: linear-gradient(90deg, rgba(233, 174, 40, 0.09), rgba(34, 211, 238, 0.05));
    border-radius: 0.85rem;
    transition: border-color 0.2s, background 0.2s;
}
.wp-video-bar:hover { border-color: rgba(233, 174, 40, 0.55); }

.wp-video-play {
    display: flex; align-items: center; justify-content: center; flex: none;
    width: 2.1rem; height: 2.1rem; border-radius: 999px;
    background: rgba(233, 174, 40, 0.18); color: #F8D678;
    border: 1px solid rgba(233, 174, 40, 0.45);
}
.wp-video-play.is-open { background: rgba(255, 255, 255, 0.08); color: #A6B6CC;
                         border-color: rgba(255, 255, 255, 0.18); }

.wp-video-text { display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }
.wp-video-label { font-size: 0.95rem; font-weight: 600; color: #EDF2F9; }
.wp-video-meta { font-size: 0.78rem; color: #8A9AB0; }

.wp-video-frame { margin-top: 0.9rem; }
.wp-video-frame video {
    width: 100%; display: block; background: #04060C;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

/* พิมพ์ PDF ไม่ต้องมีตัวเล่นวิดีโอ */
@media print { .wp-video { display: none !important; } }
</style>
