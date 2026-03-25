<script setup>
/**
 * MintCertificate — Modal สำหรับ Mint NFT ใบรับรอง
 * กดปุ่มเดียว → ได้ NFT บน TPIX Chain
 */
import { ref, computed } from 'vue';

const props = defineProps({
    product: { type: Object, required: true },
    walletAddress: { type: String, required: true },
    contractAddress: { type: String, default: '' },
});

const emit = defineEmits(['close', 'minted']);

const loading = ref(false);
const error = ref(null);
const step = ref(1); // 1=confirm, 2=minting, 3=done
const mintedCertificate = ref(null);

const CONTRACT_ADDRESS = props.contractAddress || import.meta.env.VITE_FOOD_PASSPORT_NFT_ADDRESS || '';

async function mintNFT() {
    step.value = 2;
    loading.value = true;
    error.value = null;

    try {
        // Simulate token ID (in production: call smart contract first)
        const tokenId = Math.floor(Math.random() * 100000) + 1;

        const res = await fetch(`/api/v1/food-passport/mint/${props.product.id}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Wallet-Address': props.walletAddress,
            },
            body: JSON.stringify({
                owner_address: props.walletAddress,
                token_id: tokenId,
                contract_address: CONTRACT_ADDRESS,
                token_uri: `https://api.tpixtrade.com/food-passport/metadata/${props.product.id}`,
            }),
        });

        const json = await res.json();

        if (json.success) {
            mintedCertificate.value = json.data;
            step.value = 3;
            emit('minted', json.data);
        } else {
            error.value = json.error?.message || 'Mint ไม่สำเร็จ';
            step.value = 1;
        }
    } catch (e) {
        error.value = e.message;
        step.value = 1;
    }

    loading.value = false;
}
</script>

<template>
    <div class="modal-content max-w-lg">
        <!-- Step 1: Confirm -->
        <template v-if="step === 1">
            <div class="flex items-center justify-between mb-5">
                <h2 class="text-xl font-bold text-white">Mint NFT ใบรับรอง</h2>
                <button @click="emit('close')" class="p-2 rounded-xl text-dark-400 hover:text-white hover:bg-white/10">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <!-- Product Summary -->
            <div class="p-4 rounded-xl bg-dark-800/50 border border-white/5 mb-5">
                <p class="text-white font-semibold">{{ product.name }}</p>
                <p class="text-dark-400 text-sm">{{ product.origin }} | Batch: {{ product.batch_number }}</p>
                <p class="text-dark-500 text-xs mt-1">Checkpoints: {{ product.traces_count || 'N/A' }}</p>
            </div>

            <!-- What you'll get -->
            <div class="space-y-3 mb-5">
                <h3 class="text-sm font-medium text-dark-300">คุณจะได้รับ:</h3>
                <div class="flex items-center gap-3 p-3 rounded-lg bg-purple-500/5 border border-purple-500/10">
                    <span class="text-2xl">🏆</span>
                    <div>
                        <p class="text-white text-sm font-medium">NFT Certificate (ERC-721)</p>
                        <p class="text-dark-400 text-xs">ใบรับรองดิจิทัลบน TPIX Chain — ยืนยันว่าสินค้าผ่านมาตรฐาน</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 p-3 rounded-lg bg-cyan-500/5 border border-cyan-500/10">
                    <span class="text-2xl">⛓️</span>
                    <div>
                        <p class="text-white text-sm font-medium">บันทึกบน Blockchain ถาวร</p>
                        <p class="text-dark-400 text-xs">NFT เก็บข้อมูลเส้นทางอาหาร + IoT data ทั้งหมด</p>
                    </div>
                </div>
            </div>

            <!-- Info -->
            <div class="p-3 rounded-xl bg-green-500/5 border border-green-500/10 mb-5">
                <p class="text-green-400 text-sm">Gas FREE — ไม่มีค่าธรรมเนียม บน TPIX Chain</p>
            </div>

            <div v-if="error" class="p-3 rounded-xl bg-trading-red/10 text-trading-red text-sm mb-4">{{ error }}</div>

            <!-- Actions -->
            <div class="flex gap-3">
                <button @click="emit('close')" class="flex-1 py-3 text-dark-400 hover:text-white text-sm">ยกเลิก</button>
                <button @click="mintNFT"
                    class="flex-1 py-3 bg-gradient-to-r from-purple-500 to-cyan-500 text-white rounded-xl font-bold text-sm hover:from-purple-600 hover:to-cyan-600 shadow-lg shadow-purple-500/25">
                    Mint Certificate
                </button>
            </div>
        </template>

        <!-- Step 2: Minting -->
        <template v-if="step === 2">
            <div class="py-12 text-center">
                <div class="spinner mx-auto mb-4 w-12 h-12"></div>
                <p class="text-white font-medium">กำลัง Mint NFT...</p>
                <p class="text-dark-400 text-sm mt-1">บันทึกใบรับรองบน TPIX Chain</p>
            </div>
        </template>

        <!-- Step 3: Done -->
        <template v-if="step === 3">
            <div class="py-8 text-center">
                <div class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-green-500/20 to-cyan-500/20 flex items-center justify-center mb-4">
                    <span class="text-5xl">🏆</span>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">Mint สำเร็จ!</h3>
                <p class="text-dark-400 text-sm mb-4">NFT ใบรับรองถูกสร้างบน TPIX Chain แล้ว</p>

                <div v-if="mintedCertificate" class="p-4 rounded-xl bg-dark-800/50 border border-white/5 text-left mb-5">
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-dark-400">Token ID:</span>
                            <span class="text-white font-mono">#{{ mintedCertificate.token_id }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-dark-400">Owner:</span>
                            <span class="text-white font-mono text-xs">{{ mintedCertificate.owner_address?.slice(0, 10) }}...</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-dark-400">Chain:</span>
                            <span class="text-primary-400">TPIX Chain (4289)</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-dark-400">Status:</span>
                            <span class="text-trading-green">Active</span>
                        </div>
                    </div>
                </div>

                <button @click="emit('close')" class="w-full py-3 bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-medium text-sm">
                    ปิด
                </button>
            </div>
        </template>
    </div>
</template>
