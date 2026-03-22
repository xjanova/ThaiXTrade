<script setup>
/**
 * TPIX TRADE - User Registration Page
 * Email + password registration with Turnstile support
 * Developed by Xman Studio
 */

import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import SocialLoginButtons from '@/Components/Auth/SocialLoginButtons.vue';

const props = defineProps({
    turnstileEnabled: {
        type: Boolean,
        default: false,
    },
    turnstileSiteKey: {
        type: String,
        default: '',
    },
    enabledProviders: {
        type: Array,
        default: () => [],
    },
});

const showPassword = ref(false);
const showPasswordConfirm = ref(false);

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    'cf-turnstile-response': '',
});

const turnstileReady = ref(false);
const turnstileLoadFailed = ref(false);
const turnstileError = ref('');
let turnstileWidgetId = null;

const turnstileRequired = computed(() => {
    return props.turnstileEnabled && props.turnstileSiteKey && !turnstileLoadFailed.value;
});

const loadTurnstile = () => {
    if (!props.turnstileEnabled || !props.turnstileSiteKey) {
        turnstileLoadFailed.value = true;
        return;
    }

    if (document.querySelector('script[src*="turnstile"]')) {
        if (window.turnstile) {
            renderTurnstile();
        } else {
            const prevOnLoad = window.onTurnstileLoad;
            window.onTurnstileLoad = () => {
                prevOnLoad?.();
                renderTurnstile();
            };
        }
        return;
    }

    const script = document.createElement('script');
    script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=onTurnstileLoad&render=explicit';
    script.async = true;

    window.onTurnstileLoad = () => {
        renderTurnstile();
    };

    script.onerror = () => {
        turnstileLoadFailed.value = true;
        turnstileError.value = 'Unable to load security verification — please refresh';
    };

    document.head.appendChild(script);
};

const renderTurnstile = () => {
    nextTick(() => {
        const container = document.getElementById('cf-turnstile-register');
        if (!container || !window.turnstile) return;

        if (turnstileWidgetId !== null) {
            try { window.turnstile.remove(turnstileWidgetId); } catch {}
        }

        turnstileError.value = '';

        turnstileWidgetId = window.turnstile.render(container, {
            sitekey: props.turnstileSiteKey,
            theme: 'dark',
            callback: (token) => {
                form['cf-turnstile-response'] = token;
                turnstileReady.value = true;
                turnstileError.value = '';
            },
            'expired-callback': () => {
                form['cf-turnstile-response'] = '';
                turnstileReady.value = false;
            },
            'error-callback': (errorCode) => {
                turnstileLoadFailed.value = true;
                turnstileError.value = `Verification error (${errorCode})`;
                form['cf-turnstile-response'] = '';
            },
        });
    });
};

const resetTurnstile = () => {
    if (!window.turnstile || turnstileWidgetId === null) return;
    try { window.turnstile.reset(turnstileWidgetId); } catch {}
    form['cf-turnstile-response'] = '';
    turnstileReady.value = false;
};

onMounted(() => {
    loadTurnstile();
});

onBeforeUnmount(() => {
    if (window.turnstile && turnstileWidgetId !== null) {
        try { window.turnstile.remove(turnstileWidgetId); } catch {}
        turnstileWidgetId = null;
    }
});

const submit = () => {
    form.post('/register', {
        onError: () => {
            form.reset('password', 'password_confirmation');
            resetTurnstile();
        },
    });
};
</script>

<template>
    <Head title="Register" />

    <div class="min-h-screen bg-dark-950 flex items-center justify-center p-4">
        <!-- Background Effects -->
        <div class="fixed inset-0 pointer-events-none">
            <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-accent-500/8 rounded-full blur-3xl"></div>
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[500px] h-[500px] bg-primary-500/10 rounded-full blur-3xl"></div>
            <div class="absolute bottom-1/4 right-1/4 w-96 h-96 bg-warm-500/5 rounded-full blur-3xl"></div>
        </div>

        <div class="relative w-full max-w-md">
            <!-- Logo -->
            <div class="text-center mb-8">
                <Link href="/">
                    <img src="/logo.png" alt="TPIX TRADE" class="w-20 h-20 rounded-2xl mx-auto mb-4 shadow-glow-brand object-cover" />
                </Link>
                <h1 class="text-2xl font-bold text-white">TPIX <span class="text-gradient">TRADE</span></h1>
                <p class="text-dark-400 text-sm mt-1">Create your trading account</p>
            </div>

            <!-- Register Card -->
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-8 shadow-glass">
                <h2 class="text-lg font-semibold text-white mb-6">Create Account</h2>

                <!-- Error Messages -->
                <div v-if="Object.keys(form.errors).length > 0" class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/20">
                    <div class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <div class="text-sm text-red-400 space-y-1">
                            <p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
                        </div>
                    </div>
                </div>

                <form @submit.prevent="submit" class="space-y-5">
                    <!-- Name -->
                    <div>
                        <label for="name" class="block text-sm font-medium text-dark-300 mb-2">Name</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <svg class="w-5 h-5 text-dark-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                            </div>
                            <input
                                id="name"
                                v-model="form.name"
                                type="text"
                                required
                                autofocus
                                autocomplete="name"
                                placeholder="Your name"
                                class="w-full bg-dark-800/50 border border-dark-600 rounded-xl pl-12 pr-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200"
                            />
                        </div>
                    </div>

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
                                autocomplete="email"
                                placeholder="you@example.com"
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
                                autocomplete="new-password"
                                placeholder="Min 8 chars, mixed case + numbers"
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

                    <!-- Confirm Password -->
                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-dark-300 mb-2">Confirm Password</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <svg class="w-5 h-5 text-dark-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                </svg>
                            </div>
                            <input
                                id="password_confirmation"
                                v-model="form.password_confirmation"
                                :type="showPasswordConfirm ? 'text' : 'password'"
                                required
                                autocomplete="new-password"
                                placeholder="Confirm your password"
                                class="w-full bg-dark-800/50 border border-dark-600 rounded-xl pl-12 pr-12 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200"
                            />
                            <button
                                type="button"
                                @click="showPasswordConfirm = !showPasswordConfirm"
                                class="absolute inset-y-0 right-0 pr-4 flex items-center text-dark-500 hover:text-dark-300 transition-colors"
                            >
                                <svg v-if="showPasswordConfirm" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                </svg>
                                <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Turnstile -->
                    <div v-if="turnstileEnabled" class="space-y-2">
                        <div id="cf-turnstile-register" class="flex justify-center">
                            <!-- Cloudflare Turnstile widget -->
                        </div>
                        <p v-if="turnstileError" class="text-center text-xs text-amber-400">{{ turnstileError }}</p>
                    </div>

                    <!-- Submit -->
                    <button
                        type="submit"
                        :disabled="form.processing || (turnstileRequired && !turnstileReady)"
                        class="w-full bg-primary-500 text-white py-3 rounded-xl font-medium hover:bg-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-dark-900 transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2 shadow-glow-sm hover:shadow-glow"
                    >
                        <svg v-if="form.processing" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span v-if="form.processing">Creating account...</span>
                        <span v-else>Create Account</span>
                    </button>
                </form>

                <!-- Social Login -->
                <SocialLoginButtons :enabledProviders="enabledProviders" mode="register" />

                <!-- Login Link -->
                <div class="mt-6 text-center">
                    <p class="text-dark-400 text-sm">
                        Already have an account?
                        <Link href="/login" class="text-primary-400 hover:text-primary-300 font-medium transition-colors">
                            Sign in
                        </Link>
                    </p>
                </div>
            </div>

            <!-- Footer -->
            <p class="text-center text-dark-500 text-xs mt-6">
                TPIX TRADE &middot; by Xman Studio
            </p>
        </div>
    </div>
</template>
