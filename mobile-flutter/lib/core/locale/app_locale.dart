/// TPIX TRADE — ภาษาปัจจุบันของแอปสำหรับชั้นที่ไม่มี BuildContext
///
/// ตัว HTTP client (ApiService / AiBotApi / KycApi) เป็น singleton ไม่มี context
/// แต่ต้องแนบหัว `X-Locale` ไปกับทุกคำขอ เพื่อให้เหตุผลที่เซิร์ฟเวอร์แปลให้
/// (app/Http/Middleware/SetLocale.php) มาเป็นภาษาเดียวกับที่ผู้ใช้เลือกในแอป
/// — ไม่แนบ = ได้ภาษาปริยายของเว็บ ผู้ใช้ที่เลือกอังกฤษเห็นข้อความไทยปน
///
/// [LocaleProvider] เป็นผู้เขียนค่านี้ทุกครั้งที่โหลด/เปลี่ยนภาษา
///
/// Developed by Xman Studio
library;

class AppLocale {
  AppLocale._();

  static const String headerName = 'X-Locale';

  /// ภาษาที่เซิร์ฟเวอร์รองรับ — ต้องตรงกับ SetLocale::SUPPORTED
  static const List<String> supported = ['th', 'en'];

  static String _code = 'en';

  /// รหัสภาษาปัจจุบัน ('th' | 'en')
  static String get code => _code;

  /// ตั้งภาษา — ค่าที่ไม่รู้จักถูกเมิน (คงค่าเดิม) ไม่ส่งค่าแปลกไปให้เซิร์ฟเวอร์
  static void set(String? code) {
    final c = code?.trim().toLowerCase();
    if (c != null && supported.contains(c)) _code = c;
  }

  /// หัวที่ต้องแนบไปกับทุกคำขอ API
  static Map<String, String> headers() => {headerName: _code};
}
