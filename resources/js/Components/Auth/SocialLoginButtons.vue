<script setup>
/**
 * TPIX TRADE - Social Login Buttons Component
 * Reusable for Login, Register, and Profile pages
 * Developed by Xman Studio
 */
defineProps({
    enabledProviders: {
        type: Array,
        default: () => [],
    },
    mode: {
        type: String,
        default: 'login', // login | register | link
    },
});

const providerConfig = {
    google: {
        name: 'Google',
        color: 'hover:bg-red-500/10 hover:border-red-500/30',
        icon: 'M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z',
    },
    facebook: {
        name: 'Facebook',
        color: 'hover:bg-blue-500/10 hover:border-blue-500/30',
        icon: 'M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z',
    },
    line: {
        name: 'LINE',
        color: 'hover:bg-green-500/10 hover:border-green-500/30',
        icon: 'M24 10.304c0-5.369-5.383-9.738-12-9.738-6.616 0-12 4.369-12 9.738 0 4.814 4.269 8.846 10.036 9.608.391.084.922.258 1.057.592.121.303.079.778.039 1.085l-.171 1.027c-.053.303-.242 1.186 1.039.647 1.281-.54 6.911-4.069 9.428-6.967C23.155 14.392 24 12.458 24 10.304z',
    },
};

const labelText = (mode) => {
    if (mode === 'link') return 'Connect';
    if (mode === 'register') return 'Sign up with';
    return 'Sign in with';
};
</script>

<template>
    <div v-if="enabledProviders.length > 0" class="space-y-4">
        <!-- Divider -->
        <div class="relative">
            <div class="absolute inset-0 flex items-center">
                <div class="w-full border-t border-white/10"></div>
            </div>
            <div class="relative flex justify-center text-sm">
                <span class="px-4 bg-transparent text-dark-400">or continue with</span>
            </div>
        </div>

        <!-- Social Buttons -->
        <div class="grid gap-3" :class="enabledProviders.length === 1 ? 'grid-cols-1' : enabledProviders.length === 2 ? 'grid-cols-2' : 'grid-cols-3'">
            <a
                v-for="provider in enabledProviders"
                :key="provider"
                :href="`/auth/${provider}`"
                class="flex items-center justify-center gap-2 px-4 py-3 rounded-xl border border-white/10 bg-white/5 text-dark-300 text-sm font-medium transition-all duration-200"
                :class="providerConfig[provider]?.color"
            >
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                    <path :d="providerConfig[provider]?.icon" />
                </svg>
                <span class="hidden sm:inline">{{ providerConfig[provider]?.name }}</span>
            </a>
        </div>
    </div>
</template>
