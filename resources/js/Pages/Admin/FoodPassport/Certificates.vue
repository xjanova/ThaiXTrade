<script setup>
/**
 * TPIX TRADE - Admin FoodPassport Certificates
 * จัดการใบรับรอง NFT — ดู, revoke
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

const props = defineProps({
    certificates: Object,
    certStats: Object,
});

const showRevokeModal = ref(false);
const revokeId = ref(null);
const revokeReason = ref('');

function openRevokeModal(id) {
    revokeId.value = id;
    revokeReason.value = '';
    showRevokeModal.value = true;
}

function submitRevoke() {
    if (!revokeReason.value.trim()) return;
    router.post(`/admin/food-passport/certificates/${revokeId.value}/revoke`, {
        reason: revokeReason.value,
    });
    showRevokeModal.value = false;
}

// สถานะมาจากเซิร์ฟเวอร์ (active/revoked) — แปลเฉพาะตอนแสดง ไม่มีคีย์ก็โชว์ค่าดิบ
function statusLabel(s) {
    const key = `admin.foodPassportCertificates.status.${s}`;
    const text = t(key);
    return text === key ? s : text;
}

// ป้ายปุ่มก่อนหน้า/ถัดไปของ paginator มาจาก Laravel เป็นภาษาอังกฤษตายตัว — แปลตอนแสดง
function pageLabel(label) {
    if (label === '&laquo; Previous') return `&laquo; ${t('common.previous')}`;
    if (label === 'Next &raquo;') return `${t('common.next')} &raquo;`;
    return label;
}

function formatDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString(locale.value === 'th' ? 'th-TH' : 'en-US', { day: 'numeric', month: 'short', year: 'numeric' });
}
</script>

<template>
    <AdminLayout :title="t('admin.foodPassportCertificates.pageTitle')">
        <!-- Header -->
        <div class="mb-6">
            <Link href="/admin/food-passport" class="text-sm text-gray-400 hover:text-white transition-colors mb-2 inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                FoodPassport
            </Link>
            <h1 class="text-2xl font-bold text-white">{{ t('admin.foodPassportCertificates.title') }}</h1>
            <p class="text-sm text-gray-400 mt-1">{{ t('admin.foodPassportCertificates.subtitle') }}</p>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="glass-dark p-4 rounded-xl border border-white/10">
                <p class="text-2xl font-bold text-white">{{ certStats?.total || 0 }}</p>
                <p class="text-xs text-gray-400">{{ t('admin.foodPassportCertificates.stats.total') }}</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-green-500/20">
                <p class="text-2xl font-bold text-green-400">{{ certStats?.active || 0 }}</p>
                <p class="text-xs text-gray-400">{{ t('admin.foodPassportCertificates.stats.active') }}</p>
            </div>
            <div class="glass-dark p-4 rounded-xl border border-red-500/20">
                <p class="text-2xl font-bold text-red-400">{{ certStats?.revoked || 0 }}</p>
                <p class="text-xs text-gray-400">{{ t('admin.foodPassportCertificates.stats.revoked') }}</p>
            </div>
        </div>

        <!-- Certificates Table -->
        <div class="glass-dark rounded-xl border border-white/10 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left p-4 text-gray-400 font-medium">{{ t('admin.foodPassportCertificates.cols.certificate') }}</th>
                            <th class="text-left p-4 text-gray-400 font-medium">{{ t('admin.foodPassportCertificates.cols.product') }}</th>
                            <th class="text-left p-4 text-gray-400 font-medium hidden md:table-cell">{{ t('admin.foodPassportCertificates.cols.owner') }}</th>
                            <th class="text-left p-4 text-gray-400 font-medium">{{ t('admin.foodPassportCertificates.cols.status') }}</th>
                            <th class="text-left p-4 text-gray-400 font-medium hidden sm:table-cell">{{ t('admin.foodPassportCertificates.cols.issued') }}</th>
                            <th class="text-right p-4 text-gray-400 font-medium">{{ t('admin.foodPassportCertificates.cols.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="cert in certificates?.data || []"
                            :key="cert.id"
                            class="border-b border-white/5 hover:bg-white/5 transition-colors"
                        >
                            <td class="p-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-yellow-500 to-orange-600 flex items-center justify-center">
                                        <span class="text-sm font-bold text-white">#{{ cert.id }}</span>
                                    </div>
                                    <div>
                                        <p class="font-medium text-white">{{ t('admin.foodPassportCertificates.certNo', { id: cert.id }) }}</p>
                                        <p v-if="cert.tx_hash" class="text-xs text-gray-400 font-mono">{{ cert.tx_hash?.slice(0, 12) }}...</p>
                                    </div>
                                </div>
                            </td>
                            <td class="p-4">
                                <Link
                                    v-if="cert.product"
                                    :href="`/admin/food-passport/products/${cert.product.id}`"
                                    class="text-primary-400 hover:text-primary-300 transition-colors"
                                >
                                    {{ cert.product.name }}
                                </Link>
                                <span v-else class="text-gray-500">—</span>
                            </td>
                            <td class="p-4 hidden md:table-cell">
                                <span class="text-xs text-gray-400 font-mono">{{ cert.owner_address?.slice(0, 8) }}...{{ cert.owner_address?.slice(-4) }}</span>
                            </td>
                            <td class="p-4">
                                <span
                                    class="text-xs px-2.5 py-1 rounded-full font-medium"
                                    :class="cert.status === 'active' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'"
                                >
                                    {{ statusLabel(cert.status) }}
                                </span>
                            </td>
                            <td class="p-4 text-gray-400 text-xs hidden sm:table-cell">{{ formatDate(cert.created_at) }}</td>
                            <td class="p-4 text-right">
                                <button
                                    v-if="cert.status === 'active'"
                                    @click="openRevokeModal(cert.id)"
                                    class="px-3 py-1.5 rounded-lg bg-red-500/10 text-red-400 text-xs font-medium hover:bg-red-500/20 transition-colors"
                                >
                                    {{ t('admin.foodPassportCertificates.revoke') }}
                                </button>
                                <span v-else class="text-xs text-gray-500">
                                    {{ cert.certificate_data?.revoked_at ? t('admin.foodPassportCertificates.revokedOn', { date: formatDate(cert.certificate_data.revoked_at) }) : t('admin.foodPassportCertificates.status.revoked') }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!certificates?.data?.length">
                            <td colspan="6" class="p-8 text-center text-gray-400">{{ t('admin.foodPassportCertificates.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="certificates?.last_page > 1" class="flex items-center justify-between p-4 border-t border-white/5">
                <p class="text-xs text-gray-400">
                    {{ t('admin.foodPassportCertificates.showing', { from: certificates.from, to: certificates.to, total: certificates.total }) }}
                </p>
                <div class="flex gap-1">
                    <Link
                        v-for="link in certificates.links"
                        :key="link.label"
                        :href="link.url || '#'"
                        class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
                        :class="link.active ? 'bg-primary-500/20 text-primary-400' : 'text-gray-400 hover:bg-white/5'"
                        v-html="pageLabel(link.label)"
                    />
                </div>
            </div>
        </div>

        <!-- Revoke Modal -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="showRevokeModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-dark-950/80 backdrop-blur-sm" @click="showRevokeModal = false"></div>
                    <div class="relative glass-dark rounded-2xl border border-white/10 p-6 w-full max-w-md">
                        <h3 class="text-lg font-bold text-white mb-2">{{ t('admin.foodPassportCertificates.modal.title') }}</h3>
                        <p class="text-sm text-gray-400 mb-4">{{ t('admin.foodPassportCertificates.modal.body') }}</p>
                        <textarea
                            v-model="revokeReason"
                            :placeholder="t('admin.foodPassportCertificates.modal.reasonPlaceholder')"
                            class="w-full bg-dark-800 border border-white/10 rounded-xl p-3 text-white text-sm placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-red-500/50 resize-none"
                            rows="3"
                        ></textarea>
                        <div class="flex gap-3 mt-4">
                            <button @click="showRevokeModal = false" class="flex-1 px-4 py-2.5 rounded-lg border border-white/10 text-gray-400 hover:bg-white/5 transition-colors">
                                {{ t('common.cancel') }}
                            </button>
                            <button
                                @click="submitRevoke"
                                :disabled="!revokeReason.trim()"
                                class="flex-1 px-4 py-2.5 rounded-lg bg-red-500 text-white font-medium hover:bg-red-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {{ t('admin.foodPassportCertificates.modal.confirm') }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AdminLayout>
</template>
