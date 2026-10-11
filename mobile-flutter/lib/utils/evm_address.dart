/// TPIX TRADE — ตรวจที่อยู่กระเป๋า EVM
///
/// รูปแบบ 0x + 40 hex และถ้าเขียนตัวพิมพ์ผสม ต้องตรง checksum EIP-55 (keccak)
/// — ที่อยู่ที่ถูกแก้ทีละตัวอักษรระหว่างทาง (deep link จากแอปอื่น/QR) จะไม่ผ่าน
/// ตัวเล็กล้วน/ตัวใหญ่ล้วนยังรับ (ไม่มี checksum ให้ตรวจ — เป็นรูปแบบที่ถูกต้อง)
///
/// Developed by Xman Studio
library;

import 'package:web3dart/web3dart.dart' show EthereumAddress;

final RegExp _shape = RegExp(r'^0x[0-9a-fA-F]{40}$');

bool isValidEvmAddress(String? address) {
  if (address == null || !_shape.hasMatch(address)) return false;
  final body = address.substring(2);
  if (body == body.toLowerCase() || body == body.toUpperCase()) return true;
  try {
    // ตัวพิมพ์ผสม → web3dart ตรวจ checksum EIP-55 ให้ (ผิด = ArgumentError)
    EthereumAddress.fromHex(address);
    return true;
  } catch (_) {
    return false;
  }
}
