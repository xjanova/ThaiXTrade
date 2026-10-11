/// TPIX TRADE — token list ของเชน TPIX + โลโก้ของเหรียญบนเชน
///
/// สิ่งที่กันไว้:
///   - endpoint ยังไม่เปิด (404 เป็นหน้า HTML) ต้องได้รายการว่าง ไม่ใช่ error
///   - รับเฉพาะโทเคนเชน 4289 ที่รูปแบบถูกต้อง · โลโก้ https เท่านั้น
///   - เหรียญบนเชน TPIX ที่ไม่มีโลโก้ ต้องได้ตัวอักษร ไม่ใช่โลโก้เหรียญดังที่ชื่อเดียวกัน
///
/// Developed by Xman Studio
library;

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/services/token_list_service.dart';
import 'package:tpix_trade/widgets/common/coin_logo.dart';

void main() {
  group('TokenListService.parse', () {
    Map<String, dynamic> token(Map<String, dynamic> over) => {
          'chainId': 4289,
          'address': '0x1234567890abcdef1234567890ABCDEF12345678',
          'symbol': 'IDTC',
          'name': 'IDTC',
          'decimals': 18,
          'logoURI': 'https://tpix.online/storage/token-logos/idtc.png',
          ...over,
        };

    test('หน้า 404 (HTML) = ไม่ใช่ token list', () {
      expect(TokenListService.parse('<!DOCTYPE html><html></html>'), isNull);
      expect(TokenListService.parse(null), isNull);
      expect(TokenListService.parse({'name': 'x'}), isNull);
    });

    test('รายการถูกต้อง อ่านด้วยที่อยู่ตัวเล็ก', () {
      final m = TokenListService.parse({
        'name': 'TPIX Chain',
        'tokens': [token({})],
      })!;
      final e = m['0x1234567890abcdef1234567890abcdef12345678']!;
      expect(e.symbol, 'IDTC');
      expect(e.decimals, 18);
      expect(e.logoUri, startsWith('https://'));
    });

    test('ข้ามเชนอื่น/ที่อยู่ผิด/ชื่อแปลก/decimals ผิด', () {
      final m = TokenListService.parse({
        'tokens': [
          token({'chainId': 56}),
          token({'address': '0x1234'}),
          token({'symbol': "X');alert(1)//"}),
          token({'decimals': 99}),
          token({'decimals': '18'}),
          'not a map',
        ],
      })!;
      expect(m, isEmpty);
    });

    test('โลโก้ที่ไม่ใช่ https ถูกทิ้ง แต่โทเคนยังอยู่', () {
      final m = TokenListService.parse({
        'tokens': [
          token({'logoURI': 'http://evil.test/a.png'}),
        ],
      })!;
      expect(m.values.single.logoUri, isNull);
    });

    test('httpsOnly', () {
      expect(TokenListService.httpsOnly('https://a.test/x.png'),
          'https://a.test/x.png');
      expect(TokenListService.httpsOnly('http://a.test/x.png'), isNull);
      expect(TokenListService.httpsOnly('data:image/png;base64,AAA'), isNull);
      expect(TokenListService.httpsOnly('ipfs://abc'), isNull);
      expect(TokenListService.httpsOnly(''), isNull);
      expect(TokenListService.httpsOnly(null), isNull);
    });
  });

  group('CoinLogo เหรียญบนเชน TPIX', () {
    Widget host(Widget child) =>
        MaterialApp(home: Scaffold(body: Center(child: child)));

    testWidgets('ไม่มีโลโก้ = ตัวอักษร ไม่ไปหาโลโก้ ETH จริงจากชื่อ',
        (tester) async {
      await tester.pumpWidget(
          host(const CoinLogo(symbol: 'ETH', chainToken: true)));
      expect(find.text('E'), findsOneWidget);
      expect(find.byType(CachedNetworkImage), findsNothing);
    });

    testWidgets('โลโก้ http ถูกทิ้ง = ตัวอักษร', (tester) async {
      await tester.pumpWidget(host(const CoinLogo(
        symbol: 'IDTC',
        chainToken: true,
        logoUrl: 'http://evil.test/idtc.png',
      )));
      expect(find.text('I'), findsOneWidget);
      expect(find.byType(CachedNetworkImage), findsNothing);
    });
  });
}
