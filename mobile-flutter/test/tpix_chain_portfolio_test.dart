/// TPIX TRADE — ยอดบนเชน TPIX ในหน้า Portfolio/Home
///
/// สิ่งที่กันไว้:
///   - อ่านยอดพลาดต้องคงยอดเดิม ห้ามกลายเป็น 0 (ผู้ใช้จะคิดว่าเงินหาย)
///   - เซิร์ฟเวอร์ส่ง `token_address` แต่แอปเคยอ่าน `contract_address` อย่างเดียว
///   - ยอดเชน TPIX ที่อ่านเอง + ยอดจากเซิร์ฟเวอร์ต้องรวมกันไม่ซ้ำ (native 0x0 vs 0xEeee)
///   - มูลค่ารวมต้องไม่เป็น $0 ตลอด: TPIX × ราคา · USDT = 1
///
/// Developed by Xman Studio
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/models/api_models.dart';
import 'package:tpix_trade/services/tpix_chain_portfolio.dart';

const _usdtAddr = '0x0A4Ee87551eF041f3EecFBBfdcfFC1B46a4878f1';
const _wtpixAddr = '0x0A3dB168aF6b85e61D81CA78C608BDadAC832DFF';
const _otherAddr = '0x1111111111111111111111111111111111111111';

const _tpix = TpixChainToken(
  address: '0x0000000000000000000000000000000000000000',
  symbol: 'TPIX',
  decimals: 18,
  priceKind: TpixPriceKind.tpix,
);
const _usdt = TpixChainToken(
  address: _usdtAddr,
  symbol: 'USDT',
  decimals: 6,
  priceKind: TpixPriceKind.usd,
);
const _wtpix = TpixChainToken(
  address: _wtpixAddr,
  symbol: 'WTPIX',
  decimals: 18,
  priceKind: TpixPriceKind.tpix,
);
const _other = TpixChainToken(
  address: _otherAddr,
  symbol: 'ABC',
  decimals: 18,
);

void main() {
  group('applyReads — ห้ามโชว์ 0 เพราะอ่านพลาด', () {
    test('อ่านได้ครบ: TPIX × ราคา, USDT = 1 USD, เหรียญไม่รู้ราคาไม่มีมูลค่า', () {
      final out = TpixChainPortfolio.applyReads(
        previous: const {},
        tokens: const [_tpix, _usdt, _other],
        reads: const [100, 25, 7],
        tpixUsd: 0.1,
      );
      expect(out[_tpix.key]!.balance, 100);
      expect(out[_tpix.key]!.usdValue, closeTo(10, 1e-9));
      expect(out[_usdt.key]!.usdValue, 25);
      expect(out[_other.key]!.usdValue, isNull);
      expect(out[_tpix.key]!.chainId, 4289);
      expect(out[_tpix.key]!.isNative, isTrue);
    });

    test('อ่านพลาด (null) = คงยอดเดิมไว้', () {
      final first = TpixChainPortfolio.applyReads(
        previous: const {},
        tokens: const [_tpix, _usdt],
        reads: const [100, 25],
        tpixUsd: 0.1,
      );
      final second = TpixChainPortfolio.applyReads(
        previous: first,
        tokens: const [_tpix, _usdt],
        reads: const [null, null],
        tpixUsd: 0.1,
      );
      expect(second[_tpix.key]!.balance, 100);
      expect(second[_usdt.key]!.balance, 25);
    });

    test('ไม่เคยอ่านได้เลย = ไม่มีแถว (ไม่รู้ ≠ ศูนย์)', () {
      final out = TpixChainPortfolio.applyReads(
        previous: const {},
        tokens: const [_tpix, _usdt],
        reads: const [null, null],
        tpixUsd: 0.1,
      );
      expect(out, isEmpty);
    });

    test('native ที่อ่านได้ว่า 0 แสดงเป็น 0 จริง · โทเคน 0 ไม่แสดง', () {
      final out = TpixChainPortfolio.applyReads(
        previous: const {},
        tokens: const [_tpix, _usdt],
        reads: const [0, 0],
        tpixUsd: 0.1,
      );
      expect(out[_tpix.key]!.balance, 0);
      expect(out.containsKey(_usdt.key), isFalse);
    });

    test('โทเคนที่ขายหมดแล้ว (อ่านได้ 0) ถูกเอาออกจากรายการ', () {
      final first = TpixChainPortfolio.applyReads(
        previous: const {},
        tokens: const [_usdt],
        reads: const [25],
      );
      final second = TpixChainPortfolio.applyReads(
        previous: first,
        tokens: const [_usdt],
        reads: const [0],
      );
      expect(second.containsKey(_usdt.key), isFalse);
    });

    test('ยังไม่รู้ราคา TPIX = ไม่มีมูลค่า USD (ไม่เดาเป็น 0) แต่ USDT ยังนับได้', () {
      final out = TpixChainPortfolio.applyReads(
        previous: const {},
        tokens: const [_tpix, _usdt, _wtpix],
        reads: const [100, 3, 2],
      );
      expect(out[_tpix.key]!.usdValue, isNull);
      expect(out[_wtpix.key]!.usdValue, isNull);
      expect(out[_usdt.key]!.usdValue, 3);
    });

    test('ราคามาทีหลัง → ยอดที่คงไว้จากรอบก่อนได้มูลค่าใหม่ด้วย', () {
      final first = TpixChainPortfolio.applyReads(
        previous: const {},
        tokens: const [_tpix],
        reads: const [50],
      );
      final second = TpixChainPortfolio.applyReads(
        previous: first,
        tokens: const [_tpix],
        reads: const [null],
        tpixUsd: 0.2,
      );
      expect(second[_tpix.key]!.balance, 50);
      expect(second[_tpix.key]!.usdValue, closeTo(10, 1e-9));
    });
  });

  group('merge — ยอดเชน TPIX ที่อ่านเอง + ยอดจากเซิร์ฟเวอร์', () {
    test('native ของเซิร์ฟเวอร์ (0xEeee) กับของแอป (0x0) คือเหรียญเดียวกัน — ไม่ซ้ำ', () {
      final onchain = TpixChainPortfolio.ordered(TpixChainPortfolio.applyReads(
        previous: const {},
        tokens: const [_tpix, _usdt],
        reads: const [100, 25],
        tpixUsd: 0.1,
      ));
      final server = [
        TokenBalance.fromJson({
          'token_address': '0xEeeeeEeeeEeEeeEeEeEeeEEEeeeeEeeeeeeeEEeE',
          'symbol': 'TPIX',
          'name': 'TPIX',
          'decimals': 18,
          'balance': '99.5', // เซิร์ฟเวอร์แคชไว้ — ของที่อ่านจากเชนสดกว่า
          'is_native': true,
        }, chainId: 4289),
        TokenBalance.fromJson({
          'token_address': _usdtAddr,
          'symbol': 'USDT',
          'name': 'USDT',
          'decimals': 6,
          'balance': '25',
          'is_native': false,
        }, chainId: 4289),
      ];

      final merged =
          TpixChainPortfolio.merge(onchain: onchain, server: server);
      expect(merged.length, 2);
      expect(merged.first.symbol, 'TPIX');
      expect(merged.first.balance, 100);
    });

    test('ยอดเชนอื่นจากเซิร์ฟเวอร์ (BSC) เก็บไว้ครบ — USDT คนละเชนไม่ถูกรวมเป็นตัวเดียว', () {
      final onchain = TpixChainPortfolio.ordered(TpixChainPortfolio.applyReads(
        previous: const {},
        tokens: const [_usdt],
        reads: const [25],
      ));
      final bsc = [
        TokenBalance.fromJson({
          'token_address': '0x55d398326f99059fF775485246999027B3197955',
          'symbol': 'USDT',
          'name': 'Tether USD',
          'decimals': 18,
          'balance': '10',
          'is_native': false,
        }, chainId: 56),
      ];
      final merged = TpixChainPortfolio.merge(onchain: onchain, server: bsc);
      expect(merged.length, 2);
      expect(merged.map((b) => b.chainId).toSet(), {4289, 56});
    });

    test('เซิร์ฟเวอร์ 403 (ไม่มีข้อมูล) ยังได้ยอดเชน TPIX ครบ — ไม่ใช่ "ยังไม่มีสินทรัพย์"', () {
      final onchain = TpixChainPortfolio.ordered(TpixChainPortfolio.applyReads(
        previous: const {},
        tokens: const [_tpix, _usdt],
        reads: const [199.9, 19.99],
        tpixUsd: 0.1,
      ));
      final merged = TpixChainPortfolio.merge(onchain: onchain, server: const []);
      expect(merged, isNotEmpty);
      final total = merged.fold<double>(0, (s, b) => s + (b.usdValue ?? 0));
      expect(total, closeTo(39.98, 1e-9));
    });
  });

  group('TokenBalance.fromJson — ชื่อฟิลด์ที่อยู่โทเคน', () {
    test('อ่าน token_address (ชื่อที่ Web3BalanceService ส่งจริง)', () {
      final b = TokenBalance.fromJson({
        'token_address': _usdtAddr,
        'symbol': 'USDT',
        'name': 'USDT',
        'decimals': 6,
        'balance': '12.5',
        'is_native': false,
      }, chainId: 4289);
      expect(b.contractAddress, _usdtAddr);
      expect(b.balance, 12.5);
      expect(b.decimals, 6);
      expect(b.chainId, 4289);
      expect(b.isNative, isFalse);
    });

    test('ยังรับ contract_address ของ endpoint เดิมได้', () {
      final b = TokenBalance.fromJson({
        'contract_address': _usdtAddr,
        'symbol': 'USDT',
        'name': 'USDT',
        'balance': 1,
      });
      expect(b.contractAddress, _usdtAddr);
      expect(b.chainId, isNull);
    });

    test('native รู้จักทั้งธง is_native และที่อยู่ 0xEeee', () {
      final byFlag = TokenBalance.fromJson({
        'token_address': '0xEeeeeEeeeEeEeeEeEeEeeEEEeeeeEeeeeeeeEEeE',
        'symbol': 'BNB',
        'name': 'BNB',
        'balance': '0',
        'is_native': true,
      }, chainId: 56);
      final byAddr = TokenBalance.fromJson({
        'token_address': '0xEeeeeEeeeEeEeeEeEeEeeEEEeeeeEeeeeeeeEEeE',
        'symbol': 'BNB',
        'name': 'BNB',
        'balance': '0',
      }, chainId: 56);
      expect(byFlag.isNative, isTrue);
      expect(byAddr.isNative, isTrue);
      expect(byFlag.key, '56:native');
    });

    test('ไม่มี usd_value (เซิร์ฟเวอร์ไม่ส่ง) = null ไม่ใช่ 0', () {
      final b = TokenBalance.fromJson({
        'token_address': _usdtAddr,
        'symbol': 'USDT',
        'name': 'USDT',
        'balance': '3',
      });
      expect(b.usdValue, isNull);
    });
  });
}
