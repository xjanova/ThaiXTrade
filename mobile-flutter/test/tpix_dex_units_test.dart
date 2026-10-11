/// TPIX TRADE — หน่วยเงินแบบตรงตัวสำหรับเหรียญลูกบนเชน TPIX
///
/// สิ่งที่กันไว้:
///   - ยอดหลักร้อยล้านเหรียญ (IDTC 18 ตำแหน่ง) ต้องแปลงจากข้อความเป็น wei ตรงตัว
///     ไม่ใช่ผ่าน double ที่ปัดแล้วเกินยอดจริง ("ขาย 100%" แล้วเจอยอดไม่พอ)
///   - ทศนิยมเกินจำนวนตำแหน่งของเหรียญต้องปัดลงเสมอ
///   - slippage นอกช่วงต้องถูกปฏิเสธก่อนถึงกระเป๋า
///
/// Developed by Xman Studio
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/services/bsc_swap_service.dart' show SwapException;
import 'package:tpix_trade/services/tpix_dex_service.dart';

void main() {
  group('parseUnits', () {
    test('ยอดใหญ่ 18 ตำแหน่งตรงตัวทุกหลัก', () {
      expect(
        TpixDexService.parseUnits('666666666.666666666666666666', 18),
        BigInt.parse('666666666666666666666666666'),
      );
    });

    test('USDT 6 ตำแหน่ง และทศนิยมเกินถูกตัด (ปัดลง)', () {
      expect(TpixDexService.parseUnits('1.5', 6), BigInt.from(1500000));
      expect(TpixDexService.parseUnits('0.0000019', 6), BigInt.one);
      expect(TpixDexService.parseUnits('.5', 6), BigInt.from(500000));
      expect(TpixDexService.parseUnits('7', 0), BigInt.from(7));
    });

    test('รูปแบบผิด = null', () {
      expect(TpixDexService.parseUnits('', 18), isNull);
      expect(TpixDexService.parseUnits('.', 18), isNull);
      expect(TpixDexService.parseUnits('-1', 18), isNull);
      expect(TpixDexService.parseUnits('1e5', 18), isNull);
      expect(TpixDexService.parseUnits('1.2.3', 18), isNull);
      expect(TpixDexService.parseUnits('abc', 18), isNull);
    });

    test('ยอดเต็มจากเชนวนกลับได้ค่าเดิม (ปุ่ม 100%)', () {
      final wei = BigInt.parse('123456789012345678901234567');
      final text = TpixDexService.formatUnits(wei, 18);
      expect(text, '123456789.012345678901234567');
      expect(TpixDexService.parseUnits(text, 18), wei);
    });
  });

  group('formatUnits', () {
    test('ตัดศูนย์ท้าย / จำนวนเต็ม / ศูนย์', () {
      expect(TpixDexService.formatUnits(BigInt.from(1500000), 6), '1.5');
      expect(TpixDexService.formatUnits(BigInt.from(2000000), 6), '2');
      expect(TpixDexService.formatUnits(BigInt.one, 6), '0.000001');
      expect(TpixDexService.formatUnits(BigInt.zero, 18), '0');
    });
  });

  group('checkSlippage', () {
    test('ช่วงปกติผ่าน', () {
      TpixDexService.checkSlippage(0.5);
      TpixDexService.checkSlippage(50);
    });

    test('นอกช่วงถูกปฏิเสธ', () {
      for (final v in [0.0, -1.0, 50.1, 99.0, double.nan, double.infinity]) {
        expect(() => TpixDexService.checkSlippage(v),
            throwsA(isA<SwapException>()),
            reason: '$v');
      }
    });
  });
}
