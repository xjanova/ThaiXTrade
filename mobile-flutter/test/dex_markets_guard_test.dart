/// TPIX TRADE — คู่บนเชน TPIX ต้องไม่ปลอมตัวเป็นคู่ตลาดหลัก
///
/// สิ่งที่กันไว้:
///   - ใครก็สร้างเหรียญชื่อ SUI บนเชน TPIX แล้วเปิดพูล SUI-USDT ได้ — ผู้ใช้ที่กดแถว
///     SUI-USDT (ราคาจาก Binance) ต้องไม่ถูกพาไปซื้อเหรียญปลอมบน DEX
///   - เหรียญปลอมชื่อ USDT/TPIX (ที่อยู่ไม่ตรงตัวจริง) ต้องไม่ถูกนับเป็นสกุลหลัก
///
/// Developed by Xman Studio
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/models/api_models.dart';
import 'package:tpix_trade/providers/market_provider.dart';

const _usdt = '0x0A4Ee87551eF041f3EecFBBfdcfFC1B46a4878f1';
const _native = '0x0000000000000000000000000000000000000000';
const _idtc = '0x1234567890abcdef1234567890abcdef12345678';

Ticker _t(String symbol, {bool dex = false}) => Ticker(
      symbol: symbol,
      baseAsset: symbol.split('-').first,
      quoteAsset: symbol.split('-').last,
      lastPrice: 1,
      priceChange: 0,
      priceChangePercent: 0,
      high24h: 0,
      low24h: 0,
      volume24h: 0,
      quoteVolume24h: 0,
      isDex: dex,
    );

DexPairInfo _pair(String base, String baseAddr, String quote, String quoteAddr) =>
    DexPairInfo(
      symbol: '$base-$quote',
      baseAsset: base,
      quoteAsset: quote,
      baseAddress: baseAddr,
      quoteAddress: quoteAddr,
      baseDecimals: 18,
      quoteDecimals: 6,
    );

void main() {
  group('shadowedSymbols', () {
    test('คู่บนเชนชื่อชนคู่ตลาดหลัก = ถูกตัด', () {
      final s = MarketProvider.shadowedSymbols(
        ['SUI-USDT', 'IDTC-USDT', 'TPIX-USDT'],
        [_t('SUI-USDT'), _t('BTC-USDT'), _t('TPIX-USDT', dex: true)],
      );
      expect(s, {'SUI-USDT'});
    });

    test('ชื่อไม่ชน = ไม่ถูกตัด', () {
      expect(
        MarketProvider.shadowedSymbols(['IDTC-USDT', 'IDTC-TPIX'], [_t('BTC-USDT')]),
        isEmpty,
      );
    });
  });

  group('genuineLegs', () {
    test('IDTC-USDT / IDTC-TPIX ตัวจริงผ่าน', () {
      expect(MarketProvider.genuineLegs(_pair('IDTC', _idtc, 'USDT', _usdt), _usdt),
          isTrue);
      expect(
          MarketProvider.genuineLegs(_pair('IDTC', _idtc, 'TPIX', _native), _usdt),
          isTrue);
      expect(
          MarketProvider.genuineLegs(_pair('TPIX', _native, 'USDT', _usdt), _usdt),
          isTrue);
    });

    test('USDT ปลอม (ที่อยู่ไม่ตรง) ไม่ผ่าน', () {
      expect(MarketProvider.genuineLegs(_pair('IDTC', _idtc, 'USDT', _idtc), _usdt),
          isFalse);
    });

    test('TPIX ปลอม (ไม่ใช่เนทีฟ) ไม่ผ่าน', () {
      expect(MarketProvider.genuineLegs(_pair('TPIX', _idtc, 'USDT', _usdt), _usdt),
          isFalse);
    });

    test('ยังไม่รู้ที่อยู่ USDT จริง = คู่ USDT ไม่ผ่าน (fail-closed)', () {
      expect(MarketProvider.genuineLegs(_pair('IDTC', _idtc, 'USDT', _usdt), null),
          isFalse);
    });
  });
}
