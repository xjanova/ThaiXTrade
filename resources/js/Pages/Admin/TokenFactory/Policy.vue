<script setup>
/**
 * TPIX TRADE — ตั้งค่า Token Factory (super_admin เท่านั้น)
 *
 * เจ้าของสั่ง: "ปิดทุกอย่างที่เสี่ยงไว้ก่อน แอดมินเปิด/ปิดเองทีละตัว พร้อมตั้งค่าละเอียด"
 *
 * - ทุกประเภท/ตัวเลือกมีสามสถานะ: ปิด · เปิด · ล็อก (ล็อกในโค้ด เปิดจากหน้านี้ไม่ได้ — แสดงเหตุผล)
 * - ของเสี่ยงที่เปิด ผู้สร้างเหรียญจะเห็นกล่องเตือนสีแดงและต้องติ๊กยอมรับก่อนสร้าง
 * - บันทึกต้องยืนยันก่อน และทุกการเปลี่ยนแปลงลง audit log (ใคร · อะไร · ค่าเดิม → ค่าใหม่)
 *
 * Developed by Xman Studio
 */
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    policy: { type: Object, required: true },
    resolvedFeeWallet: { type: String, default: '' },
});

const page = usePage();
const flash = computed(() => page.props.flash || {});
const errors = computed(() => page.props.errors || {});

const typeKeys = Object.keys(props.policy.types || {});
const optionKeys = Object.keys(props.policy.options || {});

function initialForm() {
    const types = {};
    for (const key of typeKeys) {
        const t = props.policy.types[key];
        types[key] = {
            state: t.state === 'on' ? 'on' : 'off',
            fee: t.fee,
            max_supply: t.max_supply,
            decimals_min: t.decimals_min,
            decimals_max: t.decimals_max,
            auto_approve: !!t.auto_approve,
            require_kyc: !!t.require_kyc,
        };
    }
    const options = {};
    for (const key of optionKeys) {
        const o = props.policy.options[key];
        options[key] = { state: o.state === 'on' ? 'on' : 'off', fee: o.fee };
    }
    const reserved = Object.entries(props.policy.reserved_symbols || {}).map(([symbol, wallets]) => ({
        symbol,
        wallets: (wallets || []).join('\n'),
    }));

    return {
        creation_enabled: !!props.policy.creation_enabled,
        fee_wallet: props.policy.fee_wallet_override || '',
        required_confirmations: props.policy.limits.required_confirmations,
        quote_ttl_minutes: props.policy.limits.quote_ttl_minutes,
        daily_limit_per_wallet: props.policy.limits.daily_limit_per_wallet,
        name_max_length: props.policy.limits.name_max_length,
        symbol_max_length: props.policy.limits.symbol_max_length,
        auto_list_on_deploy: !!props.policy.auto_list_on_deploy,
        require_kyc: !!props.policy.require_kyc,
        reserved_symbols: reserved,
        types,
        options,
    };
}

const form = useForm(initialForm());

const isLocked = (group, key) => props.policy[group]?.[key]?.state === 'locked';

/** จำนวนช่องที่เปลี่ยนจากค่าที่บันทึกไว้ — แสดงในกล่องยืนยัน */
const changedCount = computed(() => {
    const before = JSON.stringify(initialForm());
    if (before === JSON.stringify(form.data())) return 0;
    const a = flatten(initialForm());
    const b = flatten(form.data());
    return Object.keys(b).filter(k => JSON.stringify(a[k]) !== JSON.stringify(b[k])).length;
});

function flatten(obj, prefix = '', out = {}) {
    for (const [k, v] of Object.entries(obj)) {
        const path = prefix ? `${prefix}.${k}` : k;
        if (v && typeof v === 'object' && !Array.isArray(v)) flatten(v, path, out);
        else out[path] = v;
    }
    return out;
}

/** ของเสี่ยงที่กำลังจะเปิด — เตือนซ้ำในกล่องยืนยันให้แน่ใจว่าตั้งใจ */
const riskyTurningOn = computed(() => {
    const list = [];
    for (const key of typeKeys) {
        if (props.policy.types[key].risky && form.types[key].state === 'on' && props.policy.types[key].state !== 'on') {
            list.push(props.policy.types[key].label_th);
        }
    }
    for (const key of optionKeys) {
        if (props.policy.options[key].risky && form.options[key].state === 'on' && props.policy.options[key].state !== 'on') {
            list.push(props.policy.options[key].label_th);
        }
    }
    return list;
});

function save() {
    if (changedCount.value === 0) return;

    let message = `ยืนยันบันทึกนโยบาย Token Factory (${changedCount.value} รายการที่เปลี่ยน)?`;
    if (riskyTurningOn.value.length) {
        message += `\n\n⚠️ กำลังเปิดของที่เสี่ยงต่อผู้ซื้อ:\n- ${riskyTurningOn.value.join('\n- ')}`;
    }
    message += '\n\nทุกการเปลี่ยนแปลงจะถูกบันทึกใน Audit Log';
    if (!window.confirm(message)) return;

    form.transform((data) => ({
        ...data,
        reserved_symbols: data.reserved_symbols
            .filter(r => (r.symbol || '').trim() !== '')
            .map(r => ({ symbol: r.symbol.trim().toUpperCase(), wallets: r.wallets })),
    })).put('/admin/token-factory/policy', {
        preserveScroll: true,
        // ค่าที่บันทึกแล้วกลายเป็นฐานใหม่ — ไม่งั้นตัวนับ "ยังไม่บันทึก" ค้างหลังบันทึกสำเร็จ
        onSuccess: () => {
            form.defaults(initialForm());
            form.reset();
        },
    });
}

function addReserved() {
    form.reserved_symbols.push({ symbol: '', wallets: '' });
}

function removeReserved(index) {
    const row = form.reserved_symbols[index];
    if (row.symbol && !window.confirm(`ลบสัญลักษณ์สงวน ${row.symbol}? หลังบันทึก ใครก็ใช้สัญลักษณ์นี้ได้`)) return;
    form.reserved_symbols.splice(index, 1);
}

const stateButton = (active, tone) => [
    'px-3 py-1.5 text-xs font-medium transition-colors',
    active
        ? (tone === 'on' ? 'bg-green-500/20 text-green-300' : 'bg-white/10 text-white')
        : 'text-dark-400 hover:text-white',
];

const inputClass = 'w-full bg-dark-800/50 border border-dark-600 rounded-xl px-3 py-2 text-sm text-white placeholder-dark-500 focus:outline-none focus:border-primary-500';
const labelClass = 'block text-xs font-medium text-dark-300 mb-1';
</script>

<template>
    <Head title="ตั้งค่า Token Factory" />

    <AdminLayout title="ตั้งค่า Token Factory">
        <div class="max-w-5xl space-y-6">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold text-white">ตั้งค่า Token Factory</h1>
                    <p class="text-sm text-dark-400 mt-1">
                        เปิด/ปิดประเภทเหรียญและตัวเลือกทีละตัว · ของที่เสี่ยงปิดไว้ก่อนเสมอ · ทุกการเปลี่ยนแปลงลง Audit Log
                    </p>
                </div>
                <Link href="/admin/token-factory" class="text-sm text-primary-400 hover:text-primary-300">← รายการเหรียญ</Link>
            </div>

            <div v-if="flash.success" class="p-3 rounded-xl bg-green-500/10 border border-green-500/20 text-green-400 text-sm">{{ flash.success }}</div>
            <div v-if="flash.error" class="p-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm">{{ flash.error }}</div>

            <!-- ภาพรวม -->
            <section class="rounded-2xl border border-white/10 bg-white/5 p-5 space-y-4">
                <h2 class="text-sm font-semibold text-white">ภาพรวม</h2>
                <div class="grid sm:grid-cols-2 gap-4">
                    <label class="flex items-center justify-between gap-3 p-3 rounded-xl bg-dark-800/50 border border-white/5">
                        <span class="text-sm text-dark-200">เปิดให้สร้างเหรียญ</span>
                        <input v-model="form.creation_enabled" type="checkbox" class="rounded" data-test="creation-enabled" />
                    </label>
                    <label class="flex items-center justify-between gap-3 p-3 rounded-xl bg-dark-800/50 border border-white/5">
                        <span class="text-sm text-dark-200">ต้องยืนยันตัวตน (KYC) ทุกประเภท</span>
                        <input v-model="form.require_kyc" type="checkbox" class="rounded" />
                    </label>
                    <label class="flex items-center justify-between gap-3 p-3 rounded-xl bg-dark-800/50 border border-white/5 sm:col-span-2">
                        <span class="text-sm text-dark-200">
                            ลิสต์เหรียญอัตโนมัติเมื่อ deploy เสร็จ
                            <span class="block text-[11px] text-dark-500">ปิด = แอดมินกด "List" เองก่อนเหรียญจะขึ้นหน้า Explore และเปิดคู่เทรดบน DEX</span>
                        </span>
                        <input v-model="form.auto_list_on_deploy" type="checkbox" class="rounded" />
                    </label>
                </div>

                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div class="sm:col-span-2 lg:col-span-3">
                        <label :class="labelClass">กระเป๋ารับค่าธรรมเนียม (TPIX บนเชน 4289)</label>
                        <input v-model="form.fee_wallet" type="text" :class="inputClass" placeholder="ว่าง = ใช้กระเป๋ารับรายได้ TPIX จากหน้าตั้งค่า" />
                        <p class="text-[11px] text-dark-500 mt-1">
                            ที่ใช้อยู่ตอนนี้: <span class="font-mono text-dark-300">{{ resolvedFeeWallet || 'ยังไม่ได้ตั้ง' }}</span>
                        </p>
                        <p v-if="errors.fee_wallet" class="text-xs text-red-400 mt-1">{{ errors.fee_wallet }}</p>
                    </div>
                    <div>
                        <label :class="labelClass">บล็อกยืนยันก่อนรับค่าธรรมเนียม</label>
                        <input v-model.number="form.required_confirmations" type="number" min="1" max="100" :class="inputClass" />
                    </div>
                    <div>
                        <label :class="labelClass">อายุใบเสนอราคา (นาที)</label>
                        <input v-model.number="form.quote_ttl_minutes" type="number" min="5" max="120" :class="inputClass" />
                    </div>
                    <div>
                        <label :class="labelClass">สร้างได้ต่อกระเป๋าต่อวัน</label>
                        <input v-model.number="form.daily_limit_per_wallet" type="number" min="1" max="100" :class="inputClass" />
                    </div>
                    <div>
                        <label :class="labelClass">ความยาวชื่อสูงสุด</label>
                        <input v-model.number="form.name_max_length" type="number" min="2" max="100" :class="inputClass" />
                    </div>
                    <div>
                        <label :class="labelClass">ความยาวสัญลักษณ์สูงสุด (A–Z, 0–9)</label>
                        <input v-model.number="form.symbol_max_length" type="number" min="2" max="20" :class="inputClass" />
                    </div>
                </div>
            </section>

            <!-- ประเภทเหรียญ -->
            <section class="rounded-2xl border border-white/10 bg-white/5 p-5 space-y-3">
                <h2 class="text-sm font-semibold text-white">ประเภทเหรียญ</h2>
                <div
                    v-for="key in typeKeys"
                    :key="key"
                    class="rounded-xl border p-4"
                    :class="isLocked('types', key) ? 'border-white/5 bg-dark-900/40 opacity-60' : 'border-white/10 bg-dark-800/40'"
                    :data-test="`type-${key}`"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-white">
                                {{ policy.types[key].label_th }}
                                <span v-if="policy.types[key].risky" class="ml-1 text-[10px] px-1.5 py-0.5 rounded bg-red-500/20 text-red-300">เสี่ยง</span>
                            </p>
                            <p class="text-xs text-dark-400 mt-0.5">{{ policy.types[key].desc_th }}</p>
                        </div>

                        <span v-if="isLocked('types', key)" class="text-xs px-2 py-1 rounded-lg bg-dark-700 text-dark-300" data-test="locked-badge">🔒 ล็อก</span>
                        <div v-else class="inline-flex rounded-lg border border-white/10 overflow-hidden">
                            <button type="button" :class="stateButton(form.types[key].state === 'off', 'off')" @click="form.types[key].state = 'off'">ปิด</button>
                            <button type="button" :class="stateButton(form.types[key].state === 'on', 'on')" @click="form.types[key].state = 'on'">เปิด</button>
                        </div>
                    </div>

                    <p v-if="isLocked('types', key)" class="mt-2 text-xs text-dark-400">{{ policy.types[key].locked_reason }}</p>
                    <p v-else-if="policy.types[key].risky && form.types[key].state === 'on'" class="mt-2 text-xs text-red-300 bg-red-500/10 border border-red-500/20 rounded-lg p-2">
                        ผู้สร้างจะเห็นคำเตือนนี้และต้องติ๊กยอมรับ: {{ policy.types[key].warning_th }}
                    </p>

                    <div v-if="!isLocked('types', key)" class="mt-3 grid grid-cols-2 sm:grid-cols-6 gap-3">
                        <div>
                            <label :class="labelClass">ค่าธรรมเนียม (TPIX)</label>
                            <input v-model="form.types[key].fee" type="number" min="0" step="any" :class="inputClass" />
                        </div>
                        <div class="sm:col-span-2">
                            <label :class="labelClass">จำนวนสูงสุด</label>
                            <input v-model="form.types[key].max_supply" type="text" inputmode="numeric" :class="inputClass" />
                        </div>
                        <div>
                            <label :class="labelClass">ทศนิยมต่ำสุด</label>
                            <input v-model.number="form.types[key].decimals_min" type="number" min="0" max="18" :class="inputClass" />
                        </div>
                        <div>
                            <label :class="labelClass">ทศนิยมสูงสุด</label>
                            <input v-model.number="form.types[key].decimals_max" type="number" min="0" max="18" :class="inputClass" />
                        </div>
                        <div class="flex flex-col justify-end gap-1.5 text-xs text-dark-300">
                            <label class="flex items-center gap-2"><input v-model="form.types[key].auto_approve" type="checkbox" class="rounded" /> อนุมัติอัตโนมัติ</label>
                            <label class="flex items-center gap-2"><input v-model="form.types[key].require_kyc" type="checkbox" class="rounded" /> ต้อง KYC</label>
                        </div>
                    </div>
                    <p v-if="form.types[key].auto_approve && !isLocked('types', key)" class="mt-2 text-[11px] text-amber-300">
                        อนุมัติอัตโนมัติใช้เฉพาะคำขอที่ไม่มีตัวเลือกเสี่ยง — มีของเสี่ยงต้องรอแอดมินอนุมัติเสมอ
                    </p>
                    <p v-for="field in ['state', 'fee', 'max_supply', 'decimals']" :key="field">
                        <span v-if="errors[`types.${key}.${field}`]" class="text-xs text-red-400">{{ errors[`types.${key}.${field}`] }}</span>
                    </p>
                </div>
            </section>

            <!-- ตัวเลือกย่อย -->
            <section class="rounded-2xl border border-white/10 bg-white/5 p-5 space-y-3">
                <h2 class="text-sm font-semibold text-white">ตัวเลือกย่อย</h2>
                <div
                    v-for="key in optionKeys"
                    :key="key"
                    class="rounded-xl border p-4"
                    :class="isLocked('options', key) ? 'border-white/5 bg-dark-900/40 opacity-60' : 'border-white/10 bg-dark-800/40'"
                    :data-test="`option-${key}`"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-white">
                                {{ policy.options[key].label_th }}
                                <span v-if="policy.options[key].risky" class="ml-1 text-[10px] px-1.5 py-0.5 rounded bg-red-500/20 text-red-300">เสี่ยง</span>
                            </p>
                            <p class="text-xs text-dark-400 mt-0.5">{{ policy.options[key].desc_th }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div v-if="!isLocked('options', key)" class="w-28">
                                <input v-model="form.options[key].fee" type="number" min="0" step="any" :class="inputClass" title="ค่าธรรมเนียม (TPIX)" />
                            </div>
                            <span v-if="isLocked('options', key)" class="text-xs px-2 py-1 rounded-lg bg-dark-700 text-dark-300" data-test="locked-badge">🔒 ล็อก</span>
                            <div v-else class="inline-flex rounded-lg border border-white/10 overflow-hidden">
                                <button type="button" :class="stateButton(form.options[key].state === 'off', 'off')" @click="form.options[key].state = 'off'">ปิด</button>
                                <button type="button" :class="stateButton(form.options[key].state === 'on', 'on')" @click="form.options[key].state = 'on'">เปิด</button>
                            </div>
                        </div>
                    </div>
                    <p v-if="isLocked('options', key)" class="mt-2 text-xs text-dark-400">{{ policy.options[key].locked_reason }}</p>
                    <p v-else-if="policy.options[key].risky && form.options[key].state === 'on'" class="mt-2 text-xs text-red-300 bg-red-500/10 border border-red-500/20 rounded-lg p-2">
                        ผู้สร้างจะเห็นคำเตือนนี้และต้องติ๊กยอมรับ: {{ policy.options[key].warning_th }}
                    </p>
                    <span v-if="errors[`options.${key}.state`]" class="text-xs text-red-400">{{ errors[`options.${key}.state`] }}</span>
                    <span v-if="errors[`options.${key}.fee`]" class="text-xs text-red-400">{{ errors[`options.${key}.fee`] }}</span>
                </div>
            </section>

            <!-- สัญลักษณ์สงวน -->
            <section class="rounded-2xl border border-white/10 bg-white/5 p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-white">สัญลักษณ์สงวน</h2>
                        <p class="text-xs text-dark-400 mt-0.5">ใช้ได้เฉพาะกระเป๋าที่ระบุ (เว้นว่าง = ไม่มีใครใช้ได้) เช่น ใส่กระเป๋าเจ้าของโครงการเพื่อสร้าง IDTC ตัวทางการ</p>
                    </div>
                    <button type="button" class="text-xs px-3 py-1.5 rounded-lg bg-primary-500/10 text-primary-300 hover:bg-primary-500/20" @click="addReserved">+ เพิ่ม</button>
                </div>
                <div v-for="(row, index) in form.reserved_symbols" :key="index" class="grid grid-cols-1 sm:grid-cols-[140px_1fr_auto] gap-3 items-start">
                    <input v-model="row.symbol" type="text" maxlength="20" :class="inputClass" placeholder="SYMBOL" />
                    <textarea v-model="row.wallets" rows="1" :class="[inputClass, 'font-mono text-xs']" placeholder="0x... (หนึ่งกระเป๋าต่อบรรทัด)"></textarea>
                    <button type="button" class="text-xs px-3 py-2 rounded-lg text-red-300 hover:bg-red-500/10" @click="removeReserved(index)">ลบ</button>
                    <p v-if="errors[`reserved_symbols.${index}`]" class="text-xs text-red-400 sm:col-span-3">{{ errors[`reserved_symbols.${index}`] }}</p>
                </div>
            </section>

            <div class="flex items-center justify-end gap-3 pb-10">
                <span class="text-xs text-dark-400">{{ changedCount ? `เปลี่ยน ${changedCount} รายการ (ยังไม่บันทึก)` : 'ไม่มีการเปลี่ยนแปลง' }}</span>
                <button
                    type="button"
                    class="btn-primary px-6 py-2.5 disabled:opacity-40"
                    :disabled="form.processing || changedCount === 0"
                    data-test="save-policy"
                    @click="save"
                >
                    {{ form.processing ? 'กำลังบันทึก...' : 'บันทึกนโยบาย' }}
                </button>
            </div>
        </div>
    </AdminLayout>
</template>
