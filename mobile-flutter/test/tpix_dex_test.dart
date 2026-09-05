/// TPIX TRADE — เทรดบนเชน TPIX ฝั่งแอป
///
/// สิ่งที่กันไว้:
///   - ยังไม่ได้ deploy = ทุก action ต้องล้มแบบมีข้อความ ไม่ใช่ยิงไปที่อยู่ที่เดา
///   - คู่บนเชน TPIX ต้องตัดสินจากข้อมูลเซิร์ฟเวอร์ (เชนจริง + execution_mode)
///     ไม่ใช่เดาจากชื่อคู่แบบเดิม ซึ่งทำให้คู่ใหม่ที่คนเติมพูลเองไม่มีวันเปิด
///   - หน่วยเงินต้องแปลงตรงกับฝั่งเว็บเป๊ะ ๆ ไม่งั้นยอดเพี้ยนทั้งไม้
///
/// Developed by Xman Studio

import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/models/api_models.dart';
import 'package:tpix_trade/services/bsc_swap_service.dart'
    show SwapException, VerifiedToken;
import 'package:tpix_trade/services/tpix_dex_service.dart';

void main() {
  group('TpixDexConfig', () {
    test('อ่านที่อยู่จากทะเบียนบนเซิร์ฟเวอร์ครบทุกตัว', () {
      final cfg = TpixDexConfig.fromJson({
        'ready': true,
        'chainId': 4289,
        'rpc': 'https://rpc.tpix.online',
        'WTPIX': '0x1111111111111111111111111111111111111111',
        'USDT': '0x2222222222222222222222222222222222222222',
        'FACTORY': '0x3333333333333333333333333333333333333333',
        'ROUTER': '0x4444444444444444444444444444444444444444',
        'missing': <String>[],
      });

      expect(cfg.ready, isTrue);
      expect(cfg.chainId, 4289);
      expect(cfg.router, '0x4444444444444444444444444444444444444444');
      expect(cfg.missing, isEmpty);
    });

    test('ยังไม่ deploy = ready เท็จ พร้อมบอกว่าขาดอะไร', () {
      final cfg = TpixDexConfig.fromJson({
        'ready': false,
        'chainId': 4289,
        'rpc': 'https://rpc.tpix.online',
        'WTPIX': null,
        'USDT': null,
        'FACTORY': null,
        'ROUTER': null,
        'missing': ['wtpix', 'usdt_tpix', 'dex_factory', 'dex_router'],
      });

      expect(cfg.ready, isFalse);
      expect(cfg.missing.length, 4);
      expect(cfg.router, isNull);
    });

    test('ค่าตั้งต้นก่อนโหลดคือ "ยังไม่พร้อม" — fail-closed', () {
      expect(TpixDexConfig.notReady.ready, isFalse);
      expect(TpixDexConfig.notReady.chainId, 4289);
    });
  });

  group('TpixDexService — ด่านก่อนแตะเงิน', () {
    test('ยังไม่ deploy แล้วขอ quote = โยน SwapException สองภาษา', () async {
      final service = TpixDexService();

      await expectLater(
        service.getQuote(
          fromToken: _nativeTpix,
          toToken: _usdtOnTpix,
          amount: 1,
        ),
        throwsA(isA<SwapException>()),
      );
    });

    test('จำนวนเป็นศูนย์ถูกปฏิเสธก่อนแตะเชน', () async {
      final service = TpixDexService();

      await expectLater(
        service.getQuote(
          fromToken: _nativeTpix,
          toToken: _usdtOnTpix,
          amount: 0,
        ),
        throwsA(isA<SwapException>()),
      );
    });

    test('รู้จักเหรียญเนทีฟทุกรูปแบบที่ระบบใช้', () {
      expect(
          TpixDexService.isNative(
              '0x0000000000000000000000000000000000000000'),
          isTrue);
      expect(TpixDexService.isNative('0x${'eE' * 20}'), isTrue);
      expect(TpixDexService.isNative(''), isTrue);
      expect(
          TpixDexService.isNative(
              '0xabcabcabcabcabcabcabcabcabcabcabcabcabca'),
          isFalse);
    });
  });

  group('การแปลงหน่วยเงิน — ต้องตรงกับฝั่งเว็บ', () {
    test('toWei ปัดตามจำนวนทศนิยมของเหรียญ', () {
      expect(TpixDexService.toWei(1, 18), BigInt.parse('1000000000000000000'));
      expect(TpixDexService.toWei(1.5, 6), BigInt.from(1500000));
      expect(TpixDexService.toWei(0.000001, 6), BigInt.one);
    });

    test('toWei ตัดเศษเกิน 12 ตำแหน่งทิ้ง — กัน float garbage', () {
      // 0.1 + 0.2 ใน double ได้ 0.30000000000000004 — ต้องไม่หลุดไปเป็น wei
      final wei = TpixDexService.toWei(0.1 + 0.2, 18);
      expect(wei, BigInt.parse('300000000000000000'));
    });

    test('fromWei กลับมาได้ค่าเดิม', () {
      expect(
          TpixDexService.fromWei(BigInt.parse('2500000000000000000'), 18), 2.5);
      expect(TpixDexService.fromWei(BigInt.from(1500000), 6), 1.5);
    });
  });

  group('TradingPairInfo — คู่บนเชนไหน เทรดได้จริงไหม', () {
    TradingPairInfo parse(Map<String, dynamic> extra) =>
        TradingPairInfo.fromJson({
          'symbol': 'ABC-TPIX',
          'base_asset': 'ABC',
          'quote_asset': 'TPIX',
          'min_trade_amount': 0,
          'max_trade_amount': 0,
          'price_precision': 8,
          'amount_precision': 4,
          ...extra,
        });

    test('คู่บนเชน TPIX ที่ส่งคำสั่งได้จริง', () {
      final pair = parse({
        'chain_id': 11, // PK ของตาราง chains — ห้ามเอาไปเทียบกับกระเป๋า
        'network_chain_id': 4289,
        'execution_mode': 'onchain',
        'base_address': '0xabcabcabcabcabcabcabcabcabcabcabcabcabca',
        'quote_address': '0x0000000000000000000000000000000000000000',
        'base_decimals': 18,
        'quote_decimals': 18,
        'dex_pair_address': '0x5555555555555555555555555555555555555555',
      });

      expect(pair.networkChainId, 4289);
      expect(pair.isOnchain, isTrue);
      expect(pair.chainId, 11, reason: 'chain_id เดิมคือ PK ไม่ใช่ chain id จริง');
      expect(pair.dexPairAddress,
          '0x5555555555555555555555555555555555555555');
    });

    test('คู่ดัชนี (ดูราคาอย่างเดียว) ต้องไม่ถูกนับว่าเทรดได้', () {
      final pair = parse({
        'network_chain_id': 4289,
        'execution_mode': 'index',
      });

      expect(pair.isOnchain, isFalse);
    });

    test('ข้อมูลเก่าที่ไม่มีฟิลด์ใหม่ = ถือว่าดูราคาอย่างเดียว', () {
      final pair = parse({});

      expect(pair.executionMode, 'index');
      expect(pair.isOnchain, isFalse);
      expect(pair.networkChainId, isNull);
      expect(pair.baseDecimals, 18);
    });
  });
}

/// โทเคนตัวอย่างสำหรับเทสต์ — ไม่ต้องแตะเชนจริง
const _nativeTpix = VerifiedToken(
  symbol: 'TPIX',
  address: '0x0000000000000000000000000000000000000000',
  decimals: 18,
  native: true,
);

const _usdtOnTpix = VerifiedToken(
  symbol: 'USDT',
  address: '0x2222222222222222222222222222222222222222',
  decimals: 6,
  native: false,
);
