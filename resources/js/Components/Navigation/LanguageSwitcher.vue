<script setup>
/**
 * TPIX TRADE — Language Switcher
 * เปลี่ยนภาษา TH/EN — แสดงเป็น flag + ชื่อ
 * Developed by Xman Studio
 */
import { ref, onMounted, onUnmounted } from 'vue';
import { useTranslation } from '@/Composables/useTranslation';

const { locale, setLocale, availableLocales } = useTranslation();
const isOpen = ref(false);

const currentFlag = () => availableLocales.find(l => l.code === locale.value)?.flag || '🇹🇭';

function select(code) {
    setLocale(code);
    isOpen.value = false;
    // ไม่ต้อง reload — reactive ทันที
}

function handleClickOutside(e) {
    if (!e.target.closest('.lang-switcher')) isOpen.value = false;
}
onMounted(() => document.addEventListener('click', handleClickOutside));
onUnmounted(() => document.removeEventListener('click', handleClickOutside));
</script>

<template>
    <div class="lang-switcher relative">
        <button @click="isOpen = !isOpen"
            class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-white/5 border border-white/10 hover:border-primary-500/30 transition-all text-sm">
            <span>{{ currentFlag() }}</span>
            <span class="text-dark-300 text-xs hidden sm:inline">{{ locale.toUpperCase() }}</span>
        </button>

        <div v-if="isOpen" class="absolute right-0 top-full mt-1 bg-dark-800 border border-white/10 rounded-xl shadow-xl z-50 py-1 min-w-[140px]">
            <button v-for="lang in availableLocales" :key="lang.code" @click="select(lang.code)"
                :class="['w-full flex items-center gap-2 px-3 py-2 text-sm hover:bg-white/5 transition-colors',
                    locale === lang.code ? 'text-primary-400' : 'text-dark-300']">
                <span>{{ lang.flag }}</span>
                <span>{{ lang.name }}</span>
                <span v-if="locale === lang.code" class="ml-auto text-primary-400">✓</span>
            </button>
        </div>
    </div>
</template>
