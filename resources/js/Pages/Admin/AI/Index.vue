<script setup>
/**
 * TPIX TRADE - AI Analysis Dashboard
 * Market analysis and AI-powered insights powered by Groq API
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { useForm, usePage, router } from '@inertiajs/vue3';

const props = defineProps({
    recentAnalyses: {
        type: Array,
        default: () => [],
    },
    recentNews: {
        type: Array,
        default: () => [],
    },
    stats: {
        type: Object,
        default: () => ({
            total_analyses: 0,
            success_rate: 100,
            avg_processing_time: 0,
            tokens_today: 0,
        }),
    },
    models: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();

const flash = computed(() => page.props.flash || {});

const analysisForm = useForm({
    symbol: 'BTC/USDT',
    type: 'technical',
    model: 'llama-3.3-70b-versatile',
});

const analysisResult = ref(null);
const showResult = ref(false);

const popularSymbols = [
    'BTC/USDT',
    'ETH/USDT',
    'BNB/USDT',
    'SOL/USDT',
    'XRP/USDT',
    'ADA/USDT',
    'DOGE/USDT',
    'AVAX/USDT',
];

const analysisTypes = [
    { value: 'technical', label: 'Technical Analysis', icon: 'chart' },
    { value: 'sentiment', label: 'Sentiment Analysis', icon: 'heart' },
    { value: 'price_prediction', label: 'Price Prediction', icon: 'trending' },
    { value: 'market_analysis', label: 'Market Analysis', icon: 'globe' },
];

const runAnalysis = () => {
    showResult.value = false;
    analysisResult.value = null;

    analysisForm.post(route('admin.ai.analyze'), {
        preserveScroll: true,
        onSuccess: (pageData) => {
            const latestAnalysis = props.recentAnalyses[0];
            if (latestAnalysis && latestAnalysis.status === 'completed') {
                analysisResult.value = latestAnalysis;
                showResult.value = true;
            }
        },
    });
};

const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('th-TH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const formatNumber = (num) => {
    if (num === null || num === undefined) return '0';
    return num.toLocaleString();
};

const getTypeLabel = (type) => {
    const found = analysisTypes.find((t) => t.value === type);
    return found ? found.label : type;
};

const getTypeColor = (type) => {
    const colors = {
        technical: 'bg-blue-500/10 text-blue-400 border-blue-500/20',
        sentiment: 'bg-pink-500/10 text-pink-400 border-pink-500/20',
        price_prediction: 'bg-amber-500/10 text-amber-400 border-amber-500/20',
        market_analysis: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
    };
    return colors[type] || 'bg-primary-500/10 text-primary-400 border-primary-500/20';
};

const getStatusColor = (status) => {
    const colors = {
        completed: 'bg-green-500/10 text-green-400',
        failed: 'bg-red-500/10 text-red-400',
        processing: 'bg-yellow-500/10 text-yellow-400',
        pending: 'bg-dark-500/10 text-dark-400',
    };
    return colors[status] || 'bg-dark-500/10 text-dark-400';
};

const selectedAnalysis = ref(null);

const viewAnalysis = (analysis) => {
    selectedAnalysis.value = analysis;
    showResult.value = true;
    analysisResult.value = analysis;
};
</script>

<template>
    <div class="min-h-screen bg-dark-950 p-6">
        <!-- Page Header -->
        <div class="mb-8">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                    </svg>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-white">AI Analysis</h1>
                    <p class="text-dark-400 text-sm">Powered by Groq LLM - Market analysis and insights</p>
                </div>
            </div>
        </div>

        <!-- Flash Messages -->
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="flash.success" class="mb-6 p-4 rounded-xl bg-green-500/10 border border-green-500/20 text-green-400 text-sm">
                {{ flash.success }}
            </div>
        </Transition>
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="flash.error" class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm">
                {{ flash.error }}
            </div>
        </Transition>

        <!-- Quick Stats -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6 hover:bg-white/[0.07] transition-all duration-300">
                <div class="flex items-start justify-between mb-4">
                    <div class="w-12 h-12 rounded-xl bg-violet-500/10 flex items-center justify-center">
                        <svg class="w-6 h-6 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                    </div>
                </div>
                <p class="text-2xl font-bold text-white mb-1">{{ formatNumber(stats.total_analyses) }}</p>
                <p class="text-sm text-dark-400">Total Analyses</p>
            </div>

            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6 hover:bg-white/[0.07] transition-all duration-300">
                <div class="flex items-start justify-between mb-4">
                    <div class="w-12 h-12 rounded-xl bg-green-500/10 flex items-center justify-center">
                        <svg class="w-6 h-6 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>
                <p class="text-2xl font-bold text-white mb-1">{{ stats.success_rate }}%</p>
                <p class="text-sm text-dark-400">Success Rate</p>
            </div>

            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6 hover:bg-white/[0.07] transition-all duration-300">
                <div class="flex items-start justify-between mb-4">
                    <div class="w-12 h-12 rounded-xl bg-amber-500/10 flex items-center justify-center">
                        <svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>
                <p class="text-2xl font-bold text-white mb-1">{{ formatNumber(stats.avg_processing_time) }}ms</p>
                <p class="text-sm text-dark-400">Avg Processing Time</p>
            </div>

            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6 hover:bg-white/[0.07] transition-all duration-300">
                <div class="flex items-start justify-between mb-4">
                    <div class="w-12 h-12 rounded-xl bg-cyan-500/10 flex items-center justify-center">
                        <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                </div>
                <p class="text-2xl font-bold text-white mb-1">{{ formatNumber(stats.tokens_today) }}</p>
                <p class="text-sm text-dark-400">Tokens Used Today</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Market Analysis Panel -->
            <div class="lg:col-span-2">
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
                    <!-- Analysis Form Header -->
                    <div class="px-6 py-4 border-b border-white/5">
                        <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                            <svg class="w-5 h-5 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                            </svg>
                            Market Analysis
                        </h2>
                    </div>

                    <div class="p-6">
                        <form @submit.prevent="runAnalysis" class="space-y-5">
                            <!-- Symbol Selection -->
                            <div>
                                <label class="block text-sm font-medium text-dark-300 mb-2">Symbol</label>
                                <div class="relative">
                                    <input
                                        v-model="analysisForm.symbol"
                                        type="text"
                                        placeholder="e.g. BTC/USDT"
                                        class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-400 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200"
                                    />
                                </div>
                                <!-- Quick Symbol Buttons -->
                                <div class="flex flex-wrap gap-2 mt-3">
                                    <button
                                        v-for="sym in popularSymbols"
                                        :key="sym"
                                        type="button"
                                        @click="analysisForm.symbol = sym"
                                        class="px-3 py-1.5 text-xs font-medium rounded-lg transition-all duration-150"
                                        :class="analysisForm.symbol === sym
                                            ? 'bg-primary-500/20 text-primary-400 border border-primary-500/30'
                                            : 'bg-dark-800/50 text-dark-400 border border-dark-600 hover:text-white hover:border-dark-500'"
                                    >
                                        {{ sym }}
                                    </button>
                                </div>
                            </div>

                            <!-- Analysis Type -->
                            <div>
                                <label class="block text-sm font-medium text-dark-300 mb-2">Analysis Type</label>
                                <div class="grid grid-cols-2 gap-3">
                                    <button
                                        v-for="aType in analysisTypes"
                                        :key="aType.value"
                                        type="button"
                                        @click="analysisForm.type = aType.value"
                                        class="flex items-center gap-3 p-3 rounded-xl border transition-all duration-200"
                                        :class="analysisForm.type === aType.value
                                            ? 'bg-primary-500/10 border-primary-500/30 text-white'
                                            : 'bg-dark-800/30 border-dark-600 text-dark-400 hover:text-white hover:border-dark-500'"
                                    >
                                        <!-- Chart Icon -->
                                        <svg v-if="aType.icon === 'chart'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" />
                                        </svg>
                                        <!-- Heart Icon -->
                                        <svg v-else-if="aType.icon === 'heart'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                                        </svg>
                                        <!-- Trending Icon -->
                                        <svg v-else-if="aType.icon === 'trending'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                        </svg>
                                        <!-- Globe Icon -->
                                        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <span class="text-sm font-medium">{{ aType.label }}</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Model Selection -->
                            <div>
                                <label class="block text-sm font-medium text-dark-300 mb-2">AI Model</label>
                                <select
                                    v-model="analysisForm.model"
                                    class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all duration-200"
                                >
                                    <option v-for="(label, key) in models" :key="key" :value="key">
                                        {{ label }}
                                    </option>
                                </select>
                            </div>

                            <!-- Submit Button -->
                            <button
                                type="submit"
                                :disabled="analysisForm.processing"
                                class="w-full inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl font-medium transition-all duration-200 bg-gradient-to-r from-violet-500 to-purple-600 text-white hover:from-violet-600 hover:to-purple-700 focus:outline-none focus:ring-2 focus:ring-violet-500 focus:ring-offset-2 focus:ring-offset-dark-900 disabled:opacity-50 disabled:cursor-not-allowed shadow-lg hover:shadow-violet-500/25"
                            >
                                <!-- Spinner -->
                                <svg v-if="analysisForm.processing" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                                </svg>
                                <span>{{ analysisForm.processing ? 'Analyzing...' : 'Run Analysis' }}</span>
                            </button>
                        </form>

                        <!-- Analysis Result -->
                        <Transition
                            enter-active-class="transition ease-out duration-300"
                            enter-from-class="opacity-0 translate-y-4"
                            enter-to-class="opacity-100 translate-y-0"
                            leave-active-class="transition ease-in duration-200"
                            leave-from-class="opacity-100"
                            leave-to-class="opacity-0"
                        >
                            <div v-if="showResult && analysisResult" class="mt-6">
                                <div class="bg-dark-800/50 border border-white/5 rounded-xl overflow-hidden">
                                    <div class="px-5 py-3 border-b border-white/5 flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <span class="text-sm font-medium text-white">{{ analysisResult.symbol }}</span>
                                            <span
                                                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border"
                                                :class="getTypeColor(analysisResult.type)"
                                            >
                                                {{ getTypeLabel(analysisResult.type) }}
                                            </span>
                                        </div>
                                        <div class="flex items-center gap-3 text-xs text-dark-400">
                                            <span>{{ analysisResult.tokens_used }} tokens</span>
                                            <span>{{ analysisResult.processing_time_ms }}ms</span>
                                        </div>
                                    </div>
                                    <div class="p-5">
                                        <div class="prose prose-invert prose-sm max-w-none text-dark-200 leading-relaxed whitespace-pre-wrap">{{ analysisResult.response }}</div>
                                    </div>
                                </div>
                            </div>
                        </Transition>
                    </div>
                </div>
            </div>

            <!-- Analysis History Sidebar -->
            <div class="lg:col-span-1">
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
                    <div class="px-6 py-4 border-b border-white/5">
                        <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                            <svg class="w-5 h-5 text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Recent Analyses
                        </h2>
                    </div>

                    <div class="divide-y divide-white/5">
                        <template v-if="recentAnalyses.length > 0">
                            <button
                                v-for="analysis in recentAnalyses"
                                :key="analysis.id"
                                @click="viewAnalysis(analysis)"
                                class="w-full px-6 py-4 text-left hover:bg-white/5 transition-colors"
                            >
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-sm font-medium text-white">{{ analysis.symbol || 'N/A' }}</span>
                                    <span
                                        class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                                        :class="getStatusColor(analysis.status)"
                                    >
                                        {{ analysis.status }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span
                                        class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border"
                                        :class="getTypeColor(analysis.type)"
                                    >
                                        {{ getTypeLabel(analysis.type) }}
                                    </span>
                                    <span class="text-xs text-dark-500">{{ formatDate(analysis.created_at) }}</span>
                                </div>
                                <div v-if="analysis.tokens_used" class="mt-2 flex items-center gap-3 text-xs text-dark-500">
                                    <span>{{ formatNumber(analysis.tokens_used) }} tokens</span>
                                    <span>{{ formatNumber(analysis.processing_time_ms) }}ms</span>
                                </div>
                            </button>
                        </template>

                        <div v-else class="px-6 py-12 text-center">
                            <svg class="w-12 h-12 text-dark-600 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                            </svg>
                            <p class="text-dark-400 text-sm">No analyses yet</p>
                            <p class="text-dark-500 text-xs mt-1">Run your first analysis to see results here</p>
                        </div>
                    </div>
                </div>

                <!-- Recent AI News -->
                <div class="mt-6 bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
                    <div class="px-6 py-4 border-b border-white/5 flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                            <svg class="w-5 h-5 text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z" />
                            </svg>
                            AI News
                        </h2>
                        <a
                            :href="route('admin.ai.news')"
                            class="text-xs text-primary-400 hover:text-primary-300 transition-colors"
                        >
                            View All
                        </a>
                    </div>

                    <div class="divide-y divide-white/5">
                        <template v-if="recentNews.length > 0">
                            <div
                                v-for="news in recentNews"
                                :key="news.id"
                                class="px-6 py-4"
                            >
                                <h3 class="text-sm font-medium text-white line-clamp-2 mb-1">{{ news.title }}</h3>
                                <div class="flex items-center gap-2 text-xs text-dark-500">
                                    <span class="capitalize">{{ news.category.replace('_', ' ') }}</span>
                                    <span>&middot;</span>
                                    <span>{{ formatDate(news.created_at) }}</span>
                                </div>
                            </div>
                        </template>

                        <div v-else class="px-6 py-8 text-center">
                            <p class="text-dark-400 text-sm">No AI news articles yet</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
