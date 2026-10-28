<script setup>
/**
 * TPIX TRADE — ตัวเล่นวิดีโอบทเรียน (หมวดเรียนรู้) + รายการบทที่กดข้ามไปได้
 *
 * คลิปเป็นแนวตั้ง 9:16 (อัดจากมือถือ) — จอใหญ่วางรายการบทไว้ข้าง ๆ จอมือถือวางไว้ใต้คลิป
 * preload="metadata" = ไม่ดึงทั้งไฟล์จนกว่าจะกดเล่น
 *
 * Developed by Xman Studio.
 */
import { ref, computed } from 'vue';

const props = defineProps({
    /** { video, poster, duration, chapters: [{ t, label }] } จาก LearnCatalog::player() */
    lesson: { type: Object, required: true },
    lang: { type: String, default: 'th' },
    showChapters: { type: Boolean, default: true },
});

const el = ref(null);
const current = ref(0);
const failed = ref(false);

const th = computed(() => props.lang === 'th');
const chapters = computed(() => props.lesson.chapters || []);

const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

function onTime() {
    const t = el.value?.currentTime ?? 0;
    let idx = 0;
    chapters.value.forEach((c, i) => { if (c.t <= t + 0.25) idx = i; });
    current.value = idx;
}

// ตั้ง currentTime ก่อน metadata มาถึงไม่มีผล — รอ loadedmetadata ก่อนเสมอ
function seek(t, i) {
    const v = el.value;
    if (!v || failed.value) return;
    current.value = i;
    const go = () => {
        v.currentTime = t;
        v.play().catch(() => {});
    };
    if (v.readyState >= 1) go();
    else v.addEventListener('loadedmetadata', go, { once: true });
}
</script>

<template>
    <div class="flex flex-col md:flex-row gap-6">
        <div class="w-full max-w-xs mx-auto md:mx-0 shrink-0">
            <div class="relative aspect-[9/16] rounded-2xl overflow-hidden bg-black ring-1 ring-white/10">
                <video ref="el" :src="lesson.video" :poster="lesson.poster" controls playsinline preload="metadata"
                    class="w-full h-full object-contain" @timeupdate="onTime" @error="failed = true"></video>
                <div v-if="failed" class="absolute inset-0 flex items-center justify-center p-6 bg-dark-900/90 text-center text-sm text-dark-300">
                    {{ th ? 'เปิดวิดีโอไม่ได้ตอนนี้ ลองใหม่อีกครั้งภายหลังนะคะ' : 'The video could not be loaded. Please try again later.' }}
                </div>
            </div>
            <p class="mt-2 text-center text-xs text-dark-500">
                {{ th ? 'บรรยายอังกฤษ · ซับไทย' : 'English narration · Thai subtitles' }} · {{ mmss(lesson.duration) }}
            </p>
        </div>

        <div v-if="showChapters && chapters.length" class="flex-1 min-w-0">
            <h3 class="text-white font-semibold mb-3">{{ th ? 'บทในคลิป (แตะเพื่อข้ามไป)' : 'Chapters (tap to jump)' }}</h3>
            <ol class="space-y-1">
                <li v-for="(c, i) in chapters" :key="c.t">
                    <button type="button" @click="seek(c.t, i)"
                        :class="['w-full flex items-center gap-3 px-3 py-2 rounded-lg text-left text-sm transition-colors',
                            current === i ? 'bg-primary-500/15 text-primary-300' : 'text-dark-300 hover:bg-white/5']">
                        <span class="font-mono text-xs text-dark-500 w-10 shrink-0">{{ mmss(c.t) }}</span>
                        <span class="min-w-0">{{ c.label }}</span>
                    </button>
                </li>
            </ol>
        </div>
    </div>
</template>
