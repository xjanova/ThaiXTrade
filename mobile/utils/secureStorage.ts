/**
 * Secure Storage Wrapper — Platform-aware
 *
 * Native (iOS/Android): ใช้ expo-secure-store (encrypted by OS)
 * Web: fallback เป็น AsyncStorage (สำหรับ dev/preview เท่านั้น)
 *
 * ⚠️ บน web ข้อมูลไม่ได้ encrypted เท่า native
 *    ใช้สำหรับ development/testing เท่านั้น
 *
 * Developed by Xman Studio
 */

import { Platform } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';

// Prefix สำหรับ web fallback เพื่อแยกจาก AsyncStorage ปกติ
const WEB_SECURE_PREFIX = '__secure_';

// Lazy import SecureStore เฉพาะ native เพื่อหลีกเลี่ยง error บน web
let SecureStore: typeof import('expo-secure-store') | null = null;

if (Platform.OS !== 'web') {
  // eslint-disable-next-line @typescript-eslint/no-var-requires
  SecureStore = require('expo-secure-store');
}

/**
 * เก็บค่าแบบ secure (native) หรือ AsyncStorage (web fallback)
 */
export async function setSecureItem(key: string, value: string): Promise<void> {
  if (Platform.OS === 'web') {
    await AsyncStorage.setItem(`${WEB_SECURE_PREFIX}${key}`, value);
  } else if (SecureStore) {
    await SecureStore.setItemAsync(key, value);
  }
}

/**
 * อ่านค่าจาก secure storage
 */
export async function getSecureItem(key: string): Promise<string | null> {
  if (Platform.OS === 'web') {
    return AsyncStorage.getItem(`${WEB_SECURE_PREFIX}${key}`);
  }
  if (SecureStore) {
    return SecureStore.getItemAsync(key);
  }
  return null;
}

/**
 * ลบค่าจาก secure storage
 */
export async function deleteSecureItem(key: string): Promise<void> {
  if (Platform.OS === 'web') {
    await AsyncStorage.removeItem(`${WEB_SECURE_PREFIX}${key}`);
  } else if (SecureStore) {
    await SecureStore.deleteItemAsync(key);
  }
}
