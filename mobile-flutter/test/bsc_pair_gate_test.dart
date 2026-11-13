/// TPIX TRADE — คู่ไหนส่งเข้า PancakeSwap ได้ (C2)
///
/// สิ่งที่กันไว้ (กติกาเดียวกับ `isBscTradable` / `bscPairPending` ใน Trade.vue):
///   - อยู่ในทะเบียนเหรียญของแอปอย่างเดียว **ไม่พอ** — เซิร์ฟเวอร์ต้องยืนยันว่าคู่นี้เปิดอยู่
///     บนเชน 56 แบบ onchain และที่อยู่เหรียญตรงกับทะเบียน (ไม่สนตัวพิมพ์)
///   - ทะเบียนคู่ยังโหลดไม่เสร็จ / โหลดไม่สำเร็จ = ยังส่งไม่ได้ พร้อมเหตุที่บอกได้ (ไม่ปล่อยผ่านเงียบ ๆ)
///
/// Developed by Xman Studio
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/models/api_models.dart';
import 'package:tpix_trade/models/bsc_trade_tokens.dart';

const _btcb = '0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c';
const _usdt = '0x55d398326f99059fF775485246999027B3197955';

TradingPairInfo _pair({
  String symbol = 'BTC-USDT',
  int? networkChainId = 56,
  String executionMode = 'onchain',
  String? baseAddress = _btcb,
  String? quoteAddress = _usdt,
  bool isActive = true,
}) =>
    TradingPairInfo(
      symbol: symbol,
      baseAsset: symbol.split('-').first,
      quoteAsset: symbol.split('-').last,
      minTradeAmount: 0,
      maxTradeAmount: 0,
      pricePrecision: 2,
      amountPrecision: 4,
      networkChainId: networkChainId,
      executionMode: executionMode,
      baseAddress: baseAddress,
      quoteAddress: quoteAddress,
      isActive: isActive,
    );

BscPairGate _gate(
  TradingPairInfo? pair, {
  String base = 'BTC',
  String quote = 'USDT',
  bool loaded = true,
  bool failed = false,
}) =>
    bscPairGate(
      baseSymbol: base,
      quoteSymbol: quote,
      serverPair: pair,
      pairsLoaded: loaded,
      pairsLoadFailed: failed,
    );

void main() {
  test('เซิร์ฟเวอร์ยืนยันครบ = เทรดได้', () {
    expect(_gate(_pair()), BscPairGate.tradable);
  });

  test('ที่อยู่เหรียญเทียบแบบไม่สนตัวพิมพ์', () {
    expect(
      _gate(_pair(
          baseAddress: _btcb.toLowerCase(), quoteAddress: _usdt.toUpperCase().replaceFirst('0X', '0x'))),
      BscPairGate.tradable,
    );
  });

  test('BNB: เซิร์ฟเวอร์เก็บ native เป็น 0x000… แอปใช้ 0xEeee… = ตัวเดียวกัน', () {
    final pair = _pair(
      symbol: 'BNB-USDT',
      baseAddress: '0x0000000000000000000000000000000000000000',
    );
    expect(_gate(pair, base: 'BNB'), BscPairGate.tradable);
    expect(sameBscTokenAddress(kNativeTokenAddress,
        '0x0000000000000000000000000000000000000000'), isTrue);
  });

  test('ไม่อยู่ในทะเบียนเหรียญของแอป = ไม่ใช่คู่ BSC (ไม่ว่าเซิร์ฟเวอร์จะว่าอย่างไร)', () {
    expect(_gate(_pair(symbol: 'TPIX-USDT'), base: 'TPIX'),
        BscPairGate.notInRegistry);
    expect(_gate(null, base: 'BTC', quote: 'XYZ'), BscPairGate.notInRegistry);
  });

  test('ทะเบียนคู่ยังโหลดไม่เสร็จ = กำลังตรวจ (ห้ามส่ง)', () {
    expect(_gate(null, loaded: false), BscPairGate.checking);
  });

  test('โหลดทะเบียนคู่ไม่สำเร็จ = ตรวจไม่ได้ (ห้ามส่ง)', () {
    expect(_gate(null, loaded: false, failed: true), BscPairGate.checkFailed);
  });

  test('โหลดสำเร็จแล้วแต่ไม่มีคู่นี้ (แอดมินปิด/ลบ) = ไม่เปิดให้เทรด', () {
    expect(_gate(null), BscPairGate.unavailable);
  });

  test('คู่อยู่เชนอื่น / ไม่มีเลขเชน = ไม่เปิดให้เทรด', () {
    expect(_gate(_pair(networkChainId: 1)), BscPairGate.unavailable);
    expect(_gate(_pair(networkChainId: 4289)), BscPairGate.unavailable);
    expect(_gate(_pair(networkChainId: null)), BscPairGate.unavailable);
  });

  test('คู่ดัชนี (ดูราคาอย่างเดียว) = ไม่เปิดให้เทรด', () {
    expect(_gate(_pair(executionMode: 'index')), BscPairGate.unavailable);
  });

  test('เซิร์ฟเวอร์บอกว่าปิด (is_active false) = ไม่เปิดให้เทรด', () {
    expect(_gate(_pair(isActive: false)), BscPairGate.unavailable);
  });

  test('ที่อยู่เหรียญไม่ตรงทะเบียน / ไม่มี = ไม่เปิดให้เทรด', () {
    expect(
      _gate(_pair(
          baseAddress: '0x1111111111111111111111111111111111111111')),
      BscPairGate.unavailable,
    );
    expect(_gate(_pair(quoteAddress: null)), BscPairGate.unavailable);
    expect(_gate(_pair(baseAddress: '')), BscPairGate.unavailable);
    // base/quote สลับกัน
    expect(_gate(_pair(baseAddress: _usdt, quoteAddress: _btcb)),
        BscPairGate.unavailable);
  });

  test('แถวของคู่ยังอยู่จากรอบก่อน แม้รอบล่าสุดโหลดไม่สำเร็จ = ตัดสินจากแถวนั้น', () {
    expect(_gate(_pair(), loaded: false, failed: true), BscPairGate.tradable);
  });
}
