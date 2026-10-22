<script setup>
/**
 * TPIX TRADE — การ์ด "เชื่อมด้วยแอป TPIX Wallet" (ตัวเลือกอันดับ 1 ทุกที่ที่ให้เลือกวิธีเชื่อม/เข้าสู่ระบบ)
 *
 * เจ้าของสั่ง 2026-10-22: "ให้นำระบบเชื่อมต่อกระดานเทรดด้วย wallet app มาเป็นอันดับ 1 ในวิธีเชื่อมต่อ
 * และปรับปรุงโลโก้ให้สวยเด่น คือเน้นให้ใช้แอปมากกว่า"
 *
 * โลโก้ = ไอคอนแอปตัวจริงที่อยู่บนหน้าจอมือถือ (ดู TPIX_WALLET_APP_ICON) — ผู้ใช้จำรูปนี้ได้ทันที
 * คอมพิวเตอร์ = สแกน QR ด้วยแอป · มือถือ = เปิดแอป · ยังไม่มีแอป = หน้าดาวน์โหลดของเราเอง
 *
 * ตัวนี้แค่วาดและบอกว่าผู้ใช้กดเชื่อม (emit connect) — การเชื่อมจริงอยู่ที่ WalletModal / walletStore
 *
 * Developed by Xman Studio
 */

import { useTranslation } from '@/Composables/useTranslation';
import { TPIX_WALLET_APP_ICON } from '@/utils/brand';

defineProps({
    /** มือถือ = ปุ่ม "เปิดแอป" แทน "สแกน QR" */
    mobile: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
    /** หน้าเข้าสู่ระบบ — หัวการ์ดบอกว่า "เข้าสู่ระบบด้วย…" */
    signIn: { type: Boolean, default: false },
});

const emit = defineEmits(['connect']);
const { t } = useTranslation();
</script>

<template>
    <div class="tpix-app-card relative overflow-hidden glass-brand p-4 sm:p-5 shadow-glow-brand" data-testid="tpix-app-card">
        <!-- แสงเรือง ๆ ด้านหลัง ให้การ์ดนี้เด่นกว่าตัวเลือกอื่น -->
        <div class="pointer-events-none absolute -top-12 -right-10 w-44 h-44 rounded-full bg-primary-500/20 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-14 -left-12 w-44 h-44 rounded-full bg-accent-500/15 blur-3xl"></div>

        <div class="relative flex items-center gap-4">
            <img
                :src="TPIX_WALLET_APP_ICON"
                alt="TPIX Wallet"
                width="64"
                height="64"
                class="w-16 h-16 rounded-2xl ring-1 ring-white/15 shadow-lg shadow-primary-500/25 flex-shrink-0"
                data-testid="tpix-app-icon"
            />
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 flex-wrap">
                    <p class="text-lg font-bold text-white leading-tight">
                        {{ signIn ? t('wallet.app.signInTitle') : 'TPIX Wallet' }}
                    </p>
                    <span
                        class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide text-white bg-gradient-to-r from-accent-500 to-primary-500 shadow-glow-sm"
                        data-testid="tpix-app-badge"
                    >
                        {{ t('wallet.app.recommended') }}
                    </span>
                </div>
                <p class="text-xs text-dark-300 mt-1">{{ t('wallet.app.tagline') }}</p>
            </div>
        </div>

        <button
            type="button"
            class="btn-brand relative w-full mt-4 py-3 text-sm"
            :disabled="busy"
            data-testid="tpix-app-connect"
            @click="emit('connect')"
        >
            <!-- มือถือ: รูปโทรศัพท์ · คอม: รูป QR -->
            <svg v-if="mobile" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
            </svg>
            <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 0h2m4 0v2m-6 2v2m4-2h2v2" />
            </svg>
            {{ mobile ? t('wallet.app.openApp') : t('wallet.app.scanQr') }}
        </button>

        <p class="relative text-center text-xs text-dark-400 mt-3">
            {{ t('wallet.app.noApp') }}
            <a href="/download" class="text-primary-400 hover:text-primary-300 font-medium" data-testid="tpix-app-download">
                {{ t('wallet.app.download') }}
            </a>
        </p>
    </div>
</template>
