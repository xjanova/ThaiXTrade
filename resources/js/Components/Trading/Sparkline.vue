<script setup>
/**
 * TPIX TRADE — Sparkline
 * เส้นกราฟเล็กแสดง trend ของคู่เทรดในรายการ (เหมือน MiniSparkline ในแอพมือถือ)
 * เป็น presentational ล้วน — ไม่ยิง API เอง ให้ useSparklines เป็นคนดึงข้อมูลมาให้
 * Developed by Xman Studio.
 */
import { computed, useId } from 'vue';

const props = defineProps({
    /** ราคาปิดเรียงจากเก่า → ใหม่ */
    points: { type: Array, default: () => [] },
    width: { type: Number, default: 72 },
    height: { type: Number, default: 28 },
    /** ทิศทาง 24 ชม. — ใช้เลือกสีเส้น (เขียว/แดง) */
    isUp: { type: Boolean, default: true },
    /** แสดง skeleton จางๆ ระหว่างรอข้อมูล */
    loading: { type: Boolean, default: false },
});

// gradient id ต้องไม่ชนกันเมื่อมี sparkline หลายอันในหน้าเดียว
const uid = useId();
const gradientId = computed(() => `spark-${uid}`);

const color = computed(() => (props.isUp ? '#00C853' : '#FF1744'));

const series = computed(() =>
    props.points.filter(p => Number.isFinite(p) && p > 0)
);

const hasData = computed(() => series.value.length >= 2);

/** แปลงราคาเป็นพิกัดใน viewBox (padding บน/ล่าง 2px กันเส้นโดนตัด) */
const coords = computed(() => {
    const values = series.value;
    if (values.length < 2) return [];

    const min = Math.min(...values);
    const max = Math.max(...values);
    const range = max - min;
    const usable = props.height - 4;

    return values.map((v, i) => {
        const x = (i / (values.length - 1)) * props.width;
        // ราคานิ่งทั้งช่วง (range = 0) → วาดเส้นตรงกลาง ไม่ใช่หาร 0
        const y = range === 0
            ? props.height / 2
            : props.height - ((v - min) / range) * usable - 2;
        return `${x.toFixed(2)},${y.toFixed(2)}`;
    });
});

const linePath = computed(() => coords.value.join(' '));

/** ปิด path ลงขอบล่างเพื่อเติมไล่สีใต้เส้น */
const areaPath = computed(() => {
    if (!hasData.value) return '';
    return `${props.width},${props.height} ${linePath.value} 0,${props.height}`;
});

/** จุดเรืองแสงที่ปลายเส้น */
const lastPoint = computed(() => {
    if (!hasData.value) return null;
    const [x, y] = coords.value[coords.value.length - 1].split(',');
    return { x: parseFloat(x), y: parseFloat(y) };
});
</script>

<template>
    <div :style="{ width: `${width}px`, height: `${height}px` }" class="shrink-0">
        <!-- Skeleton ระหว่างโหลด -->
        <div v-if="loading && !hasData" class="w-full h-full flex items-center">
            <div class="w-full h-[2px] rounded-full bg-white/10 animate-pulse"></div>
        </div>

        <!-- ไม่มีข้อมูล (เช่น คู่ที่ยังไม่มีตลาด) → ขีดจางๆ แทนพื้นที่ว่าง -->
        <div v-else-if="!hasData" class="w-full h-full flex items-center">
            <div class="w-full h-px bg-white/5"></div>
        </div>

        <svg
            v-else
            :viewBox="`0 0 ${width} ${height}`"
            :width="width"
            :height="height"
            preserveAspectRatio="none"
            class="overflow-visible"
            aria-hidden="true"
        >
            <defs>
                <linearGradient :id="gradientId" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" :stop-color="color" stop-opacity="0.28" />
                    <stop offset="100%" :stop-color="color" stop-opacity="0" />
                </linearGradient>
            </defs>

            <polygon :points="areaPath" :fill="`url(#${gradientId})`" />
            <polyline
                :points="linePath"
                fill="none"
                :stroke="color"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                vector-effect="non-scaling-stroke"
            />
            <circle
                v-if="lastPoint"
                :cx="lastPoint.x"
                :cy="lastPoint.y"
                r="1.8"
                :fill="color"
            />
        </svg>
    </div>
</template>
