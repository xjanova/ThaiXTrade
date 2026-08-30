/// TPIX TRADE — Entry Point
/// MultiProvider setup + App initialization
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'app.dart';
import 'providers/wallet_provider.dart';
import 'providers/market_provider.dart';
import 'providers/update_provider.dart';
import 'providers/config_provider.dart';
import 'providers/accent_provider.dart';
import 'providers/ai_bot_provider.dart';
import 'core/locale/locale_provider.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Lock portrait orientation
  await SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ]);

  // Preload the saved metal tone before the first frame (avoids a tone flash).
  final accent = AccentProvider();
  await accent.ready;

  runApp(
    MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (_) => LocaleProvider()),
        ChangeNotifierProvider(create: (_) => WalletProvider()),
        ChangeNotifierProvider(create: (_) => MarketProvider()),
        ChangeNotifierProvider(create: (_) => UpdateProvider()),
        ChangeNotifierProvider(create: (_) => ConfigProvider()),
        ChangeNotifierProvider<AccentProvider>.value(value: accent),
        // AI TRADE (บอทคลาวด์) — ตัวเดียวทั้งแอพ ห้ามสร้างใหม่ต่อหน้าจอ
        // ไม่งั้นลูปเดินบอทของแพลนฟรีจะซ้อนกันแล้วกินโควตาคำขอจนโดน 429
        //
        // ใช้ ProxyProvider เพื่อฉีด WalletProvider ให้เอง — หน้าจอจึงไม่ต้อง
        // จำว่าต้อง bind() และการสลับกระเป๋าจะล้างข้อมูลของกระเป๋าเก่าทันที
        ChangeNotifierProxyProvider<WalletProvider, AiBotProvider>(
          create: (_) => AiBotProvider(),
          update: (_, wallet, bot) => (bot ?? AiBotProvider())..bind(wallet),
        ),
      ],
      child: const TpixTradeApp(),
    ),
  );
}
