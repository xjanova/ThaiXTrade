<script setup>
/**
 * TPIX TRADE - Admin Profile Edit
 * Profile information and password management
 * Developed by Xman Studio
 */

import { reactive, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

const props = defineProps({
    admin: Object,
});

const page = usePage();

// Profile form
const profileForm = reactive({
    name: props.admin.name,
    email: props.admin.email,
});
const profileProcessing = ref(false);
const profileErrors = ref({});

const updateProfile = () => {
    profileProcessing.value = true;
    profileErrors.value = {};
    router.put('/admin/profile', profileForm, {
        preserveScroll: true,
        onError: (errors) => { profileErrors.value = errors; },
        onFinish: () => { profileProcessing.value = false; },
    });
};

// Password form
const passwordForm = reactive({
    current_password: '',
    password: '',
    password_confirmation: '',
});
const passwordProcessing = ref(false);
const passwordErrors = ref({});

const updatePassword = () => {
    passwordProcessing.value = true;
    passwordErrors.value = {};
    router.put('/admin/profile/password', passwordForm, {
        preserveScroll: true,
        onSuccess: () => {
            passwordForm.current_password = '';
            passwordForm.password = '';
            passwordForm.password_confirmation = '';
        },
        onError: (errors) => { passwordErrors.value = errors; },
        onFinish: () => { passwordProcessing.value = false; },
    });
};

// ป้ายบทบาทตามภาษา — บทบาทที่ไม่มีคำแปลแสดงค่าจากเซิร์ฟเวอร์
const roleLabel = (role) => {
    if (!role) return '';
    const key = `admin.profileEdit.roles.${role}`;
    const label = t(key);
    return label === key ? role : label;
};

const inputClass = 'w-full bg-dark-800/50 border border-dark-600 rounded-xl px-4 py-2.5 text-sm text-white placeholder-dark-500 focus:outline-none focus:border-primary-500 transition-colors';
</script>

<template>
    <Head :title="t('admin.profileEdit.title')" />

    <AdminLayout :title="t('admin.profileEdit.title')">
        <div class="max-w-2xl mx-auto space-y-6">
            <!-- Profile Header -->
            <div class="flex items-center gap-4 p-6 bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl">
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-accent-500 via-primary-500 to-warm-500 flex items-center justify-center flex-shrink-0">
                    <span class="text-white font-bold text-2xl">{{ (admin.name || 'A').charAt(0).toUpperCase() }}</span>
                </div>
                <div>
                    <h2 class="text-xl font-semibold text-white">{{ admin.name }}</h2>
                    <p class="text-sm text-dark-400">{{ admin.email }}</p>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary-500/10 text-primary-400 border border-primary-500/20">
                            {{ roleLabel(admin.role) }}
                        </span>
                        <span class="text-xs text-dark-500">{{ t('admin.profileEdit.memberSinceInline', { date: admin.created_at }) }}</span>
                    </div>
                </div>
            </div>

            <!-- Profile Information -->
            <div class="p-6 bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl">
                <h3 class="text-lg font-semibold text-white mb-4">{{ t('admin.profileEdit.profileInfo.title') }}</h3>
                <form @submit.prevent="updateProfile" class="space-y-4">
                    <div>
                        <label class="block text-xs text-dark-400 mb-1.5">{{ t('admin.profileEdit.profileInfo.name') }}</label>
                        <input v-model="profileForm.name" type="text" :class="inputClass" />
                        <p v-if="profileErrors.name" class="text-red-400 text-xs mt-1">{{ profileErrors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-xs text-dark-400 mb-1.5">{{ t('admin.profileEdit.profileInfo.email') }}</label>
                        <input v-model="profileForm.email" type="email" :class="inputClass" />
                        <p v-if="profileErrors.email" class="text-red-400 text-xs mt-1">{{ profileErrors.email }}</p>
                    </div>

                    <!-- Flash success -->
                    <p v-if="page.props.flash?.success && !profileProcessing" class="text-green-400 text-sm">
                        {{ page.props.flash.success }}
                    </p>

                    <div class="flex justify-end">
                        <button
                            type="submit"
                            :disabled="profileProcessing"
                            class="btn-primary px-6 py-2.5 text-sm disabled:opacity-50"
                        >
                            {{ profileProcessing ? t('admin.profileEdit.profileInfo.saving') : t('admin.profileEdit.profileInfo.save') }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Change Password -->
            <div class="p-6 bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl">
                <h3 class="text-lg font-semibold text-white mb-4">{{ t('admin.profileEdit.password.title') }}</h3>
                <form @submit.prevent="updatePassword" class="space-y-4">
                    <div>
                        <label class="block text-xs text-dark-400 mb-1.5">{{ t('admin.profileEdit.password.current') }}</label>
                        <input v-model="passwordForm.current_password" type="password" :class="inputClass" :placeholder="t('admin.profileEdit.password.currentPlaceholder')" />
                        <p v-if="passwordErrors.current_password" class="text-red-400 text-xs mt-1">{{ passwordErrors.current_password }}</p>
                    </div>
                    <div>
                        <label class="block text-xs text-dark-400 mb-1.5">{{ t('admin.profileEdit.password.new') }}</label>
                        <input v-model="passwordForm.password" type="password" :class="inputClass" :placeholder="t('admin.profileEdit.password.newPlaceholder')" />
                        <p v-if="passwordErrors.password" class="text-red-400 text-xs mt-1">{{ passwordErrors.password }}</p>
                    </div>
                    <div>
                        <label class="block text-xs text-dark-400 mb-1.5">{{ t('admin.profileEdit.password.confirm') }}</label>
                        <input v-model="passwordForm.password_confirmation" type="password" :class="inputClass" :placeholder="t('admin.profileEdit.password.confirmPlaceholder')" />
                    </div>
                    <div class="flex justify-end">
                        <button
                            type="submit"
                            :disabled="passwordProcessing"
                            class="btn-primary px-6 py-2.5 text-sm disabled:opacity-50"
                        >
                            {{ passwordProcessing ? t('admin.profileEdit.password.updating') : t('admin.profileEdit.password.submit') }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Account Info -->
            <div class="p-6 bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl">
                <h3 class="text-lg font-semibold text-white mb-4">{{ t('admin.profileEdit.account.title') }}</h3>
                <dl class="space-y-3">
                    <div class="flex justify-between">
                        <dt class="text-sm text-dark-400">{{ t('admin.profileEdit.account.role') }}</dt>
                        <dd class="text-sm text-white">{{ roleLabel(admin.role) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm text-dark-400">{{ t('admin.profileEdit.account.memberSince') }}</dt>
                        <dd class="text-sm text-white">{{ admin.created_at }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm text-dark-400">{{ t('admin.profileEdit.account.lastLogin') }}</dt>
                        <dd class="text-sm text-white">{{ admin.last_login_at || t('admin.profileEdit.account.never') }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </AdminLayout>
</template>
