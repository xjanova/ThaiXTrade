/// TPIX TRADE — กติกาของสะพาน USDT (ฟังก์ชันล้วน ไม่มี widget / เน็ต / เก็บข้อมูล) ทดสอบได้ตรงๆ
///
/// พอร์ตจาก TPIX Wallet (lib/services/bridge_rules.dart) ซึ่งพอร์ตมาจากเว็บอีกทอด:
///   resources/js/utils/bridge.js — สะพาน USDT หลายเชน ⇄ USDT_TPIX (เชน 4289)
/// กติกาต้องตรงกับเว็บทุกข้อ — เว็บกับแอปตัดสิน "กดได้/ไม่ได้" ต่างกันไม่ได้
/// (รวมของใหม่ฝั่งเว็บ: ฝากเกินเพดานต่อครั้งจริง = ห้ามส่ง · ค่าธรรมเนียมฝาก · chain_paused/chain_disabled)
///
/// กติกาเงิน: ทุกจำนวนเป็น BigInt หน่วยเล็กสุด — ห้ามผ่าน double
/// (1 USDT บน BSC = 1e18 · 0.1 + 0.2 ≠ 0.3 ในโลก float)
///
/// Developed by Xman Studio
library;

import 'dart:convert';
import 'dart:typed_data';

import '../utils/evm_address.dart' as evm;

/// USDT_TPIX ใช้ 6 ทศนิยม — ฝั่ง mint ตัดเศษที่เกินนี้ทิ้งอยู่แล้ว จึงไม่ให้กรอกเกินตั้งแต่หน้าจอ
const int usdtTpixDecimals = 6;

/// เชน TPIX — ตรึงไว้ในแอป เซิร์ฟเวอร์บอกเลขอื่นมา = ไม่เปิดฟอร์ม
const int bridgeTpixChainId = 4289;

/// ชนิดรายการของสะพาน USDT
const String usdtDeposit = 'deposit';
const String usdtWithdraw = 'withdraw';

final _amountPattern = RegExp(r'^\d+(\.\d+)?$');
final _txHashPattern = RegExp(r'^0x[0-9a-fA-F]{64}$');
final _addressPattern = RegExp(r'^0x[0-9a-fA-F]{40}$');
final _zeroAddressPattern = RegExp(r'^0x0{40}$');
final _ten = BigInt.from(10);

/// ค่าจาก JSON → ข้อความ (เซิร์ฟเวอร์ส่งเป็นสตริงเสมอ แต่รับตัวเลขไว้ด้วย) — null/อย่างอื่น = null
String? bridgeText(Object? v) => v is String ? v : (v is num ? v.toString() : null);

/// tx hash รูปแบบถูกต้อง (0x + 64 hex)
bool isTxHash(String? s) => s != null && _txHashPattern.hasMatch(s.trim());

// ════════════════════════════════════════════════════════════════
//  หน่วยเงิน
// ════════════════════════════════════════════════════════════════

/// ข้อความจากช่องกรอก → BigInt หน่วยเล็กสุดของเหรียญ — null ถ้าไม่ใช่ตัวเลขที่รับได้
///
/// จำกัดทศนิยมไม่เกิน 6 ตำแหน่งเสมอ (แม้เหรียญต้นทางจะมี 18 เช่น USDT บน BSC) เพราะ USDT_TPIX มี 6
/// ตัวเลขที่เกินจะกลายเป็นฝุ่นค้างใน vault ที่ผู้ใช้ไม่ได้คืน
/// (จุลภาคถือเป็นตัวคั่นหลักพัน เหมือนเว็บ — ช่องกรอกในแอปแปลง , เป็น . ก่อนถึงตรงนี้)
BigInt? parseAmountToUnits(Object? input, int decimals) {
  final text = (input?.toString() ?? '').trim().replaceAll(',', '');
  if (!_amountPattern.hasMatch(text) || decimals < 0 || decimals > 36) return null;

  final parts = text.split('.');
  final whole = parts[0];
  final frac = parts.length > 1 ? parts[1] : '';
  final maxFrac = decimals < usdtTpixDecimals ? decimals : usdtTpixDecimals;
  if (frac.length > maxFrac) return null;
  // กันตัวเลขยาวผิดปกติ (วางข้อความขยะ) — ยอดจริงไม่มีทางเกิน 30 หลัก
  if (whole.length > 30) return null;

  final fracUnits = decimals == 0
      ? BigInt.zero
      : BigInt.parse(frac.padRight(decimals, '0').substring(0, decimals));
  return BigInt.parse(whole) * _ten.pow(decimals) + fracUnits;
}

/// BigInt หน่วยเล็กสุด → ข้อความทศนิยมเต็มความแม่นยำ (ตัดศูนย์ท้าย ไม่มีคอมมา) เช่น 1500000/6 → "1.5"
String formatUnitsPlain(BigInt value, int decimals) {
  final negative = value.isNegative;
  final abs = value.abs();
  if (decimals <= 0) return '${negative ? '-' : ''}$abs';
  final divisor = _ten.pow(decimals);
  final whole = abs ~/ divisor;
  final frac = (abs % divisor).toString().padLeft(decimals, '0').replaceFirst(RegExp(r'0+$'), '');
  final body = frac.isEmpty ? '$whole' : '$whole.$frac';
  return negative ? '-$body' : body;
}

/// ข้อความ USDT (เช่น '0.3' จาก API) → BigInt 6 ทศนิยม — อ่านไม่ออกถือเป็น 0
BigInt usdtToUnits(Object? text) => parseAmountToUnits(text, usdtTpixDecimals) ?? BigInt.zero;

/// ข้อความ USDT จาก API → BigInt 6 ทศนิยม · ไม่มีค่า/อ่านไม่ออก = null (ไม่เดาว่าเป็น 0)
BigInt? usdtToUnitsOrNull(Object? text) {
  final s = bridgeText(text);
  if (s == null || s.isEmpty) return null;
  return parseAmountToUnits(s, usdtTpixDecimals);
}

/// แปลงหน่วยของเหรียญต้นทาง → หน่วย 6 ทศนิยม (เทียบวงเงิน)
/// เชน 18 ทศนิยมหารลง — กรอกได้แค่ 6 ตำแหน่งอยู่แล้ว เศษจึงเป็นศูนย์เสมอ
BigInt toUsdt6(BigInt units, int decimals) {
  final diff = decimals - usdtTpixDecimals;
  return diff >= 0 ? units ~/ _ten.pow(diff) : units * _ten.pow(-diff);
}

/// ยอดที่ได้รับจริงเมื่อถอน = จำนวน − ค่าธรรมเนียม (หน่วย 6 ทศนิยม) — ไม่ติดลบ
BigInt withdrawReceiveUnits(BigInt amountUnits, Object? feeText) {
  final fee = usdtToUnits(feeText);
  return amountUnits > fee ? amountUnits - fee : BigInt.zero;
}

/// ยอดที่ได้รับจริงเมื่อฝาก = จำนวน − ค่าธรรมเนียมฝาก (หน่วย 6 ทศนิยม) — ไม่ติดลบ
/// ปกติค่าธรรมเนียมฝากเป็น 0 แต่แอดมินตั้งได้ จึงห้ามเขียน "ฟรี" ตายตัว
BigInt depositReceiveUnits(BigInt units6, Object? feeText) {
  final fee = usdtToUnits(feeText ?? '0');
  return units6 > fee ? units6 - fee : BigInt.zero;
}

/// calldata ของ ERC-20 transfer(address to, uint256 amount)
/// selector 0xa9059cbb = keccak256("transfer(address,uint256)")[0..4]
Uint8List encodeErc20Transfer(String to, BigInt amount) {
  if (amount.isNegative) throw ArgumentError('amount must be >= 0');
  final addr = to.trim().toLowerCase().replaceFirst('0x', '');
  if (!RegExp(r'^[0-9a-f]{40}$').hasMatch(addr)) {
    throw ArgumentError('invalid recipient address');
  }
  final amountHex = amount.toRadixString(16);
  if (amountHex.length > 64) throw ArgumentError('amount overflows uint256');
  final hex = 'a9059cbb${addr.padLeft(64, '0')}${amountHex.padLeft(64, '0')}';
  final out = Uint8List(hex.length ~/ 2);
  for (var i = 0; i < out.length; i++) {
    out[i] = int.parse(hex.substring(i * 2, i * 2 + 2), radix: 16);
  }
  return out;
}

// ════════════════════════════════════════════════════════════════
//  ตรวจยอด
// ════════════════════════════════════════════════════════════════

/// ผลตรวจยอด — [error] = ห้ามกดส่ง · [warning] = ส่งได้แต่จะช้า/รอทีมงานตรวจ
/// ทั้งสองเป็นคีย์ข้อความ + ค่าที่ต้องแทนใน {..}
class AmountCheck {
  final String? error;
  final Map<String, String> params;
  final String? warning;
  final Map<String, String> warningParams;

  const AmountCheck({
    this.error,
    this.params = const {},
    this.warning,
    this.warningParams = const {},
  });

  static const ok = AmountCheck();

  bool get blocked => error != null;
}

/// ตรวจยอดฝากกับวงเงินจากเซิร์ฟเวอร์ (หน่วย 6 ทศนิยม) — ลำดับเดียวกับเว็บ
///
/// - ต่ำกว่าขั้นต่ำ = error (ไม่ถูก mint)
/// - เกินเพดานต่อครั้งที่ใช้ได้จริง ([effectiveMax] = deposit_max_per_tx = min(วงเงินเว็บ, maxPerTx บนสัญญา))
///   = error — mint ไม่ได้เลยแม้ทีมงานอนุมัติ เงินจะค้าง vault (เว็บเปลี่ยนจากคำเตือนเป็นห้ามส่งแล้ว)
/// - เกินโควตา mint ที่เหลือของวันนี้บนสัญญา ([remainingToday]) = warning — รอโควตาวันถัดไป (ช้า)
AmountCheck checkDepositAmount(
  BigInt? units,
  Map<String, dynamic>? limits, {
  Object? effectiveMax,
  Object? remainingToday,
}) {
  if (units == null || units <= BigInt.zero) {
    return const AmountCheck(error: 'bridge.errAmountInvalid');
  }

  final minText = bridgeText(limits?['min_deposit']) ?? '1';
  if (units < usdtToUnits(minText)) {
    return AmountCheck(error: 'bridge.errBelowMin', params: {'min': minText});
  }

  final maxText = bridgeText(effectiveMax) ?? bridgeText(limits?['max_deposit_per_tx']) ?? '0';
  final max = usdtToUnits(maxText);
  if (max > BigInt.zero && units > max) {
    return AmountCheck(error: 'bridge.errAboveMaxBlocked', params: {'max': maxText});
  }

  final remaining = usdtToUnitsOrNull(remainingToday);
  if (remaining != null && units > remaining) {
    return AmountCheck(
      warning: 'bridge.warnDailyCap',
      warningParams: {'remaining': formatUnitsPlain(remaining, usdtTpixDecimals)},
    );
  }

  return AmountCheck.ok;
}

/// ตรวจยอดถอน (หน่วย 6 ทศนิยม) — ลำดับเดียวกับหน้าเว็บ
///
/// [balance] = ยอด USDT_TPIX ที่อ่านได้ (null = อ่านไม่ได้ ไม่บล็อกตรงนี้ — ด่านก่อนส่งอ่านใหม่อีกรอบ)
/// เกิน max_withdraw_per_tx = warning (รอทีมงานตรวจ) เหมือนเว็บ
AmountCheck checkWithdrawAmount(
  BigInt? units, {
  required Object? fee,
  required Map<String, dynamic>? limits,
  BigInt? balance,
}) {
  if (units == null || units <= BigInt.zero) {
    return const AmountCheck(error: 'bridge.errAmountInvalid');
  }
  if (withdrawReceiveUnits(units, fee) <= BigInt.zero) {
    return const AmountCheck(error: 'bridge.errFeeTooHigh');
  }
  final minText = bridgeText(limits?['min_withdraw']) ?? '0';
  if (units < usdtToUnits(minText)) {
    return AmountCheck(error: 'bridge.errBelowMinWithdraw', params: {'min': minText});
  }
  if (balance != null && units > balance) {
    return const AmountCheck(error: 'bridge.errInsufficient');
  }
  final maxText = bridgeText(limits?['max_withdraw_per_tx']);
  final max = usdtToUnitsOrNull(maxText);
  if (max != null && max > BigInt.zero && units > max) {
    return AmountCheck(warning: 'bridge.warnAboveMaxWithdraw', warningParams: {'max': maxText!});
  }
  return AmountCheck.ok;
}

/// ที่อยู่ EVM ใช้ได้ไหม — ตัวพิมพ์ผสมต้องตรง checksum (EIP-55) ไม่งั้นแปลว่าพิมพ์ผิด
/// ที่อยู่ศูนย์ล้วนใช้ไม่ได้ (เงินหาย) · ใช้ตัวตรวจ EIP-55 ตัวเดียวกับทั้งแอป
bool isBridgeAddress(Object? address) {
  if (address is! String) return false;
  final text = address.trim();
  if (!_addressPattern.hasMatch(text)) return false;
  if (_zeroAddressPattern.hasMatch(text)) return false;
  return evm.isValidEvmAddress(text);
}

// ════════════════════════════════════════════════════════════════
//  สถานะ
// ════════════════════════════════════════════════════════════════

/// โทนของป้ายสถานะ — ทุกจุดในหน้าใช้ชุดเดียวกัน
enum BridgeTone { success, info, warning, danger, muted }

class StateBadge {
  final String labelKey;
  final BridgeTone tone;

  /// จบแล้ว — เลิกถามเซิร์ฟเวอร์
  final bool done;

  const StateBadge(this.labelKey, this.tone, this.done);
}

/// สถานะที่จบแล้วของสะพาน USDT — เลิกถามเซิร์ฟเวอร์
/// (chain_paused ไม่จบ: เปิดเชนใหม่เมื่อไหร่ตัวสแกนไล่ต่อให้เอง)
const Set<String> usdtTerminalStates = {
  'minted', 'paid', 'rejected', 'failed', 'not_a_deposit', 'not_a_withdrawal',
  'ignored', 'below_fee', 'unsupported_chain', 'chain_disabled',
};

/// สถานะที่เงินอยู่ใน vault แล้วแต่จะไม่ถูก mint อัตโนมัติ — ต้องบอกผู้ใช้ว่าติดต่อขอคืนได้
const Set<String> usdtRefundStates = {'chain_disabled', 'ignored'};

const Map<String, BridgeTone> _usdtTones = {
  'minted': BridgeTone.success,
  'paid': BridgeTone.success,
  'confirming': BridgeTone.info,
  'queued': BridgeTone.info,
  'minting': BridgeTone.info,
  'sending': BridgeTone.info,
  'pending': BridgeTone.info,
  'review': BridgeTone.warning,
  'awaiting_liquidity': BridgeTone.warning,
  'unknown': BridgeTone.warning,
  'chain_paused': BridgeTone.warning,
  'rejected': BridgeTone.danger,
  'failed': BridgeTone.danger,
  'not_found': BridgeTone.danger,
  'not_a_deposit': BridgeTone.danger,
  'not_a_withdrawal': BridgeTone.danger,
  'unsupported_chain': BridgeTone.danger,
  'chain_disabled': BridgeTone.danger,
  'ignored': BridgeTone.muted,
  'below_fee': BridgeTone.muted,
};

/// สถานะ → ป้าย + โทนสี
StateBadge stateBadge(String state) => StateBadge(
      'bridge.state.$state',
      _usdtTones[state] ?? BridgeTone.muted,
      usdtTerminalStates.contains(state),
    );

/// สถานะในตารางของประวัติ (ชื่อฝั่งฐานข้อมูล) → สถานะสาธารณะ — เหมือนที่หน้าเว็บแปลง
String usdtDepositRowState(String status) => switch (status) {
      'needs_review' => 'review',
      'confirmed' => 'queued',
      _ => status,
    };

String usdtWithdrawalRowState(String status) => switch (status) {
      'needs_review' => 'review',
      'pending' => 'queued',
      'ignored' => 'below_fee',
      _ => status,
    };

/// ลิงก์ธุรกรรมบน explorer (ตัด / ท้ายที่ซ้ำ) — ไม่มีข้อมูลครบ = null
String? explorerTx(String? explorer, String? hash) {
  if (explorer == null || explorer.isEmpty || hash == null || hash.isEmpty) return null;
  return '${explorer.replaceFirst(RegExp(r'/+$'), '')}/tx/$hash';
}

/// URL ที่ยอมเปิดในเบราว์เซอร์ — https เท่านั้น มีโฮสต์ ไม่มี user:pass (ค่ามาจากเซิร์ฟเวอร์)
String? safeHttpsUrl(Object? raw) {
  if (raw is! String || raw.length > 500) return null;
  final uri = Uri.tryParse(raw.trim());
  if (uri == null || uri.scheme.toLowerCase() != 'https' || uri.host.isEmpty) return null;
  if (uri.userInfo.isNotEmpty) return null;
  return uri.toString();
}

/// ด่านตรวจก่อนเซ็น/ส่งไม่ผ่าน — [key] คือข้อความที่ผู้ใช้เห็น
class BridgeCheckException implements Exception {
  final String key;
  final Map<String, String> params;

  const BridgeCheckException(this.key, [this.params = const {}]);

  @override
  String toString() => 'BridgeCheckException($key)';
}

// ════════════════════════════════════════════════════════════════
//  สถานะจากเซิร์ฟเวอร์ (/status, /withdrawals) — ตรวจทุกช่องก่อนใช้
// ════════════════════════════════════════════════════════════════

final _statePattern = RegExp(r'^[a-z_]{1,40}$');
final _amountTextPattern = RegExp(r'^\d{1,30}(\.\d{1,18})?$');

String? bridgeAmountText(Object? v) {
  final s = bridgeText(v);
  return s != null && _amountTextPattern.hasMatch(s) ? s : null;
}

int? bridgeInt(Object? v) {
  if (v is int) return v;
  if (v is String && RegExp(r'^\d{1,12}$').hasMatch(v)) return int.parse(v);
  return null;
}

String bridgeState(Object? v) => v is String && _statePattern.hasMatch(v) ? v : 'unknown';

/// หนึ่งแถวในผลสถานะ (หนึ่ง log ในธุรกรรม) — เก็บเฉพาะที่หน้าจอใช้
class BridgeStatusRow {
  /// ยอดที่ได้/จ่ายอีกฝั่ง (mint_amount · payout_amount)
  final String? amount;

  /// ลิงก์ธุรกรรมปลายทาง (mint_url · payout_url) — https เท่านั้น
  final String? url;

  /// ถอน: ที่อยู่ + เชนปลายทาง
  final String? targetAddress;
  final int? targetChainId;

  const BridgeStatusRow({this.amount, this.url, this.targetAddress, this.targetChainId});

  Map<String, dynamic> toJson() => {
        if (amount != null) 'amount': amount,
        if (url != null) 'url': url,
        if (targetAddress != null) 'targetAddress': targetAddress,
        if (targetChainId != null) 'targetChainId': targetChainId,
      };

  static BridgeStatusRow? fromStored(Object? json) {
    if (json is! Map) return null;
    final target = json['targetAddress'];
    return BridgeStatusRow(
      amount: bridgeAmountText(json['amount']),
      url: safeHttpsUrl(json['url']),
      targetAddress: isBridgeAddress(target) ? (target as String).trim() : null,
      targetChainId: bridgeInt(json['targetChainId']),
    );
  }
}

/// สถานะของธุรกรรมต้นทางหนึ่งรายการ (รวมแบบที่หน้าจอใช้)
class BridgeStatusView {
  final String state;

  /// ขณะรอ finality: ยอดที่เห็นบนเชน (ข้อความจากเซิร์ฟเวอร์) + จำนวนบล็อกยืนยัน
  final String? amount;
  final int? confirmations;
  final bool isFinal;

  /// เงินเข้า vault แล้วแต่ไม่ถูก mint อัตโนมัติ (เซิร์ฟเวอร์บอก refund: true) — ต้องติดต่อขอคืน
  final bool refund;
  final List<BridgeStatusRow> rows;

  const BridgeStatusView({
    required this.state,
    this.amount,
    this.confirmations,
    this.isFinal = false,
    this.refund = false,
    this.rows = const [],
  });

  /// ต้องบอกผู้ใช้ให้ติดต่อขอคืนเงินไหม
  bool get needsRefund => refund || usdtRefundStates.contains(state);

  /// data ของ /status หรือ /withdrawals → มุมมองของหน้าจอ (ช่องที่ผิดรูปถูกทิ้ง ไม่เดา)
  factory BridgeStatusView.fromServer(Map<String, dynamic> data) {
    final rows = <BridgeStatusRow>[];
    for (final d in (data['deposits'] is List ? data['deposits'] as List : const [])) {
      if (d is Map) {
        rows.add(BridgeStatusRow(amount: bridgeAmountText(d['mint_amount']), url: safeHttpsUrl(d['mint_url'])));
      }
    }
    for (final w in (data['withdrawals'] is List ? data['withdrawals'] as List : const [])) {
      if (w is! Map) continue;
      final target = w['target_address'];
      rows.add(BridgeStatusRow(
        amount: bridgeAmountText(w['payout_amount']),
        url: safeHttpsUrl(w['payout_url']),
        targetAddress: isBridgeAddress(target) ? (target as String).trim() : null,
        targetChainId: bridgeInt(w['target_chain_id']),
      ));
    }
    return BridgeStatusView(
      state: bridgeState(data['state']),
      amount: bridgeAmountText(data['amount']),
      confirmations: bridgeInt(data['confirmations']),
      isFinal: data['final'] == true,
      refund: data['refund'] == true,
      rows: rows.take(10).toList(),
    );
  }

  Map<String, dynamic> toJson() => {
        'state': state,
        if (amount != null) 'amount': amount,
        if (confirmations != null) 'confirmations': confirmations,
        if (isFinal) 'final': true,
        if (refund) 'refund': true,
        if (rows.isNotEmpty) 'rows': [for (final r in rows) r.toJson()],
      };

  static BridgeStatusView? fromStored(Object? json) {
    if (json is! Map) return null;
    final state = json['state'];
    if (state is! String || !_statePattern.hasMatch(state)) return null;
    final rows = <BridgeStatusRow>[];
    for (final r in (json['rows'] is List ? json['rows'] as List : const [])) {
      final row = BridgeStatusRow.fromStored(r);
      if (row != null) rows.add(row);
    }
    return BridgeStatusView(
      state: state,
      amount: bridgeAmountText(json['amount']),
      confirmations: bridgeInt(json['confirmations']),
      isFinal: json['final'] == true,
      refund: json['refund'] == true,
      rows: rows.take(10).toList(),
    );
  }
}

// ════════════════════════════════════════════════════════════════
//  รายการที่กำลังติดตาม (เก็บในเครื่อง — สะดวกต่อผู้ใช้เท่านั้น แหล่งความจริงคือเชน)
// ════════════════════════════════════════════════════════════════

const int trackLimit = 20;

/// หนึ่งรายการที่ติดตาม
class TrackedTransfer {
  /// 'deposit' | 'withdraw'
  final String kind;

  /// เชนต้นทางของธุรกรรม (ที่ใช้ถามสถานะ) — ถอน = 4289 เสมอ
  final int chainId;

  /// ตัวเล็กเสมอ
  final String txHash;

  /// ถอน: เชนปลายทาง (ไว้โชว์ก่อนเซิร์ฟเวอร์เห็นรายการ)
  final int? targetChainId;

  /// กระเป๋าที่รายการนี้เป็นของมัน (ตัวเล็ก) — สลับกระเป๋าแล้วไม่โชว์ของใบอื่น
  final String? owner;

  /// จำนวนที่ส่ง (ข้อความ USDT) — ไว้โชว์ก่อนเซิร์ฟเวอร์เห็นรายการ
  final String? amount;

  final int addedAt;

  /// สถานะล่าสุดที่รู้ — เก็บไว้ให้ปิดแอปแล้วกลับมาเห็น และไม่ถามซ้ำเมื่อจบแล้ว
  final BridgeStatusView? status;

  TrackedTransfer({
    required this.kind,
    required this.chainId,
    required String txHash,
    this.targetChainId,
    String? owner,
    this.amount,
    int? addedAt,
    this.status,
  })  : txHash = txHash.trim().toLowerCase(),
        owner = owner?.toLowerCase(),
        addedAt = addedAt ?? DateTime.now().millisecondsSinceEpoch;

  /// คีย์กันซ้ำ — ธุรกรรมเดียวกันติดตามซ้ำ/กดเบิ้ล = แถวเดิม
  String get key => '$kind:$chainId:$txHash';

  TrackedTransfer withStatus(BridgeStatusView? next) => TrackedTransfer(
        kind: kind,
        chainId: chainId,
        txHash: txHash,
        targetChainId: targetChainId,
        owner: owner,
        amount: amount,
        addedAt: addedAt,
        status: next,
      );

  Map<String, dynamic> toJson() => {
        'kind': kind,
        'chainId': chainId,
        'txHash': txHash,
        if (targetChainId != null) 'targetChainId': targetChainId,
        if (owner != null) 'owner': owner,
        if (amount != null) 'amount': amount,
        'addedAt': addedAt,
        if (status != null) 'status': status!.toJson(),
      };

  /// null = แถวผิดรูป (ข้าม ไม่เดา)
  static TrackedTransfer? fromJson(Object? json) {
    if (json is! Map) return null;
    final kind = json['kind'];
    final chainId = json['chainId'];
    final hash = json['txHash'];
    if (kind != usdtDeposit && kind != usdtWithdraw) return null;
    if (chainId is! int || chainId <= 0) return null;
    if (hash is! String || !_txHashPattern.hasMatch(hash)) return null;
    final owner = json['owner'];
    final added = json['addedAt'];
    return TrackedTransfer(
      kind: kind as String,
      chainId: chainId,
      txHash: hash,
      targetChainId: bridgeInt(json['targetChainId']),
      owner: owner is String && _addressPattern.hasMatch(owner) ? owner : null,
      amount: bridgeAmountText(json['amount']),
      addedAt: added is int ? added : 0,
      status: BridgeStatusView.fromStored(json['status']),
    );
  }
}

/// อ่านรายการที่ติดตาม — ข้อมูลเสีย/ไม่ใช่ JSON = รายการว่าง ไม่พัง
List<TrackedTransfer> decodeTracked(String? raw) {
  if (raw == null || raw.isEmpty) return [];
  try {
    final parsed = jsonDecode(raw);
    if (parsed is! List) return [];
    final out = <TrackedTransfer>[];
    for (final e in parsed) {
      final t = TrackedTransfer.fromJson(e);
      if (t != null) out.add(t);
    }
    return out.take(trackLimit).toList();
  } on FormatException {
    return [];
  }
}

String encodeTracked(List<TrackedTransfer> list) =>
    jsonEncode([for (final e in list.take(trackLimit)) e.toJson()]);

/// เพิ่มรายการ (ซ้ำ = ย้ายขึ้นบนสุด ไม่เพิ่มแถวใหม่ · เก็บสถานะเดิมไว้ถ้าแถวใหม่ยังไม่มี)
List<TrackedTransfer> addTracked(List<TrackedTransfer> list, TrackedTransfer entry) {
  TrackedTransfer? previous;
  for (final e in list) {
    if (e.key == entry.key) previous = e;
  }
  final next = entry.status == null && previous?.status != null ? entry.withStatus(previous!.status) : entry;
  return [next, ...list.where((e) => e.key != next.key)].take(trackLimit).toList();
}
