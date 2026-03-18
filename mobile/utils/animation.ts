/**
 * Shared animation utilities
 * ค่าคงที่และคอมโพเนนต์แอนิเมชันที่ใช้ร่วมกัน
 *
 * Consolidates AnimatedPressable and spring config
 * from GlassCard, GradientButton, MarketRow, AssetRow.
 * รวม AnimatedPressable และค่า spring config จากหลายคอมโพเนนต์
 */

import { Pressable } from 'react-native';
import Animated from 'react-native-reanimated';

/**
 * Animated version of Pressable - created once and reused
 * คอมโพเนนต์ Pressable แบบ Animated - สร้างครั้งเดียวใช้ซ้ำได้
 */
export const AnimatedPressable = Animated.createAnimatedComponent(Pressable);

/**
 * Default spring animation config for press effects
 * ค่า spring animation เริ่มต้นสำหรับเอฟเฟกต์กดปุ่ม
 */
export const SPRING_CONFIG = {
  damping: 15,
  stiffness: 300,
  mass: 0.8,
} as const;

/**
 * Slightly different spring config for buttons (snappier feel)
 * ค่า spring สำหรับปุ่มกด (ตอบสนองเร็วกว่า)
 */
export const BUTTON_SPRING_CONFIG = {
  damping: 15,
  stiffness: 350,
  mass: 0.7,
} as const;
