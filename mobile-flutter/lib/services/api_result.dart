/// TPIX TRADE — ผลลัพธ์การเรียก API แบบเก็บเหตุผล
///
/// เกิดมาเพื่อแก้กับดักใหญ่ที่สุดของแอพ: `_get/_post` ใน `api_service.dart`
/// จับ DioException แล้ว `return null` ทำให้สถานะที่ต่างกันสิ้นเชิง — เน็ตหลุด,
/// ลายเซ็นหมดอายุ, เครดิตไม่พอ, ยังไม่เปิดขาย, โดน rate limit — ยุบเหลือ null
/// ค่าเดียว หน้าจอจึงไม่มีข้อมูลพอจะบอก "ทางออก" ให้ผู้ใช้ได้เลย
///
/// ทุกคำขอของ `/ai-bot/*` ต้องผ่านชนิดนี้ ห้ามยุบเหลือ null เด็ดขาด
///
/// Developed by Xman Studio

library;

/// ผลลัพธ์ที่พก code + message + status กลับมาเสมอ
///
/// ใช้ `switch` แบบ exhaustive ฝั่งเรียก — คอมไพเลอร์จะบังคับให้จัดการเคสพลาด
/// ซึ่งคือสิ่งที่หายไปตอนใช้ `null` เป็นตัวแทนความล้มเหลว
sealed class ApiResult<T> {
  const ApiResult();

  /// สำเร็จไหม (ใช้เมื่อไม่อยากเขียน switch เต็มรูป)
  bool get isOk => this is ApiOk<T>;

  /// ค่าที่ได้ — null เมื่อพลาด
  T? get valueOrNull => this is ApiOk<T> ? (this as ApiOk<T>).data : null;

  /// ข้อผิดพลาด — null เมื่อสำเร็จ
  ApiErr<T>? get errorOrNull => this is ApiErr<T> ? this as ApiErr<T> : null;
}

/// สำเร็จ — `data` คือเนื้อใน `data` ของซองตอบกลับ
final class ApiOk<T> extends ApiResult<T> {
  final T data;

  const ApiOk(this.data);
}

/// ล้มเหลว — พกเหตุผลมาให้หน้าจออธิบายและเสนอทางออกได้
final class ApiErr<T> extends ApiResult<T> {
  /// รหัสจากเซิร์ฟเวอร์ (`WALLET_NOT_VERIFIED`, `INSUFFICIENT_CREDITS`, …)
  /// หรือรหัสที่ฝั่งแอพตั้งเอง: `NETWORK` · `TIMEOUT` · `BAD_PAYLOAD`
  /// `VALIDATION_ERROR` · `RATE_LIMITED` · `HTTP_500` · `NO_WALLET`
  final String code;

  /// ข้อความไทยจากเซิร์ฟเวอร์ — ใช้เป็นทางลงสุดท้ายเมื่อแอพไม่รู้จักรหัสนี้
  final String message;

  /// null = ยิงไม่ถึงเซิร์ฟเวอร์เลย (เน็ตหลุด/timeout)
  final int? status;

  /// ข้อผิดพลาดรายฟิลด์จาก validator ของ Laravel (422 รูป `{errors:{field:[…]}}`)
  /// ซองนี้ **ไม่มี** `error.code` จึงต้องแยกเก็บ ไม่ใช่ยัดรวมกับ code ปกติ
  final Map<String, List<String>> fieldErrors;

  /// วินาทีที่ควรรอก่อนยิงใหม่ (จากเฮดเดอร์ `Retry-After` ตอนโดน 429)
  final int? retryAfterSeconds;

  /// ฟีเจอร์/ระดับที่ด่าน KYC ต้องการ — มีเฉพาะรหัส `KYC_REQUIRED`
  final String? kycFeature;
  final String? kycLevel;

  const ApiErr(
    this.code,
    this.message, {
    this.status,
    this.fieldErrors = const {},
    this.retryAfterSeconds,
    this.kycFeature,
    this.kycLevel,
  });

  /// ต้องให้ผู้ใช้เซ็นกระเป๋าใหม่
  ///
  /// `WALLET_IP_MISMATCH` เกิดจากมือถือสลับ WiFi ↔ 4G/5G ระหว่างใช้งาน
  /// (เซิร์ฟเวอร์ผูกลายเซ็นกับ IP เฉพาะคำขอที่เขียนข้อมูล) — คนละเหตุกับ
  /// ลายเซ็นหมดอายุ จึงต้องมีข้อความคนละอัน แต่ทางแก้เหมือนกันคือเซ็นใหม่
  bool get needsWalletSign =>
      code == 'WALLET_NOT_VERIFIED' || code == 'WALLET_IP_MISMATCH';

  /// เครือข่ายเปลี่ยนกลางคัน (แยกออกมาเพื่อให้ข้อความบนจอตรงเหตุ)
  bool get isIpMismatch => code == 'WALLET_IP_MISMATCH';

  /// ยิงไม่ถึงเซิร์ฟเวอร์
  bool get isOffline => status == null;

  /// โดนจำกัดจำนวนคำขอ — ต้องหยุด poll แล้วถอยตาม [retryAfterSeconds]
  bool get isThrottled => status == 429 || code == 'RATE_LIMITED';

  /// ผิดที่ค่าในฟอร์ม — หน้าจอต้องชี้ลงช่องนั้น ไม่ใช่ toast รวม
  bool get isValidation => fieldErrors.isNotEmpty || code == 'VALIDATION_ERROR';

  /// ต้องยืนยันตัวตนก่อน (ด่านเปิดปิดได้จากหลังบ้านทุกเมื่อ)
  bool get needsKyc => code == 'KYC_REQUIRED';

  /// เรื่องเงินล้วนๆ — รหัสเดียวที่ควรพาไปหน้าเติมเครดิต
  ///
  /// `SALES_CLOSED` เป็น 422 และ **ห้าม** พาไปเติมเงิน เพราะเติมไปก็เช่าไม่ได้
  bool get needsCredits => code == 'INSUFFICIENT_CREDITS';

  /// ข้อความรายฟิลด์ตัวแรกของช่องที่ระบุ (ไว้แปะใต้ TextField)
  String? fieldError(String field) {
    final list = fieldErrors[field];
    if (list == null || list.isEmpty) return null;
    return list.first;
  }

  @override
  String toString() => 'ApiErr($code, status=$status): $message';
}

/// Developed by Xman Studio
