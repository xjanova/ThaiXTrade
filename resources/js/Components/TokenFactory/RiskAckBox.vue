<script setup>
/**
 * TPIX TRADE — กล่องเตือนความเสี่ยงสีแดง + ช่องติ๊กยอมรับ (บังคับ)
 *
 * ใช้กับทุกประเภท/ตัวเลือกที่ให้อำนาจเจ้าของเหรียญเหนือผู้ถือ (mint เพิ่ม, หยุดการโอน, บล็อกกระเป๋า ...)
 * ข้อความเตือนมาจากนโยบายฝั่งเซิร์ฟเวอร์ และเซิร์ฟเวอร์ปฏิเสธคำขอที่ไม่ได้ติ๊กยอมรับเสมอ
 */
defineProps({
    ackKey: { type: String, required: true },
    title: { type: String, required: true },
    warning: { type: String, default: '' },
    modelValue: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div
        class="mt-3 rounded-xl border border-red-500/50 bg-red-500/10 p-3 text-left"
        role="alert"
        :data-test="`risk-warning-${ackKey}`"
    >
        <p class="text-sm font-semibold text-red-400 flex items-center gap-2">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            คำเตือน: {{ title }}
        </p>
        <p class="mt-1 text-xs leading-relaxed text-red-300">{{ warning }}</p>
        <label class="mt-2 flex items-start gap-2 text-xs text-red-200 cursor-pointer select-none">
            <input
                type="checkbox"
                class="mt-0.5 rounded border-red-500/60 bg-transparent text-red-500 focus:ring-red-500"
                required
                :checked="modelValue"
                :data-test="`risk-ack-${ackKey}`"
                @change="$emit('update:modelValue', $event.target.checked)"
            />
            <span>ข้าพเจ้าเข้าใจว่าเจ้าของเหรียญมีอำนาจตามที่เตือนข้างต้น และยอมรับความเสี่ยงนี้</span>
        </label>
    </div>
</template>
