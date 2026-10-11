/// TPIX TRADE — ตรวจที่อยู่กระเป๋า (EIP-55)
///
/// Developed by Xman Studio
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/utils/evm_address.dart';

void main() {
  test('checksum ถูก / ตัวเล็กล้วน / ตัวใหญ่ล้วน = ผ่าน', () {
    expect(isValidEvmAddress('0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed'), isTrue);
    expect(isValidEvmAddress('0x5aaeb6053f3e94c9b9a09f33669435e7ef1beaed'), isTrue);
    expect(isValidEvmAddress('0x5AAEB6053F3E94C9B9A09F33669435E7EF1BEAED'), isTrue);
  });

  test('ตัวพิมพ์ผสมที่ checksum ผิด (ถูกแก้ระหว่างทาง) = ไม่ผ่าน', () {
    expect(isValidEvmAddress('0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAeD'), isFalse);
  });

  test('รูปแบบผิด = ไม่ผ่าน', () {
    expect(isValidEvmAddress(null), isFalse);
    expect(isValidEvmAddress(''), isFalse);
    expect(isValidEvmAddress('0x1234'), isFalse);
    expect(isValidEvmAddress('5aaeb6053f3e94c9b9a09f33669435e7ef1beaed'), isFalse);
    expect(isValidEvmAddress('0xZZaeb6053f3e94c9b9a09f33669435e7ef1beaed'), isFalse);
  });
}
