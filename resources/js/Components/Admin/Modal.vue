<script setup>
/**
 * TPIX TRADE - Admin Modal Component
 * Reusable modal dialog with transitions
 * Developed by Xman Studio
 */

import { watch, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: '',
    },
    maxWidth: {
        type: String,
        default: 'lg',
        validator: (value) => ['sm', 'md', 'lg', 'xl', '2xl'].includes(value),
    },
    closeable: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['close']);

const maxWidthClass = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-lg',
    xl: 'max-w-xl',
    '2xl': 'max-w-2xl',
};

const close = () => {
    if (props.closeable) {
        emit('close');
    }
};

const handleEscape = (e) => {
    if (e.key === 'Escape' && props.show) {
        close();
    }
};

onMounted(() => {
    document.addEventListener('keydown', handleEscape);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleEscape);
});

watch(() => props.show, (value) => {
    if (value) {
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = '';
    }
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <!-- Overlay -->
                <div
                    class="fixed inset-0 bg-dark-950/80 backdrop-blur-sm"
                    @click="close"
                ></div>

                <!-- Modal Content -->
                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0 scale-95 translate-y-4"
                    enter-to-class="opacity-100 scale-100 translate-y-0"
                    leave-active-class="transition ease-in duration-150"
                    leave-from-class="opacity-100 scale-100 translate-y-0"
                    leave-to-class="opacity-0 scale-95 translate-y-4"
                >
                    <div
                        v-if="show"
                        class="relative w-full bg-dark-900 border border-white/10 rounded-2xl shadow-glass-lg overflow-hidden"
                        :class="maxWidthClass[maxWidth]"
                    >
                        <!-- Header -->
                        <div v-if="title || $slots.header" class="flex items-center justify-between px-6 py-4 border-b border-white/5">
                            <slot name="header">
                                <h3 class="text-lg font-semibold text-white">{{ title }}</h3>
                            </slot>
                            <button
                                v-if="closeable"
                                @click="close"
                                class="p-1 rounded-lg text-dark-400 hover:text-white hover:bg-white/5 transition-colors"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <!-- Body -->
                        <div class="px-6 py-4 max-h-[70vh] overflow-y-auto">
                            <slot />
                        </div>

                        <!-- Footer -->
                        <div v-if="$slots.footer" class="px-6 py-4 border-t border-white/5 bg-dark-800/30">
                            <slot name="footer" />
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
