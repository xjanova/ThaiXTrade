import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

/**
 * document root ของโปรเจกต์นี้คือ public_html/ ไม่ใช่ public/
 * คอมโพเนนต์จึงอ้างรูปด้วย path ของ document root เช่น <img src="/tpixlogo.webp">
 *
 * ตอน build จริง vite-plugin-laravel รู้จักโฟลเดอร์นี้ แต่ตอนรัน vitest ไม่รู้ —
 * vue compiler แปลง src เป็น `import _imports_0 from '/tpixlogo.webp'` แล้ว resolve
 * ไม่เจอ ล้มทั้งไฟล์เทสต์ ทั้งที่รูปไม่เกี่ยวกับสิ่งที่ทดสอบเลย
 * (alias ธรรมดาดักไม่ได้ เพราะ id ขึ้นต้นด้วย / จึงถูกมองเป็น path ของ fs)
 */
const publicAssetStub = {
    name: 'tpix-public-asset-stub',
    enforce: 'pre',
    resolveId(id) {
        return /^\/[^/].*\.(png|jpe?g|gif|svg|webp|avif)$/.test(id) ? '\0public-asset-stub' : null;
    },
    load(id) {
        return id === '\0public-asset-stub' ? 'export default "test-asset-stub"' : null;
    },
};

export default defineConfig({
    plugins: [publicAssetStub, vue()],
    test: {
        globals: true,
        environment: 'jsdom',
        include: ['tests/js/**/*.{test,spec}.{js,ts}'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'json', 'html'],
            reportsDirectory: './coverage-js',
            include: ['resources/js/**/*.{js,vue}'],
            exclude: ['resources/js/app.js'],
        },
        setupFiles: ['./tests/js/setup.js'],
    },
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
            '@components': resolve(__dirname, 'resources/js/Components'),
            '@pages': resolve(__dirname, 'resources/js/Pages'),
            '@layouts': resolve(__dirname, 'resources/js/Layouts'),
        },
    },
});
