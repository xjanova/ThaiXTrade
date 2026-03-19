/**
 * Web-compatible Tab Bar Background
 * พื้นหลัง Tab Bar ที่รองรับเว็บ
 *
 * Uses CSS backdrop-filter on web (works in all modern browsers)
 * and expo-blur BlurView on native platforms.
 * ใช้ CSS backdrop-filter บนเว็บ และ BlurView บน native
 */

import React from 'react';
import { StyleSheet, View, Platform } from 'react-native';
import { colors } from '@/theme';

/**
 * Web fallback for blur effect using CSS backdrop-filter
 * Fallback สำหรับเว็บใช้ CSS backdrop-filter
 */
function WebBlurBackground() {
  return (
    <View
      style={[
        StyleSheet.absoluteFill,
        {
          // @ts-ignore - web-only CSS property / คุณสมบัติ CSS เฉพาะเว็บ
          backdropFilter: 'blur(20px)',
          // @ts-ignore
          WebkitBackdropFilter: 'blur(20px)',
          backgroundColor: 'rgba(10, 14, 26, 0.85)',
        },
      ]}
    />
  );
}

/**
 * Native blur using expo-blur
 * Blur บน native ใช้ expo-blur
 */
function NativeBlurBackground() {
  // Lazy import to avoid web bundle issues / import แบบ lazy เพื่อหลีกเลี่ยงปัญหาบนเว็บ
  const { BlurView } = require('expo-blur');
  return (
    <View style={StyleSheet.absoluteFill}>
      <BlurView intensity={40} tint="dark" style={StyleSheet.absoluteFill} />
      <View style={styles.overlay} />
    </View>
  );
}

/**
 * Platform-aware tab bar background
 * พื้นหลัง tab bar ที่ปรับตาม platform
 */
export function TabBarBackground() {
  if (Platform.OS === 'web') {
    return <WebBlurBackground />;
  }
  return <NativeBlurBackground />;
}

const styles = StyleSheet.create({
  overlay: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: 'rgba(10, 14, 26, 0.92)',
  },
});
