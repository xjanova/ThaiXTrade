/// TPIX TRADE — หน้าเลือกวิธีเชื่อมกระเป๋า: แอป TPIX Wallet ต้องเป็นอันดับ 1 และเด่นที่สุด
///
/// เจ้าของสั่ง 2026-10-22: "ให้นำระบบเชื่อมต่อกระดานเทรดด้วย wallet app มาเป็นอันดับ 1 ในวิธีเชื่อมต่อ
/// และปรับปรุงโลโก้ให้สวยเด่น คือเน้นให้ใช้แอปมากกว่า"
///
/// สิ่งที่ห้ามพัง:
///   - ไทล์ TPIX Wallet อยู่เหนือกระเป๋าอื่น (MetaMask ฯลฯ) ในชีตเชื่อมกระเป๋า
///   - ใช้ไอคอนแอป TPIX Wallet ตัวจริง ไม่ใช่โลโก้แอปเทรด
///   - ป้าย "แนะนำ / RECOMMENDED" — ภาษาอังกฤษบนจอแคบ 320dp ต้องไม่ล้น
///
/// หมายเหตุ: ฟอนต์ของเทสต์ Flutter กว้างกว่าฟอนต์จริงมาก แถวอื่นในชีต (ช่องวลีกู้คืน ฯลฯ) ล้นในเทสต์
/// ที่จอแคบทั้งที่บนเครื่องจริงไม่ล้น — จึงเช็กลำดับในชีตบนจอกว้าง และเช็กจอแคบที่ตัวไทล์อย่างเดียว
library;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tpix_trade/core/locale/locale_provider.dart';
import 'package:tpix_trade/providers/accent_provider.dart';
import 'package:tpix_trade/providers/wallet_provider.dart';
import 'package:tpix_trade/widgets/wallet/tpix_wallet_app_tile.dart';
import 'package:tpix_trade/widgets/wallet/wallet_connect_sheet.dart';

Future<(LocaleProvider, AccentProvider)> _providers(String lang) async {
  SharedPreferences.setMockInitialValues({
    'app_locale': lang,
    'accent_fireflies': false,
    'accent_reduce_motion': true,
  });
  final locale = LocaleProvider();
  await locale.init();
  final accent = AccentProvider();
  await accent.ready;
  return (locale, accent);
}

void _screen(WidgetTester tester, double width) {
  tester.view.devicePixelRatio = 1;
  tester.view.physicalSize = Size(width, 1400);
  addTearDown(tester.view.reset);
}

void main() {
  testWidgets('ชีตเชื่อมกระเป๋า: ไทล์ TPIX Wallet อยู่บนสุด เหนือ MetaMask', (tester) async {
    _screen(tester, 1200);
    final (locale, accent) = await _providers('th');
    await tester.pumpWidget(MultiProvider(
      providers: [
        ChangeNotifierProvider<LocaleProvider>.value(value: locale),
        ChangeNotifierProvider<AccentProvider>.value(value: accent),
        ChangeNotifierProvider<WalletProvider>(create: (_) => WalletProvider()),
      ],
      child: const MaterialApp(
        home: Scaffold(body: Align(alignment: Alignment.bottomCenter, child: WalletConnectSheet())),
      ),
    ));
    await tester.pump();

    expect(find.byType(TpixWalletAppTile), findsOneWidget);
    final tpixTop = tester.getTopLeft(find.byType(TpixWalletAppTile)).dy;
    final metaMaskTop = tester.getTopLeft(find.text('MetaMask')).dy;
    expect(tpixTop, lessThan(metaMaskTop));
  });

  testWidgets('ไทล์ใช้ไอคอนแอป TPIX Wallet ตัวจริง + ป้ายแนะนำ', (tester) async {
    _screen(tester, 400);
    final (locale, accent) = await _providers('th');
    await tester.pumpWidget(ChangeNotifierProvider<AccentProvider>.value(
      value: accent,
      child: MaterialApp(
        home: Scaffold(body: TpixWalletAppTile(locale: locale, connecting: false, onTap: () {})),
      ),
    ));

    final icon = find.byKey(const Key('tpix-wallet-app-icon'));
    expect(icon, findsOneWidget);
    final image = tester.widget<Image>(icon).image as AssetImage;
    expect(image.assetName, 'assets/images/tpix_wallet_app.webp');
    expect(find.text('เชื่อม TPIX Wallet'), findsOneWidget);
    expect(find.text('แนะนำ'), findsOneWidget);
  });

  testWidgets('ภาษาอังกฤษ: ป้าย RECOMMENDED บนจอแคบ 320dp ไม่ล้น (ขึ้นบรรทัดใหม่แทน)', (tester) async {
    _screen(tester, 320);
    final (locale, accent) = await _providers('en');
    var taps = 0;
    await tester.pumpWidget(ChangeNotifierProvider<AccentProvider>.value(
      value: accent,
      child: MaterialApp(
        home: Scaffold(
          body: Padding(
            // ระยะขอบเท่ากับในชีตจริง
            padding: const EdgeInsets.symmetric(horizontal: 22),
            child: TpixWalletAppTile(locale: locale, connecting: false, onTap: () => taps++),
          ),
        ),
      ),
    ));

    expect(find.text('Connect TPIX Wallet'), findsOneWidget);
    expect(find.text('RECOMMENDED'), findsOneWidget);
    expect(tester.takeException(), isNull);

    await tester.tap(find.byType(TpixWalletAppTile));
    expect(taps, 1);
  });
}
