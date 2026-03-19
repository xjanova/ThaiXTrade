/**
 * Responsive layout utilities for cross-platform support
 * ยูทิลิตี้สำหรับ responsive layout รองรับทุกแพลตฟอร์ม (mobile, tablet, desktop web)
 *
 * Provides breakpoints, max-width containers, and platform-aware layout helpers.
 * ให้ breakpoints, container จำกัดความกว้าง, และ helpers สำหรับ layout ตาม platform
 */

import { useWindowDimensions, Platform } from 'react-native';
import { useMemo } from 'react';

// Breakpoints (matching common responsive design patterns)
// จุดเปลี่ยนขนาดหน้าจอ (ตาม pattern responsive design ทั่วไป)
export const BREAKPOINTS = {
  sm: 480,    // Mobile / มือถือ
  md: 768,    // Tablet / แท็บเล็ต
  lg: 1024,   // Desktop / เดสก์ท็อป
  xl: 1280,   // Large desktop / เดสก์ท็อปใหญ่
} as const;

// Maximum content width on large screens / ความกว้างสูงสุดของเนื้อหาบนหน้าจอใหญ่
export const MAX_CONTENT_WIDTH = 480;
export const MAX_TABLET_WIDTH = 768;

export type ScreenSize = 'sm' | 'md' | 'lg' | 'xl';

export interface ResponsiveLayout {
  /** Current screen width / ความกว้างหน้าจอปัจจุบัน */
  screenWidth: number;
  /** Current screen height / ความสูงหน้าจอปัจจุบัน */
  screenHeight: number;
  /** Current screen size category / หมวดหมู่ขนาดหน้าจอ */
  size: ScreenSize;
  /** Whether running on web / ว่ารันบนเว็บหรือไม่ */
  isWeb: boolean;
  /** Whether screen is tablet or larger / ว่าหน้าจอเป็นแท็บเล็ตหรือใหญ่กว่า */
  isTabletOrLarger: boolean;
  /** Whether screen is desktop or larger / ว่าหน้าจอเป็นเดสก์ท็อปหรือใหญ่กว่า */
  isDesktop: boolean;
  /** Content width (capped at maxWidth on large screens) / ความกว้างเนื้อหา */
  contentWidth: number;
  /** Horizontal padding for centering content on large screens / padding แนวนอน */
  contentPaddingHorizontal: number;
  /** Card width for grid layouts / ความกว้างการ์ดสำหรับ grid */
  favoriteCardWidth: number;
  /** Chart width / ความกว้างกราฟ */
  chartWidth: number;
  /** Allocation chart size / ขนาดกราฟสัดส่วน */
  allocationChartSize: number;
}

/**
 * Hook for responsive layout calculations
 * Hook สำหรับคำนวณ layout ที่ปรับตามขนาดหน้าจอ
 *
 * Returns dimensions and helpers that adapt to screen size.
 * On web with large screens, content is constrained to a max width.
 * คืนค่า dimensions และ helpers ที่ปรับตามหน้าจอ
 * บนเว็บที่มีหน้าจอใหญ่ เนื้อหาจะถูกจำกัดความกว้างสูงสุด
 */
export function useResponsiveLayout(): ResponsiveLayout {
  const { width: screenWidth, height: screenHeight } = useWindowDimensions();
  const isWeb = Platform.OS === 'web';

  return useMemo(() => {
    const size: ScreenSize =
      screenWidth >= BREAKPOINTS.xl ? 'xl' :
      screenWidth >= BREAKPOINTS.lg ? 'lg' :
      screenWidth >= BREAKPOINTS.md ? 'md' : 'sm';

    const isTabletOrLarger = screenWidth >= BREAKPOINTS.md;
    const isDesktop = screenWidth >= BREAKPOINTS.lg;

    // On large screens, cap content width / บนหน้าจอใหญ่ จำกัดความกว้างเนื้อหา
    const contentWidth = isDesktop
      ? Math.min(screenWidth, MAX_CONTENT_WIDTH)
      : screenWidth;

    // Calculate centering padding / คำนวณ padding สำหรับจัดกึ่งกลาง
    const contentPaddingHorizontal = isDesktop
      ? Math.max((screenWidth - MAX_CONTENT_WIDTH) / 2, 20)
      : 20;

    // Card widths adapt to screen / ความกว้างการ์ดปรับตามหน้าจอ
    const favoriteCardWidth = isTabletOrLarger
      ? Math.min(200, contentWidth * 0.3)
      : contentWidth * 0.42;

    // Chart width uses available content area / กราฟใช้พื้นที่เนื้อหาที่มี
    const chartWidth = contentWidth - 40;

    // Allocation chart size / ขนาดกราฟสัดส่วน
    const allocationChartSize = isTabletOrLarger
      ? Math.min(180, contentWidth * 0.35)
      : contentWidth * 0.45;

    return {
      screenWidth,
      screenHeight,
      size,
      isWeb,
      isTabletOrLarger,
      isDesktop,
      contentWidth,
      contentPaddingHorizontal,
      favoriteCardWidth,
      chartWidth,
      allocationChartSize,
    };
  }, [screenWidth, screenHeight, isWeb]);
}
