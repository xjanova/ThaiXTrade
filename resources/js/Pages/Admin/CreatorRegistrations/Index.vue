<script setup>
/**
 * TPIX TRADE — คิวตรวจทะเบียนผู้สร้างเหรียญ (หลังบ้าน)
 *
 * สวิตช์ "บังคับลงทะเบียนก่อนสร้างเหรียญ" อยู่หน้าเดียวกับคิว — เปิดสวิตช์แปลว่าคิวนี้จะยาวขึ้นทันที
 * แก้ค่าได้เฉพาะ super admin (route ล็อกไว้ ปุ่มซ่อนตาม canEditSettings)
 *
 * Developed by Xman Studio
 */
import { computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';
import { formatBps } from '@/utils/creatorRegistration';

const { t, locale } = useTranslation();

const props = defineProps({
    registrations: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({ status: 'submitted' }) },
    counts: { type: Object, default: () => ({}) },
    settings: { type: Object, default: () => ({ required: false, default_starter_bps: 1000, max_starter_bps: 2000 }) },
    canEditSettings: { type: Boolean, default: false },
});

const flash = computed(() => usePage().props.flash || {});
const pageErrors = computed(() => usePage().props.errors || {});

const TABS = ['submitted', 'approved', 'rejected', 'draft', 'all'];
const setStatus = (status) => router.get('/admin/creator-registrations', { status }, { preserveState: true, preserveScroll: true });

const settingsForm = useForm({
    required: !!props.settings.required,
    // หน้าจอเป็นเปอร์เซ็นต์ ส่งเซิร์ฟเวอร์เป็น bps
    default_starter_pct: (props.settings.default_starter_bps ?? 1000) / 100,
});

const saveSettings = () => settingsForm
    .transform((d) => ({ required: d.required, default_starter_bps: Math.round(Number(d.default_starter_pct) * 100) }))
    .put('/admin/creator-registrations/settings', { preserveScroll: true });

const STATUS_CLS = {
    draft: 'bg-white/10 text-gray-300',
    submitted: 'bg-amber-500/15 text-amber-300',
    approved: 'bg-trading-green/15 text-trading-green',
    rejected: 'bg-trading-red/15 text-trading-red',
};

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleString(locale.value === 'th' ? 'th-TH' : 'en-GB') : '—');
const shortWallet = (w) => (w ? `${w.slice(0, 6)}…${w.slice(-4)}` : '—');
</script>

<template>
    <Head :title="t('admin.creatorRegistrations.headTitle')" />
    <AdminLayout>
        <div class="space-y-6">
            <header>
                <h1 class="text-2xl font-bold text-white">{{ t('admin.creatorRegistrations.title') }}</h1>
                <p class="text-sm text-gray-400">{{ t('admin.creatorRegistrations.subtitle') }}</p>
            </header>

            <div v-if="flash.success" class="rounded-lg border border-trading-green/40 bg-trading-green/10 px-3 py-2 text-sm text-trading-green">{{ flash.success }}</div>
            <div v-for="(msg, key) in pageErrors" :key="key" class="rounded-lg border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-300">{{ msg }}</div>

            <!-- ตั้งค่า -->
            <section class="glass-card p-5 space-y-3" data-test="settings">
                <h2 class="text-lg font-semibold text-white">{{ t('admin.creatorRegistrations.settings.title') }}</h2>
                <label class="flex items-start gap-3 text-sm text-white">
                    <input v-model="settingsForm.required" type="checkbox" class="mt-1" :disabled="!canEditSettings" data-test="required-toggle">
                    <span>
                        <strong>{{ t('admin.creatorRegistrations.settings.required') }}</strong><br>
                        <span class="text-gray-400">{{ t('admin.creatorRegistrations.settings.requiredHelp') }}</span>
                    </span>
                </label>
                <label class="block text-sm max-w-xs">
                    <span class="text-gray-400">{{ t('admin.creatorRegistrations.settings.defaultStarter') }}</span>
                    <input v-model="settingsForm.default_starter_pct" type="number" min="0" :max="settings.max_starter_bps / 100" step="0.5"
                           class="trading-input w-full" :disabled="!canEditSettings" data-test="default-starter">
                </label>
                <button v-if="canEditSettings" type="button" class="btn-primary" :disabled="settingsForm.processing" data-test="save-settings" @click="saveSettings">
                    {{ t('admin.creatorRegistrations.settings.save') }}
                </button>
                <p v-else class="text-xs text-gray-500">{{ t('admin.creatorRegistrations.settings.superOnly') }}</p>
            </section>

            <!-- แท็บ -->
            <nav class="flex flex-wrap gap-2">
                <button v-for="tab in TABS" :key="tab" type="button"
                        class="rounded-lg px-3 py-1.5 text-sm border"
                        :class="filters.status === tab ? 'border-primary-500 bg-primary-500/20 text-white' : 'border-white/10 text-gray-400 hover:text-white'"
                        :data-test="`tab-${tab}`" @click="setStatus(tab)">
                    {{ t(`admin.creatorRegistrations.tabs.${tab}`) }}
                    <span v-if="tab !== 'all' && counts[tab]" class="ml-1 text-xs opacity-70">{{ counts[tab] }}</span>
                </button>
            </nav>

            <!-- ตาราง -->
            <section class="glass-card overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-400 border-b border-white/10">
                        <tr>
                            <th class="px-4 py-3">{{ t('admin.creatorRegistrations.table.project') }}</th>
                            <th class="px-4 py-3">{{ t('admin.creatorRegistrations.table.type') }}</th>
                            <th class="px-4 py-3">{{ t('admin.creatorRegistrations.table.wallet') }}</th>
                            <th class="px-4 py-3">{{ t('admin.creatorRegistrations.table.submitted') }}</th>
                            <th class="px-4 py-3">{{ t('admin.creatorRegistrations.table.status') }}</th>
                            <th class="px-4 py-3">{{ t('admin.creatorRegistrations.table.starter') }}</th>
                            <th class="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in registrations.data" :key="r.uuid" class="border-b border-white/5" data-test="row">
                            <td class="px-4 py-3 text-white">
                                {{ r.project_name || '—' }}
                                <span v-if="r.token_symbol" class="ml-1 text-xs text-gray-400">{{ r.token_symbol }}</span>
                            </td>
                            <td class="px-4 py-3 text-gray-300">{{ r.applicant_type ? t(`admin.creatorRegistrations.type.${r.applicant_type}`) : '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-300">{{ shortWallet(r.wallet_address) }}</td>
                            <td class="px-4 py-3 text-gray-300">{{ fmtDate(r.submitted_at) }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded px-2 py-0.5 text-xs" :class="STATUS_CLS[r.status]">{{ t(`admin.creatorRegistrations.status.${r.status}`) }}</span>
                            </td>
                            <td class="px-4 py-3 text-gray-300">{{ r.starter_allocation_bps != null ? `${formatBps(r.starter_allocation_bps)}%` : '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <Link :href="`/admin/creator-registrations/${r.uuid}`" class="text-primary-400 hover:underline">{{ t('admin.creatorRegistrations.table.view') }}</Link>
                            </td>
                        </tr>
                        <tr v-if="!registrations.data?.length">
                            <td colspan="7" class="px-4 py-8 text-center text-gray-500" data-test="empty">{{ t('admin.creatorRegistrations.table.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <nav v-if="registrations.links && registrations.last_page > 1" class="flex flex-wrap gap-1">
                <Link v-for="(l, i) in registrations.links" :key="i" :href="l.url || '#'"
                      class="rounded px-3 py-1 text-sm" :class="l.active ? 'bg-primary-500/30 text-white' : 'text-gray-400'"
                      preserve-scroll v-html="l.label" />
            </nav>
        </div>
    </AdminLayout>
</template>
