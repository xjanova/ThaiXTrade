/// TPIX TRADE — เทสต์ปุ่ม "ซื้อ TPIX" (เปิดหน้า token sale บนเว็บ — จ่ายบัตรผ่าน Stripe ที่นั่น)
library;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tpix_trade/core/locale/locale_provider.dart';
import 'package:tpix_trade/providers/accent_provider.dart';
import 'package:tpix_trade/widgets/common/buy_tpix_button.dart';

Future<List<Uri>> _pump(WidgetTester tester, {required bool opens}) async {
  SharedPreferences.setMockInitialValues({'app_locale': 'th', 'accent_fireflies': false, 'accent_reduce_motion': true});
  final locale = LocaleProvider();
  await locale.init();
  final accent = AccentProvider();
  await accent.ready;
  final opened = <Uri>[];
  await tester.pumpWidget(MultiProvider(
    providers: [
      ChangeNotifierProvider<LocaleProvider>.value(value: locale),
      ChangeNotifierProvider<AccentProvider>.value(value: accent),
    ],
    child: MaterialApp(
      home: Scaffold(
        body: BuyTpixCard(opener: (uri) async {
          opened.add(uri);
          return opens;
        }),
      ),
    ),
  ));
  return opened;
}

void main() {
  testWidgets('แตะแล้วเปิด https://tpix.online/token-sale ในเบราว์เซอร์ภายนอก', (tester) async {
    final opened = await _pump(tester, opens: true);
    expect(find.text('ซื้อ TPIX'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('buy-tpix')));
    await tester.pump();
    expect(opened.single.toString(), kTokenSaleUrl);
    expect(kTokenSaleUrl, 'https://tpix.online/token-sale');
    expect(find.byType(SnackBar), findsNothing);
  });

  testWidgets('เปิดเบราว์เซอร์ไม่ได้ → แจ้งผู้ใช้ ไม่พัง', (tester) async {
    await _pump(tester, opens: false);
    await tester.tap(find.byKey(const ValueKey('buy-tpix')));
    await tester.pump();
    expect(find.textContaining('tpix.online/token-sale'), findsOneWidget);
  });
}
