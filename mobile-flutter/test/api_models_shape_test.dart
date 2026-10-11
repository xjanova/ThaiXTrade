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
