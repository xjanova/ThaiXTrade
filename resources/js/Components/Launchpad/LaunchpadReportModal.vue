<script setup>
/**
 * TPIX TRADE — ปุ่ม "แจ้งเหรียญนี้" (POST /api/v1/launchpad/sales/{id}/report)
 *
 * ไม่ต้องเชื่อมกระเป๋า · รายงานไม่ซ่อนหน้าขายเอง — แอดมินอ่านแล้วตัดสินใจ
 *
 * Developed by Xman Studio
 */
import { ref } from 'vue';
import axios from 'axios';
import { useTranslation } from '@/Composables/useTranslation';
import { REPORT_REASONS } from '@/utils/launchpad';

const { t } = useTranslation();

const props = defineProps({
    saleId: { type: Number, required: true },
    wallet: { type: String, default: null },
});

const open = ref(false);
const reason = ref('scam');
const details = ref('');
const busy = ref(false);
const result = ref(null); // 'ok' | 'failed'

function show() {
    open.value = true;
    result.value = null;
}

function close() {
    if (busy.value) return;
    open.value = false;
    if (result.value === 'ok') {
        details.value = '';
        reason.value = 'scam';
    }
}

async function submit() {
    if (busy.value) return;
    busy.value = true;
    try {
        await axios.post(`/api/v1/launchpad/sales/${props.saleId}/report`, {
            reason: reason.value,
            details: details.value.trim() || null,
            wallet: props.wallet || null,
        });
        result.value = 'ok';
    } catch {
        result.value = 'failed';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div>
        <button type="button" class="text-xs text-dark-400 hover:text-trading-red underline underline-offset-2" data-test="report-open" @click="show">
            🚩 {{ t('launchpad.report.button') }}
        </button>

        <!-- ไปไว้ที่ body: กล่อง glass (backdrop-filter) ทำให้ fixed อิงกล่องแทนหน้าจอ -->
        <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" role="dialog" aria-modal="true" @click.self="close">
            <div class="glass-dark w-full max-w-md rounded-2xl border border-white/10 p-5 space-y-4" data-test="report-modal">
                <h3 class="text-lg font-semibold text-white">{{ t('launchpad.report.title') }}</h3>

                <template v-if="result !== 'ok'">
                    <p class="text-sm text-dark-300">{{ t('launchpad.report.body') }}</p>
                    <fieldset class="space-y-2">
                        <legend class="text-xs text-dark-400 mb-1">{{ t('launchpad.report.reason') }}</legend>
                        <label v-for="r in REPORT_REASONS" :key="r" class="flex items-center gap-2 text-sm text-dark-200">
                            <input v-model="reason" type="radio" name="report-reason" :value="r" :data-test="`reason-${r}`" />
                            {{ t(`launchpad.report.reasons.${r}`) }}
                        </label>
                    </fieldset>
                    <label class="block">
                        <span class="text-xs text-dark-400">{{ t('launchpad.report.details') }}</span>
                        <textarea v-model="details" maxlength="1000" rows="3" class="trading-input w-full mt-1" :placeholder="t('launchpad.report.detailsPlaceholder')" data-test="report-details" />
                    </label>
                    <p v-if="result === 'failed'" class="text-sm text-trading-red">{{ t('launchpad.report.failed') }}</p>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="px-4 py-2 text-sm text-dark-300 hover:text-white" :disabled="busy" @click="close">{{ t('launchpad.report.close') }}</button>
                        <button type="button" class="btn-danger px-4 py-2 text-sm" :disabled="busy" data-test="report-submit" @click="submit">
                            {{ busy ? t('launchpad.report.sending') : t('launchpad.report.submit') }}
                        </button>
                    </div>
                </template>

                <template v-else>
                    <p class="text-sm text-trading-green" data-test="report-thanks">{{ t('launchpad.report.thanks') }}</p>
                    <div class="flex justify-end">
                        <button type="button" class="px-4 py-2 text-sm text-dark-300 hover:text-white" @click="close">{{ t('launchpad.report.close') }}</button>
                    </div>
                </template>
            </div>
        </div>
        </Teleport>
    </div>
</template>
