<script setup>
/**
 * TPIX TRADE - Admin Layout
 * Professional sidebar layout with navigation for admin panel
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';

defineProps({
    title: String,
});

const page = usePage();
const sidebarOpen = ref(true);
const mobileSidebarOpen = ref(false);

const admin = computed(() => page.props.auth?.admin || page.props.auth?.user);
const currentUrl = computed(() => page.url);

const isActive = (path) => {
    if (path === '/admin') {
        return currentUrl.value === '/admin' || currentUrl.value === '/admin/';
    }
    return currentUrl.value.startsWith(path);
};

const isExactActive = (path) => {
    return currentUrl.value === path;
};

const navigationSections = [
    {
        title: null,
        items: [
            { name: 'Dashboard', href: '/admin', icon: 'dashboard' },
        ],
    },
    {
        title: 'Trading',
        items: [
            { name: 'Chains', href: '/admin/chains', icon: 'chain' },
            { name: 'Tokens', href: '/admin/tokens', icon: 'token' },
            { name: 'Trading Pairs', href: '/admin/trading-pairs', icon: 'pair' },
        ],
    },
    {
        title: 'Finance',
        items: [
            { name: 'Fees', href: '/admin/fees', icon: 'fee' },
            { name: 'Transactions', href: '/admin/transactions', icon: 'transaction' },
            { name: 'Swap', href: '/admin/swap', icon: 'swap' },
        ],
    },
    {
        title: 'Content',
        items: [
            { name: 'Settings', href: '/admin/settings', icon: 'settings' },
            { name: 'Languages', href: '/admin/languages', icon: 'language' },
        ],
    },
    {
        title: 'Support',
        items: [
            { name: 'Tickets', href: '/admin/support', icon: 'ticket' },
            { name: 'Audit Logs', href: '/admin/audit-logs', icon: 'audit' },
        ],
    },
];

const toggleSidebar = () => {
    sidebarOpen.value = !sidebarOpen.value;
};

const toggleMobileSidebar = () => {
    mobileSidebarOpen.value = !mobileSidebarOpen.value;
};

const handleLogout = () => {
    router.post('/admin/logout');
};
</script>

<template>
    <div class="min-h-screen bg-dark-950">
        <!-- Mobile Sidebar Overlay -->
        <Transition
            enter-active-class="transition ease-out duration-300"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="mobileSidebarOpen"
                class="fixed inset-0 bg-dark-950/80 backdrop-blur-sm z-40 lg:hidden"
                @click="toggleMobileSidebar"
            ></div>
        </Transition>

        <!-- Sidebar -->
        <aside
            class="fixed top-0 left-0 z-50 h-screen bg-dark-950 border-r border-white/5 transition-all duration-300 flex flex-col"
            :class="[
                sidebarOpen ? 'w-[260px]' : 'w-[72px]',
                mobileSidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'
            ]"
        >
            <!-- Logo -->
            <div class="flex items-center gap-3 px-5 py-5 border-b border-white/5">
                <img src="/logo.png" alt="TPIX TRADE" class="w-10 h-10 rounded-xl flex-shrink-0 object-cover" />
                <div v-if="sidebarOpen" class="overflow-hidden">
                    <p class="font-bold text-white text-sm whitespace-nowrap">TPIX <span class="text-gradient">TRADE</span></p>
                    <p class="text-xs text-dark-400 whitespace-nowrap">Admin Panel</p>
                </div>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-6">
                <div v-for="(section, sIdx) in navigationSections" :key="sIdx">
                    <p v-if="section.title && sidebarOpen" class="px-3 mb-2 text-xs font-semibold text-dark-500 uppercase tracking-wider">
                        {{ section.title }}
                    </p>
                    <div v-else-if="section.title && !sidebarOpen" class="border-t border-white/5 mb-3 mx-2"></div>

                    <div class="space-y-1">
                        <Link
                            v-for="item in section.items"
                            :key="item.href"
                            :href="item.href"
                            class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200"
                            :class="[
                                isActive(item.href)
                                    ? 'text-primary-400 bg-primary-500/10'
                                    : 'text-dark-400 hover:text-white hover:bg-white/5'
                            ]"
                            :title="!sidebarOpen ? item.name : ''"
                        >
                            <!-- Dashboard -->
                            <svg v-if="item.icon === 'dashboard'" class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z" />
                            </svg>
                            <!-- Chain -->
                            <svg v-else-if="item.icon === 'chain'" class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                            </svg>
                            <!-- Token -->
                            <svg v-else-if="item.icon === 'token'" class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <!-- Pair -->
                            <svg v-else-if="item.icon === 'pair'" class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                            </svg>
                            <!-- Fee -->
                            <svg v-else-if="item.icon === 'fee'" class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                            <!-- Transaction -->
                            <svg v-else-if="item.icon === 'transaction'" class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                            </svg>
                            <!-- Swap -->
                            <svg v-else-if="item.icon === 'swap'" class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                            <!-- Settings -->
                            <svg v-else-if="item.icon === 'settings'" class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <!-- Language -->
                            <svg v-else-if="item.icon === 'language'" class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129" />
                            </svg>
                            <!-- Ticket -->
                            <svg v-else-if="item.icon === 'ticket'" class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" />
                            </svg>
                            <!-- Audit -->
                            <svg v-else-if="item.icon === 'audit'" class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                            </svg>

                            <span v-if="sidebarOpen" class="whitespace-nowrap">{{ item.name }}</span>
                        </Link>
                    </div>
                </div>
            </nav>

            <!-- Sidebar Toggle -->
            <div class="hidden lg:block px-3 py-3 border-t border-white/5">
                <button
                    @click="toggleSidebar"
                    class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-xl text-dark-400 hover:text-white hover:bg-white/5 transition-colors"
                >
                    <svg class="w-5 h-5 transition-transform" :class="{ 'rotate-180': !sidebarOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
                    </svg>
                    <span v-if="sidebarOpen" class="text-sm">Collapse</span>
                </button>
            </div>
        </aside>

        <!-- Main Area -->
        <div class="transition-all duration-300" :class="sidebarOpen ? 'lg:pl-[260px]' : 'lg:pl-[72px]'">
            <!-- Top Bar -->
            <header class="sticky top-0 z-30 bg-dark-900/80 backdrop-blur-xl border-b border-white/5">
                <div class="flex items-center justify-between px-4 lg:px-6 py-3">
                    <!-- Mobile Menu Button -->
                    <button
                        @click="toggleMobileSidebar"
                        class="lg:hidden p-2 rounded-xl text-dark-400 hover:text-white hover:bg-white/5 transition-colors"
                    >
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>

                    <!-- Page Title -->
                    <div class="hidden lg:block">
                        <h1 class="text-lg font-semibold text-white">{{ title }}</h1>
                    </div>

                    <!-- Right Side -->
                    <div class="flex items-center gap-4">
                        <!-- View Site Link -->
                        <a
                            href="/"
                            target="_blank"
                            class="hidden sm:flex items-center gap-2 text-sm text-dark-400 hover:text-white transition-colors"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                            </svg>
                            View Site
                        </a>

                        <!-- Admin Info -->
                        <div class="flex items-center gap-3">
                            <div class="text-right hidden sm:block">
                                <p class="text-sm font-medium text-white">{{ admin?.name || 'Admin' }}</p>
                                <p class="text-xs text-dark-400">{{ admin?.role || 'Administrator' }}</p>
                            </div>

                            <!-- Avatar -->
                            <div class="w-9 h-9 rounded-full bg-gradient-to-br from-accent-500 via-primary-500 to-warm-500 flex items-center justify-center">
                                <span class="text-white font-semibold text-sm">
                                    {{ (admin?.name || 'A').charAt(0).toUpperCase() }}
                                </span>
                            </div>

                            <!-- Role Badge -->
                            <span class="hidden md:inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary-500/10 text-primary-400 border border-primary-500/20">
                                {{ admin?.role || 'Admin' }}
                            </span>

                            <!-- Logout -->
                            <button
                                @click="handleLogout"
                                class="p-2 rounded-xl text-dark-400 hover:text-red-400 hover:bg-red-500/10 transition-colors"
                                title="Logout"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <main class="p-4 lg:p-6 min-h-[calc(100vh-64px)]">
                <slot />
            </main>
        </div>
    </div>
</template>
