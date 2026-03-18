import { useEffect, useCallback } from 'react';
import { StatusBar } from 'expo-status-bar';
import { Stack } from 'expo-router';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { StyleSheet } from 'react-native';
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
      <GestureHandlerRootView style={styles.container} onLayout={onLayoutRootView}>
        <StatusBar style="light" backgroundColor={colors.bg.primary} />
        <Stack
          screenOptions={{
            headerShown: false,
            contentStyle: { backgroundColor: colors.bg.primary },
            animation: 'slide_from_right',
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
});
