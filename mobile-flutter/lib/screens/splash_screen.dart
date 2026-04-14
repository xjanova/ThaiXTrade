/// TPIX TRADE — Splash Screen
/// โลโก้ + Loading animation ก่อนเข้าหน้าหลัก
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:go_router/go_router.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import '../core/theme/app_colors.dart';
import '../core/theme/gradients.dart';
import '../core/router.dart';
import '../providers/wallet_provider.dart';
import '../providers/market_provider.dart';
import '../providers/update_provider.dart';
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
    // โหลดข้อมูลพื้นฐานพร้อมกัน
    await Future.wait([
      context.read<WalletProvider>().loadSavedWallet(),
      context.read<MarketProvider>().loadTickers(),
      context.read<MarketProvider>().loadTpixPrice(),
      context.read<MarketProvider>().loadFavorites(),
      context.read<LocaleProvider>().init(),
      Future.delayed(const Duration(milliseconds: 1800)), // minimum splash time
    ]);

    if (!mounted) return;

    // Biometric auth ถ้าเปิดไว้ + มี wallet
    final wallet = context.read<WalletProvider>();
    if (wallet.isConnected && wallet.biometricEnabled) {
      final ok = await BiometricService().authenticate('Unlock TPIX TRADE');
      if (!mounted) return;
      if (!ok) {
        // ล้มเหลว — ยังให้เข้าได้แต่ disconnect wallet เพื่อความปลอดภัย
        await wallet.disconnect();
      } else {
        // โหลด portfolio data หลัง biometric ผ่าน
        wallet.loadPortfolio();
      }
    } else if (wallet.isConnected) {
      wallet.loadPortfolio();
    }

    if (!mounted) return;

    // Auto-check update ใน background — ไม่ block UI, แค่เก็บผลไว้ให้ Home แสดง banner
    context.read<UpdateProvider>().checkInBackground();

    // Init deep link handler — รับ tpixtrade:// จาก Wallet หรือ external
    DeepLinkService().init(rootNavigatorKey);

    context.go('/home');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(
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
                  style: GoogleFonts.inter(
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
                style: GoogleFonts.inter(
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
