/**
 * TPIX TRADE — detectWalletBrowser(): เปิดเว็บอยู่ "ในแอปกระเป๋า" (dapp browser บนมือถือ) หรือไม่
 *
 * เจ้าของเจอจริง 2026-10-22: "การเชื่อมต่อด้วย tpix wallet อยู่ไหน บนหน้าเว็บทำไมเห็นแต่ metamask"
 * คอมที่ติดตั้งส่วนขยาย MetaMask (และ Brave / Rabby ที่ตั้ง isMetaMask = true เพื่อให้เข้ากันได้)
 * ถูกนับว่าอยู่ในแอป MetaMask → หน้าต่างเชื่อมกระเป๋าซ่อนการ์ด TPIX Wallet และกระเป๋าอื่นทั้งหมด
 *
 * ส่วนขยายบนคอมไม่ใช่ "แอปกระเป๋า" — นับเฉพาะบนมือถือ
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { detectWalletBrowser } from '@/utils/mobileWallet';

const UA = {
    desktopChrome: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
    androidChrome: 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
    metamaskAndroid: 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36 MetaMaskMobile',
    coinbaseIos: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 CoinbaseBrowser/28.0',
};

function useUserAgent(ua) {
    vi.spyOn(navigator, 'userAgent', 'get').mockReturnValue(ua);
}

afterEach(() => {
    vi.restoreAllMocks();
    delete window.ethereum;
    delete window.trustwallet;
    delete window.okxwallet;
});

describe('คอม: ส่วนขยายกระเป๋าไม่ใช่แอปกระเป๋า', () => {
    it('ส่วนขยาย MetaMask (window.ethereum.isMetaMask) → null', () => {
        useUserAgent(UA.desktopChrome);
        window.ethereum = { isMetaMask: true };

        expect(detectWalletBrowser()).toBeNull();
    });

    it('Brave / Rabby ที่ตั้ง isMetaMask = true เพื่อให้เข้ากันได้ → null', () => {
        useUserAgent(UA.desktopChrome);
        window.ethereum = { isMetaMask: true, isBraveWallet: true };

        expect(detectWalletBrowser()).toBeNull();
    });

    it('ส่วนขยาย Trust / OKX → null', () => {
        useUserAgent(UA.desktopChrome);
        window.trustwallet = {};
        window.okxwallet = {};

        expect(detectWalletBrowser()).toBeNull();
    });
});

describe('มือถือ: ในแอปกระเป๋าจริงเท่านั้น', () => {
    it('Chrome บนมือถือ ไม่มี provider → null', () => {
        useUserAgent(UA.androidChrome);

        expect(detectWalletBrowser()).toBeNull();
    });

    it('เบราว์เซอร์ในแอป MetaMask → metamask', () => {
        useUserAgent(UA.metamaskAndroid);
        window.ethereum = { isMetaMask: true };

        expect(detectWalletBrowser()).toBe('metamask');
    });

    it('Brave บนมือถือ (กระเป๋าในตัว ตั้ง isMetaMask = true) → null', () => {
        useUserAgent(UA.androidChrome);
        window.ethereum = { isMetaMask: true, isBraveWallet: true };

        expect(detectWalletBrowser()).toBeNull();
    });

    it('แอป Trust ที่ตั้ง isMetaMask = true ด้วย → trustwallet ไม่ใช่ metamask', () => {
        useUserAgent(UA.androidChrome);
        window.ethereum = { isMetaMask: true, isTrust: true };

        expect(detectWalletBrowser()).toBe('trustwallet');
    });

    it('เบราว์เซอร์ในแอป Coinbase (iPhone) → coinbase', () => {
        useUserAgent(UA.coinbaseIos);

        expect(detectWalletBrowser()).toBe('coinbase');
    });
});
