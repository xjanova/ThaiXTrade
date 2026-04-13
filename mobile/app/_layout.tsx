import '@/polyfills'; // Must be first — crypto polyfill for ethers.js
import { useCallback, useEffect, useState } from 'react';
import { StatusBar } from 'expo-status-bar';
import { Stack } from 'expo-router';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { StyleSheet, Platform, Linking, Animated, View, Text, Image } from 'react-native';
import * as SplashScreen from 'expo-splash-screen';
import { colors } from '@/theme';
import { ErrorBoundary } from '@/components/common/ErrorBoundary';
import UpdateModal from '@/components/common/UpdateModal';
import WalletConnectModal from '@/components/wallet/WalletConnectModal';
import { useUpdateStore } from '@/stores/updateStore';
import { handleWalletCallback, useWalletStore } from '@/stores/walletStore';
import { playSplashSound } from '@/utils/sounds';

// Prevent splash screen from hiding until we're ready
SplashScreen.preventAutoHideAsync();

export default function RootLayout() {
  const checkUpdate = useUpdateStore((s) => s.checkUpdate);
  const loadSavedWallet = useWalletStore((s) => s.loadSavedWallet);
  const [showAnimatedSplash, setShowAnimatedSplash] = useState(true);
  const [fadeAnim] = useState(() => new Animated.Value(1));
  const [scaleAnim] = useState(() => new Animated.Value(0.8));

  const onLayoutRootView = useCallback(async () => {
    // ซ่อน native splash ทันที แล้วแสดง animated splash แทน
    await SplashScreen.hideAsync();

    // เล่นเสียง splash
    playSplashSound();

    // Animate: scale up logo
    Animated.spring(scaleAnim, {
      toValue: 1,
      friction: 6,
      tension: 80,
      useNativeDriver: true,
    }).start();

    // Fade out animated splash หลัง 2 วินาที
    setTimeout(() => {
      Animated.timing(fadeAnim, {
        toValue: 0,
        duration: 500,
        useNativeDriver: true,
      }).start(() => {
        setShowAnimatedSplash(false);
      });
    }, 2000);
  }, []);

  // Restore wallet state on app start / กู้คืนสถานะกระเป๋าตอนเปิดแอป
  useEffect(() => { loadSavedWallet(); }, []);

  // Check for updates on app start / ตรวจสอบอัปเดตตอนเปิดแอป
  useEffect(() => {
    // Small delay so the app loads first / หน่วงเล็กน้อยให้แอปโหลดก่อน
    const timer = setTimeout(() => {
      checkUpdate();
    }, 2000);
    return () => clearTimeout(timer);
  }, []);

  // Listen for wallet deep link callbacks / ฟัง deep link callback จากกระเป๋า
  useEffect(() => {
    const handleUrl = ({ url }: { url: string }) => {
      if (url.startsWith('tpixtrade://wallet/')) {
        handleWalletCallback(url);
      }
    };

    // Check if app was opened via deep link / ตรวจสอบว่าแอปถูกเปิดผ่าน deep link หรือไม่
    Linking.getInitialURL().then((url) => {
      if (url?.startsWith('tpixtrade://wallet/')) {
        handleWalletCallback(url);
      }
    });

    // Listen for future deep links / ฟัง deep link ในอนาคต
    const subscription = Linking.addEventListener('url', handleUrl);
    return () => subscription.remove();
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

        {/* Update Modal — แสดงเมื่อมีเวอร์ชันใหม่ */}
        <UpdateModal />

        {/* Wallet Connect Modal — เข้าถึงได้จากทุกหน้า */}
        <WalletConnectModal />

        {/* Animated Splash Screen Overlay */}
        {showAnimatedSplash && (
          <Animated.View
            style={[
              styles.splashOverlay,
              { opacity: fadeAnim },
            ]}
            pointerEvents="none"
          >
            <Animated.View style={{ transform: [{ scale: scaleAnim }], alignItems: 'center' }}>
              <Image
                source={require('../assets/images/icon.png')}
                style={styles.splashIcon}
                resizeMode="contain"
              />
              <Text style={styles.splashTitle}>TPIX TRADE</Text>
              <Text style={styles.splashSubtitle}>Decentralized Exchange</Text>
            </Animated.View>
          </Animated.View>
        )}
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
  splashOverlay: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: colors.bg.primary,
    alignItems: 'center',
    justifyContent: 'center',
    zIndex: 999,
  },
  splashIcon: {
    width: 100,
    height: 100,
    marginBottom: 16,
  },
  splashTitle: {
    fontSize: 28,
    fontWeight: '700',
    color: '#ffffff',
    letterSpacing: 2,
  },
  splashSubtitle: {
    fontSize: 14,
    color: 'rgba(255,255,255,0.5)',
    marginTop: 4,
    letterSpacing: 1,
  },
});
