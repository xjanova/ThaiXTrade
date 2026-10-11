/// TPIX TRADE — รูปข้อมูลจากเซิร์ฟเวอร์จริงที่เคยทำแอปพัง
///
/// สิ่งที่กันไว้:
///   - preferences ว่างมาเป็น `[]` (list ของ PHP) ต้องไม่ทำให้โหลดโปรไฟล์ล้ม
///   - ราคา TPIX ที่เซิร์ฟเวอร์ตกไปใช้ค่าตั้งไว้ (source: admin) ต้องไม่ถูกถือเป็นราคาตลาด
///
/// Developed by Xman Studio
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/models/api_models.dart';

void main() {
  group('UserProfile.fromJson', () {
    test('preferences ว่างแบบ [] ไม่ทำให้พัง', () {
      final p = UserProfile.fromJson({
        'wallet_address': '0xabc',
        'preferences': <dynamic>[],
        'kyc_status': null,
      });
      expect(p.preferences, isEmpty);
      expect(p.kycStatus, 'none');
    });

    test('preferences แบบ map อ่านได้ตามเดิม', () {
      final p = UserProfile.fromJson({
        'wallet_address': '0xabc',
        'preferences': {'language': 'th', 'slippage': 1},
      });
      expect(p.prefLanguage, 'th');
      expect(p.preferences['slippage'], 1);
    });
  });

  group('DexPairInfo.tryParse (เหรียญลูกบนเชน TPIX)', () {
    Map<String, dynamic> row({
      String base = 'IDTC',
      String quote = 'USDT',
      String baseAddr = '0x1234567890abcdef1234567890abcdef12345678',
      dynamic logo = 'https://tpix.online/storage/token-logos/idtc.png',
      bool active = true,
    }) =>
        {
          'symbol': '$base-$quote',
          'base_asset': base,
          'quote_asset': quote,
          'base_address': baseAddr,
          'quote_address': '0x0A4Ee87551eF041f3EecFBBfdcfFC1B46a4878f1',
          'base_decimals': 18,
          'quote_decimals': 6,
          'base_logo': logo,
          'quote_logo': null,
          'pair_address': '0x3684e0ebd1bd7ef7b7783e695639badcd04bc4f0',
          'is_active': active,
          'chain_id': 4289,
        };

    test('คู่ปกติอ่านครบ decimals ตามจริง (18/6)', () {
      final p = DexPairInfo.tryParse(row())!;
      expect(p.symbol, 'IDTC-USDT');
      expect(p.baseDecimals, 18);
      expect(p.quoteDecimals, 6);
      expect(p.baseLogo, startsWith('https://'));
      expect(p.isActive, isTrue);
    });

    test('คู่กับ TPIX เนทีฟ (ที่อยู่ 0x0) ใช้ได้', () {
      final p = DexPairInfo.tryParse(row(
        quote: 'TPIX',
      )..['quote_address'] = '0x0000000000000000000000000000000000000000')!;
      expect(p.symbol, 'IDTC-TPIX');
    });

    test('โลโก้ที่ไม่ใช่ https ถูกทิ้ง (ใช้ตัวอักษรแทน) ไม่ทำให้ทั้งแถวหาย', () {
      expect(DexPairInfo.tryParse(row(logo: 'http://evil.test/x.png'))!.baseLogo,
          isNull);
      expect(DexPairInfo.tryParse(row(logo: 'javascript:alert(1)'))!.baseLogo,
          isNull);
      expect(DexPairInfo.tryParse(row(logo: 42))!.baseLogo, isNull);
      expect(DexPairInfo.tryParse(row(logo: ''))!.baseLogo, isNull);
    });

    test('ชื่อเหรียญแปลก (โค้ด/ช่องว่าง/อักษรคล้าย) ไม่ขึ้นตลาด', () {
      expect(DexPairInfo.tryParse(row(base: "X');alert(1)//")), isNull);
      expect(DexPairInfo.tryParse(row(base: 'ID TC')), isNull);
      expect(DexPairInfo.tryParse(row(base: 'IDТC')), isNull); // Т ซีริลลิก
      expect(DexPairInfo.tryParse(row(base: '')), isNull);
    });

    test('ที่อยู่ผิดรูปแบบ = ข้ามแถว', () {
      expect(DexPairInfo.tryParse(row(baseAddr: '0x1234')), isNull);
    });

    test('ตัวพิมพ์เล็กจากเชนถูกทำเป็นตัวใหญ่', () {
      expect(DexPairInfo.tryParse(row(base: 'idtc'))!.symbol, 'IDTC-USDT');
    });
  });

  group('TpixPrice.source', () {
    test('ราคาจากพูลบนเชนเป็นราคาตลาด', () {
      final p = TpixPrice.fromJson({'price': 0.1, 'source': 'dex'});
      expect(p.isMarket, isTrue);
    });

    test('ราคาที่แอดมินตั้ง/ค่าเริ่มต้น ไม่ใช่ราคาตลาด', () {
      final p = TpixPrice.fromJson({'price': 0.18, 'source': 'admin'});
      expect(p.isMarket, isFalse);
    });

    test('API เก่าที่ไม่บอกแหล่ง = ไม่ถือเป็นราคาตลาด', () {
      final p = TpixPrice.fromJson({'price': 0.18});
      expect(p.isMarket, isFalse);
      expect(p.source, 'unknown');
    });

    test('ราคาที่แอปอ่านจากพูลเอง', () {
      final p = TpixPrice.fromPool(0.1);
      expect(p.isMarket, isTrue);
      expect(p.price, 0.1);
    });
  });
}
