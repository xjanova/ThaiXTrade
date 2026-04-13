/**
 * Polyfills for React Native (Hermes engine)
 * Must be imported BEFORE ethers.js or any crypto library
 *
 * React Native ไม่มี crypto.getRandomValues() ซึ่ง ethers.js ต้องการ
 * ไฟล์นี้ต้อง import ก่อน ethers.js ทุกที่
 */
import { getRandomValues } from 'expo-crypto';

// Polyfill crypto.getRandomValues for ethers.js
if (typeof globalThis.crypto === 'undefined') {
  (globalThis as any).crypto = {};
}
if (typeof globalThis.crypto.getRandomValues !== 'function') {
  (globalThis as any).crypto.getRandomValues = getRandomValues;
}
