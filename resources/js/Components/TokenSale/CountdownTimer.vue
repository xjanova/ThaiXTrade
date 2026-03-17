<script setup>
/**
 * CountdownTimer — นับถอยหลังเวลาสิ้นสุดรอบขาย
 * แสดง วัน:ชั่วโมง:นาที:วินาที แบบ real-time
 * Developed by Xman Studio
 */
import { ref, computed, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    /** วันสิ้นสุด (ISO 8601 string หรือ Date) */
    targetDate: { type: [String, Date], required: true },
    /** ข้อความเมื่อหมดเวลา */
    expiredText: { type: String, default: 'Sale Ended' },
});

const emit = defineEmits(['expired']);

// เวลาที่เหลือ (มิลลิวินาที)
const timeLeft = ref(0);
let intervalId = null;

// คำนวณ วัน, ชั่วโมง, นาที, วินาที
const days = computed(() => Math.floor(timeLeft.value / (1000 * 60 * 60 * 24)));
const hours = computed(() => Math.floor((timeLeft.value % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60)));
const minutes = computed(() => Math.floor((timeLeft.value % (1000 * 60 * 60)) / (1000 * 60)));
const seconds = computed(() => Math.floor((timeLeft.value % (1000 * 60)) / 1000));

// หมดเวลาแล้วหรือยัง
const isExpired = computed(() => timeLeft.value <= 0);

// format ตัวเลข 2 หลัก
function pad(n) {
    return String(n).padStart(2, '0');
}

function updateTimer() {
    const target = new Date(props.targetDate).getTime();
    const now = Date.now();
    timeLeft.value = Math.max(0, target - now);

    if (timeLeft.value <= 0 && intervalId) {
        clearInterval(intervalId);
        intervalId = null;
        emit('expired');
    }
}

onMounted(() => {
    updateTimer();
    intervalId = setInterval(updateTimer, 1000);
});

onUnmounted(() => {
    if (intervalId) clearInterval(intervalId);
});
</script>

<template>
    <div class="countdown-timer">
        <!-- หมดเวลาแล้ว -->
        <div v-if="isExpired" class="text-center">
            <span class="text-xl font-bold text-trading-red">{{ expiredText }}</span>
        </div>

        <!-- ยังไม่หมดเวลา — แสดง countdown -->
        <div v-else class="flex items-center justify-center gap-3">
            <!-- วัน -->
            <div class="time-block">
                <span class="time-value">{{ pad(days) }}</span>
                <span class="time-label">Days</span>
            </div>
            <span class="time-separator">:</span>

            <!-- ชั่วโมง -->
            <div class="time-block">
                <span class="time-value">{{ pad(hours) }}</span>
                <span class="time-label">Hours</span>
            </div>
            <span class="time-separator">:</span>

            <!-- นาที -->
            <div class="time-block">
                <span class="time-value">{{ pad(minutes) }}</span>
                <span class="time-label">Min</span>
            </div>
            <span class="time-separator">:</span>

            <!-- วินาที -->
            <div class="time-block">
                <span class="time-value">{{ pad(seconds) }}</span>
                <span class="time-label">Sec</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.time-block {
    @apply flex flex-col items-center;
}
.time-value {
    @apply text-3xl sm:text-4xl font-bold font-mono text-white
           bg-white/5 rounded-lg px-3 py-2 min-w-[60px] text-center
           border border-white/10;
}
.time-label {
    @apply text-xs text-gray-400 mt-1 uppercase tracking-wider;
}
.time-separator {
    @apply text-2xl sm:text-3xl font-bold text-primary-400 self-start mt-2;
}
</style>
