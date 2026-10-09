/// TPIX TRADE — โครงข้อมูลหน้า "ยืนยันตัวตน (KYC)"
///
/// อ่านจาก payload ของ `GET /api/v1/kyc/status` (และ `complete` / `refresh` ที่คืน
/// payload ก้อนเดียวกัน) — ทุกตัวอ่านแบบ "ทนข้อมูลแปลก": PHP ส่ง bool มาเป็น 1/0
/// ได้ ฟิลด์ว่างมาเป็นสตริงว่างได้ ถ้าโมเดล throw เพราะเรื่องแค่นี้ ผู้ใช้จะเจอ
/// "อ่านข้อมูลไม่สำเร็จ" ทั้งที่เซิร์ฟเวอร์ตอบถูกทุกอย่าง
///
/// ไฟล์นี้ไม่มีข้อมูลบัตรประชาชนใดๆ — เซิร์ฟเวอร์ไม่ส่งมาให้แอปตั้งแต่ต้น
/// (ได้แค่ "ผ่านไหม · ระดับไหน · วันไหน") และต้องคงไว้แบบนั้น
///
/// Developed by Xman Studio
library;

// ══════════════════════════════════════════════════════════════════
// ด่านรายฟีเจอร์
// ══════════════════════════════════════════════════════════════════

/// ระดับการยืนยันตัวตน
class KycLevel {
  KycLevel._();

  static const String basic = 'basic';
  static const String enhanced = 'enhanced';
}

/// คีย์ฟีเจอร์ที่รู้จัก — ต้องตรงกับ `config/kyc.php` ฝั่งเซิร์ฟเวอร์
/// (ป้ายชื่อ 2 ภาษาอยู่ที่ `kyc.feature.<key>` ใน locale_provider.dart)
///
/// เรียงตามลำดับที่อยากให้ผู้ใช้เห็น (สิ่งที่คนในแอปใช้บ่อยขึ้นก่อน) ไม่ใช่ลำดับ
/// ที่ JSON ส่งมา — ลำดับคีย์ของ PHP array เปลี่ยนได้ทุกครั้งที่ใครแก้ config
const List<String> kKycFeatureOrder = [
  'ai_bot',
  'trading',
  'token_sale',
  'token_factory',
  'bridge',
  'masternode',
];

/// ด่านของฟีเจอร์หนึ่งตัว
class KycFeatureGate {
  final String key;
  final bool required;
  final String level;
  final bool passed;

  const KycFeatureGate({
    required this.key,
    required this.required,
    required this.level,
    required this.passed,
  });

  factory KycFeatureGate.fromJson(String key, Map<String, dynamic> json) =>
      KycFeatureGate(
        key: key,
        required: _bool(json['required']),
        level: _str(json['level']) ?? KycLevel.basic,
        // ไม่มีค่า = ถือว่ายังไม่ผ่าน (ปลอดภัยกว่าการเดาว่าผ่าน)
        passed: _bool(json['passed']),
      );

  /// ต้องใช้ระดับสูง (บัตร + เอกสารเพิ่ม) ไม่ใช่แค่ระดับพื้นฐาน
  bool get needsEnhanced => level == KycLevel.enhanced;

  /// คีย์ป้ายชื่อใน locale_provider
  String get labelKey => 'kyc.feature.$key';

  /// ชื่อสำรองของคีย์ที่แอปยังไม่รู้จัก (เพิ่มฝั่งเซิร์ฟเวอร์ทีหลัง) — ต้องยังโชว์ได้
  /// ไม่ใช่หายไปจากรายการเงียบๆ
  String get fallbackLabel => key.replaceAll('_', ' ');
}

/// สถานะด่าน KYC ทั้งระบบของบัญชีนี้
class KycGateStatus {
  /// ระบบ KYC เปิดอยู่ไหม (ปิด = ทุกฟีเจอร์ผ่านหมด ไม่ต้องยืนยันอะไร)
  final bool enabled;

  /// ระดับที่ผ่านแล้ว — null = ยังไม่ผ่านระดับไหนเลย
  final String? approvedLevel;

  /// ทุกฟีเจอร์ เรียงตาม [kKycFeatureOrder] แล้วตามด้วยคีย์ที่ไม่รู้จัก
  final List<KycFeatureGate> features;

  const KycGateStatus({
    required this.enabled,
    required this.approvedLevel,
    required this.features,
  });

  static const KycGateStatus empty =
      KycGateStatus(enabled: false, approvedLevel: null, features: []);

  factory KycGateStatus.fromJson(Map<String, dynamic> json) {
    final raw = _map(json['features']) ?? const <String, dynamic>{};
    final features = <KycFeatureGate>[];
    for (final entry in raw.entries) {
      final f = _map(entry.value);
      if (f != null) features.add(KycFeatureGate.fromJson(entry.key, f));
    }
    int rank(String key) {
      final i = kKycFeatureOrder.indexOf(key);
      return i < 0 ? kKycFeatureOrder.length : i;
    }

    features.sort((a, b) {
      final byRank = rank(a.key).compareTo(rank(b.key));
      return byRank != 0 ? byRank : a.key.compareTo(b.key);
    });

    return KycGateStatus(
      enabled: _bool(json['enabled']),
      approvedLevel: _str(json['approved_level']),
      features: List.unmodifiable(features),
    );
  }

  /// เฉพาะฟีเจอร์ที่บังคับยืนยันตัวตนจริงตอนนี้ — ตัวที่ไม่บังคับไม่ต้องโชว์
  /// (โชว์ทั้งหมดจะทำให้ผู้ใช้เข้าใจผิดว่าทุกอย่างถูกล็อก)
  List<KycFeatureGate> get requiredFeatures =>
      features.where((f) => f.required).toList(growable: false);
}

// ══════════════════════════════════════════════════════════════════
// ใบยืนยันตัวตนล่าสุด
// ══════════════════════════════════════════════════════════════════

class KycSubmissionInfo {
  /// pending · approved · rejected · cancelled · expired
  final String status;
  final String level;

  /// manual (ส่งเอกสารทางเว็บ) · thaiprompt (ใช้ผลจาก Thaiprompt)
  final String source;
  final DateTime? submittedAt;
  final DateTime? reviewedAt;
  final String? rejectReason;

  const KycSubmissionInfo({
    required this.status,
    required this.level,
    required this.source,
    this.submittedAt,
    this.reviewedAt,
    this.rejectReason,
  });

  factory KycSubmissionInfo.fromJson(Map<String, dynamic> json) =>
      KycSubmissionInfo(
        status: _str(json['status']) ?? 'pending',
        level: _str(json['level']) ?? KycLevel.basic,
        source: _str(json['source']) ?? 'manual',
        submittedAt: _date(json['submitted_at']),
        reviewedAt: _date(json['reviewed_at']),
        rejectReason: _str(json['reject_reason']),
      );

  bool get isApproved => status == 'approved';
  bool get isPending => status == 'pending';
  bool get isRejected => status == 'rejected';
  bool get viaThaiprompt => source == 'thaiprompt';

  /// วันที่ใช้บอกผู้ใช้ว่า "ยืนยันเมื่อไหร่" — วันที่ตรวจผ่าน ถ้าไม่มีใช้วันที่ส่ง
  DateTime? get effectiveDate => reviewedAt ?? submittedAt;
}

// ══════════════════════════════════════════════════════════════════
// การเชื่อมบัญชี Thaiprompt
// ══════════════════════════════════════════════════════════════════

class ThaipromptLinkInfo {
  /// none (ยังไม่ทำ eKYC ในแอป Thaiprompt) · pending · approved · rejected
  final String status;
  final DateTime? verifiedAt;
  final String? method;
  final DateTime? linkedAt;
  final DateTime? lastCheckedAt;

  /// สิทธิ์ที่ได้จาก Thaiprompt หมดอายุ/ถูกถอน — ต้องกดเชื่อมใหม่ ถามผลซ้ำไม่ช่วย
  final bool needsReconnect;

  const ThaipromptLinkInfo({
    required this.status,
    this.verifiedAt,
    this.method,
    this.linkedAt,
    this.lastCheckedAt,
    this.needsReconnect = false,
  });

  factory ThaipromptLinkInfo.fromJson(Map<String, dynamic> json) =>
      ThaipromptLinkInfo(
        status: _str(json['status']) ?? 'none',
        verifiedAt: _date(json['verified_at']),
        method: _str(json['method']),
        linkedAt: _date(json['linked_at']),
        lastCheckedAt: _date(json['last_checked_at']),
        needsReconnect: _bool(json['needs_reconnect']),
      );

  bool get isApproved => status == 'approved';
  bool get isPending => status == 'pending';
  bool get isRejected => status == 'rejected';
}

class ThaipromptInfo {
  /// false = เซิร์ฟเวอร์ยังไม่ได้ตั้งค่า Thaiprompt → ซ่อนทางนี้ทั้งก้อน
  final bool available;

  /// null = ยังไม่เคยเชื่อมบัญชี Thaiprompt
  final ThaipromptLinkInfo? link;

  /// ลิงก์เปิดหน้า eKYC ในแอป Thaiprompt (`thaiprompt://ekyc?...`)
  final String? appLink;

  /// หน้าดาวน์โหลดแอป Thaiprompt (เมื่อเครื่องยังไม่มีแอป)
  final String? downloadUrl;

  const ThaipromptInfo({
    required this.available,
    this.link,
    this.appLink,
    this.downloadUrl,
  });

  static const ThaipromptInfo unavailable = ThaipromptInfo(available: false);

  factory ThaipromptInfo.fromJson(Map<String, dynamic> json) {
    final link = _map(json['link']);
    return ThaipromptInfo(
      available: _bool(json['available']),
      link: link == null ? null : ThaipromptLinkInfo.fromJson(link),
      appLink: _str(json['app_link']),
      downloadUrl: _str(json['download_url']),
    );
  }

  /// ลิงก์เปิดแอป — รับเฉพาะ scheme ของ Thaiprompt หรือ https เท่านั้น
  ///
  /// ค่ามาจาก config ฝั่งเซิร์ฟเวอร์ก็จริง แต่ปลายทางคือ "เปิดแอปอื่นบนเครื่อง
  /// ผู้ใช้" ค่าที่ผิดรูป (เช่น intent:// หรือ file://) ไม่ควรถูกส่งต่อให้ระบบเปิด
  Uri? get appLinkUri {
    final uri = _uri(appLink);
    if (uri == null) return null;
    return (uri.scheme == 'thaiprompt' || uri.scheme == 'https') ? uri : null;
  }

  Uri? get downloadUri => httpsUri(downloadUrl);
}

// ══════════════════════════════════════════════════════════════════
// payload รวม
// ══════════════════════════════════════════════════════════════════

/// ทุกอย่างที่หน้า KYC ต้องใช้วาด — ได้มาจากคำขอเดียว
class KycStatus {
  /// ทางสำรองเมื่อเซิร์ฟเวอร์ไม่ส่ง `web_kyc_url` มา (หรือส่งมาผิดรูป)
  static const String defaultWebKycUrl = 'https://tpix.online/kyc';

  final KycGateStatus gate;
  final KycSubmissionInfo? submission;
  final ThaipromptInfo thaiprompt;
  final String webKycUrl;

  const KycStatus({
    required this.gate,
    required this.submission,
    required this.thaiprompt,
    this.webKycUrl = defaultWebKycUrl,
  });

  factory KycStatus.fromJson(Map<String, dynamic> json) {
    final gate = _map(json['gate']);
    final submission = _map(json['submission']);
    final thaiprompt = _map(json['thaiprompt']);
    final web = httpsUri(_str(json['web_kyc_url']));
    return KycStatus(
      gate: gate == null ? KycGateStatus.empty : KycGateStatus.fromJson(gate),
      submission:
          submission == null ? null : KycSubmissionInfo.fromJson(submission),
      thaiprompt: thaiprompt == null
          ? ThaipromptInfo.unavailable
          : ThaipromptInfo.fromJson(thaiprompt),
      webKycUrl: web?.toString() ?? defaultWebKycUrl,
    );
  }

  /// แปลงแบบไม่ throw — ใช้กับ payload ที่แนบมากับซองล้มเหลว (422 ของ refresh)
  /// ซึ่งเป็น "ของแถม" พังก็แค่ไม่มี ไม่ควรทำให้ทั้งหน้าล้ม
  static KycStatus? tryParse(Object? raw) {
    if (raw is! Map) return null;
    try {
      return KycStatus.fromJson(raw.map((k, v) => MapEntry(k.toString(), v)));
    } catch (_) {
      return null;
    }
  }

  /// ยืนยันตัวตนผ่านแล้ว (ใบล่าสุดได้รับอนุมัติ)
  bool get isApproved => submission?.isApproved == true;

  ThaipromptLinkInfo? get thaipromptLink => thaiprompt.link;

  Uri get webKycUri => Uri.parse(webKycUrl);
}

// ══════════════════════════════════════════════════════════════════
// สิ่งที่หน้าจอต้องวาด — ตัดสินจากสถานะเพียวๆ (ทดสอบได้โดยไม่ต้องมี widget)
// ══════════════════════════════════════════════════════════════════

enum KycView {
  /// ยังไม่ได้เชื่อมกระเป๋า
  noWallet,

  /// ต้องเซ็นยืนยันกระเป๋าใหม่ก่อน (403 WALLET_NOT_VERIFIED / IP_MISMATCH)
  needsWalletSign,

  /// กำลังโหลดครั้งแรก ยังไม่มีข้อมูลอะไรเลย
  loading,

  /// โหลดครั้งแรกไม่สำเร็จ — ไม่มีข้อมูลเก่าให้โชว์
  loadFailed,

  /// ยืนยันตัวตนผ่านแล้ว
  approved,

  /// ทางหลัก: ยังไม่เชื่อม Thaiprompt (หรือสิทธิ์หมดอายุ ต้องเชื่อมใหม่)
  connectThaiprompt,

  /// เชื่อม Thaiprompt แล้ว รอผู้ใช้ทำ eKYC ในแอป Thaiprompt / รอผลตรวจ
  waitingThaiprompt,

  /// เซิร์ฟเวอร์ยังไม่เปิดทาง Thaiprompt — เหลือแค่ส่งเอกสารทางเว็บ
  webOnly,
}

/// ตัดสินว่าหน้าจอควรอยู่สถานะไหน
///
/// ลำดับสำคัญ: กระเป๋า → ลายเซ็น → ข้อมูล → ผ่านแล้ว → ทาง Thaiprompt
/// สถานะ "ผ่านแล้ว" ต้องมาก่อนเรื่อง Thaiprompt เสมอ — คนที่ผ่านด้วยเอกสารทางเว็บ
/// แล้วไม่เคยเชื่อม Thaiprompt ต้องไม่เห็นการ์ดชวนไปเชื่อม
KycView resolveKycView({
  required bool walletConnected,
  required bool needsWalletSign,
  required KycStatus? status,
  required bool loadFailed,
}) {
  if (!walletConnected) return KycView.noWallet;
  if (needsWalletSign) return KycView.needsWalletSign;
  if (status == null) return loadFailed ? KycView.loadFailed : KycView.loading;
  if (status.isApproved) return KycView.approved;
  if (!status.thaiprompt.available) return KycView.webOnly;
  final link = status.thaiprompt.link;
  if (link == null || link.needsReconnect) return KycView.connectThaiprompt;
  return KycView.waitingThaiprompt;
}

// ══════════════════════════════════════════════════════════════════
// ลิงก์ที่ Thaiprompt ส่งกลับเข้าแอป (tpixtrade://kyc?...)
// ══════════════════════════════════════════════════════════════════

enum KycReturnResult {
  /// ผู้ใช้กดอนุญาตแล้ว — มี [KycReturnLink.completion] ให้เอาไปแลก
  ok,

  /// ผู้ใช้กดไม่อนุญาตที่หน้า Thaiprompt
  denied,

  /// คำขอหมดอายุ (state หาย / ค้างหน้าอนุญาตนานเกิน)
  expired,

  /// Thaiprompt ตอบผิดรูป
  error,

  /// ลิงก์ผิดรูป (ไม่มี result หรือรหัสรับผลไม่ผ่านการตรวจ)
  invalid,
}

/// ผลจาก deep link `tpixtrade://kyc?result=ok&completion=…`
///
/// แยกเป็นฟังก์ชันเพียวไว้ทดสอบได้ — และเพื่อให้กฎ "ห้ามเผยรหัสรับผล" อยู่จุดเดียว:
/// [toString] ไม่พิมพ์ [completion] เด็ดขาด (รหัสนี้แลกผล KYC ได้ภายใน 8 นาที
/// หลุดไปอยู่ใน log / รายงานบั๊ก = คนที่ถือกระเป๋าบัญชีเดียวกันเอาไปแลกได้)
class KycReturnLink {
  /// รูปแบบเดียวกับที่เซิร์ฟเวอร์ตรวจ (`regex:/^[A-Za-z0-9]{48}$/`)
  static final RegExp completionPattern = RegExp(r'^[A-Za-z0-9]{48}$');

  final KycReturnResult result;

  /// มีค่าเฉพาะ [KycReturnResult.ok] และผ่าน [completionPattern] แล้วเท่านั้น
  final String? completion;

  const KycReturnLink._(this.result, [this.completion]);

  /// null = ไม่ใช่ลิงก์ของหน้า KYC เลย (คนละ scheme / host)
  static KycReturnLink? parse(Uri uri) {
    if (uri.scheme != 'tpixtrade' || uri.host != 'kyc') return null;
    final qp = uri.queryParameters;
    final result = qp['result']?.trim().toLowerCase();
    final completion = qp['completion']?.trim();
    final validCompletion =
        completion != null && completionPattern.hasMatch(completion);

    switch (result) {
      case 'ok':
        return validCompletion
            ? KycReturnLink._(KycReturnResult.ok, completion)
            : const KycReturnLink._(KycReturnResult.invalid);
      case 'denied':
        return const KycReturnLink._(KycReturnResult.denied);
      case 'expired':
        return const KycReturnLink._(KycReturnResult.expired);
      case 'error':
        return const KycReturnLink._(KycReturnResult.error);
      case null:
      case '':
        // ไม่มี result แต่รหัสรับผลถูกรูป = ยังเอาไปแลกได้ ไม่ทิ้งของที่ผู้ใช้อนุญาตมาแล้ว
        return validCompletion
            ? KycReturnLink._(KycReturnResult.ok, completion)
            : const KycReturnLink._(KycReturnResult.invalid);
      default:
        return const KycReturnLink._(KycReturnResult.invalid);
    }
  }

  @override
  String toString() => 'KycReturnLink(${result.name})';
}

// ══════════════════════════════════════════════════════════════════
// ตัวช่วยอ่าน JSON (ทนทุกรูปที่ PHP ส่งได้)
// ══════════════════════════════════════════════════════════════════

/// เปิดได้เฉพาะ https — ลิงก์ที่จะส่งให้ระบบเปิดในเบราว์เซอร์ต้องไม่ใช่ http/javascript/file
Uri? httpsUri(String? raw) {
  final uri = _uri(raw);
  if (uri == null || uri.scheme != 'https' || uri.host.isEmpty) return null;
  return uri;
}

Uri? _uri(String? raw) {
  if (raw == null) return null;
  final s = raw.trim();
  if (s.isEmpty) return null;
  return Uri.tryParse(s);
}

Map<String, dynamic>? _map(Object? value) {
  if (value is! Map) return null;
  return value.map((k, v) => MapEntry(k.toString(), v));
}

String? _str(Object? value) {
  if (value == null) return null;
  final s = value.toString().trim();
  return s.isEmpty ? null : s;
}

/// PHP ส่ง bool มาได้ทั้ง true / 1 / "1" / "true"
bool _bool(Object? value) {
  if (value is bool) return value;
  if (value is num) return value != 0;
  if (value is String) {
    final s = value.trim().toLowerCase();
    return s == '1' || s == 'true';
  }
  return false;
}

DateTime? _date(Object? value) {
  final s = _str(value);
  return s == null ? null : DateTime.tryParse(s);
}

/// Developed by Xman Studio
