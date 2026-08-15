/// TPIX TRADE — BSC Trade Token Registry Tests (mobile)
/// ตรวจ registry ให้ตรงเงื่อนไขเดียวกับฝั่งเว็บ (tests/js/config/bscTradeTokens.test.js)
/// Developed by Xman Studio
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/models/bsc_trade_tokens.dart';

void main() {
  // คู่ major 18 ตัวที่ backend seed ไว้ ต้องเทรดได้ครบ
  const seededMajors = [
    'BTC', 'ETH', 'BNB', 'SOL', 'XRP', 'DOGE', 'ADA', 'POL', 'AVAX',
    'DOT', 'LINK', 'UNI', 'LTC', 'TRX', 'ATOM', 'NEAR', 'SHIB', 'PEPE',
  ];

  group('BSC trade token registry', () {
    test('every token has a valid EVM address', () {
      final re = RegExp(r'^0x[a-fA-F0-9]{40}$');
      kBscTradeTokens.forEach((symbol, token) {
        expect(re.hasMatch(token.address), isTrue,
            reason: '$symbol address invalid');
      });
    });

    test('no duplicate addresses', () {
      final addresses = kBscTradeTokens.values
          .map((t) => t.address.toLowerCase())
          .toList();
      expect(addresses.toSet().length, addresses.length);
    });

    test('decimals are sane (0-18)', () {
      kBscTradeTokens.forEach((symbol, token) {
        expect(token.decimals, inInclusiveRange(0, 18),
            reason: '$symbol decimals');
      });
    });

    test('covers all 18 seeded major pairs plus USDT quote', () {
      for (final symbol in seededMajors) {
        expect(bscTradeToken(symbol), isNotNull,
            reason: '$symbol must be tradable');
      }
      expect(bscTradeToken('USDT'), isNotNull);
    });

    test('only BNB is native', () {
      final natives = kBscTradeTokens.entries
          .where((e) => e.value.native)
          .map((e) => e.key)
          .toList();
      expect(natives, ['BNB']);
    });

    test('non-native tokens declare accepted on-chain symbols', () {
      kBscTradeTokens.forEach((symbol, token) {
        if (token.native) return;
        expect(token.onchainSymbols, isNotEmpty,
            reason: '$symbol onchainSymbols');
      });
    });

    test('lookup is case-insensitive and null-safe', () {
      expect(bscTradeToken('btc'), same(kBscTradeTokens['BTC']));
      expect(bscTradeToken('TPIX'), isNull); // TPIX รอเชน TPIX — ยังไม่เปิด
      expect(bscTradeToken(''), isNull);
      expect(bscTradeToken(null), isNull);
    });

    test('pair tradability requires both sides in registry', () {
      expect(isBscTradablePair('BTC', 'USDT'), isTrue);
      expect(isBscTradablePair('TPIX', 'USDT'), isFalse);
      expect(isBscTradablePair('BTC', 'XYZ'), isFalse);
    });
  });
}
