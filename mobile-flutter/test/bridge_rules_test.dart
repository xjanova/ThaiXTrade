/// TPIX TRADE — เทสต์กติกาของสะพาน USDT (พอร์ตเคสจาก TPIX Wallet + เว็บ ThaiXTrade)
///
/// เงินคำนวณเป็น BigInt ทั้งหมด — เทสต์ชุดนี้กันไม่ให้มีใครแอบเปลี่ยนไปใช้ double
/// (1 USDT บน BSC = 1e18 · 0.1 + 0.2 ≠ 0.3)
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/services/bridge_rules.dart';
import 'package:tpix_trade/services/bridge_service.dart';
import 'package:web3dart/crypto.dart' show bytesToHex;

BigInt b(Object v) => BigInt.parse(v.toString());

// ── calldata อ้างอิงจาก ethers v6.17 (ค่าเดียวกับเทสต์ของ TPIX Wallet — ไม่ได้คำนวณด้วยโค้ดเรา) ──
const _refBridgeBurn25To56 = '0x9f434051'
    '00000000000000000000000000000000000000000000000000000000017d7840'
    '0000000000000000000000000000000000000000000000000000000000000038'
    '0000000000000000000000000000000000000000000000000000000000000060'
    '000000000000000000000000000000000000000000000000000000000000002a'
    '3078663436313331433832383139643736323131363346343832623366653838'
    '6132323841373830376300000000000000000000000000000000000000000000';
const _refTransferBsc = '0xa9059cbb'
    '000000000000000000000000f46131c82819d7621163f482b3fe88a228a7807c'
    '000000000000000000000000000000000000000000000000ab54a8bb155ae000';

void main() {
  group('parseAmountToUnits', () {
    test('แปลงเป็นหน่วยเล็กสุดแบบเป๊ะ รวม USDT บน BSC ที่มี 18 ทศนิยม', () {
      expect(parseAmountToUnits('12.345678', 18), b('12345678000000000000'));
      expect(parseAmountToUnits('0.1', 6), b(100000));
      expect(parseAmountToUnits('1000', 6), b(1000000000));
      expect(parseAmountToUnits('1,000.5', 6), b(1000500000));
      // 0.1 + 0.2 ในหน่วยเล็กสุด = 0.3 พอดี (double ทำไม่ได้)
      expect(parseAmountToUnits('0.1', 18)! + parseAmountToUnits('0.2', 18)!, parseAmountToUnits('0.3', 18));
    });

    test('ทศนิยมเกิน 6 ตำแหน่ง → ไม่รับ แม้เชนต้นทางมี 18 (เศษจะเป็นฝุ่นค้างใน vault)', () {
      expect(parseAmountToUnits('1.1234567', 18), isNull);
      expect(parseAmountToUnits('1.1234567', 6), isNull);
      expect(parseAmountToUnits('1.123456', 18), b('1123456000000000000'));
    });

    test('อะไรที่ไม่ใช่ตัวเลขบวกธรรมดา → null', () {
      for (final bad in ['', 'abc', '-1', '1e6', '0x10', '1.2.3', ' . ', '.5', '5.', null]) {
        expect(parseAmountToUnits(bad, 6), isNull, reason: '$bad');
      }
    });
  });

  group('formatUnitsPlain / toUsdt6', () {
    test('ไม่มีเศษ float', () {
      expect(formatUnitsPlain(b('24700000000000000000'), 18), '24.7');
      expect(formatUnitsPlain(BigInt.one, 6), '0.000001');
      expect(formatUnitsPlain(BigInt.zero, 6), '0');
      expect(formatUnitsPlain(b(-300000), 6), '-0.3');
    });

    test('18 ทศนิยม (BSC) หารลงเป็น 6 · 6 ทศนิยมคงเดิม', () {
      expect(toUsdt6(b('25000000000000000000'), 18), b(25000000));
      expect(toUsdt6(b(25000000), 6), b(25000000));
      expect(toUsdt6(b(25), 0), b(25000000));
    });
  });

  group('ค่าธรรมเนียม', () {
    test('ถอน: หักค่าธรรมเนียมคงที่ของเชน และไม่ติดลบ', () {
      expect(withdrawReceiveUnits(b(25000000), '0.3'), b(24700000));
      expect(withdrawReceiveUnits(b(1000000), '1'), BigInt.zero);
      expect(withdrawReceiveUnits(b(500000), '1'), BigInt.zero);
    });

    test('ฝาก: ค่าธรรมเนียมฝากที่แอดมินตั้ง (ปกติ 0) — ไม่ติดลบ', () {
      expect(depositReceiveUnits(b(25000000), '0'), b(25000000));
      expect(depositReceiveUnits(b(25000000), null), b(25000000));
      expect(depositReceiveUnits(b(25000000), '0.5'), b(24500000));
      expect(depositReceiveUnits(b(400000), '0.5'), BigInt.zero);
    });
  });

  group('checkDepositAmount', () {
    final limits = {'min_deposit': '1', 'max_deposit_per_tx': '1000'};

    test('ต่ำกว่าขั้นต่ำ / ศูนย์ / อ่านไม่ออก → ห้ามส่ง', () {
      final r = checkDepositAmount(b(500000), limits);
      expect(r.error, 'bridge.errBelowMin');
      expect(r.params, {'min': '1'});
      expect(checkDepositAmount(BigInt.zero, limits).error, 'bridge.errAmountInvalid');
      expect(checkDepositAmount(null, limits).error, 'bridge.errAmountInvalid');
    });

    test('เกินเพดานต่อครั้งจริง → ห้ามส่ง (mint ไม่ได้เลย เงินจะค้าง vault) — แบบเว็บรุ่นปัจจุบัน', () {
      final r = checkDepositAmount(b(1500000000), limits);
      expect(r.error, 'bridge.errAboveMaxBlocked');
      expect(r.params, {'max': '1000'});
      // deposit_max_per_tx จากเซิร์ฟเวอร์ (min ของเว็บกับสัญญา) ชนะวงเงินเว็บ
      final tighter = checkDepositAmount(b(600000000), limits, effectiveMax: '500');
      expect(tighter.error, 'bridge.errAboveMaxBlocked');
      expect(tighter.params, {'max': '500'});
      expect(checkDepositAmount(b(1000000000), limits).blocked, isFalse);
    });

    test('เกินโควตา mint ที่เหลือวันนี้ → ส่งได้แต่เตือนว่าจะรอวันถัดไป', () {
      final r = checkDepositAmount(b(300000000), limits, remainingToday: '200');
      expect(r.blocked, isFalse);
      expect(r.warning, 'bridge.warnDailyCap');
      expect(r.warningParams, {'remaining': '200'});
      expect(checkDepositAmount(b(200000000), limits, remainingToday: '200').warning, isNull);
      // อ่านโควตาไม่ได้ = ไม่เดา ไม่เตือน
      expect(checkDepositAmount(b(300000000), limits, remainingToday: null).warning, isNull);
    });

    test('ไม่มีวงเงินจากเซิร์ฟเวอร์ → ขั้นต่ำ 1 USDT แบบเว็บ', () {
      expect(checkDepositAmount(b(999999), null).error, 'bridge.errBelowMin');
      expect(checkDepositAmount(b(1000000), null).blocked, isFalse);
    });
  });

  group('checkWithdrawAmount', () {
    final limits = {'min_withdraw': '2', 'max_withdraw_per_tx': '1000'};

    test('ลำดับเดียวกับเว็บ: ผิดรูป → ไม่เกินค่าธรรมเนียม → ต่ำกว่าขั้นต่ำ → ยอดไม่พอ', () {
      expect(checkWithdrawAmount(null, fee: '0.3', limits: limits).error, 'bridge.errAmountInvalid');
      expect(checkWithdrawAmount(b(300000), fee: '0.3', limits: limits).error, 'bridge.errFeeTooHigh');
      final belowMin = checkWithdrawAmount(b(1500000), fee: '0.3', limits: limits);
      expect(belowMin.error, 'bridge.errBelowMinWithdraw');
      expect(belowMin.params, {'min': '2'});
      expect(checkWithdrawAmount(b(5000000), fee: '0.3', limits: limits, balance: b(4000000)).error,
          'bridge.errInsufficient');
      expect(checkWithdrawAmount(b(5000000), fee: '0.3', limits: limits, balance: b(5000000)).blocked, isFalse);
    });

    test('อ่านยอดไม่ได้ (null) ≠ ศูนย์ — ไม่บล็อกตรงนี้', () {
      expect(checkWithdrawAmount(b(5000000), fee: '0.3', limits: limits).blocked, isFalse);
    });

    test('เกินต่อครั้ง = เตือนว่ารอทีมงานตรวจ (ไม่บล็อก)', () {
      final r = checkWithdrawAmount(b(1500000000), fee: '0.3', limits: limits);
      expect(r.blocked, isFalse);
      expect(r.warning, 'bridge.warnAboveMaxWithdraw');
    });
  });

  group('isBridgeAddress', () {
    test('ตัวเล็กล้วน และ checksum ที่ถูกต้อง → ผ่าน', () {
      expect(isBridgeAddress('0xf46131c82819d7621163f482b3fe88a228a7807c'), isTrue);
      expect(isBridgeAddress('0xf46131C82819d7621163F482b3fe88a228A7807c'), isTrue);
      expect(isBridgeAddress(' 0xf46131c82819d7621163f482b3fe88a228a7807c '), isTrue);
    });

    test('checksum ผิด / ที่อยู่ศูนย์ / รูปแบบผิด → ไม่ผ่าน', () {
      expect(isBridgeAddress('0xF46131C82819d7621163F482b3fe88a228A7807c'), isFalse);
      expect(isBridgeAddress('0x0000000000000000000000000000000000000000'), isFalse);
      expect(isBridgeAddress('0x1234'), isFalse);
      expect(isBridgeAddress(null), isFalse);
      expect(isBridgeAddress(42), isFalse);
    });
  });

  group('สถานะ', () {
    test('ป้าย/โทน/จบแล้ว — รวมสถานะใหม่ chain_paused (ยังไม่จบ) และ chain_disabled (จบ)', () {
      expect(stateBadge('minted').done, isTrue);
      expect(stateBadge('minted').tone, BridgeTone.success);
      expect(stateBadge('confirming').done, isFalse);
      expect(stateBadge('chain_paused').done, isFalse);
      expect(stateBadge('chain_paused').tone, BridgeTone.warning);
      expect(stateBadge('chain_disabled').done, isTrue);
      expect(stateBadge('chain_disabled').tone, BridgeTone.danger);
      expect(stateBadge('something_new').tone, BridgeTone.muted);
      expect(stateBadge('something_new').done, isFalse);
    });

    test('สถานะในประวัติแปลงเหมือนหน้าเว็บ', () {
      expect(usdtDepositRowState('needs_review'), 'review');
      expect(usdtDepositRowState('confirmed'), 'queued');
      expect(usdtWithdrawalRowState('pending'), 'queued');
      expect(usdtWithdrawalRowState('ignored'), 'below_fee');
      expect(usdtWithdrawalRowState('paid'), 'paid');
    });

    test('ผล /status → มุมมอง: ช่องผิดรูปถูกทิ้ง · ลิงก์ http ไม่รับ · refund', () {
      final v = BridgeStatusView.fromServer({
        'state': 'minted',
        'deposits': [
          {'mint_amount': '25', 'mint_url': 'https://explorer.tpix.online/tx/0xabc'},
          {'mint_amount': 'lots', 'mint_url': 'http://evil.test/tx'},
        ],
      });
      expect(v.state, 'minted');
      expect(v.rows.first.amount, '25');
      expect(v.rows.first.url, 'https://explorer.tpix.online/tx/0xabc');
      expect(v.rows.last.amount, isNull);
      expect(v.rows.last.url, isNull);

      expect(BridgeStatusView.fromServer({'state': '<script>'}).state, 'unknown');
      final disabled = BridgeStatusView.fromServer({'state': 'chain_disabled', 'refund': true});
      expect(disabled.needsRefund, isTrue);
      expect(BridgeStatusView.fromServer({'state': 'ignored'}).needsRefund, isTrue);
      expect(BridgeStatusView.fromServer({'state': 'minted'}).needsRefund, isFalse);
    });

    test('safeHttpsUrl รับเฉพาะ https ที่ไม่มี user:pass', () {
      expect(safeHttpsUrl('https://bscscan.com'), 'https://bscscan.com');
      expect(safeHttpsUrl('http://bscscan.com'), isNull);
      expect(safeHttpsUrl('javascript:alert(1)'), isNull);
      expect(safeHttpsUrl('https://user:pw@bscscan.com'), isNull);
      expect(explorerTx('https://bscscan.com/', '0xabc'), 'https://bscscan.com/tx/0xabc');
    });
  });

  group('รายการที่ติดตาม (encode/decode)', () {
    final h = '0x${'ab' * 32}';

    test('เข้ารหัสแล้วอ่านกลับได้ครบ รวมสถานะล่าสุด', () {
      final t = TrackedTransfer(
        kind: usdtWithdraw,
        chainId: 4289,
        txHash: h.toUpperCase().replaceFirst('0X', '0x'),
        targetChainId: 56,
        owner: '0xAAAA000000000000000000000000000000001111',
        amount: '25',
        status: const BridgeStatusView(state: 'paid', rows: [BridgeStatusRow(amount: '24.7', targetChainId: 56)]),
      );
      final back = decodeTracked(encodeTracked([t])).single;
      expect(back.txHash, h);
      expect(back.owner, '0xaaaa000000000000000000000000000000001111');
      expect(back.targetChainId, 56);
      expect(back.amount, '25');
      expect(back.status?.state, 'paid');
      expect(back.status?.rows.single.amount, '24.7');
    });

    test('ข้อมูลเสีย/แถวผิดรูป = ข้าม ไม่พัง', () {
      expect(decodeTracked('not json'), isEmpty);
      expect(decodeTracked('{"a":1}'), isEmpty);
      final list = decodeTracked('[{"kind":"deposit","chainId":56,"txHash":"0x12"},'
          '{"kind":"other","chainId":56,"txHash":"$h"},'
          '{"kind":"deposit","chainId":56,"txHash":"$h"}]');
      expect(list.length, 1);
    });

    test('เพิ่มซ้ำ = ย้ายขึ้นบนสุด ไม่เพิ่มแถว และเก็บสถานะเดิม', () {
      final a = TrackedTransfer(kind: usdtDeposit, chainId: 56, txHash: h, status: const BridgeStatusView(state: 'confirming'));
      final other = TrackedTransfer(kind: usdtDeposit, chainId: 56, txHash: '0x${'cd' * 32}');
      final list = addTracked(addTracked([a], other), TrackedTransfer(kind: usdtDeposit, chainId: 56, txHash: h));
      expect(list.length, 2);
      expect(list.first.txHash, h);
      expect(list.first.status?.state, 'confirming');
    });
  });

  group('calldata (เทียบกับ ethers v6.17)', () {
    test('bridgeBurn(uint256,uint256,string) — ที่อยู่ปลายทางเป็นสตริงตามที่พิมพ์', () {
      expect(
        bytesToHex(BridgeTx.encodeBridgeBurn(b(25000000), 56, '0xf46131C82819d7621163F482b3fe88a228A7807c'),
            include0x: true),
        _refBridgeBurn25To56,
      );
    });

    test('transfer(vault, 12.345678 USDT บน BSC = 18 ทศนิยม)', () {
      final units = parseAmountToUnits('12.345678', 18)!;
      expect(bytesToHex(BridgeTx.encodeTransfer('0xf46131c82819d7621163f482b3fe88a228a7807c', units), include0x: true),
          _refTransferBsc);
    });

    test('ที่อยู่/จำนวนผิดรูป → โยน ไม่ส่งไบต์เพี้ยน', () {
      expect(() => encodeErc20Transfer('0x1234', BigInt.one), throwsArgumentError);
      expect(() => encodeErc20Transfer('0xf46131c82819d7621163f482b3fe88a228a7807c', -BigInt.one), throwsArgumentError);
    });
  });
}
