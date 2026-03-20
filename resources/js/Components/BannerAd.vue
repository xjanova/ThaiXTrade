<script setup>
/**
 * TPIX TRADE — BannerAd Component
 * Universal banner component — รองรับ Image, Google AdSense, Custom HTML
 * วางได้ทุกหน้า ดึงข้อมูลจาก API ตาม placement
 * Developed by Xman Studio.
 */
import { ref, onMounted, onUnmounted, nextTick } from 'vue';
import axios from 'axios';

const props = defineProps({
    placement: { type: String, required: true },
    class: { type: String, default: '' },
});

const banners = ref([]);
const currentIndex = ref(0);
let rotateInterval = null;

// ดึง banners จาก API ตาม placement
async function loadBanners() {
    try {
        const { data } = await axios.get(`/api/v1/banners?placement=${props.placement}`);
        if (data.success && data.data?.length) {
            banners.value = data.data;
        }
    } catch {
        // ไม่แสดง error — ถ้า API ไม่ตอบก็ไม่แสดง banner
    }
}

// นับ click
async function trackClick(banner) {
    try {
        await axios.post(`/api/v1/banners/${banner.id}/click`);
    } catch {
        // ignore
    }
}

// Auto-rotate ถ้ามีหลาย banners
function startRotation() {
    if (banners.value.length > 1) {
        rotateInterval = setInterval(() => {
            currentIndex.value = (currentIndex.value + 1) % banners.value.length;
            injectAdSense();
        }, 5000);
    }
}

// Inject Google AdSense script ถ้า type เป็น google_adsense
function injectAdSense() {
    nextTick(() => {
        const current = banners.value[currentIndex.value];
        if (current?.type === 'google_adsense') {
            try {
                (window.adsbygoogle = window.adsbygoogle || []).push({});
            } catch {
                // AdSense not loaded
            }
        }
    });
}

onMounted(async () => {
    await loadBanners();
    startRotation();
    injectAdSense();
});

onUnmounted(() => {
    if (rotateInterval) clearInterval(rotateInterval);
});
</script>

<template>
    <div v-if="banners.length" :class="props.class">
        <template v-for="(banner, idx) in banners" :key="banner.id">
            <div v-show="idx === currentIndex" class="banner-ad">
                <!-- Type: Image + Link -->
                <a v-if="banner.type === 'image' && banner.image_url"
                    :href="banner.link_url || '#'"
                    :target="banner.target || '_blank'"
                    rel="noopener sponsored"
                    @click="trackClick(banner)"
                    class="block">
                    <img :src="banner.image_url"
                        :alt="banner.title"
                        class="w-full rounded-lg object-cover max-h-24"
                        loading="lazy" />
                </a>

                <!-- Type: Google AdSense -->
                <div v-else-if="banner.type === 'google_adsense' && banner.ad_code"
                    v-html="banner.ad_code"
                    class="adsense-container">
                </div>

                <!-- Type: Custom HTML -->
                <div v-else-if="banner.type === 'html' && banner.ad_code"
                    v-html="banner.ad_code"
                    class="custom-html-banner">
                </div>
            </div>
        </template>

        <!-- Dots indicator ถ้ามีหลาย banners -->
        <div v-if="banners.length > 1" class="flex justify-center gap-1.5 mt-2">
            <button v-for="(_, idx) in banners" :key="idx"
                @click="currentIndex = idx"
                :class="idx === currentIndex ? 'bg-primary-400' : 'bg-dark-600'"
                class="w-1.5 h-1.5 rounded-full transition-colors">
            </button>
        </div>
    </div>
</template>
