<script setup>
/**
 * FoodPassport Hero — Banner section พร้อมสถิติ
 */
defineProps({
    stats: { type: Object, default: () => ({}) },
});
</script>

<template>
    <div class="relative overflow-hidden bg-gradient-to-br from-green-900/30 via-dark-900 to-cyan-900/20 pb-16">
        <!-- Background Pattern -->
        <div class="absolute inset-0 opacity-5">
            <div class="absolute top-10 left-10 text-8xl">🌾</div>
            <div class="absolute top-20 right-20 text-6xl">🥬</div>
            <div class="absolute bottom-10 left-1/3 text-7xl">🐟</div>
            <div class="absolute bottom-20 right-10 text-5xl">📡</div>
        </div>

        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-12 pb-8">
            <div class="flex flex-col lg:flex-row items-center gap-8">
                <!-- Left: Text -->
                <div class="flex-1 text-center lg:text-left">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-green-500/10 border border-green-500/20 mb-4">
                        <span class="w-2 h-2 rounded-full bg-green-400 animate-pulse"></span>
                        <span class="text-green-400 text-xs font-medium">Powered by TPIX Chain + IoT</span>
                    </div>

                    <h1 class="text-3xl lg:text-5xl font-bold text-white mb-4">
                        Food<span class="text-transparent bg-clip-text bg-gradient-to-r from-green-400 to-cyan-400">Passport</span>
                    </h1>

                    <p class="text-dark-300 text-lg mb-2">
                        ตรวจสอบที่มาอาหารบน Blockchain
                    </p>
                    <p class="text-dark-500 text-sm max-w-lg">
                        ติดตามเส้นทางอาหารตั้งแต่ฟาร์มถึงมือคุณ ด้วย IoT sensors + NFT ใบรับรอง
                        บน TPIX Chain — โปร่งใส ปลอดภัย แก้ไขไม่ได้
                    </p>
                </div>

                <!-- Right: Visual -->
                <div class="flex-shrink-0">
                    <div class="relative w-64 h-64">
                        <!-- Journey Circle -->
                        <svg viewBox="0 0 200 200" class="w-full h-full">
                            <circle cx="100" cy="100" r="90" fill="none" stroke="rgba(255,255,255,0.05)" stroke-width="2"/>
                            <circle cx="100" cy="100" r="90" fill="none" stroke="url(#gradient)" stroke-width="3"
                                stroke-dasharray="565" stroke-dashoffset="141" stroke-linecap="round"
                                class="animate-[spin_20s_linear_infinite]" style="transform-origin: center"/>
                            <defs>
                                <linearGradient id="gradient" x1="0%" y1="0%" x2="100%">
                                    <stop offset="0%" stop-color="#22C55E"/>
                                    <stop offset="50%" stop-color="#06B6D4"/>
                                    <stop offset="100%" stop-color="#8B5CF6"/>
                                </linearGradient>
                            </defs>
                        </svg>
                        <!-- Stage Icons -->
                        <div class="absolute top-2 left-1/2 -translate-x-1/2 text-3xl">🌱</div>
                        <div class="absolute top-1/2 right-2 -translate-y-1/2 text-3xl">🏭</div>
                        <div class="absolute bottom-2 left-1/2 -translate-x-1/2 text-3xl">🚛</div>
                        <div class="absolute top-1/2 left-2 -translate-y-1/2 text-3xl">🏪</div>
                        <!-- Center -->
                        <div class="absolute inset-0 flex items-center justify-center">
                            <div class="text-center">
                                <p class="text-4xl">🛡️</p>
                                <p class="text-white text-xs font-bold mt-1">Verified</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
