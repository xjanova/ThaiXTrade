/// TPIX TRADE — คำขอเซ็นข้ามแอปไป TPIX Wallet ต้องระบุบัญชีที่ผูกไว้
///
/// สิ่งที่กันไว้:
///   - เดิมคำขอไม่มีที่อยู่บัญชีเลย → TPIX Wallet เซ็นด้วยบัญชีไหนก็ได้ที่เปิดค้างไว้
///   - `from=trade` (ชื่อแอปต้นทาง) ต้องยังอยู่ — กระเป๋าใช้โชว์ในหน้ายืนยัน
///   - ที่อยู่ผิดรูป = ไม่ส่งคำขอเลย (fail-closed)
///
/// Developed by Xman Studio
library;

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/services/linked_wallet_signer.dart';

const _addr = '0xAbCdEf0123456789aBcDeF0123456789ABCDEF01';

void main() {
  test('sign: มี address และยังคง from=trade (ชื่อแอปต้นทาง) ไว้', () {
    final uri = LinkedWalletSigner.buildSignUri(
        message: 'hello', nonce: 'a' * 32, address: _addr);
    expect(uri.scheme, 'tpixwallet');
    expect(uri.host, 'sign');
    expect(uri.queryParameters['address'], _addr);
    expect(uri.queryParameters['from'], 'trade');
    expect(uri.queryParameters['message'], 'hello');
    expect(uri.queryParameters['callback'], 'tpixtrade://sign-result');
  });

  test('sign-typed: มี address', () {
    final uri = LinkedWalletSigner.buildSignTypedUri(
        typedJson: '{}', nonce: 'b' * 32, address: _addr);
    expect(uri.host, 'sign-typed');
    expect(uri.queryParameters['address'], _addr);
    expect(uri.queryParameters['from'], 'trade');
  });

  test('sign-tx: address ในลิงก์ + from ใน tx JSON เป็นค่าเดียวกัน', () {
    final uri = LinkedWalletSigner.buildTxUri(
      nonce: 'c' * 32,
      address: _addr,
      to: '0x8aF94D36Ea0C14C40CDaff5bddE017269A9ccc04',
      chainId: 4289,
      dataHex: '0x1234',
    );
    expect(uri.host, 'sign-tx');
    expect(uri.queryParameters['address'], _addr);
    expect(uri.queryParameters['from'], 'trade');
    expect(uri.queryParameters['callback'], 'tpixtrade://tx-result?kind=tx');
    final tx = jsonDecode(uri.queryParameters['tx']!) as Map<String, dynamic>;
    expect(tx['from'], _addr);
    expect(tx['chainId'], 4289);
    expect(tx['data'], '0x1234');
    expect(tx.containsKey('value'), isFalse);
  });

  test('ที่อยู่ผิดรูปถูกปฏิเสธ (ไม่ส่งคำขอที่ไม่มีบัญชีกำกับ)', () {
    expect(LinkedWalletSigner.isValidAddress(_addr), isTrue);
    expect(LinkedWalletSigner.isValidAddress(null), isFalse);
    expect(LinkedWalletSigner.isValidAddress(''), isFalse);
    expect(LinkedWalletSigner.isValidAddress('0x1234'), isFalse);
    expect(LinkedWalletSigner.isValidAddress('${_addr}00'), isFalse);
  });

  test('requestSignature ด้วยที่อยู่ผิดรูป = null ทันที ไม่เปิดกระเป๋า', () async {
    final sig = await LinkedWalletSigner()
        .requestSignature('hello', address: 'not-an-address');
    expect(sig, isNull);
    expect(LinkedWalletSigner().hasPending, isFalse);
  });
}
