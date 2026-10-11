/// TPIX TRADE — WalletConnect บนเชน TPIX
///
/// สิ่งที่กันไว้:
///   - เชน 4289 ต้องลงทะเบียนกับ Reown ก่อนสร้าง modal ไม่งั้นหาเชนไม่เจอ สลับเชนไม่ได้
///   - Reown คืน chain id แบบ 'eip155:56' — แปลงเป็นเลขให้ได้ (เดิม int.tryParse ได้ null ตลอด)
///   - ขอเพิ่มเชนต้องแนบ iconUrls (โลโก้ ?v=2) ไม่งั้นกระเป๋าโชว์เชนเป็นตัวอักษรเปล่า
///
/// Developed by Xman Studio
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:reown_appkit/reown_appkit.dart';
import 'package:tpix_trade/core/config/reown_config.dart';
import 'package:tpix_trade/models/chain_config.dart';
import 'package:tpix_trade/services/external_wallet_service.dart';

void main() {
  group('ลงทะเบียนเชน TPIX กับ Reown', () {
    test('หลังลงทะเบียน Reown หาเชน 4289 เจอ พร้อมข้อมูลครบ', () {
      ExternalWalletService.registerTpixNetwork();

      final info = ReownAppKitModalNetworks.getNetworkInfo('eip155', '4289');
      expect(info, isNotNull);
      expect(info!.chainId, 'eip155:4289');
      expect(info.name, 'TPIX Chain');
      expect(info.currency, 'TPIX');
      expect(info.rpcUrl, 'https://rpc.tpix.online');
      expect(info.extraRpcUrls, contains('https://rpc1.tpix.online'));
      expect(info.explorerUrl, 'https://explorer.tpix.online');
      expect(info.chainIcon, ReownConfig.tpixCoinIcon);
      expect(info.isTestNetwork, isFalse);
    });

    test('เรียกซ้ำไม่เพิ่มเชนซ้ำ', () {
      ExternalWalletService.registerTpixNetwork();
      ExternalWalletService.registerTpixNetwork();
      final all = ReownAppKitModalNetworks.getAllSupportedNetworks(
              namespace: 'eip155')
          .where((n) => n.chainId == 'eip155:4289');
      expect(all.length, 1);
    });

    test('เชนหลักเดิม (BSC) ยังอยู่ — ลงทะเบียนเพิ่ม ไม่ได้ทับ', () {
      ExternalWalletService.registerTpixNetwork();
      expect(ReownAppKitModalNetworks.getNetworkInfo('eip155', '56'), isNotNull);
    });
  });

  group('parseChainId — Reown คืนแบบ CAIP-2', () {
    test('ทั้ง eip155:4289 และ 4289 ได้เลขเดียวกัน', () {
      expect(ExternalWalletService.parseChainId('eip155:4289'), 4289);
      expect(ExternalWalletService.parseChainId('eip155:56'), 56);
      expect(ExternalWalletService.parseChainId('4289'), 4289);
    });

    test('ค่าว่าง/เพี้ยน = null', () {
      expect(ExternalWalletService.parseChainId(null), isNull);
      expect(ExternalWalletService.parseChainId(''), isNull);
      expect(ExternalWalletService.parseChainId('eip155:'), isNull);
      expect(ExternalWalletService.parseChainId('solana:abc'), isNull);
    });
  });

  group('wallet_addEthereumChain', () {
    test('พารามิเตอร์ครบตาม EIP-3085 พร้อม iconUrls ใหม่', () {
      final p = ExternalWalletService.addChainParams(ChainConfig.tpix);
      expect(p['chainId'], '0x10c1'); // 4289
      expect(p['chainName'], 'TPIX Chain');
      expect(p['nativeCurrency'],
          {'name': 'TPIX', 'symbol': 'TPIX', 'decimals': 18});
      expect(p['rpcUrls'],
          ['https://rpc.tpix.online', 'https://rpc1.tpix.online']);
      expect(p['blockExplorerUrls'], ['https://explorer.tpix.online']);
      expect(p['iconUrls'],
          ['https://tpix.online/images/brand/tpix-coin.png?v=2']);
    });

    test('เชนอื่นไม่แนบโลโก้ TPIX', () {
      final p = ExternalWalletService.addChainParams(ChainConfig.bsc);
      expect(p['chainId'], '0x38');
      expect(p.containsKey('iconUrls'), isFalse);
    });

    test('โลโก้แอปใน metadata ใช้ไฟล์ใหม่ ?v=2 (หลบแคช Cloudflare 1 ปี)', () {
      expect(ReownConfig.metadata.icons,
          ['https://tpix.online/images/brand/tpix-coin.png?v=2']);
    });

    test('แยก "ผู้ใช้ปฏิเสธ" ออกจาก "กระเป๋าไม่รู้จักเชน"', () {
      expect(
          ExternalWalletService.isUserRejected(
              const JsonRpcError(code: 4001, message: 'User rejected')),
          isTrue);
      expect(
          ExternalWalletService.isUserRejected(
              const JsonRpcError(code: 5000, message: 'x')),
          isTrue);
      expect(
          ExternalWalletService.isUserRejected(const JsonRpcError(
              code: 4902, message: 'Unrecognized chain ID')),
          isFalse);
    });
  });
}
