/**
 * Error Boundary Component
 * คอมโพเนนต์จับข้อผิดพลาด
 *
 * Catches render errors in child components and shows a fallback UI
 * instead of crashing the entire app with a white screen.
 * จับ error ในคอมโพเนนต์ลูกและแสดง UI สำรอง
 * แทนที่จะทำให้แอปทั้งหมดพังเป็นหน้าจอขาว
 */

import React, { Component, ErrorInfo, ReactNode } from 'react';
import { StyleSheet, Text, View, Pressable } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors, spacing, typography } from '@/theme';

interface Props {
  children: ReactNode;
  fallback?: ReactNode;
}

interface State {
  hasError: boolean;
  error: Error | null;
}

export class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, errorInfo: ErrorInfo) {
    // Log error for debugging / บันทึก error สำหรับ debug
    console.error('[ErrorBoundary] Caught error:', error, errorInfo);
  }

  handleRetry = () => {
    this.setState({ hasError: false, error: null });
  };

  render() {
    if (this.state.hasError) {
      if (this.props.fallback) {
        return this.props.fallback;
      }

      return (
        <View style={styles.container}>
          <View style={styles.iconContainer}>
            <Ionicons name="warning-outline" size={48} color={colors.trading.yellow} />
          </View>
          <Text style={styles.title}>Something went wrong</Text>
          <Text style={styles.titleTh}>เกิดข้อผิดพลาดบางอย่าง</Text>
          <Text style={styles.message}>
            {this.state.error?.message || 'An unexpected error occurred'}
          </Text>
          <Pressable style={styles.retryButton} onPress={this.handleRetry}>
            <Ionicons name="refresh-outline" size={18} color={colors.brand.cyan} />
            <Text style={styles.retryText}>Try Again / ลองอีกครั้ง</Text>
          </Pressable>
        </View>
      );
    }

    return this.props.children;
  }
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.bg.primary,
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing['3xl'],
  },
  iconContainer: {
    width: 80,
    height: 80,
    borderRadius: 40,
    backgroundColor: 'rgba(255, 214, 0, 0.1)',
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.xl,
  },
  title: {
    ...typography.h3,
    color: colors.text.primary,
    marginBottom: spacing.xs,
  },
  titleTh: {
    ...typography.body,
    color: colors.text.tertiary,
    marginBottom: spacing.md,
  },
  message: {
    ...typography.bodySmall,
    color: colors.text.tertiary,
    textAlign: 'center',
    marginBottom: spacing['2xl'],
  },
  retryButton: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    paddingHorizontal: spacing.xl,
    paddingVertical: spacing.md,
    borderRadius: 12,
    borderWidth: 1,
    borderColor: colors.brand.cyan + '40',
    backgroundColor: colors.brand.cyan + '10',
  },
  retryText: {
    ...typography.body,
    color: colors.brand.cyan,
    fontWeight: '600',
  },
});
