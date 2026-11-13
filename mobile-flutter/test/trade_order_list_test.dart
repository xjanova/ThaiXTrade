/// TPIX TRADE — รายการคำสั่ง/ประวัติ: โหลดไม่ได้ ≠ ว่าง · ฝั่งที่ไม่รู้จัก ≠ ขาย (L10 / U1)
///
/// สิ่งที่กันไว้:
///   - 403 / เน็ตหลุด / 5xx ต้องไม่กลายเป็น "ไม่มีข้อมูล" — แยก ต้องยืนยันกระเป๋า / โหลดไม่ได้ / ว่างจริง
///   - `side` ที่ไม่ใช่ buy/sell (เช่น swap, bridge, ว่าง) = เป็นกลาง ไม่ใช่ "ขาย"
///   - คำสั่งตั้งราคาจากหน้าเว็บ (`source: limit`): รอราคา (ยกเลิกได้) / รอผลบนเชน (ยกเลิกไม่ได้)
///   - หัว X-Locale ส่งเฉพาะภาษาที่เซิร์ฟเวอร์รองรับ
///
/// Developed by Xman Studio
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/core/locale/app_locale.dart';
import 'package:tpix_trade/models/api_models.dart';
import 'package:tpix_trade/services/api_service.dart';

Map<String, dynamic> _limitRow({String status = 'open', String side = 'buy'}) => {
      'id': '0b2c7a52-6f0e-4d5e-9c1a-3f1f5f0a9e10',
      'pair': 'BTC-USDT',
      'network_chain_id': 56,
      'side': side,
      'type': 'limit',
      'price': '65000.5',
      'amount': '0.01',
      'total': '650.005',
      'min_receive': '0.00995',
      'status': status,
      'source': 'limit',
      'expires_at': '2026-12-01T00:00:00+00:00',
      'created_at': '2026-11-13T08:00:00+00:00',
    };

void main() {
  group('parseOrderList — โหลดไม่ได้ ≠ ว่าง', () {
    test('403 = ต้องยืนยันกระเป๋า', () {
      final r = ApiService.parseOrderList(403, {
        'success': false,
        'error': {'code': 'WALLET_NOT_VERIFIED'},
      });
      expect(r.state, ListLoadState.needsWalletVerify);
      expect(r.items, isEmpty);
    });

    test('เน็ตหลุด / 5xx / คำตอบผิดรูป = โหลดไม่ได้', () {
      expect(ApiService.parseOrderList(null, null).state, ListLoadState.failed);
      expect(ApiService.parseOrderList(500, {'success': false}).state,
          ListLoadState.failed);
      expect(ApiService.parseOrderList(200, '<html>').state,
          ListLoadState.failed);
      expect(ApiService.parseOrderList(200, {'success': true, 'data': 'x'}).state,
          ListLoadState.failed);
    });

    test('สำเร็จและว่างจริง = ok + รายการว่าง', () {
      final r = ApiService.parseOrderList(200, {'success': true, 'data': []});
      expect(r.state, ListLoadState.ok);
      expect(r.isOk, isTrue);
      expect(r.items, isEmpty);
    });

    test('แถวผิดรูปถูกข้าม ไม่ทำให้ทั้งรายการล้ม', () {
      final r = ApiService.parseOrderList(200, {
        'success': true,
        'data': [
          _limitRow(),
          'garbage',
          {'id': 7, 'pair': 'ETH/USDT', 'side': 'sell', 'amount': 1},
        ],
      });
      expect(r.isOk, isTrue);
      expect(r.items, hasLength(2));
      expect(r.items[1].id, '7');
    });
  });

  group('TradeOrder — ฝั่งของไม้', () {
    TradeOrder order(Object? side) =>
        TradeOrder.fromJson({'id': 1, 'pair': 'BTC/USDT', 'side': side, 'amount': 1});

    test('buy / sell (ไม่สนตัวพิมพ์/ช่องว่าง)', () {
      expect(order('buy').tradeSide, TradeSide.buy);
      expect(order(' BUY ').isBuy, isTrue);
      expect(order('Sell').tradeSide, TradeSide.sell);
      expect(order('sell').isSell, isTrue);
    });

    test('อย่างอื่นทั้งหมด = เป็นกลาง (ไม่ใช่ขาย และไม่เดาเป็นซื้อ)', () {
      for (final side in <Object?>['swap', 'bridge', '', null, 'order_buy', 42]) {
        final o = order(side);
        expect(o.tradeSide, TradeSide.neutral, reason: '$side');
        expect(o.isBuy, isFalse);
        expect(o.isSell, isFalse);
      }
    });

    test('ชื่อคู่: BTC/USDT และ btc-usdt คือคู่เดียวกัน', () {
      expect(order('buy').pairKey, 'BTC-USDT');
      expect(TradeOrder.normalizePair(' btc-usdt '), 'BTC-USDT');
    });
  });

  group('คำสั่งตั้งราคาจากหน้าเว็บ (source: limit)', () {
    test('รอราคา: อ่านคู่/ฝั่ง/ราคา/จำนวนครบ และยกเลิกได้', () {
      final o = TradeOrder.fromJson(_limitRow());
      expect(o.isLimitWatch, isTrue);
      expect(o.isWaitingForPrice, isTrue);
      expect(o.isAwaitingChain, isFalse);
      expect(o.isCancellable, isTrue);
      expect(o.pairKey, 'BTC-USDT');
      expect(o.tradeSide, TradeSide.buy);
      expect(o.type, 'limit');
      expect(o.price, 65000.5);
      expect(o.amount, 0.01);
      expect(o.minReceive, 0.00995);
      expect(o.networkChainId, 56);
      expect(o.expiresAt, isNotNull);
    });

    test('ส่งธุรกรรมแล้ว รอผลบนเชน: ยกเลิกไม่ได้', () {
      final o = TradeOrder.fromJson(_limitRow(status: 'submitted', side: 'sell'));
      expect(o.isAwaitingChain, isTrue);
      expect(o.isWaitingForPrice, isFalse);
      expect(o.isCancellable, isFalse);
      expect(o.tradeSide, TradeSide.sell);
    });

    test('คำสั่งชนิดเดิม (ไม่ใช่ source: limit) ยังยกเลิกได้ตามเดิม', () {
      final o = TradeOrder.fromJson({
        'id': 'x',
        'pair': 'TPIX-USDT',
        'side': 'buy',
        'type': 'limit',
        'amount': 5,
        'status': 'pending',
        'source': 'internal',
      });
      expect(o.isLimitWatch, isFalse);
      expect(o.isCancellable, isTrue);
    });
  });

  group('AppLocale — หัว X-Locale (L15)', () {
    tearDown(() => AppLocale.set('en'));

    test('ส่งภาษาปัจจุบันของแอป', () {
      AppLocale.set('th');
      expect(AppLocale.headers(), {'X-Locale': 'th'});
      AppLocale.set('EN ');
      expect(AppLocale.headers(), {'X-Locale': 'en'});
    });

    test('ค่าที่เซิร์ฟเวอร์ไม่รองรับถูกเมิน (คงค่าเดิม)', () {
      AppLocale.set('th');
      AppLocale.set('fr');
      AppLocale.set(null);
      AppLocale.set('');
      expect(AppLocale.code, 'th');
    });
  });
}
