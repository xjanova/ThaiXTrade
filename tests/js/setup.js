/**
 * TPIX TRADE - Vitest Setup
 * Developed by Xman Studio
 */

import { config } from '@vue/test-utils';

/**
 * ตรึงภาษาของเทสต์เป็นอังกฤษ
 * useTranslation อ่าน locale ตอนโหลดโมดูล และ setupFiles รันก่อนไฟล์เทสต์เสมอ
 * จึงต้องตั้งที่นี่ ไม่ใช่ในไฟล์เทสต์ (ไม่งั้นอ่านค่าไปแล้ว)
 * การสลับภาษาเองมีเทสต์แยกที่ tests/js/i18n.test.js
 */
localStorage.setItem('tpix_locale', 'en');

// Global mocks
global.ResizeObserver = class ResizeObserver {
    observe() {}
    unobserve() {}
    disconnect() {}
};

// Mock IntersectionObserver
global.IntersectionObserver = class IntersectionObserver {
    constructor() {}
    observe() {}
    unobserve() {}
    disconnect() {}
};

// Mock window.matchMedia
Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: (query) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: () => {},
        removeListener: () => {},
        addEventListener: () => {},
        removeEventListener: () => {},
        dispatchEvent: () => {},
    }),
});

// Mock Ethereum provider
global.ethereum = {
    isMetaMask: true,
    request: async ({ method }) => {
        if (method === 'eth_chainId') return '0x38'; // BSC
        if (method === 'eth_accounts') return [];
        if (method === 'eth_requestAccounts') return ['0x1234567890123456789012345678901234567890'];
        return null;
    },
    on: () => {},
    removeListener: () => {},
};

// Configure Vue Test Utils
config.global.mocks = {
    $route: {
        path: '/',
        params: {},
        query: {},
    },
    $router: {
        push: () => {},
        replace: () => {},
    },
};

// Mock Inertia
config.global.mocks.$inertia = {
    visit: () => {},
    get: () => {},
    post: () => {},
};

config.global.stubs = {
    Link: {
        template: '<a><slot /></a>',
    },
    Head: {
        template: '<div></div>',
    },
};
