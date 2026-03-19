import { useCallback, useEffect } from 'react';
import { StatusBar } from 'expo-status-bar';
import { Stack } from 'expo-router';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { StyleSheet, Platform } from 'react-native';
import * as SplashScreen from 'expo-splash-screen';
import { colors } from '@/theme';
import { ErrorBoundary } from '@/components/common/ErrorBoundary';
import UpdateModal from '@/components/common/UpdateModal';
import { useUpdateStore } from '@/stores/updateStore';

// Prevent splash screen from hiding until we're ready
// ป้องกันไม่ให้ splash screen หายไปจนกว่าจะพร้อม
SplashScreen.preventAutoHideAsync();

export default function RootLayout() {
  const checkUpdate = useUpdateStore((s) => s.checkUpdate);

  const onLayoutRootView = useCallback(async () => {
    // Hide splash screen after layout is ready
    // ซ่อน splash screen หลังจาก layout พร้อมแล้ว
    await SplashScreen.hideAsync();
  }, []);

  // Check for updates on app start / ตรวจสอบอัปเดตตอนเปิดแอป
  useEffect(() => {
    // Small delay so the app loads first / หน่วงเล็กน้อยให้แอปโหลดก่อน
    const timer = setTimeout(() => {
      checkUpdate();
    }, 2000);
    return () => clearTimeout(timer);
  }, []);

  return (
    <ErrorBoundary>
      <GestureHandlerRootView
        style={[
          styles.container,
          Platform.OS === 'web' && styles.webContainer,
        ]}
        onLayout={onLayoutRootView}
      >
        <StatusBar style="light" backgroundColor={colors.bg.primary} />
        <Stack
          screenOptions={{
            headerShown: false,
            contentStyle: { backgroundColor: colors.bg.primary },
            animation: Platform.OS === 'web' ? 'none' : 'slide_from_right',
          }}
        >
          <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
        </Stack>

        {/* Update Modal - shows when new version available */}
        {/* Modal อัปเดต - แสดงเมื่อมีเวอร์ชันใหม่ */}
        <UpdateModal />
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
    // @ts-ignore
    overflow: 'auto',
  },
});
