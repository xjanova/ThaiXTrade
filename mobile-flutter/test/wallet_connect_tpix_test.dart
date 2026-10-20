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
      expect(info.rpcUrl, 'https://rpc1.tpix.online');
      // modal ของ Reown ส่ง toRawJson() ให้กระเป๋าเอง — ห้ามมี rpc.tpix.online (Cloudflare 403)
      expect(info.extraRpcUrls, isEmpty);
      expect(info.toRawJson()['rpcUrls'], ['https://rpc1.tpix.online']);
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
      // กระเป๋าได้แค่ rpc1 — rpc.tpix.online โดน Cloudflare 403 ฝั่งแอปกระเป๋า
      expect(p['rpcUrls'], ['https://rpc1.tpix.online']);
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

  // ตรงกับ tests/js/stores/walletAssets.test.js ของเว็บ — ข้อมูลชุดเดียวกัน ผลต้องเหมือนกัน
  group('เหรียญบนเชน TPIX จาก /dex/pairs (tpixChainTokens)', () {
    const usdt = '0x0A4Ee87551eF041f3EecFBBfdcfFC1B46a4878f1';
    const usdtLogo =
        'https://assets-cdn.trustwallet.com/blockchains/ethereum/assets/0xdAC17F958D2ee523a2206206994597C13D831ec7/logo.png';
    const pair = <String, dynamic>{
      'symbol': 'TPIX-USDT',
      'base_address': '0x0000000000000000000000000000000000000000',
      'quote_address': usdt,
      'quote_asset': 'USDT',
      'quote_decimals': 6,
      'quote_logo': usdtLogo,
      'chain_id': 4289,
    };
    Map<String, dynamic> with_(Map<String, dynamic> patch) => {...pair, ...patch};

    test('ได้ USDT บนเชน TPIX พร้อมทศนิยมและโลโก้จาก API คู่เทรด', () {
      final tokens = ExternalWalletService.tpixChainTokens([pair]);
      expect(tokens, hasLength(1));
      expect(tokens.single.address, usdt);
      expect(tokens.single.symbol, 'USDT');
      expect(tokens.single.decimals, 6);
      expect(tokens.single.image, usdtLogo);
    });

    test('ข้ามคู่เชนอื่น คู่ที่ base ไม่ใช่ TPIX และข้อมูลผิดรูป', () {
      final tokens = ExternalWalletService.tpixChainTokens([
        with_({'chain_id': 56}),
        with_({'chain_id': null}),
        with_({
          'base_address': '0x1111111111111111111111111111111111111111'
        }),
        with_({'quote_address': '0x123'}),
        with_({
          'quote_address': '0x0000000000000000000000000000000000000000'
        }),
        with_({'quote_decimals': 'six'}),
        with_({'quote_decimals': 6.5}),
        with_({'quote_decimals': -1}),
        with_({'quote_decimals': 37}),
        with_({'quote_asset': 'WAYTOOLONGSYMBOL'}),
        with_({'quote_asset': '   '}),
      ]);
      expect(tokens, isEmpty);
    });

    test('ตัวเลขที่มาเป็นสตริง/ทศนิยม .0 ใช้ได้ (แบบ Number() ของเว็บ)', () {
      final tokens = ExternalWalletService.tpixChainTokens([
        with_({'chain_id': '4289', 'quote_decimals': '6'}),
      ]);
      expect(tokens.single.decimals, 6);
      expect(
          ExternalWalletService.tpixChainTokens([
            with_({'quote_decimals': 18.0})
          ]).single.decimals,
          18);
    });

    test('ขอบเขตทศนิยม 0 และ 36 ผ่าน · symbol 11 ตัวผ่าน', () {
      expect(
          ExternalWalletService.tpixChainTokens(
              [with_({'quote_decimals': 0})]),
          hasLength(1));
      expect(
          ExternalWalletService.tpixChainTokens(
              [with_({'quote_decimals': 36})]),
          hasLength(1));
      expect(
          ExternalWalletService.tpixChainTokens(
              [with_({'quote_asset': 'ABCDEFGHIJK'})]),
          hasLength(1));
    });

    test('ที่อยู่ซ้ำ (ต่างตัวพิมพ์) เสนอครั้งเดียว', () {
      final tokens = ExternalWalletService.tpixChainTokens([
        pair,
        with_({'quote_address': usdt.toLowerCase()}),
      ]);
      expect(tokens, hasLength(1));
    });

    test('โลโก้ที่ไม่ใช่ https ไม่ถูกส่งให้กระเป๋า', () {
      for (final logo in ['/storage/usdt.png', 'http://x.io/u.png', null]) {
        final tokens =
            ExternalWalletService.tpixChainTokens([with_({'quote_logo': logo})]);
        expect(tokens.single.image, isNull, reason: '$logo');
      }
    });

    test('API ล่ม/ว่าง = ไม่เสนออะไรเลย (ไม่เดาที่อยู่)', () {
      expect(ExternalWalletService.tpixChainTokens(const []), isEmpty);
    });
  });

  group('wallet_watchAsset (EIP-747)', () {
    test('params เป็น object type ERC20 พร้อม address/symbol/decimals/image', () {
      final p = ExternalWalletService.watchAssetParams(const WatchAssetToken(
        address: '0x0A4Ee87551eF041f3EecFBBfdcfFC1B46a4878f1',
        symbol: 'USDT',
        decimals: 6,
        image: 'https://tpix.online/images/usdt.png',
      ));
      expect(p, {
        'type': 'ERC20',
        'options': {
          'address': '0x0A4Ee87551eF041f3EecFBBfdcfFC1B46a4878f1',
          'symbol': 'USDT',
          'decimals': 6,
          'image': 'https://tpix.online/images/usdt.png',
        },
      });
    });

    test('ไม่มีโลโก้ = ไม่ส่งช่อง image · symbol คงตัวพิมพ์ตามสัญญา', () {
      final p = ExternalWalletService.watchAssetParams(const WatchAssetToken(
        address: '0x0A4Ee87551eF041f3EecFBBfdcfFC1B46a4878f1',
        symbol: 'USDt',
        decimals: 6,
      ));
      final options = p['options'] as Map<String, dynamic>;
      expect(options.containsKey('image'), isFalse);
      expect(options['symbol'], 'USDt');
    });

    test('ข้อมูลจาก /dex/pairs ไหลไปเป็น params ได้ตรงตัว', () {
      final token = ExternalWalletService.tpixChainTokens([
        {
          'chain_id': 4289,
          'base_address': '0x0000000000000000000000000000000000000000',
          'quote_address': '0x0A4Ee87551eF041f3EecFBBfdcfFC1B46a4878f1',
          'quote_asset': 'USDT',
          'quote_decimals': 6,
          'quote_logo': '/storage/usdt.png',
        },
      ]).single;
      expect(ExternalWalletService.watchAssetParams(token), {
        'type': 'ERC20',
        'options': {
          'address': '0x0A4Ee87551eF041f3EecFBBfdcfFC1B46a4878f1',
          'symbol': 'USDT',
          'decimals': 6,
        },
      });
    });
  });
}
