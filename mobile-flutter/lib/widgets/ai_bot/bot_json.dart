/// TPIX TRADE — ตัวช่วยอ่าน JSON ของ AI TRADE (ทนพัง)
/// เซิร์ฟเวอร์ส่งเลขมาเป็น int บ้าง double บ้าง (JSON 100 กับ 100.5) และหลายคีย์
/// "หายไปทั้งคีย์" ไม่ใช่ null (เช่น market.change_1h, tick.action, advice.reason)
/// ไฟล์นี้จึงรวมตัวแปลงที่ไม่ระเบิดเมื่อเจอรูปแบบที่ไม่คาด — วิดเจ็ตทุกตัวใน
/// โฟลเดอร์นี้อ่าน payload ดิบจาก API ผ่านตัวช่วยชุดนี้เท่านั้น
///
/// Developed by Xman Studio
library;

/// อ่านเป็นข้อความ — คืน null เมื่อไม่มีคีย์หรือเป็นค่าว่าง
String? jsonStr(dynamic v) {
  if (v == null) return null;
  final s = v.toString().trim();
  return s.isEmpty ? null : s;
}

/// อ่านเป็นทศนิยม — รับทั้ง int, double และสตริงตัวเลข (decimal ที่หลุด cast มา)
double? jsonDouble(dynamic v) {
  if (v == null) return null;
  if (v is num) return v.toDouble();
  return double.tryParse(v.toString());
}

/// อ่านเป็นจำนวนเต็ม
int? jsonInt(dynamic v) {
  if (v == null) return null;
  if (v is num) return v.toInt();
  return int.tryParse(v.toString());
}

/// อ่านเป็นบูลีน — เซิร์ฟเวอร์อาจส่ง 1/0 หรือ "true" มาได้
bool jsonBool(dynamic v, {bool fallback = false}) {
  if (v is bool) return v;
  if (v is num) return v != 0;
  final s = v?.toString().toLowerCase();
  if (s == 'true' || s == '1') return true;
  if (s == 'false' || s == '0') return false;
  return fallback;
}

/// อ่านเป็น map — คืน map ว่างเมื่อรูปแบบไม่ตรง (กัน cast ระเบิด)
Map<String, dynamic> jsonMap(dynamic v) {
  if (v is Map) {
    return v.map((key, value) => MapEntry(key.toString(), value));
  }
  return const {};
}

/// อ่านเป็นลิสต์ของ map — กรองสมาชิกที่ไม่ใช่ map ทิ้ง
List<Map<String, dynamic>> jsonMapList(dynamic v) {
  if (v is! List) return const [];
  final out = <Map<String, dynamic>>[];
  for (final item in v) {
    if (item is Map) {
      out.add(item.map((key, value) => MapEntry(key.toString(), value)));
    }
  }
  return out;
}

/// อ่านเป็นลิสต์ข้อความ — กรองค่าที่ว่างทิ้ง
List<String> jsonStrList(dynamic v) {
  if (v is! List) return const [];
  final out = <String>[];
  for (final item in v) {
    final s = jsonStr(item);
    if (s != null) out.add(s);
  }
  return out;
}

/// อ่านเวลา ISO8601 — Carbon ส่ง offset มาด้วย (+07:00) parse ได้ตรงๆ
/// ยกเว้น news.last_ingested_at ที่เป็น MySQL datetime ดิบ (ไม่มี timezone)
DateTime? jsonDate(dynamic v) {
  final s = jsonStr(v);
  if (s == null) return null;
  return DateTime.tryParse(s);
}
