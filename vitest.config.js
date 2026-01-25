import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

export default defineConfig({
    plugins: [vue()],
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
