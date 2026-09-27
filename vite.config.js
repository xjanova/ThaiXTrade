import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            publicDirectory: 'public_html',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
            '@components': resolve(__dirname, 'resources/js/Components'),
            '@pages': resolve(__dirname, 'resources/js/Pages'),
            '@layouts': resolve(__dirname, 'resources/js/Layouts'),
            '@composables': resolve(__dirname, 'resources/js/Composables'),
            '@stores': resolve(__dirname, 'resources/js/Stores'),
            '@utils': resolve(__dirname, 'resources/js/Utils'),
        },
    },
    build: {
        chunkSizeWarningLimit: 1000,
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (id.includes('node_modules/vue/') || id.includes('node_modules/pinia/') || id.includes('node_modules/@inertiajs/')) {
                        return 'vendor';
                    }
                    if (id.includes('node_modules/lightweight-charts/') || id.includes('node_modules/apexcharts/')) {
                        return 'charts';
                    }
                    if (id.includes('node_modules/ethers/') || id.includes('node_modules/web3/')) {
                        return 'web3';
                    }
                    // three.js (~170 KB gzip) แยกก้อนของตัวเอง: แก้โค้ดหน้าแรก 3D แล้ว deploy
                    // ผู้ใช้โหลดใหม่แค่ก้อนโค้ดเรา ส่วน three.js ใช้ที่แคชไว้ต่อ (ชื่อไฟล์ไม่เปลี่ยนจนกว่าจะอัปเดตเวอร์ชัน three)
                    // ยังโหลดเฉพาะเครื่องที่ได้หน้า 3D เหมือนเดิม — ไม่มีใครนำเข้าแบบ static จากหน้าอื่น
                    if (id.includes('node_modules/three/')) {
                        return 'three';
                    }
                },
            },
        },
    },
    server: {
        hmr: {
            host: 'localhost',
        },
    },
});
