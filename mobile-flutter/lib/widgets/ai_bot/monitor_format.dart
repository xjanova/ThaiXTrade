/// TPIX TRADE — AI Monitor Format
/// ตัวช่วยจัดรูปแบบตัวเลข/เวลา + ป้ายคำสองภาษา ของแผงมอนิเตอร์ AI TRADE
///
/// โมเดลข้อมูลอยู่ที่ `lib/models/ai_bot_models.dart` (แหล่งความจริงเดียว)
/// ไฟล์นี้ทำแค่ "แปลงค่าให้อ่านออก" ไม่ถือข้อมูลเอง
///
/// กฎที่ยึด:
///   • ค่าที่ยังไม่รู้ต้องเป็นขีด "—" ไม่ใช่ 0 (0 กับ ไม่รู้ คนละความหมายเรื่องเงิน)
///   • เครื่องหมายลบอยู่หน้าสัญลักษณ์สกุลเงินเสมอ: -$420.25 ไม่ใช่ $-420.25
///   • ราคาเหรียญถูกๆ ต้องละเอียดพอ ไม่งั้นกลายเป็น 0.00
///   • ไม่พึ่ง intl — ทั้งแอพไม่ได้ใช้ จัดกลุ่มหลักพันเอง
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';

import '../../core/locale/locale_provider.dart';
import '../../core/theme/app_colors.dart';

/// เครื่องมือจัดรูปแบบของแผงมอนิเตอร์
class Fmt {
  Fmt._();

  /// ค่าที่ยังไม่มีข้อมูล
  static const String dash = '—';

  static String _group(String digits) {
    final buf = StringBuffer();
    final n = digits.length;
    for (int i = 0; i < n; i++) {
      if (i > 0 && (n - i) % 3 == 0) buf.write(',');
      buf.write(digits[i]);
    }
    return buf.toString();
  }

  /// "1,234.56" (ไม่มีสัญลักษณ์สกุลเงิน)
  static String number(double v, {int digits = 2}) {
    final neg = v < 0;
    final s = v.abs().toStringAsFixed(digits);
    final dot = s.indexOf('.');
    final intPart = _group(dot < 0 ? s : s.substring(0, dot));
    final decPart = dot < 0 ? '' : s.substring(dot);
    return '${neg ? '-' : ''}$intPart$decPart';
  }

  /// "$1,234.56" · null → "—"
  static String usd(double? v, {int digits = 2}) =>
      v == null ? dash : '\$${number(v, digits: digits)}';

  /// "-$420.25" / "+$18.40" — เครื่องหมายอยู่หน้าสัญลักษณ์สกุลเงิน
  /// ไม่งั้นกวาดสายตาดูคอลัมน์กำไรขาดทุนเร็วๆ แล้วอ่านผิด
  static String signedUsd(double? v, {int digits = 2}) {
    if (v == null) return dash;
    final sign = v < 0 ? '-' : '+';
    return '$sign\$${number(v.abs(), digits: digits)}';
  }

  /// "+2.50%" · null → "—"
  static String signedPct(double? v, {int digits = 2}) {
    if (v == null) return dash;
    final sign = v < 0 ? '-' : '+';
    return '$sign${number(v.abs(), digits: digits)}%';
  }

  /// "72.5%" · null → "—"
  static String pct(double? v, {int digits = 1}) =>
      v == null ? dash : '${number(v, digits: digits)}%';

  /// ราคาเหรียญ — ยิ่งถูกยิ่งต้องละเอียด
  static String price(double? v) {
    if (v == null) return dash;
    final a = v.abs();
    final digits = a >= 1000 ? 2 : (a >= 1 ? 4 : 8);
    return number(v, digits: digits);
  }

  /// จำนวนเหรียญ — ตัดศูนย์ท้ายทิ้งเพื่อไม่ให้แถวยาวเกินจำเป็น
  static String amount(double? v) {
    if (v == null) return dash;
    final digits = v.abs() >= 1 ? 4 : 8;
    var s = v.toStringAsFixed(digits);
    if (s.contains('.')) {
      s = s.replaceFirst(RegExp(r'0+$'), '').replaceFirst(RegExp(r'\.$'), '');
    }
    return s;
  }

  static String intGrouped(int? v) => v == null ? dash : _group(v.toString());

  static const List<String> _monthsTh = [
    'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
    'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.',
  ];
  static const List<String> _monthsEn = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
  ];

  static String _two(int n) => n.toString().padLeft(2, '0');

  /// "14:32"
  static String hm(DateTime? dt) {
    if (dt == null) return dash;
    final l = dt.toLocal();
    return '${_two(l.hour)}:${_two(l.minute)}';
  }

  /// "30 ส.ค." / "30 Aug"
  static String dayMonth(DateTime? dt, bool isThai) {
    if (dt == null) return dash;
    final l = dt.toLocal();
    return '${l.day} ${(isThai ? _monthsTh : _monthsEn)[l.month - 1]}';
  }

  /// "30 ส.ค. 14:32"
  static String dateTime(DateTime? dt, bool isThai) =>
      dt == null ? dash : '${dayMonth(dt, isThai)} ${hm(dt)}';
}

/// ป้ายคำสองภาษาของหน้ามอนิเตอร์ — ใช้คีย์ `aiTrade.*` ที่มีอยู่แล้วเป็นหลัก
/// เพื่อให้ข้อความตรงกับหน้าอื่นและกับเว็บ
extension MonitorText on LocaleProvider {
  /// "เมื่อครู่" / "12 นาทีที่แล้ว" / "2 ชั่วโมง 5 นาทีที่แล้ว"
  /// เกิน 24 ชั่วโมงเปลี่ยนเป็นวันที่จริง — "38 ชั่วโมงที่แล้ว" อ่านแล้วต้องคิดต่อ
  String agoText(DateTime? dt) {
    if (dt == null) return Fmt.dash;
    final diff = DateTime.now().difference(dt.toLocal());
    if (diff.isNegative || diff.inMinutes < 1) return t('aiTrade.justNow');
    if (diff.inMinutes < 60) {
      return tp('aiTrade.minutesAgo', {'m': diff.inMinutes});
    }
    if (diff.inHours < 24) {
      return tp('aiTrade.hoursMinutesAgo', {
        'h': diff.inHours,
        'm': diff.inMinutes % 60,
      });
    }
    return Fmt.dateTime(dt, isThai);
  }

  /// ระดับความเสี่ยงของ "ตลาด" (ไม่ใช่ความเสี่ยงของกลยุทธ์)
  String riskLevelText(String? level) {
    switch (level) {
      case 'calm':
        return t('aiTrade.riskCalm');
      case 'caution':
        return t('aiTrade.riskCaution');
      case 'elevated':
        return t('aiTrade.riskElevated');
      case 'panic':
        return t('aiTrade.riskPanic');
      default:
        return isThai ? 'ไม่ทราบ' : 'Unknown';
    }
  }

  /// สิ่งที่บอททำในรอบนั้น
  String decisionActionText(String action) {
    switch (action) {
      case 'buy':
        return t('aiTrade.tradeBuy');
      case 'sell':
        return t('aiTrade.tradeSell');
      case 'hold':
        return isThai ? 'ไม่ทำอะไร' : 'Hold';
      case 'signal':
        return isThai ? 'ส่งสัญญาณ' : 'Signal';
      case 'stopped':
        return isThai ? 'หยุด' : 'Stopped';
      case 'error':
        return isThai ? 'ผิดพลาด' : 'Error';
      default:
        return action;
    }
  }

  /// ท่าทีต่อเหรียญที่ AI ให้มา (ค่าดิบจาก LLM — ไม่รู้จักก็คืนค่าเดิม)
  String coinStanceText(String stance) {
    switch (stance) {
      case 'buy':
        return t('aiTrade.stanceBuy');
      case 'hold':
        return t('aiTrade.stanceHold');
      case 'avoid':
        return t('aiTrade.stanceAvoid');
      case 'exit':
        return t('aiTrade.stanceExit');
      default:
        return stance.isEmpty ? (isThai ? 'ไม่ระบุ' : 'n/a') : stance;
    }
  }

  /// ท่าทีตลาดโดยรวม
  String marketRegimeText(String? regime) {
    switch (regime) {
      case 'risk_on':
        return t('aiTrade.regimeRiskOn');
      case 'risk_off':
        return t('aiTrade.regimeRiskOff');
      case 'neutral':
        return t('aiTrade.regimeNeutral');
      case 'choppy':
        return isThai ? 'ตลาดแกว่งไร้ทิศ' : 'Choppy';
      case null:
        return isThai ? 'ยังไม่ระบุท่าที' : 'No stance yet';
      default:
        return regime;
    }
  }
}

/// สีของกำไร/ขาดทุน — เขียว/แดงใช้ได้เฉพาะตรงนี้ (ข้อยกเว้นของดีไซน์ซิสเต็ม)
Color pnlColor(double? v) {
  if (v == null) return AppColors.textTertiary;
  if (v > 0) return AppColors.tradingGreen;
  if (v < 0) return AppColors.tradingRed;
  return AppColors.textSecondary;
}

/// ความเข้มของป้ายความเสี่ยง 0–3 — ไล่ระดับด้วย "ความเข้มของทอง" ไม่ใช่เปลี่ยนสี
/// (เขียว/แดงสงวนไว้ให้กำไรขาดทุนและฝั่งซื้อขายเท่านั้น)
int riskLevelIntensity(String? level) {
  switch (level) {
    case 'caution':
      return 1;
    case 'elevated':
      return 2;
    case 'panic':
      return 3;
    default:
      return 0;
  }
}
