/**
 * Web-aware content container that constrains width on large screens
 * Container สำหรับจำกัดความกว้างเนื้อหาบนหน้าจอใหญ่
 *
 * On mobile: full width
 * On desktop web: centered with max-width for optimal readability
 * บนมือถือ: เต็มความกว้าง
 * บนเว็บเดสก์ท็อป: จัดกึ่งกลางพร้อมจำกัดความกว้างสูงสุด
 */

import React from 'react';
import { View, StyleSheet, Platform, ViewStyle, StyleProp } from 'react-native';
import { useResponsiveLayout, MAX_CONTENT_WIDTH } from '@/utils/responsive';
import { colors } from '@/theme';

interface WebContainerProps {
  children: React.ReactNode;
  style?: StyleProp<ViewStyle>;
}

export function WebContainer({ children, style }: WebContainerProps) {
  const { isDesktop, isWeb } = useResponsiveLayout();

  if (!isWeb || !isDesktop) {
    return <View style={[styles.base, style]}>{children}</View>;
  }

  return (
    <View style={[styles.base, style]}>
      <View style={styles.desktopWrapper}>
        <View style={styles.desktopContent}>
          {children}
        </View>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  base: {
    flex: 1,
    backgroundColor: colors.bg.primary,
  },
  desktopWrapper: {
    flex: 1,
    alignItems: 'center',
  },
  desktopContent: {
    flex: 1,
    width: '100%',
    maxWidth: MAX_CONTENT_WIDTH,
  },
});
