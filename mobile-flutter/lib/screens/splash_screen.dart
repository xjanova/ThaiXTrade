/// TPIX TRADE — Splash Screen
/// โลโก้ + Loading animation ก่อนเข้าหน้าหลัก
///
/// Developed by Xman Studio

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart' show SystemNavigator;
import 'package:flutter_animate/flutter_animate.dart';
import 'package:go_router/go_router.dart';
import '../core/theme/app_fonts.dart';
import 'package:provider/provider.dart';
import '../core/theme/app_colors.dart';
import '../core/theme/gradients.dart';
import '../core/router.dart';
import '../providers/wallet_provider.dart';
import '../providers/market_provider.dart';
import '../providers/update_provider.dart';
import '../providers/config_provider.dart';
import '../core/locale/locale_provider.dart';
import '../services/biometric_service.dart';
import '../services/deep_link_service.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> {
  @override
  void initState() {
    super.initState();
    _initialize();
  }

  Future<void> _initialize() async {
    // ราคา TPIX เริ่มก่อนเพื่อน และไม่รอ — มีลองซ้ำในตัว (รอแล้ว splash จะค้างนานตอนเน็ตช้า)
    // เสร็จเมื่อไรก็เขียนลงแถว TPIX-USDT เอง ไม่ว่า tickers จะมาก่อนหรือหลัง
    unawaited(context.read<MarketProvider>().loadTpixPrice());

    // โหลดข้อมูลพื้นฐานพร้อมกัน
    await Future.wait([
      context.read<WalletProvider>().loadSavedWallet(),
      context.read<MarketProvider>().loadTickers(),
      context.read<MarketProvider>().loadFavorites(),
      context.read<LocaleProvider>().init(),
      context.read<ConfigProvider>().loadAll(), // fees + chains + pairs (global)
      Future.delayed(const Duration(milliseconds: 1800)), // minimum splash time
    ]);

    if (!mounted) return;

    final wallet = context.read<WalletProvider>();

    // หลัง wallet โหลดแล้ว — refetch fees ตาม chain ที่ active (chain-specific)
    if (wallet.isConnected) {
      context.read<ConfigProvider>().setActiveChain(wallet.activeChainId);
    }

    // Biometric auth ถ้าเปิดไว้ + มี wallet
    if (wallet.isConnected && wallet.biometricEnabled) {
      final ok = await _unlockWithBiometrics();
      if (!mounted || !ok) return; // ผู้ใช้เลือกปิดแอป
      // โหลด portfolio data หลัง biometric ผ่าน
      wallet.loadPortfolio();
    } else if (wallet.isConnected) {
      wallet.loadPortfolio();
    }

    if (!mounted) return;

    // Auto-check update ใน background — ไม่ block UI, แค่เก็บผลไว้ให้ Home แสดง banner
    // force: เปิดแอปใหม่ = เช็กเสมอ (เดิมเว้น 6 ชม. ทำให้ไม่เห็นรุ่นใหม่ที่เพิ่งปล่อย)
    context.read<UpdateProvider>().checkInBackground(force: true);

    context.go('/home');

    // Init deep-link handler หลัง /home mount — เพื่อให้ ScaffoldMessenger
    // พร้อมแสดง snackbar ตอน auto-link wallet จาก deep-link
    // (ถ้า init ตอน splash → snackbar ขึ้นไม่ได้เพราะไม่มี Scaffold ancestor)
    WidgetsBinding.instance.addPostFrameCallback((_) {
      DeepLinkService().init(rootNavigatorKey);
    });
  }

  /// ปลดล็อกด้วยลายนิ้วมือ/ใบหน้า — **ยืนยันไม่ผ่านห้ามลบกระเป๋าเด็ดขาด**
  ///
  /// ⚠️ เดิมเรียก wallet.disconnect() เมื่อไม่ผ่าน ซึ่งลบ private key + วลีกู้คืนในเครื่องทิ้ง
  ///    กดยกเลิกสแกนนิ้วครั้งเดียว หรือเครื่องถอดล็อกหน้าจอออก = กระเป๋าในแอปหายถาวร
  ///    (ถ้าไม่ได้จดวลีกู้คืนไว้ เงินหายตามไปด้วย)
  /// ตอนนี้: ไม่ผ่าน = ถามให้ลองใหม่หรือปิดแอป กระเป๋าอยู่ครบเสมอ
  /// คืน false เมื่อผู้ใช้เลือกปิดแอป
  Future<bool> _unlockWithBiometrics() async {
    final bio = BiometricService();
    // เครื่องไม่มีล็อกหน้าจอ/ไบโอเมตริกแล้ว — ยืนยันไม่ได้ตลอดไป (การถอดล็อกต้องปลดล็อกเครื่องก่อน)
    if (!await bio.isDeviceSupported()) return true;
    while (mounted) {
      if (await bio.authenticate('Unlock TPIX TRADE')) return true;
      if (!mounted) return false;
      final th = context.read<LocaleProvider>().isThai;
      final retry = await showDialog<bool>(
        context: context,
        barrierDismissible: false,
        builder: (ctx) => AlertDialog(
          title: Text(th ? 'ยืนยันตัวตนไม่สำเร็จ' : 'Authentication failed'),
          content: Text(th
              ? 'สแกนลายนิ้วมือหรือใบหน้าเพื่อเปิด TPIX TRADE — กระเป๋าของคุณยังอยู่ครบ'
              : 'Use your fingerprint or face to open TPIX TRADE. Your wallet is safe.'),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(false),
              child: Text(th ? 'ปิดแอป' : 'Close app'),
            ),
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(true),
              child: Text(th ? 'ลองอีกครั้ง' : 'Try again'),
            ),
          ],
        ),
      );
      if (retry != true) {
        await SystemNavigator.pop();
        return false;
      }
    }
    return false;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        decoration: BoxDecoration(
          gradient: AppGradients.darkBg,
        ),
        child: Center(
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              // Logo
              Container(
                width: 80,
                height: 80,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(24),
                  boxShadow: [
                    BoxShadow(
                      color: AppColors.glowCyan.withValues(alpha: 0.5),
                      blurRadius: 30,
                      spreadRadius: 4,
                    ),
                  ],
                ),
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(24),
                  child: Image.asset(
                    'assets/images/logo.webp',
                    width: 80,
                    height: 80,
                    fit: BoxFit.cover,
                  ),
                ),
              )
                  .animate()
                  .scale(
                    begin: const Offset(0.5, 0.5),
                    end: const Offset(1.0, 1.0),
                    duration: 600.ms,
                    curve: Curves.easeOutBack,
                  )
                  .fadeIn(duration: 400.ms),

              const SizedBox(height: 24),

              // App name
              ShaderMask(
                shaderCallback: (bounds) =>
                    AppGradients.brand.createShader(bounds),
                child: Text(
                  'TPIX TRADE',
                  style: AppFonts.ui(
                    fontSize: 28,
                    fontWeight: FontWeight.w800,
                    color: Colors.white,
                    letterSpacing: 2,
                  ),
                ),
              )
                  .animate(delay: 300.ms)
                  .fadeIn(duration: 500.ms)
                  .slideY(begin: 0.3, end: 0, duration: 500.ms),

              const SizedBox(height: 8),

              Text(
                'Decentralized Exchange',
                style: AppFonts.ui(
                  fontSize: 13,
                  fontWeight: FontWeight.w400,
                  color: AppColors.textTertiary,
                  letterSpacing: 1,
                ),
              )
                  .animate(delay: 500.ms)
                  .fadeIn(duration: 500.ms),

              const SizedBox(height: 48),

              // Loading indicator
              SizedBox(
                width: 24,
                height: 24,
                child: CircularProgressIndicator(
                  strokeWidth: 2,
                  color: AppColors.brandCyan.withValues(alpha: 0.6),
                ),
              )
                  .animate(delay: 800.ms)
                  .fadeIn(duration: 300.ms),
            ],
          ),
        ),
      ),
    );
  }
}
