/**
 * Web-specific style utilities
 * ยูทิลิตี้สไตล์เฉพาะเว็บ
 *
 * Provides web-only CSS properties as RN-compatible styles.
 * ให้คุณสมบัติ CSS เฉพาะเว็บในรูปแบบที่ใช้กับ RN ได้
 */

import { Platform, ViewStyle, TextStyle } from 'react-native';

/**
 * Web cursor styles for interactive elements
 * สไตล์ cursor สำหรับ element ที่โต้ตอบได้บนเว็บ
 */
export const webCursor = Platform.OS === 'web'
  ? ({ cursor: 'pointer' } as ViewStyle)
  : ({} as ViewStyle);

/**
 * Web hover transition style
 * สไตล์ transition สำหรับ hover บนเว็บ
 */
export const webTransition = Platform.OS === 'web'
  ? ({ transition: 'opacity 0.15s ease, transform 0.15s ease' } as unknown as ViewStyle)
  : ({} as ViewStyle);

/**
 * Disable text selection on web (for UI elements)
 * ปิดการเลือกข้อความบนเว็บ (สำหรับ element UI)
 */
export const webNoSelect = Platform.OS === 'web'
  ? ({ userSelect: 'none' } as unknown as TextStyle)
  : ({} as TextStyle);

/**
 * Web-compatible scrollbar styles for dark theme
 * สไตล์ scrollbar สำหรับ dark theme บนเว็บ
 */
export const webScrollbar = Platform.OS === 'web'
  ? ({
      scrollbarWidth: 'thin',
      scrollbarColor: 'rgba(255,255,255,0.15) transparent',
    } as unknown as ViewStyle)
  : ({} as ViewStyle);
