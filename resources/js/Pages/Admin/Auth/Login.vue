<script setup>
/**
 * TPIX TRADE - Admin Login Page
 * Clean authentication form with Turnstile support
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    turnstileEnabled: {
        type: Boolean,
        default: false,
    },
    turnstileSiteKey: {
        type: String,
        default: '',
    },
    rateLimitWarning: {
        type: String,
        default: null,
    },
});

const showPassword = ref(false);

const form = useForm({
    email: '',
    password: '',
    remember: false,
    cf_turnstile_response: '',
});

const submit = () => {
    form.post('/admin/login', {
        onFinish: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <Head title="Admin Login" />

    <div class="min-h-screen bg-dark-950 flex items-center justify-center p-4">
        <!-- Background Effects (brand gradient glow) -->
        <div class="fixed inset-0 pointer-events-none">
            <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-accent-500/8 rounded-full blur-3xl"></div>
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[500px] h-[500px] bg-primary-500/10 rounded-full blur-3xl"></div>
            <div class="absolute bottom-1/4 right-1/4 w-96 h-96 bg-warm-500/5 rounded-full blur-3xl"></div>
        </div>

        <div class="relative w-full max-w-md">
            <!-- Logo -->
            <div class="text-center mb-8">
                <img src="/logo.png" alt="TPIX TRADE" class="w-20 h-20 rounded-2xl mx-auto mb-4 shadow-glow-brand object-cover" />
                <h1 class="text-2xl font-bold text-white">TPIX <span class="text-gradient">TRADE</span></h1>
                <p class="text-dark-400 text-sm mt-1">Admin Panel</p>
            </div>

            <!-- Login Card -->
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-8 shadow-glass">
                <h2 class="text-lg font-semibold text-white mb-6">Sign in to your account</h2>

                <!-- Rate Limit Warning -->
                <div v-if="rateLimitWarning" class="mb-6 p-4 rounded-xl bg-yellow-500/10 border border-yellow-500/20">
                    <div class="flex items-center gap-3">
                        <svg class="w-5 h-5 text-yellow-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <p class="text-yellow-400 text-sm">{{ rateLimitWarning }}</p>
                    </div>
                </div>

                <!-- General Error -->
                <div v-if="form.errors.email || form.errors.password" class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/20">
                    <div class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <div class="text-sm text-red-400">
                            <p v-if="form.errors.email">{{ form.errors.email }}</p>
                            <p v-if="form.errors.password">{{ form.errors.password }}</p>
                        </div>
                    </div>
                </div>

                <form @submit.prevent="submit" class="space-y-5">
                    <!-- Email -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-dark-300 mb-2">Email Address</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <svg class="w-5 h-5 text-dark-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                                </svg>
                            </div>
                            <input
                                id="email"
                                v-model="form.email"
                                type="email"
                                required
                                autofocus
                                autocomplete="email"
                                placeholder="admin@tpixtrade.com"
                                class="w-full bg-dark-800/50 border border-dark-600 rounded-xl pl-12 pr-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200"
                            />
                        </div>
                    </div>

                    <!-- Password -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-dark-300 mb-2">Password</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <svg class="w-5 h-5 text-dark-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                            </div>
                            <input
                                id="password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                required
                                autocomplete="current-password"
                                placeholder="Enter your password"
                                class="w-full bg-dark-800/50 border border-dark-600 rounded-xl pl-12 pr-12 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200"
                            />
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="absolute inset-y-0 right-0 pr-4 flex items-center text-dark-500 hover:text-dark-300 transition-colors"
                            >
                                <svg v-if="showPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                </svg>
                                <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Remember Me -->
                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                v-model="form.remember"
                                type="checkbox"
                                class="w-4 h-4 rounded border-dark-600 bg-dark-800 text-primary-500 focus:ring-primary-500 focus:ring-offset-dark-900"
                            />
                            <span class="text-sm text-dark-400">Remember me</span>
                        </label>
                    </div>

                    <!-- Turnstile -->
                    <div v-if="turnstileEnabled" id="cf-turnstile" class="flex justify-center">
                        <!-- Cloudflare Turnstile widget will be rendered here -->
                    </div>

                    <!-- Submit -->
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full bg-primary-500 text-white py-3 rounded-xl font-medium hover:bg-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-dark-900 transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2 shadow-glow-sm hover:shadow-glow"
                    >
                        <svg v-if="form.processing" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span v-if="form.processing">Signing in...</span>
                        <span v-else>Sign In</span>
                    </button>
                </form>
            </div>

            <!-- Footer -->
            <p class="text-center text-dark-500 text-xs mt-6">
                TPIX TRADE Admin &middot; by Xman Studio
            </p>
        </div>
    </div>
</template>
