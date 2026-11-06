<script setup>
/**
 * TPIX TRADE — ออกลิงก์เชิญลงทะเบียนผู้สร้างให้เหรียญที่ขึ้นเชนแล้ว (หลังบ้าน)
 *
 * เหรียญที่สร้างก่อนมีระบบลงทะเบียน (เช่น IDTC) ไม่มีใบผู้สร้าง — แอดมินกดออกลิงก์ (อายุ 30 วัน)
 * แล้วส่งให้ผู้แทนโครงการ ผู้แทนเซ็นด้วยกระเป๋ารับเหรียญของโครงการ กระเป๋าเจ้าของเหรียญไม่เปลี่ยน
 * ปุ่มออกลิงก์เฉพาะ super admin (route ล็อกไว้)
 *
 * Developed by Xman Studio
 */
import { reactive, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();

defineProps({
    tokens: { type: Array, default: () => [] },
    canCreate: { type: Boolean, default: false },
});

const links = reactive({});      // token id → { url, expires_at }
const busy = ref(0);
const error = ref('');
const copied = ref(0);

const shortWallet = (w) => (w ? `${w.slice(0, 6)}…${w.slice(-4)}` : '—');
const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString(locale.value === 'th' ? 'th-TH' : 'en-GB') : '');

async function createLink(token) {
    if (busy.value) return;
    error.value = '';
    busy.value = token.id;
    try {
        const res = await axios.post('/admin/creator-registrations/claim-link', { factory_token_id: token.id });
        links[token.id] = res.data.data;
    } catch (err) {
        error.value = err?.response?.data?.error?.message || t('admin.creatorRegistrations.claim.failed');
    } finally {
        busy.value = 0;
    }
}

async function copy(id) {
    try {
        await navigator.clipboard.writeText(links[id].url);
        copied.value = id;
        setTimeout(() => { if (copied.value === id) copied.value = 0; }, 2000);
    } catch {
        // เบราว์เซอร์ไม่ให้สิทธิ์คลิปบอร์ด — ลิงก์ยังเลือกคัดลอกเองได้จากช่อง
    }
}
</script>

<template>
    <section class="glass-card p-5 space-y-3" data-test="claim-links">
        <div>
            <h2 class="text-lg font-semibold text-white">{{ t('admin.creatorRegistrations.claim.title') }}</h2>
            <p class="text-sm text-gray-400">{{ t('admin.creatorRegistrations.claim.desc') }}</p>
        </div>

        <p v-if="error" class="text-sm text-trading-red" data-test="claim-link-error">{{ error }}</p>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-400 border-b border-white/10">
                    <tr>
                        <th class="px-3 py-2">{{ t('admin.creatorRegistrations.claim.token') }}</th>
                        <th class="px-3 py-2">{{ t('admin.creatorRegistrations.claim.owner') }}</th>
                        <th class="px-3 py-2">{{ t('admin.creatorRegistrations.claim.registration') }}</th>
                        <th class="px-3 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <template v-for="tk in tokens" :key="tk.id">
                        <tr class="border-b border-white/5" data-test="claim-row">
                            <td class="px-3 py-2 text-white">{{ tk.name }} <span class="text-xs text-gray-400">{{ tk.symbol }} · #{{ tk.id }}</span></td>
                            <td class="px-3 py-2 font-mono text-xs text-gray-300">{{ shortWallet(tk.owner_wallet) }}</td>
                            <td class="px-3 py-2 text-gray-300">
                                <template v-if="tk.registration">
                                    <Link :href="`/admin/creator-registrations/${tk.registration.uuid}`" class="text-primary-400 hover:underline">
                                        {{ t(`admin.creatorRegistrations.status.${tk.registration.status}`) }}
                                    </Link>
                                    <span class="ml-1 font-mono text-xs text-gray-500">{{ shortWallet(tk.registration.wallet_address) }}</span>
                                </template>
                                <span v-else class="text-amber-300">{{ t('admin.creatorRegistrations.claim.none') }}</span>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <button v-if="canCreate && tk.registration?.status !== 'approved'" type="button" class="btn-primary text-xs"
                                        :disabled="!!busy" :data-test="`claim-link-${tk.id}`" @click="createLink(tk)">
                                    {{ busy === tk.id ? t('admin.creatorRegistrations.claim.creating') : t('admin.creatorRegistrations.claim.create') }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="links[tk.id]" class="border-b border-white/5">
                            <td colspan="4" class="px-3 pb-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <input :value="links[tk.id].url" readonly class="trading-input flex-1 min-w-0 font-mono text-xs" data-test="claim-link-url" @focus="$event.target.select()">
                                    <button type="button" class="btn-success text-xs" @click="copy(tk.id)">
                                        {{ copied === tk.id ? t('admin.creatorRegistrations.claim.copied') : t('admin.creatorRegistrations.claim.copy') }}
                                    </button>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">{{ t('admin.creatorRegistrations.claim.linkNote', { date: fmtDate(links[tk.id].expires_at) }) }}</p>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!tokens.length">
                        <td colspan="4" class="px-3 py-6 text-center text-gray-500">{{ t('admin.creatorRegistrations.claim.empty') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
