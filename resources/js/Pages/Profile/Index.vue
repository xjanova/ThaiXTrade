<script setup>
/**
 * TPIX TRADE - User Profile Page
 * Tabbed profile with info, security, connected accounts
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useWalletStore } from '@/Stores/walletStore';

const props = defineProps({
    profileUser: Object,
    socialAccounts: Array,
    walletConnections: Array,
    enabledProviders: Array,
});

const activeTab = ref('profile');
const tabs = [
    { key: 'profile', label: 'Profile' },
    { key: 'security', label: 'Security' },
    { key: 'connections', label: 'Connections' },
    { key: 'account', label: 'Account' },
];

// Profile form
const profileForm = useForm({
    name: props.profileUser.name || '',
    email: props.profileUser.email || '',
});

const saveProfile = () => {
    profileForm.put('/profile', {
        preserveScroll: true,
    });
};

// Password form
const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const savePassword = () => {
    passwordForm.put('/profile/password', {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
};

// Avatar upload
const avatarInput = ref(null);
const uploadAvatar = (event) => {
    const file = event.target.files[0];
    if (!file) return;

    const form = useForm({ avatar: file });
    form.post('/profile/avatar', {
        preserveScroll: true,
        onSuccess: () => { avatarInput.value.value = ''; },
    });
};

const deleteAvatar = () => {
    router.delete('/profile/avatar', { preserveScroll: true });
};

// Social unlink
const unlinkSocial = (provider) => {
    if (!confirm(`Disconnect ${provider} account?`)) return;
    router.delete(`/auth/${provider}/unlink`, { preserveScroll: true });
};

// ── กระเป๋าที่ผูกกับบัญชี ────────────────────────────────────────────────────
const walletStore = useWalletStore();
const errors = computed(() => usePage().props.errors || {});

/**
 * ใช้ค่าจาก props เป็นหลัก ไม่ใช่จากกระเป๋าที่กำลังเชื่อมอยู่
 *
 * สองอย่างนี้ต่างกันได้: ผู้ใช้สลับบัญชีในกระเป๋าไปคนละใบกับที่ผูกไว้ ถ้าโชว์ใบที่
 * กำลังเชื่อมอยู่ เขาจะเข้าใจว่าผูกใบนั้นแล้วทั้งที่ยังไม่ได้ผูก
 */
const linkedWallet = computed(() => props.profileUser.wallet_address || null);

const unlinkWallet = () => {
    if (!confirm('ถอดกระเป๋าออกจากบัญชีนี้? จะเข้าสู่ระบบด้วยกระเป๋าใบนี้ไม่ได้อีก')) return;
    router.delete('/profile/wallet', { preserveScroll: true });
};

/*
 * ผูกกระเป๋าจากหน้านี้ได้เลย — เดิมมีแต่ปุ่ม "ถอดกระเป๋า"
 *
 * การผูกเกิดขึ้นหลัง ecrecover ผ่านเท่านั้น (ห้ามผูกจากที่อื่น) หน้านี้จึงไม่ได้
 * ผูกเอง แต่พาผู้ใช้ไปถึงจุดที่เซ็น: ยังไม่เชื่อม → เปิดหน้าต่างเลือกกระเป๋า ·
 * เชื่อมแล้วแต่ลายเซ็นหมดอายุ → เซ็นใหม่ตรงนี้ ไม่ต้องตัดการเชื่อมต่อก่อน
 *
 * เดิมข้อความบอกให้ "กดปุ่มมุมขวาบน" ซึ่งบนจอแคบปุ่มนั้นอยู่ในเมนูที่พับไว้
 * ผู้ใช้จึงอ่านวิธีทำแล้วหาปุ่มไม่เจอ
 */
const isLinking = ref(false);

const linkWallet = async () => {
    if (isLinking.value) return;   // กันกดรัว — ป๊อปอัพขอลายเซ็นซ้อนกันไม่ได้

    if (!walletStore.isConnected) {
        walletStore.openConnectModal();
        return;
    }

    isLinking.value = true;
    try {
        const signed = await walletStore.verifyOwnership();

        // ไม่มี signer = กระเป๋าฝังที่ยังไม่ปลดล็อก → ต้องใส่รหัสผ่านก่อน
        if (!signed) {
            walletStore.openConnectModal();
            return;
        }

        // ผูกสำเร็จหรือไม่ ดูจาก props ที่เซิร์ฟเวอร์ส่งกลับมา ไม่ใช่เดาจากที่ไม่ throw
        router.reload({ only: ['profileUser', 'walletConnections'], preserveScroll: true });
    } finally {
        isLinking.value = false;
    }
};

// Copy referral code
const copied = ref(false);
const copyReferral = () => {
    navigator.clipboard.writeText(props.profileUser.referral_code);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
};

const isLinked = (provider) => {
    return props.socialAccounts.some(sa => sa.provider === provider);
};
const getSocialAccount = (provider) => {
    return props.socialAccounts.find(sa => sa.provider === provider);
};

const providerInfo = {
    google: { name: 'Google', iconColor: 'text-red-400', bgColor: 'bg-red-500/10' },
    facebook: { name: 'Facebook', iconColor: 'text-blue-400', bgColor: 'bg-blue-500/10' },
    line: { name: 'LINE', iconColor: 'text-green-400', bgColor: 'bg-green-500/10' },
};

const kycBadge = {
    none: { label: 'Not Submitted', class: 'bg-dark-600 text-dark-300' },
    pending: { label: 'Pending', class: 'bg-yellow-500/20 text-yellow-400' },
    approved: { label: 'Approved', class: 'bg-green-500/20 text-green-400' },
    rejected: { label: 'Rejected', class: 'bg-red-500/20 text-red-400' },
};
</script>

<template>
    <AppLayout>
        <Head title="Profile" />

        <div class="max-w-4xl mx-auto px-4 py-8">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-4 mb-8">
                <div class="relative group">
                    <div class="w-20 h-20 rounded-2xl bg-primary-500/20 border border-primary-500/30 flex items-center justify-center overflow-hidden">
                        <img v-if="profileUser.avatar" :src="profileUser.avatar" class="w-full h-full object-cover" />
                        <span v-else class="text-3xl font-bold text-primary-400">
                            {{ (profileUser.name || profileUser.email || 'U')[0].toUpperCase() }}
                        </span>
                    </div>
                    <button
                        @click="avatarInput?.click()"
                        class="absolute inset-0 rounded-2xl bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"
                    >
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </button>
                    <input ref="avatarInput" type="file" accept="image/*" class="hidden" @change="uploadAvatar" />
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-white">{{ profileUser.name || 'Trader' }}</h1>
                    <p class="text-dark-400 text-sm">{{ profileUser.email }}</p>
                    <p v-if="profileUser.wallet_address" class="text-dark-500 text-xs font-mono mt-1 truncate max-w-[280px] sm:max-w-none">{{ profileUser.wallet_address }}</p>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 mb-6 overflow-x-auto">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    @click="activeTab = tab.key"
                    class="px-4 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap"
                    :class="activeTab === tab.key
                        ? 'bg-primary-500 text-white'
                        : 'text-dark-400 hover:text-white hover:bg-white/5'"
                >
                    {{ tab.label }}
                </button>
            </div>

            <!-- Tab: Profile -->
            <div v-if="activeTab === 'profile'" class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6 shadow-glass">
                <h2 class="text-lg font-semibold text-white mb-6">Profile Information</h2>

                <form @submit.prevent="saveProfile" class="space-y-5 max-w-lg">
                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Name</label>
                        <input
                            v-model="profileForm.name"
                            type="text"
                            class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all"
                            placeholder="Your name"
                        />
                        <p v-if="profileForm.errors.name" class="text-red-400 text-sm mt-1">{{ profileForm.errors.name }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Email</label>
                        <input
                            v-model="profileForm.email"
                            type="email"
                            class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all"
                            placeholder="you@example.com"
                        />
                        <p v-if="profileForm.errors.email" class="text-red-400 text-sm mt-1">{{ profileForm.errors.email }}</p>
                    </div>

                    <div class="flex items-center gap-3">
                        <button
                            type="submit"
                            :disabled="profileForm.processing"
                            class="btn-primary disabled:opacity-50"
                        >
                            {{ profileForm.processing ? 'Saving...' : 'Save Changes' }}
                        </button>
                        <button
                            v-if="profileUser.avatar"
                            type="button"
                            @click="deleteAvatar"
                            class="text-sm text-dark-400 hover:text-red-400 transition-colors"
                        >
                            Remove Avatar
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tab: Security -->
            <div v-if="activeTab === 'security'" class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6 shadow-glass">
                <h2 class="text-lg font-semibold text-white mb-2">
                    {{ profileUser.has_password ? 'Change Password' : 'Set Password' }}
                </h2>
                <p v-if="!profileUser.has_password" class="text-dark-400 text-sm mb-6">
                    You signed up via social login. Set a password to enable email login.
                </p>

                <form @submit.prevent="savePassword" class="space-y-5 max-w-lg">
                    <div v-if="profileUser.has_password">
                        <label class="block text-sm font-medium text-dark-300 mb-2">Current Password</label>
                        <input
                            v-model="passwordForm.current_password"
                            type="password"
                            class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all"
                        />
                        <p v-if="passwordForm.errors.current_password" class="text-red-400 text-sm mt-1">{{ passwordForm.errors.current_password }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">New Password</label>
                        <input
                            v-model="passwordForm.password"
                            type="password"
                            class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all"
                            placeholder="Min 8 chars, mixed case + numbers"
                        />
                        <p v-if="passwordForm.errors.password" class="text-red-400 text-sm mt-1">{{ passwordForm.errors.password }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-dark-300 mb-2">Confirm Password</label>
                        <input
                            v-model="passwordForm.password_confirmation"
                            type="password"
                            class="w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-all"
                        />
                    </div>

                    <button
                        type="submit"
                        :disabled="passwordForm.processing"
                        class="btn-primary disabled:opacity-50"
                    >
                        {{ passwordForm.processing ? 'Saving...' : (profileUser.has_password ? 'Update Password' : 'Set Password') }}
                    </button>
                </form>
            </div>

            <!-- Tab: Connections -->
            <div v-if="activeTab === 'connections'" class="space-y-6">
                <!-- Social Accounts -->
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6 shadow-glass">
                    <h2 class="text-lg font-semibold text-white mb-6">Social Accounts</h2>

                    <div v-if="enabledProviders.length === 0" class="text-dark-400 text-sm">
                        No social login providers are enabled.
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="provider in enabledProviders"
                            :key="provider"
                            class="flex items-center justify-between p-4 rounded-xl bg-dark-800/30 border border-white/5"
                        >
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center" :class="providerInfo[provider]?.bgColor">
                                    <span class="text-lg font-bold" :class="providerInfo[provider]?.iconColor">
                                        {{ providerInfo[provider]?.name[0] }}
                                    </span>
                                </div>
                                <div>
                                    <p class="text-white font-medium">{{ providerInfo[provider]?.name }}</p>
                                    <p v-if="isLinked(provider)" class="text-dark-400 text-sm">
                                        {{ getSocialAccount(provider)?.provider_email || 'Connected' }}
                                    </p>
                                    <p v-else class="text-dark-500 text-sm">Not connected</p>
                                </div>
                            </div>

                            <a
                                v-if="!isLinked(provider)"
                                :href="`/auth/${provider}`"
                                class="px-4 py-2 rounded-lg text-sm font-medium bg-primary-500/10 text-primary-400 hover:bg-primary-500/20 transition-colors"
                            >
                                Connect
                            </a>
                            <button
                                v-else
                                @click="unlinkSocial(provider)"
                                class="px-4 py-2 rounded-lg text-sm font-medium bg-red-500/10 text-red-400 hover:bg-red-500/20 transition-colors"
                            >
                                Disconnect
                            </button>
                        </div>
                    </div>
                </div>

                <!-- กระเป๋าที่ผูกกับบัญชีนี้ — ต่างจากประวัติการเชื่อมต่อด้านล่าง -->
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6 shadow-glass">
                    <h2 class="text-lg font-semibold text-white mb-1">กระเป๋าของบัญชีนี้</h2>
                    <p class="text-dark-500 text-xs mb-6">ใช้เข้าสู่ระบบได้โดยไม่ต้องใช้รหัสผ่าน</p>

                    <div v-if="linkedWallet" class="p-4 rounded-xl bg-dark-800/30 border border-white/5">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="w-2 h-2 rounded-full bg-trading-green shrink-0"></span>
                                    <span class="text-xs text-trading-green font-medium">ผูกแล้ว</span>
                                </div>
                                <p class="text-white font-mono text-sm break-all">{{ linkedWallet }}</p>
                            </div>

                            <div class="shrink-0">
                                <button
                                    v-if="profileUser.can_unlink_wallet"
                                    type="button"
                                    @click="unlinkWallet"
                                    class="px-4 py-2 rounded-lg text-sm font-medium bg-red-500/10 text-red-400 hover:bg-red-500/20 transition-colors"
                                >
                                    ถอดกระเป๋า
                                </button>
                                <!-- ถอดไม่ได้ต้องบอกว่าทำอะไรก่อนถึงจะถอดได้ ไม่ใช่ซ่อนปุ่มเฉยๆ -->
                                <p v-else class="text-[11px] text-dark-500 max-w-[220px] leading-relaxed">
                                    ถอดไม่ได้ — เป็นทางเข้าเดียวของบัญชีนี้
                                    <button type="button" class="text-primary-400 hover:text-primary-300 underline" @click="activeTab = 'security'">
                                        ตั้งอีเมลกับรหัสผ่านก่อน
                                    </button>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div v-else class="p-4 rounded-xl bg-dark-800/30 border border-white/5">
                        <p class="text-dark-300 text-sm mb-1">ยังไม่ได้ผูกกระเป๋า</p>
                        <p class="text-dark-500 text-xs leading-relaxed mb-3">
                            ผูกแล้วประวัติเทรดและบอทจะรวมอยู่ที่เดียวกัน ไม่แตกเป็นสองบัญชี
                            การผูกทำโดยเซ็นข้อความยืนยัน ไม่เสียค่าแก๊ส
                        </p>
                        <button
                            type="button"
                            class="btn-brand px-5 py-2 text-xs disabled:opacity-50 inline-flex items-center gap-2"
                            :disabled="isLinking"
                            @click="linkWallet"
                        >
                            <span v-if="isLinking" class="spinner !w-3 !h-3 !border-white/30 !border-t-white"></span>
                            {{ isLinking ? 'รอเซ็นในกระเป๋า...' : (walletStore.isConnected ? 'เซ็นเพื่อผูกกระเป๋า' : 'เชื่อมกระเป๋าเพื่อผูก') }}
                        </button>
                        <p v-if="walletStore.linkError" class="text-xs text-amber-300 leading-relaxed mt-3">
                            {{ walletStore.linkError }}
                        </p>
                    </div>

                    <p v-if="errors.wallet" class="text-red-400 text-xs mt-3">{{ errors.wallet }}</p>
                </div>

                <!-- Wallet Connections -->
                <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6 shadow-glass">
                    <h2 class="text-lg font-semibold text-white mb-1">ประวัติการเชื่อมต่อ</h2>
                    <p class="text-dark-500 text-xs mb-6">ทุกครั้งที่กระเป๋าเชื่อมเข้ามา — ไว้ตรวจย้อนหลัง</p>

                    <div v-if="walletConnections.length === 0" class="text-dark-400 text-sm">
                        ยังไม่มีประวัติ
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="wc in walletConnections"
                            :key="wc.wallet_address"
                            class="flex items-center justify-between p-4 rounded-xl bg-dark-800/30 border border-white/5"
                        >
                            <div>
                                <p class="text-white font-mono text-sm">{{ wc.wallet_address }}</p>
                                <p class="text-dark-500 text-xs mt-1">
                                    {{ wc.wallet_type }} &middot; Chain {{ wc.chain_id }} &middot; {{ wc.connected_at }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Account -->
            <div v-if="activeTab === 'account'" class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6 shadow-glass">
                <h2 class="text-lg font-semibold text-white mb-6">Account Information</h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <!-- Referral Code -->
                    <div class="p-4 rounded-xl bg-dark-800/30 border border-white/5">
                        <p class="text-dark-400 text-sm mb-1">Referral Code</p>
                        <div class="flex items-center gap-2">
                            <span class="text-white font-mono text-lg">{{ profileUser.referral_code }}</span>
                            <button @click="copyReferral" class="text-dark-400 hover:text-primary-400 transition-colors">
                                <svg v-if="!copied" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                                <svg v-else class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- KYC Status -->
                    <div class="p-4 rounded-xl bg-dark-800/30 border border-white/5">
                        <p class="text-dark-400 text-sm mb-1">KYC Status</p>
                        <span class="inline-flex px-3 py-1 rounded-full text-sm font-medium" :class="kycBadge[profileUser.kyc_status]?.class">
                            {{ kycBadge[profileUser.kyc_status]?.label }}
                        </span>
                    </div>

                    <!-- Member Since -->
                    <div class="p-4 rounded-xl bg-dark-800/30 border border-white/5">
                        <p class="text-dark-400 text-sm mb-1">Member Since</p>
                        <p class="text-white">{{ profileUser.created_at }}</p>
                    </div>

                    <!-- Trading Stats -->
                    <div class="p-4 rounded-xl bg-dark-800/30 border border-white/5">
                        <p class="text-dark-400 text-sm mb-1">Trading Stats</p>
                        <p class="text-white">
                            {{ profileUser.total_trades }} trades &middot;
                            ${{ Number(profileUser.total_volume_usd || 0).toLocaleString() }} volume
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
