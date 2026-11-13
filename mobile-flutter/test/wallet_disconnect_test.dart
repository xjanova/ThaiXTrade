/// TPIX TRADE — ตัดการเชื่อมต่อกระเป๋าต้องไม่ทำกุญแจของกระเป๋าในแอปหาย (C6)
///
/// สิ่งที่กันไว้:
///   - ตัดการเชื่อมต่อกระเป๋าที่ลิงก์/ภายนอก **ห้ามแตะ** private key + วลีกู้คืนของกระเป๋าในแอป
///     (เดิมลบ "เสมอ เผื่อมีเศษค้าง" — ผู้ใช้ที่ลิงก์กระเป๋าอื่นทับแล้วกดตัด เสียกุญแจถาวร)
///     แล้วแอปต้องกลับไปใช้กระเป๋าในแอปใบเดิม ไม่ปล่อยกุญแจค้างไว้โดยไม่มีกระเป๋า
///   - กุญแจของกระเป๋าในแอปถูกลบได้ทางเดียว: ตัดการเชื่อมต่อกระเป๋าในแอปเอง + ยืนยันชัดเจน
///   - deep link ที่จะแทนที่กระเป๋าที่ใช้อยู่ต้องได้รับการยืนยันก่อน
///   - นำเข้ากระเป๋าใบใหม่ทับกุญแจของใบเดิมไม่ได้
///
/// ไม่ยิงเน็ตจริง: binding ของเทสต์ตอบ HTTP 400 ทุกคำขอ (ซึ่งโค้ดถือเป็น "ขอไม่สำเร็จ")
///
/// Developed by Xman Studio
library;

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tpix_trade/providers/wallet_provider.dart';
import 'package:tpix_trade/services/wallet_session.dart';

// วลีทดสอบมาตรฐานของวงการ (ไม่มีเงินจริง) — ใช้สร้างกระเป๋าในแอปสองใบที่ต่างกัน
const _mnemonicA =
    'test test test test test test test test test test test junk';
const _mnemonicB =
    'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';

const _external = '0x1111111111111111111111111111111111111111';
const _other = '0x2222222222222222222222222222222222222222';

const _storage = FlutterSecureStorage();
const _keyPk = 'tpix_trade_pk';
const _keyMnemonic = 'tpix_trade_mnemonic';

Future<String?> _storedKey() => _storage.read(key: _keyPk);
Future<String?> _storedMnemonic() => _storage.read(key: _keyMnemonic);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() async {
    FlutterSecureStorage.setMockInitialValues({});
    SharedPreferences.setMockInitialValues({});
    await WalletSession.clear();
  });

  test('กติกาลบกุญแจ: เฉพาะกระเป๋าในแอป + ยืนยันแล้วเท่านั้น', () {
    expect(
        WalletProvider.shouldDeleteEmbeddedKey(
            kind: WalletKind.embedded, confirmed: true),
        isTrue);
    expect(
        WalletProvider.shouldDeleteEmbeddedKey(
            kind: WalletKind.embedded, confirmed: false),
        isFalse);
    for (final kind in [WalletKind.linked, WalletKind.walletConnect]) {
      for (final confirmed in [true, false]) {
        expect(
          WalletProvider.shouldDeleteEmbeddedKey(
              kind: kind, confirmed: confirmed),
          isFalse,
          reason: '$kind confirmed=$confirmed',
        );
      }
    }
  });

  test('มีกระเป๋าในแอป → ลิงก์กระเป๋าอื่น → ตัดการเชื่อมต่อ: กุญแจยังอยู่ครบ และกลับมาใช้ใบเดิม',
      () async {
    final wallet = WalletProvider();
    expect(await wallet.importWallet(_mnemonicA), isTrue);
    final embeddedAddress = wallet.address!;
    final keyBefore = await _storedKey();
    expect(keyBefore, isNotNull);
    expect(await _storedMnemonic(), _mnemonicA);

    // ลิงก์กระเป๋าอื่นทับ (ผู้ใช้ยืนยันแล้ว)
    expect(wallet.wouldReplaceWallet(_external), isTrue);
    expect(
      await wallet.linkFromDeepLink(
          address: _external, chainId: 56, confirmedReplace: true),
      isTrue,
    );
    expect(wallet.kind, WalletKind.linked);
    expect(wallet.address, _external);
    // ลิงก์ทับก็ไม่แตะกุญแจ
    expect(await _storedKey(), keyBefore);

    // ตัดการเชื่อมต่อกระเป๋าที่ลิงก์ — แม้ส่ง deleteEmbeddedKey มา ก็ห้ามลบ (ไม่ใช่กระเป๋าในแอป)
    expect(await wallet.disconnect(deleteEmbeddedKey: true), isTrue);
    expect(await _storedKey(), keyBefore);
    expect(await _storedMnemonic(), _mnemonicA);

    // แอปกลับไปใช้กระเป๋าในแอปใบเดิม
    expect(wallet.isConnected, isTrue);
    expect(wallet.kind, WalletKind.embedded);
    expect(wallet.address!.toLowerCase(), embeddedAddress.toLowerCase());
    final prefs = await SharedPreferences.getInstance();
    expect(prefs.getString('tpix_trade_wallet_kind'), 'embedded');
  });

  test('ลิงก์กระเป๋าอย่างเดียว (ไม่มีกระเป๋าในแอป) → ตัดการเชื่อมต่อ: ออกจากระบบ ไม่มีอะไรให้กู้',
      () async {
    final wallet = WalletProvider();
    expect(wallet.wouldReplaceWallet(_external), isFalse);
    expect(await wallet.linkFromDeepLink(address: _external, chainId: 4289),
        isTrue);
    expect(await wallet.disconnect(), isTrue);
    expect(wallet.isConnected, isFalse);
    expect(await _storedKey(), isNull);
    final prefs = await SharedPreferences.getInstance();
    expect(prefs.getString('tpix_trade_wallet_state'), isNull);
  });

  test('กระเป๋าในแอป: ตัดการเชื่อมต่อโดยไม่ยืนยัน = ไม่ทำอะไรเลย', () async {
    final wallet = WalletProvider();
    await wallet.importWallet(_mnemonicA);
    final address = wallet.address;

    expect(await wallet.disconnect(), isFalse);
    expect(wallet.isConnected, isTrue);
    expect(wallet.address, address);
    expect(await _storedKey(), isNotNull);
    expect(await _storedMnemonic(), _mnemonicA);
  });

  test('กระเป๋าในแอป: ยืนยันแล้วจึงลบกุญแจ + วลีกู้คืน', () async {
    final wallet = WalletProvider();
    await wallet.importWallet(_mnemonicA);

    expect(await wallet.disconnect(deleteEmbeddedKey: true), isTrue);
    expect(wallet.isConnected, isFalse);
    expect(await _storedKey(), isNull);
    expect(await _storedMnemonic(), isNull);
  });

  test('deep link ที่จะแทนที่กระเป๋าที่ใช้อยู่: ไม่ยืนยัน = ไม่เปลี่ยนอะไร', () async {
    final wallet = WalletProvider();
    await wallet.importWallet(_mnemonicA);
    final address = wallet.address;

    expect(await wallet.linkFromDeepLink(address: _external, chainId: 56),
        isFalse);
    expect(wallet.kind, WalletKind.embedded);
    expect(wallet.address, address);

    // กระเป๋าที่ลิงก์อยู่ก็เช่นกัน
    await wallet.linkFromDeepLink(
        address: _external, chainId: 56, confirmedReplace: true);
    expect(await wallet.linkFromDeepLink(address: _other, chainId: 56),
        isFalse);
    expect(wallet.address, _external);
  });

  test('deep link ของกระเป๋าใบเดิม (ที่อยู่เดียวกัน) ไม่ถือเป็นการแทนที่', () async {
    final wallet = WalletProvider();
    await wallet.linkFromDeepLink(address: _external, chainId: 56);
    expect(wallet.wouldReplaceWallet(_external.toUpperCase().replaceFirst('0X', '0x')),
        isFalse);
    expect(await wallet.linkFromDeepLink(address: _external, chainId: 4289),
        isTrue);
    expect(wallet.address, _external);
  });

  test('นำเข้ากระเป๋าใบใหม่ทับกุญแจของกระเป๋าในแอปใบเดิมไม่ได้', () async {
    final wallet = WalletProvider();
    await wallet.importWallet(_mnemonicA);
    final address = wallet.address;
    final keyBefore = await _storedKey();

    expect(await wallet.importWallet(_mnemonicB), isFalse);
    expect(wallet.error, isNotNull);
    expect(wallet.address, address);
    expect(await _storedKey(), keyBefore);
    expect(await _storedMnemonic(), _mnemonicA);

    // ระหว่างที่ลิงก์กระเป๋าอื่นอยู่ กุญแจใบเดิมก็ยังถูกกันไว้
    await wallet.linkFromDeepLink(
        address: _external, chainId: 56, confirmedReplace: true);
    expect(await wallet.importWallet(_mnemonicB), isFalse);
    expect(await _storedKey(), keyBefore);

    // นำเข้าวลีของใบเดิมซ้ำได้ (ที่อยู่เดียวกัน ไม่ใช่การเขียนทับ)
    expect(await wallet.importWallet(_mnemonicA), isTrue);
    expect(wallet.kind, WalletKind.embedded);
    expect(wallet.address, address);
  });

  test('เปิดแอปใหม่: ไม่มีสถานะกระเป๋าที่จำไว้ แต่กุญแจยังอยู่ = กลับไปใช้กระเป๋าในแอป', () async {
    final first = WalletProvider();
    await first.importWallet(_mnemonicA);
    final address = first.address!;

    // เซสชันกระเป๋าภายนอกหมดอายุแล้วถูกล้างสถานะ — กุญแจของกระเป๋าในแอปยังอยู่ใน secure storage
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('tpix_trade_wallet_state');
    await prefs.remove('tpix_trade_wallet_kind');

    final reopened = WalletProvider();
    await reopened.loadSavedWallet();
    expect(reopened.isConnected, isTrue);
    expect(reopened.kind, WalletKind.embedded);
    expect(reopened.address!.toLowerCase(), address.toLowerCase());
  });
}
