<script setup>
/**
 * TPIX TRADE - โอนออกจากกระเป๋าคลัง (เซ็นในเบราว์เซอร์แอดมิน)
 *
 * เซิร์ฟเวอร์ไม่มีคีย์คลัง — แอดมินเลือกไฟล์ keystore จากเครื่องตัวเองแล้วใส่รหัสผ่าน
 * ถอดรหัสและเซ็นในหน้านี้ ส่งตรงไปที่ RPC ของเชน ไฟล์/รหัสผ่านไม่ผ่านเซิร์ฟเวอร์ของเรา
 * สมุดบัญชีเก็บรายการนี้เองผ่าน tpix:treasury-sync (อ่านจาก explorer ทุก 5 นาที)
 *
 * กันพลาด: checksum ปลายทาง · กุญแจต้องตรงกระเป๋า · ยอดพอ · ยืนยันก่อนเซ็น ·
 * มี tx hash แล้วห้ามเซ็นซ้ำ · ระหว่างส่งปิดหน้าต่างไม่ได้ · ปิดแล้วล้างรหัสผ่าน/ไฟล์ทิ้ง
 *
 * Developed by Xman Studio
 */
import { ref, computed, watch } from 'vue';
import { Wallet, JsonRpcProvider, formatEther } from 'ethers';
import Modal from '@/Components/Admin/Modal.vue';
import { TPIX_DEX } from '@/Config/dexContracts';
import { findKeystoreFor, normalizeRecipient, parseTpixAmount, friendlySendError } from '@/utils/treasuryColdWallet';

const props = defineProps({
    show: { type: Boolean, default: false },
    wallet: { type: Object, default: null },
    explorerUrl: { type: String, default: '' },
});
const emit = defineEmits(['close', 'sent']);

const GAS_LIMIT = 21000n;
const MAX_KEYSTORE_BYTES = 256 * 1024;

const step = ref('form'); // form → confirm → done
const to = ref('');
const amount = ref('');
const keystoreJson = ref('');
const keystoreName = ref('');
const keystoreError = ref('');
const password = ref('');
const checked = ref(false);
const busy = ref(false);
const progress = ref(0);
const status = ref('');
const error = ref('');
const txHash = ref('');
const mined = ref(false);
const fileInput = ref(null);

// เพิ่มทุกครั้งที่เปิด/ปิด/เปลี่ยนกระเป๋า — งาน async ของรอบเก่าที่ตอบกลับมาทีหลังจะถูกทิ้ง
let session = 0;

function reset() {
    session++;
    step.value = 'form';
    to.value = '';
    amount.value = '';
    keystoreJson.value = '';
    keystoreName.value = '';
    keystoreError.value = '';
    password.value = '';
    checked.value = false;
    busy.value = false;
    progress.value = 0;
    status.value = '';
    error.value = '';
    txHash.value = '';
    mined.value = false;
    if (fileInput.value) fileInput.value.value = '';
}

// แยก source ทีละตัว — หน้าแม่รีเฟรชยอดทุก 30 วิ (object กระเป๋าใหม่ ที่อยู่เดิม) ต้องไม่ล้างฟอร์มกลางทาง
watch([() => props.show, () => props.wallet?.address], reset);

const recipient = computed(() => normalizeRecipient(to.value));
const amountWei = computed(() => parseTpixAmount(amount.value));
const balanceWei = computed(() => (props.wallet?.balance_wei ? BigInt(props.wallet.balance_wei) : null));

const problems = computed(() => {
    const list = [];
    if (to.value && !recipient.value) list.push('ที่อยู่ปลายทางไม่ถูกต้อง (หรือพิมพ์ตัวเล็ก-ใหญ่ผิดตำแหน่ง)');
    if (recipient.value && props.wallet && recipient.value.toLowerCase() === props.wallet.address.toLowerCase()) {
        list.push('ปลายทางเป็นกระเป๋าเดียวกับต้นทาง');
    }
    if (amount.value && !amountWei.value) list.push('จำนวนไม่ถูกต้อง');
    if (balanceWei.value === null) list.push('อ่านยอดกระเป๋านี้ไม่ได้ — กดรีเฟรชก่อน');
    else if (amountWei.value && amountWei.value > balanceWei.value) list.push('จำนวนเกินยอดในกระเป๋า');
    return list;
});

const canReview = computed(() =>
    !!recipient.value && !!amountWei.value && !!keystoreJson.value && password.value.length > 0 && problems.value.length === 0,
);

function fmt(wei) {
    const [whole, frac = ''] = formatEther(wei).split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const trimmed = frac.replace(/0+$/, '');
    return trimmed ? `${grouped}.${trimmed}` : grouped;
}

async function onFile(event) {
    keystoreJson.value = '';
    keystoreError.value = '';
    keystoreName.value = '';
    const file = event.target.files?.[0];
    if (!file || !props.wallet) return;
    keystoreName.value = file.name;
    if (file.size > MAX_KEYSTORE_BYTES) {
        keystoreError.value = 'ไฟล์ใหญ่ผิดปกติ — ไม่ใช่ไฟล์ keystore';
        return;
    }
    const mine = session;
    try {
        const text = await file.text();
        if (mine !== session) return;
        keystoreJson.value = findKeystoreFor(text, props.wallet.address);
    } catch (e) {
        if (mine === session) keystoreError.value = e.message;
    }
}

function review() {
    if (!canReview.value) return;
    error.value = '';
    checked.value = false;
    step.value = 'confirm';
}

async function send() {
    if (busy.value || txHash.value || !canReview.value || !checked.value) return;
    const mine = session;
    busy.value = true;
    error.value = '';
    progress.value = 0;
    let signer = null;

    try {
        status.value = 'กำลังถอดรหัส keystore ในเบราว์เซอร์นี้…';
        signer = await Wallet.fromEncryptedJson(keystoreJson.value, password.value, (p) => {
            if (mine === session) progress.value = Math.round(p * 100);
        });
        password.value = '';
        if (mine !== session) return;
        if (signer.address.toLowerCase() !== props.wallet.address.toLowerCase()) {
            throw new Error('กุญแจที่ถอดได้ไม่ใช่ของกระเป๋านี้');
        }

        status.value = 'กำลังตรวจเชนและยอดคงเหลือ…';
        const provider = new JsonRpcProvider(TPIX_DEX.RPC, Number(TPIX_DEX.CHAIN_ID), { staticNetwork: true });
        const chainId = Number(await provider.send('eth_chainId', []));
        if (chainId !== Number(TPIX_DEX.CHAIN_ID)) throw new Error(`RPC ตอบ chainId ${chainId} ไม่ใช่ ${TPIX_DEX.CHAIN_ID}`);
        // เชน TPIX ไม่มี baseFee และ eth_gasPrice = 0 — ใช้ค่าที่เชนบอก ไม่เดาเอง
        const gasPrice = BigInt(await provider.send('eth_gasPrice', []));
        const live = await provider.getBalance(signer.address);
        if (live < amountWei.value + gasPrice * GAS_LIMIT) throw new Error('insufficient funds');
        if (mine !== session) return;

        status.value = 'กำลังส่งธุรกรรม…';
        const tx = await signer.connect(provider).sendTransaction({
            to: recipient.value,
            value: amountWei.value,
            type: 0,
            gasPrice,
            gasLimit: GAS_LIMIT,
        });
        // จด hash ทันทีที่ส่ง — จากนี้หน้านี้จะไม่เซ็นซ้ำไม่ว่าจะเกิดอะไรขึ้น
        txHash.value = tx.hash;
        step.value = 'done';
        status.value = 'ส่งแล้ว รอยืนยันในบล็อก…';

        const receipt = await tx.wait(1, 120_000);
        if (mine !== session) return;
        if (receipt?.status === 1) {
            mined.value = true;
            status.value = `ยืนยันแล้วในบล็อก #${receipt.blockNumber}`;
            emit('sent', tx.hash);
        } else {
            error.value = 'เชนรับธุรกรรมแต่ทำรายการไม่สำเร็จ — ตรวจที่ explorer';
        }
    } catch (e) {
        if (mine !== session) return;
        const reason = friendlySendError(e);
        if (txHash.value) {
            error.value = `ส่งแล้วแต่ยังไม่ได้รับการยืนยัน (${reason}) — ตรวจที่ explorer ก่อน ห้ามส่งซ้ำ`;
        } else {
            // ยังไม่ได้ส่ง → กลับไปแก้ได้ (รหัสผ่านถูกล้างแล้ว ต้องพิมพ์ใหม่)
            error.value = reason;
            step.value = 'form';
        }
    } finally {
        signer = null;
        password.value = '';
        if (mine === session) busy.value = false;
    }
}
</script>

<template>
    <Modal :show="show" :closeable="!busy" :title="wallet ? `โอนออก — ${wallet.role_th}` : ''" max-width="lg" @close="emit('close')">
        <div v-if="wallet" class="space-y-4 text-sm">
            <div class="rounded-lg border border-cyan-500/30 bg-cyan-500/5 p-3 text-xs text-cyan-100 leading-relaxed">
                เซ็นในเบราว์เซอร์นี้เท่านั้น — <b>ไฟล์ keystore และรหัสผ่านไม่ถูกส่งไปที่เซิร์ฟเวอร์</b>
                ใช้บนเครื่องที่ไว้ใจได้ และปิดหน้าต่างทันทีเมื่อโอนเสร็จ
            </div>

            <!-- ต้นทาง -->
            <div class="flex items-start justify-between gap-3 rounded-lg bg-white/5 border border-white/10 p-3">
                <div class="min-w-0">
                    <div class="text-xs text-gray-500">จากกระเป๋า</div>
                    <div class="text-white font-semibold">{{ wallet.role_th }}</div>
                    <div class="font-mono text-xs text-gray-400 break-all">{{ wallet.address }}</div>
                </div>
                <div class="text-right shrink-0">
                    <div class="text-xs text-gray-500">ยอดคงเหลือ</div>
                    <div class="text-white font-bold">{{ balanceWei !== null ? fmt(balanceWei) : '—' }}</div>
                </div>
            </div>

            <!-- ขั้น 1: กรอก -->
            <template v-if="step === 'form'">
                <label class="block">
                    <span class="text-xs text-gray-400">ที่อยู่ปลายทาง (เชน TPIX 4289)</span>
                    <input v-model="to" type="text" spellcheck="false" autocomplete="off" placeholder="0x…"
                           class="mt-1 w-full font-mono text-sm px-3 py-2 rounded-lg bg-dark-800 border border-white/10 text-white focus:border-primary-500/50 focus:outline-none" />
                </label>

                <label class="block">
                    <span class="text-xs text-gray-400">จำนวน (TPIX)</span>
                    <input v-model="amount" type="text" inputmode="decimal" autocomplete="off" placeholder="เช่น 210"
                           class="mt-1 w-full text-sm px-3 py-2 rounded-lg bg-dark-800 border border-white/10 text-white focus:border-primary-500/50 focus:outline-none" />
                </label>

                <div class="block">
                    <span class="text-xs text-gray-400">ไฟล์ keystore (เลือกไฟล์เดี่ยว หรือ master-wallet.keystores.json ก็ได้)</span>
                    <input ref="fileInput" type="file" accept=".json,application/json" @change="onFile"
                           class="mt-1 block w-full text-xs text-gray-300 file:mr-3 file:px-3 file:py-1.5 file:rounded-lg file:border-0 file:bg-white/10 file:text-gray-200 hover:file:bg-white/20" />
                    <p v-if="keystoreJson" class="mt-1 text-xs text-emerald-400">&#10003; พบ keystore ของกระเป๋านี้ใน {{ keystoreName }}</p>
                    <p v-else-if="keystoreError" class="mt-1 text-xs text-red-400">{{ keystoreError }}</p>
                </div>

                <label class="block">
                    <span class="text-xs text-gray-400">รหัสผ่าน keystore</span>
                    <input v-model="password" type="password" autocomplete="off"
                           class="mt-1 w-full text-sm px-3 py-2 rounded-lg bg-dark-800 border border-white/10 text-white focus:border-primary-500/50 focus:outline-none" />
                </label>

                <ul v-if="problems.length" class="space-y-1 text-xs text-amber-300">
                    <li v-for="p in problems" :key="p">&#9888; {{ p }}</li>
                </ul>
            </template>

            <!-- ขั้น 2: ตรวจก่อนเซ็น -->
            <template v-else-if="step === 'confirm'">
                <div class="rounded-lg border border-amber-500/40 bg-amber-500/5 p-4 space-y-3">
                    <div class="text-amber-300 font-bold">ตรวจให้แน่ใจก่อนเซ็น — โอนแล้วเรียกคืนไม่ได้</div>
                    <div>
                        <div class="text-xs text-gray-400">ไปที่</div>
                        <div class="font-mono text-white break-all text-sm">{{ recipient }}</div>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-400">จำนวน</span>
                        <span class="text-white font-bold">{{ fmt(amountWei) }} TPIX</span>
                    </div>
                    <div class="flex justify-between text-xs">
                        <span class="text-gray-500">คงเหลือหลังโอน (โดยประมาณ)</span>
                        <span class="text-gray-300">{{ fmt(balanceWei - amountWei) }} TPIX</span>
                    </div>
                    <label class="flex items-start gap-2 pt-1 cursor-pointer">
                        <input v-model="checked" type="checkbox" :disabled="busy" class="mt-0.5" />
                        <span class="text-xs text-gray-200">ตรวจที่อยู่ปลายทางครบทุกตัวอักษรและจำนวนแล้ว</span>
                    </label>
                </div>

                <div v-if="busy" class="space-y-2">
                    <div class="text-xs text-gray-400">{{ status }}</div>
                    <div class="h-1.5 rounded-full bg-white/5 overflow-hidden">
                        <div class="h-full bg-primary-500 transition-all" :style="{ width: `${progress}%` }" />
                    </div>
                </div>
            </template>

            <!-- ขั้น 3: ผล -->
            <template v-else>
                <div :class="['rounded-lg border p-4 space-y-2', mined ? 'border-emerald-500/40 bg-emerald-500/5' : 'border-cyan-500/30 bg-cyan-500/5']">
                    <div :class="mined ? 'text-emerald-300 font-bold' : 'text-cyan-200 font-bold'">{{ status }}</div>
                    <div class="text-xs text-gray-400">tx</div>
                    <a :href="`${explorerUrl}/tx/${txHash}`" target="_blank" rel="noopener"
                       class="block font-mono text-xs text-primary-400 break-all hover:underline">{{ txHash }}</a>
                    <p class="text-xs text-gray-500">สมุดบัญชีจะเห็นรายการนี้เองภายในประมาณ 5 นาที</p>
                </div>
            </template>

            <p v-if="error" class="rounded-lg border border-red-500/30 bg-red-500/5 p-3 text-xs text-red-300">{{ error }}</p>
        </div>

        <template #footer>
            <div class="flex justify-end gap-3">
                <template v-if="step === 'form'">
                    <button type="button" @click="emit('close')"
                            class="px-4 py-2 text-sm rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10">ยกเลิก</button>
                    <button type="button" :disabled="!canReview" @click="review"
                            class="px-4 py-2 text-sm font-medium rounded-xl bg-primary-500/20 text-primary-300 border border-primary-500/30 hover:bg-primary-500/30 disabled:opacity-40 disabled:cursor-not-allowed">ตรวจรายการ</button>
                </template>
                <template v-else-if="step === 'confirm'">
                    <button type="button" :disabled="busy" @click="step = 'form'"
                            class="px-4 py-2 text-sm rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 disabled:opacity-40">กลับไปแก้</button>
                    <button type="button" :disabled="busy || !checked" @click="send"
                            class="px-4 py-2 text-sm font-bold rounded-xl bg-amber-500/20 text-amber-200 border border-amber-500/40 hover:bg-amber-500/30 disabled:opacity-40 disabled:cursor-not-allowed">
                        {{ busy ? 'กำลังเซ็นและส่ง…' : 'เซ็นและส่ง' }}
                    </button>
                </template>
                <button v-else type="button" :disabled="busy" @click="emit('close')"
                        class="px-4 py-2 text-sm rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 disabled:opacity-40">ปิด</button>
            </div>
        </template>
    </Modal>
</template>
