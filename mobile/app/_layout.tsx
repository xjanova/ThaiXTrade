import { useCallback } from 'react';
import { StatusBar } from 'expo-status-bar';
import { Stack } from 'expo-router';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { StyleSheet, Platform } from 'react-native';
import * as SplashScreen from 'expo-splash-screen';
import { colors } from '@/theme';
import { ErrorBoundary } from '@/components/common/ErrorBoundary';

// Prevent splash screen from hiding until we're ready
// ป้องกันไม่ให้ splash screen หายไปจนกว่าจะพร้อม
SplashScreen.preventAutoHideAsync();

export default function RootLayout() {
  const onLayoutRootView = useCallback(async () => {
    // Hide splash screen after layout is ready
    // ซ่อน splash screen หลังจาก layout พร้อมแล้ว
    await SplashScreen.hideAsync();
  }, []);

  return (
    <ErrorBoundary>
      <GestureHandlerRootView
        style={[
          styles.container,
          // Web: add min-height for proper scrolling
          // เว็บ: เพิ่ม minHeight เพื่อให้ scroll ถูกต้อง
          Platform.OS === 'web' && styles.webContainer,
        ]}
        onLayout={onLayoutRootView}
      >
        <StatusBar style="light" backgroundColor={colors.bg.primary} />
        <Stack
          screenOptions={{
            headerShown: false,
            contentStyle: { backgroundColor: colors.bg.primary },
            // Disable slide animation on web for better UX
            // ปิดแอนิเมชัน slide บนเว็บเพื่อ UX ที่ดีขึ้น
            animation: Platform.OS === 'web' ? 'none' : 'slide_from_right',
          }}
        >
          <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
        </Stack>
      </GestureHandlerRootView>
    </ErrorBoundary>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.bg.primary,
  },
  webContainer: {
    // @ts-ignore - web-only / เฉพาะเว็บ
    minHeight: '100vh',
    // @ts-ignore - web-only: smooth scrolling / เว็บ: scroll แบบ smooth
    overflow: 'auto',
  },
});
