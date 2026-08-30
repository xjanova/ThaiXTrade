/// TPIX TRADE — Locale & Language Provider
/// รองรับ ไทย/อังกฤษ (ดึง pattern จาก TPIX Wallet)
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

class LocaleProvider extends ChangeNotifier {
  static const String _key = 'app_locale';
  String _locale = 'en';

  String get locale => _locale;
  bool get isThai => _locale == 'th';

  Future<void> init() async {
    final prefs = await SharedPreferences.getInstance();
    _locale = prefs.getString(_key) ?? 'en';
    notifyListeners();
  }

  Future<void> setLocale(String locale) async {
    _locale = locale;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_key, locale);
    notifyListeners();
  }

  Future<void> toggle() async {
    await setLocale(_locale == 'th' ? 'en' : 'th');
  }

  String t(String key) =>
      _translations[_locale]?[key] ?? _translations['en']?[key] ?? key;

  /// มีคีย์นี้จริงไหม (ใช้ตัดสินก่อนจะ fallback ไปข้อความจากเซิร์ฟเวอร์)
  bool has(String key) =>
      _translations[_locale]?.containsKey(key) == true ||
      _translations['en']?.containsKey(key) == true;

  /// แปลพร้อมแทนค่าตัวแปรในข้อความ — ตัวแปรเขียนเป็น {ชื่อ} ในไฟล์แปล
  /// ตัวอย่าง: tp('aiTrade.daysLeft', {'days': '7'}) → 'เหลืออีก 7 วัน'
  /// เหตุผลที่ต้องมี: ถ้าต่อสตริงเอง ลำดับคำของสองภาษาจะสลับกันไม่ได้
  String tp(String key, Map<String, Object?> params) {
    var text = t(key);
    params.forEach((name, value) {
      text = text.replaceAll('{$name}', '${value ?? ''}');
    });
    return text;
  }

  /// แปลรหัสข้อผิดพลาดจากเซิร์ฟเวอร์เป็นข้อความสองภาษา
  /// [code] คือ error.code เช่น WALLET_NOT_VERIFIED · [serverMessage] คือ
  /// error.message (ไทยล้วน) ใช้เป็นทางสำรองเมื่อเจอรหัสที่แอพยังไม่รู้จัก
  /// — ห้ามคืนค่าว่าง เพราะทุกความล้มเหลวต้องมีเหตุผลบนจอเสมอ
  String tError(String? code, {String? serverMessage}) {
    final key = 'aiTrade.err.${code ?? ''}';
    if (code != null && code.isNotEmpty && has(key)) return t(key);
    final fallback = serverMessage?.trim();
    if (fallback != null && fallback.isNotEmpty) return fallback;
    return t('aiTrade.err.REQUEST_FAILED');
  }

  static const Map<String, Map<String, String>> _translations = {
    'th': {
      // Navigation
      'nav.home': 'หน้าหลัก',
      'nav.markets': 'ตลาด',
      'nav.trade': 'เทรด',
      'nav.portfolio': 'พอร์ต',
      'nav.settings': 'ตั้งค่า',
      'nav.ai': 'AI',
      'nav.market': 'ตลาด',
      'nav.swap': 'สลับ',
      'nav.wallet': 'กระเป๋า',

      // Home
      'home.welcome': 'สวัสดี',
      'home.portfolio_value': 'มูลค่าพอร์ต',
      'home.favorites': 'รายการโปรด',
      'home.top_gainers': 'เพิ่มขึ้นสูงสุด',
      'home.top_losers': 'ลดลงสูงสุด',
      'home.recent_trades': 'เทรดล่าสุด',

      // Markets
      'markets.search': 'ค้นหาเหรียญ...',
      'markets.all': 'ทั้งหมด',
      'markets.spot': 'Spot',
      'markets.favorites': 'โปรด',
      'markets.price': 'ราคา',
      'markets.change': 'เปลี่ยนแปลง',
      'markets.volume': 'ปริมาณ',

      // Trade
      'trade.buy': 'ซื้อ',
      'trade.sell': 'ขาย',
      'trade.limit': 'Limit',
      'trade.market': 'Market',
      'trade.price': 'ราคา',
      'trade.amount': 'จำนวน',
      'trade.total': 'รวม',
      'trade.orderbook': 'ออเดอร์บุ๊ค',
      'trade.recent_trades': 'เทรดล่าสุด',
      'trade.open_orders': 'ออเดอร์ที่เปิด',
      'trade.balance': 'ยอดคงเหลือ',

      // Portfolio
      'portfolio.title': 'พอร์ตโฟลิโอ',
      'portfolio.total_value': 'มูลค่ารวม',
      'portfolio.assets': 'สินทรัพย์',
      'portfolio.history': 'ประวัติ',
      'portfolio.pnl': 'กำไร/ขาดทุน',

      // Settings
      'settings.title': 'ตั้งค่า',
      'settings.wallet': 'กระเป๋าเงิน',
      'settings.connect_wallet': 'เชื่อมกระเป๋า',
      'settings.disconnect': 'ยกเลิกการเชื่อมต่อ',
      'settings.chain': 'เครือข่าย',
      'settings.language': 'ภาษา',
      'settings.currency': 'สกุลเงิน',
      'settings.biometric': 'ลายนิ้วมือ',
      'settings.notifications': 'การแจ้งเตือน',
      'settings.about': 'เกี่ยวกับ',
      'settings.version': 'เวอร์ชัน',
      'settings.check_update': 'ตรวจสอบอัปเดต',
      'settings.update_available': 'มีอัปเดตใหม่',
      'settings.profile': 'โปรไฟล์',
      'settings.edit_profile': 'แก้ไขโปรไฟล์',

      // Profile
      'profile.title': 'โปรไฟล์ของฉัน',
      'profile.name': 'ชื่อแสดง',
      'profile.email': 'อีเมล',
      'profile.avatar': 'รูปโปรไฟล์ (URL)',
      'profile.referral_code': 'รหัสแนะนำเพื่อน',
      'profile.kyc_status': 'สถานะยืนยันตัวตน',
      'profile.total_trades': 'จำนวนการเทรด',
      'profile.set_name': 'ตั้งชื่อ',
      'profile.set_email': 'เพิ่มอีเมล',
      'profile.guest': 'ผู้ใช้ใหม่',
      'profile.update_success': 'บันทึกโปรไฟล์แล้ว',
      'profile.update_failed': 'บันทึกโปรไฟล์ไม่สำเร็จ',
      'profile.invalid_email': 'อีเมลไม่ถูกต้อง',
      'profile.name_too_long': 'ชื่อยาวเกินไป (สูงสุด 50 ตัวอักษร)',
      'profile.verify_first': 'กรุณายืนยัน wallet ก่อนแก้ไขโปรไฟล์',

      // Wallet
      'wallet.create': 'สร้างกระเป๋าใหม่',
      'wallet.import': 'นำเข้ากระเป๋า',
      'wallet.backup': 'สำรองข้อมูล',
      'wallet.backup_warning': 'จดบันทึก 12 คำนี้ไว้ที่ปลอดภัย',
      'wallet.confirm_backup': 'ฉันบันทึกแล้ว',
      'wallet.import_hint': 'ใส่ 12 คำ คั่นด้วยช่องว่าง...',
      'wallet.import_button': 'นำเข้า',
      'wallet.invalid_mnemonic': 'วลีกู้คืนไม่ถูกต้อง',
      'wallet.creating': 'กำลังสร้าง...',
      'wallet.importing': 'กำลังนำเข้า...',
      'wallet.connected': 'เชื่อมต่อแล้ว',

      // Common
      'common.cancel': 'ยกเลิก',
      'common.confirm': 'ยืนยัน',
      'common.save': 'บันทึก',
      'common.done': 'เสร็จสิ้น',
      'common.error': 'เกิดข้อผิดพลาด',
      'common.retry': 'ลองอีกครั้ง',
      'common.loading': 'กำลังโหลด...',
      'common.no_data': 'ไม่มีข้อมูล',
      'common.copied': 'คัดลอกแล้ว!',
      'common.coming_soon': 'เร็วๆ นี้',
      'common.later': 'ไว้ทีหลัง',
      'common.download': 'ดาวน์โหลด',
      'common.downloading': 'กำลังดาวน์โหลด...',
      'common.search_pairs': 'ค้นหาคู่เทรด...',
      'common.chart': 'กราฟ',
      'common.spread': 'สเปรด',

      // Update
      'update.checking': 'กำลังตรวจสอบอัปเดต...',
      'update.latest': 'คุณใช้เวอร์ชันล่าสุดแล้ว',
      'update.available': 'มีอัปเดตใหม่',

      // Wallet status
      'wallet.verified': 'ยืนยันแล้ว',
      'wallet.pending': 'รอยืนยัน',
      'wallet.address_copied': 'คัดลอกที่อยู่แล้ว!',

      // Portfolio
      'portfolio.no_assets': 'ยังไม่มีสินทรัพย์',
      'portfolio.assets_count': 'สินทรัพย์',

      // Trade
      'trade.order_success': 'สร้างออเดอร์สำเร็จ',
      'trade.order_failed': 'สร้างออเดอร์ไม่สำเร็จ',
      'trade.invalid_amount': 'กรุณาใส่จำนวนที่ถูกต้อง',
      'trade.invalid_price': 'กรุณาใส่ราคาที่ถูกต้อง',
      'trade.fee': 'ค่าธรรมเนียม',
      'trade.below_min': 'จำนวนต่ำกว่าขั้นต่ำ',
      'trade.above_max': 'จำนวนเกินสูงสุด',
      'trade.platform_not_ready': 'ระบบยังไม่พร้อมให้เทรด — ติดต่อผู้ดูแล',
      'trade.stop_limit': 'Stop-Limit',
      'trade.trigger_price': 'ราคาทริกเกอร์',
      'trade.invalid_trigger': 'กรุณาใส่ราคาทริกเกอร์',

      // Bridge
      'bridge.title': 'บริดจ์ข้ามเชน',
      'bridge.from_chain': 'เชนต้นทาง',
      'bridge.to_chain': 'เชนปลายทาง',
      'bridge.amount': 'จำนวน',
      'bridge.fee': 'ค่าธรรมเนียมบริดจ์',
      'bridge.estimated_time': 'เวลาโดยประมาณ',
      'bridge.minutes': 'นาที',
      'bridge.submit': 'เริ่มบริดจ์',
      'bridge.disabled': 'ระบบบริดจ์ยังไม่เปิด',
      'bridge.min_amount': 'ขั้นต่ำ',
      'bridge.max_amount': 'สูงสุด',

      // Peer app (cross-app discovery)
      'peer.open_wallet': 'เปิด TPIX Wallet',
      'peer.wallet_desc': 'กระเป๋าของคุณติดตั้งอยู่ในเครื่อง',
      'peer.install_wallet': 'ติดตั้ง TPIX Wallet',
      'peer.install_wallet_desc': 'จัดการกระเป๋าและเหรียญแบบครบครัน',
      'peer.connect_title': 'เชื่อมกระเป๋าจาก Wallet?',
      'peer.connect_desc': 'TPIX Wallet ส่งที่อยู่มาให้คุณ',
      'peer.connect_accept': 'เชื่อมต่อ',

      // ─────────────────────────────────────────────────────────────
      // AI TRADE — บอทเทรดคลาวด์
      // ชุดคีย์นี้ล้อกับ aiTrade.* ของเว็บแบบ 1:1 เพื่อให้ถ้อยคำบนเว็บกับ
      // ในแอพตรงกันคำต่อคำ (ผู้ใช้คนเดียวกัน กระเป๋าใบเดียวกัน)
      // ตัวแปรในข้อความเขียนเป็น {ชื่อ} — แทนค่าด้วย tp() อย่าต่อสตริงเอง
      // ─────────────────────────────────────────────────────────────

      // หัวหน้า / บทนำ
      'aiTrade.badge': 'บอทคลาวด์',
      'aiTrade.title': 'AI TRADE',
      'aiTrade.backToBoard': 'กลับไปกระดานเทรด',
      'aiTrade.intro':
          'เช่าบอทเทรดที่รันบนคลาวด์ของ TPIX ตลอด 24 ชั่วโมง เลือกกลยุทธ์ ตั้งกรอบความเสี่ยงเอง แล้วให้บอททำงานแทน — ตั้งที่เว็บหรือในแอพก็เห็นเหมือนกัน เพราะผูกกับกระเป๋าใบเดียวกัน',
      'aiTrade.headline': 'ให้บอทเทรดแทนคุณ 24 ชม.',
      'aiTrade.subheadline':
          'บอทรันบนคลาวด์ของ TPIX ไม่ต้องเปิดเครื่องทิ้งไว้ ใช้ร่วมกันได้ทั้งเว็บและแอพด้วยกระเป๋าเดียว',

      // เชื่อมกระเป๋า / ยืนยันตัวตนของกระเป๋า
      'aiTrade.connectToStart': 'เชื่อมกระเป๋าเพื่อเริ่ม',
      'aiTrade.connectPrompt': 'เชื่อมกระเป๋าเพื่อเริ่มใช้ AI TRADE',
      'aiTrade.connectHint': 'บอทและเครดิตผูกกับที่อยู่กระเป๋าของคุณโดยตรง',
      'aiTrade.connectWallet': 'เชื่อมกระเป๋า',
      'aiTrade.needVerify': 'ต้องยืนยันกระเป๋าก่อน',
      'aiTrade.needVerifyBody':
          'AI TRADE ผูกกับกระเป๋าของคุณโดยตรง กรุณาเชื่อมกระเป๋าใหม่แล้วเซ็นข้อความยืนยัน',
      'aiTrade.reconnect': 'เชื่อมกระเป๋าใหม่',
      'aiTrade.verifyNow': 'เซ็นยืนยันกระเป๋า',
      'aiTrade.verifying': 'รอเซ็นในกระเป๋า...',
      'aiTrade.verifyOk': 'ยืนยันกระเป๋าแล้ว',
      'aiTrade.verifyFailed':
          'ยังยืนยันไม่สำเร็จ — ต้องกดยอมรับการเซ็นข้อความในกระเป๋า',
      'aiTrade.verifyNeedUnlock':
          'ปลดล็อกกระเป๋า TPIX ด้วยรหัสผ่านก่อน แล้วลองใหม่',
      'aiTrade.verifyExpiredHint':
          'การยืนยันมีอายุ 4 ชั่วโมง เปิดหน้าใหม่หลังหมดอายุต้องเซ็นอีกครั้ง (ไม่เสียค่าแก๊ส)',

      // ด่านความเสี่ยง — ประเมินไม่ได้
      'aiTrade.riskUnavailable': 'ประเมินไม่ได้',
      'aiTrade.riskUnavailableBody':
          'ยังไม่มีข้อมูลราคาย้อนหลังของคู่ {pair} มากพอให้ประเมินความเสี่ยงจากราคา — ตัวเลขความเสี่ยงจึงยังใช้อ้างอิงไม่ได้ (ด่านข่าวยังทำงานตามปกติ) เลือกคู่อื่นถ้าต้องการให้บอทประเมินครบทุกด้าน',

      // การ์ดชวนเปิดใช้งาน
      'aiTrade.cloudBot': 'บอทเทรดคลาวด์',
      'aiTrade.pitch': 'เช่าเป็นวัน จ่ายด้วยเครดิตการทำงาน · {count} กลยุทธ์พร้อมใช้',
      'aiTrade.chip247': 'คลาวด์',
      'aiTrade.chipRisk': 'คุมเสี่ยง',
      'aiTrade.chipSync': 'ซิงก์กัน',
      'aiTrade.chipWebApp': 'เว็บ+แอพ',
      'aiTrade.credits': 'เครดิตคงเหลือ',
      'aiTrade.creditsFull': 'เครดิตการทำงาน',
      'aiTrade.required': 'ต้องใช้',
      'aiTrade.activate': 'เปิดใช้งาน AI TRADE',
      'aiTrade.seeAllPlans': 'ดูแพลนและกลยุทธ์ทั้งหมด',
      'aiTrade.configure': 'ตั้งค่ากลยุทธ์แบบละเอียด',
      'aiTrade.configureShort': 'ตั้งค่าแบบละเอียด',
      'aiTrade.running': 'ทำงาน',
      'aiTrade.tradingNow': 'กำลังเทรด',
      'aiTrade.botsUnit': '{count} บอท',
      'aiTrade.daysLeft': 'เหลืออีก {days} วัน',
      'aiTrade.botsQuota': 'บอท {used}',
      'aiTrade.noBots': 'ยังไม่มีบอท — สร้างตัวแรกได้ในหน้าตั้งค่า',
      'aiTrade.noBotsHint': 'เลือกกลยุทธ์ที่เข้ากับสไตล์ของคุณ',

      // ปุ่มสั่งงานบอท
      'aiTrade.start': 'เริ่ม',
      'aiTrade.pause': 'พัก',
      'aiTrade.edit': 'แก้ไข',
      'aiTrade.remove': 'ลบ',
      'aiTrade.close': 'ปิด',
      'aiTrade.cancel': 'ยกเลิก',

      // ประตูเช่า (โมดัลในการ์ด)
      'aiTrade.gateTitle': 'เปิดใช้งาน AI TRADE',
      'aiTrade.gateSub': 'เช่าบอทเป็นวัน ยกเลิกได้ คืนเครดิตวันที่เหลือ',
      'aiTrade.gateWarn':
          'คุณยังไม่ได้เช่าบอท — ต้องมีเครดิตการทำงานคงเหลือพอ บอทจึงจะเริ่มทำงานบนคลาวด์ได้ เครดิตจะถูกตัดตามจำนวนวันที่เช่า',
      'aiTrade.rentDays': 'จำนวนวันที่เช่า',
      'aiTrade.days': '{n} วัน',
      'aiTrade.choosePlan': 'เลือกแพลน',
      'aiTrade.rentFor': 'เช่า {days} วัน',
      'aiTrade.renewFor': 'ต่ออายุ +{days} วัน',
      'aiTrade.topupFirst': 'เติมเครดิตก่อน',
      'aiTrade.notEnough': 'เครดิตไม่พอ — ขาดอีก {n} เครดิต',
      'aiTrade.notEnoughLong':
          'เครดิตการทำงานไม่พอ ขาดอีก {n} เครดิต — เติมเครดิตก่อนเริ่มใช้งานบอท',
      'aiTrade.packHint':
          'กดแพ็กเพื่อสร้างคำขอเติมเครดิต — เครดิตจะเข้าหลังทีมงานยืนยันการชำระเงิน',

      // ธงจากเซิร์ฟเวอร์
      'aiTrade.salesClosed':
          'AI TRADE อยู่ระหว่างทดสอบ — ยังไม่เปิดให้เช่า ระหว่างนี้ทดลองใช้โหมดทดลองด้วยเครดิตจำลองที่ราคาจริงได้เต็มที่ ไม่มีค่าใช้จ่าย',
      'aiTrade.teamMode':
          'โหมดทีมงาน — ใช้ได้ทุกฟังก์ชันโดยไม่ต้องเช่าหรือเติมเครดิต',
      'aiTrade.topupClosed':
          'ยังไม่เปิดให้เติมเครดิต — รอประกาศเปิดระบบชำระเงินก่อน ระหว่างนี้ใช้เครดิตต้อนรับทดลองระบบได้เต็มที่',
      'aiTrade.botStale':
          '⚠️ บอทไม่ได้เดินมา {n} นาที — ระบบประมวลผลอาจมีปัญหา แจ้งทีมงานได้เลย',

      // เติมเครดิต
      'aiTrade.topupTitle': 'เติมเครดิตการทำงาน',
      'aiTrade.topupSub':
          'กดเลือกแพ็กเพื่อสร้างคำขอ — เครดิตจะเข้าบัญชีหลังทีมงานยืนยันการชำระเงิน',
      'aiTrade.topupOk':
          'ส่งคำขอเติมเครดิตแล้ว — เครดิตจะเข้าหลังทีมงานยืนยันการชำระเงิน',
      'aiTrade.bonus': 'โบนัส {n}',

      // ผลลัพธ์ของการเช่า
      'aiTrade.activated': 'เปิดใช้งาน AI TRADE เรียบร้อย — ไปตั้งค่ากลยุทธ์ได้เลย',
      'aiTrade.planActivated': 'เปิดใช้งานแพลนเรียบร้อย — สร้างบอทได้เลย',
      'aiTrade.welcomeFree': 'รับเครดิตต้อนรับฟรี',
      'aiTrade.welcomeOk': 'รับเครดิตต้อนรับเรียบร้อย',
      'aiTrade.currentPlan': 'แพลนที่ใช้อยู่',
      'aiTrade.notRented': 'ยังไม่ได้เช่า',
      'aiTrade.botsUsed': 'บอทที่ใช้ไป',
      'aiTrade.cancelPlan': 'ยกเลิก',
      'aiTrade.switchConfirm':
          'เปลี่ยนแพลนจะจบแพลน "{from}" ที่เหลืออีก {days} วันทันที และคืนเครดิตส่วนที่ยังไม่ได้ใช้ ยืนยันไหม?',
      'aiTrade.cancelConfirm':
          'ยกเลิกการเช่าและหยุดบอททั้งหมด? เครดิตของวันที่เหลือจะถูกคืนให้',
      'aiTrade.cancelOk': 'ยกเลิกแล้ว — คืนเครดิตของวันที่เหลือเรียบร้อย',

      // เลือกแพลน
      'aiTrade.choosePlanTitle': 'เลือกแพลนเช่า',
      'aiTrade.dayCount': 'จำนวนวัน',
      'aiTrade.creditsPerDay': 'เครดิต/วัน',
      'aiTrade.totalCost': '{days} วัน = {credits} เครดิต',
      'aiTrade.maxBots': 'บอทสูงสุด {n} ตัว',
      'aiTrade.capPerTrade': 'ทุนต่อไม้ ไม่เกิน \${n}',
      'aiTrade.capUnlimited': 'ไม่จำกัดทุนต่อไม้',

      // รายการบอท
      'aiTrade.myBots': 'บอทของฉัน',
      'aiTrade.newBot': 'สร้างบอทใหม่',
      'aiTrade.quotaFull': 'จำนวนบอทเต็มโควตาของแพลนแล้ว',
      'aiTrade.noBotsLong': 'ยังไม่มีบอท — กดสร้างบอทใหม่เพื่อเลือกกลยุทธ์แรกของคุณ',

      // ฟอร์มสร้าง/แก้บอท
      'aiTrade.editBot': 'แก้ไขบอท',
      'aiTrade.createBot': 'สร้างบอทใหม่',
      'aiTrade.saveEdit': 'บันทึกการแก้ไข',
      'aiTrade.createdOk': 'สร้างบอทแล้ว — กดเริ่มเพื่อให้บอททำงาน',
      'aiTrade.savedOk': 'บันทึกการแก้ไขแล้ว',
      'aiTrade.botName': 'ชื่อบอท',
      'aiTrade.botNamePlaceholder': 'เช่น กริด BTC กลางคืน',
      'aiTrade.pair': 'คู่เทรด',
      'aiTrade.strategy': 'กลยุทธ์',
      'aiTrade.timeframe': 'กรอบเวลา',
      'aiTrade.nameRequired': 'ตั้งชื่อบอทก่อนบันทึก',
      'aiTrade.needPlan': 'ต้องเช่าแพลนก่อนจึงจะสร้างบอทได้ — เลือกแพลนด้านบน',
      'aiTrade.deleteConfirm': 'ลบบอท {name} ?',
      'aiTrade.deletedOk': 'ลบบอทแล้ว',
      'aiTrade.defaultBotName': 'บอท {n}',

      // กรอบความเสี่ยง
      'aiTrade.riskFrame': 'กรอบความเสี่ยง',
      'aiTrade.riskNote': 'เซิร์ฟเวอร์จะตัดค่าให้อยู่ในเพดานของแพลนเสมอ',
      'aiTrade.maxPosition': 'ทุนสูงสุดต่อไม้ (USD)',
      'aiTrade.stopLoss': 'ตัดขาดทุน (%)',
      'aiTrade.takeProfit': 'ทำกำไร (%)',
      'aiTrade.maxDailyLoss': 'ขาดทุนสูงสุดต่อวัน (USD)',
      'aiTrade.riskLow': 'เสี่ยงต่ำ',
      'aiTrade.riskMedium': 'เสี่ยงกลาง',
      'aiTrade.riskHigh': 'เสี่ยงสูง',

      // ระดับแพลนของกลยุทธ์
      'aiTrade.tierFree': '',
      'aiTrade.tierBasic': 'ทุกแพลน',
      'aiTrade.tierPro': 'Pro ขึ้นไป',
      'aiTrade.tierVip': 'VIP เท่านั้น',
      'aiTrade.needTier': 'ต้องใช้แพลน {tier}',
      'aiTrade.notReady': 'ยังไม่เปิด',

      // ที่ปรึกษา AI
      'aiTrade.advisorTitle': 'ที่ปรึกษา AI',
      'aiTrade.advisorSub':
          'อ่านสถิติย้อนหลังของบอทคุณแล้วให้ความเห็นว่าควรปรับตรงไหน',
      'aiTrade.advisorAsk': 'ขอคำแนะนำ',
      'aiTrade.advisorAsking': 'กำลังวิเคราะห์...',
      'aiTrade.advisorRefresh': 'ขอใหม่',
      'aiTrade.advisorDisclaimer':
          'เป็นความเห็นประกอบการตัดสินใจ ไม่ใช่คำสั่งซื้อขาย — บอทยังเดินตามกฎที่คุณตั้งไว้เหมือนเดิม',
      'aiTrade.advisorNeedWallet': 'เชื่อมกระเป๋าก่อนถึงขอคำแนะนำได้',
      'aiTrade.advisorNeedTrades':
          'ยังไม่มีไม้ให้วิเคราะห์ — เปิดบอทเดินสักพักก่อน',

      // กลยุทธ์ทั้งหมด
      'aiTrade.allStrategies': 'กลยุทธ์ทั้งหมด',
      'aiTrade.allStrategiesSub':
          'แต่ละกลยุทธ์รันบนคลาวด์เดียวกัน ปรับพารามิเตอร์ได้เอง และหยุดได้ทุกเมื่อ',
      'aiTrade.openBoard': 'เปิดกระดานเทรด',
      'aiTrade.on': 'เปิด',
      'aiTrade.off': 'ปิด',

      // โหมดทดลอง / โหมดจริง
      'aiTrade.modeDemo': 'โหมดทดลอง',
      'aiTrade.modeLive': 'โหมดจริง',
      'aiTrade.modeDemoShort': 'ทดลอง',
      'aiTrade.modeLiveShort': 'จริง',
      'aiTrade.switchToDemo': 'สลับเป็นโหมดทดลอง',
      'aiTrade.switchToLive': 'สลับเป็นโหมดจริง',
      'aiTrade.modeSwitched': 'สลับโหมดเรียบร้อย',
      'aiTrade.liveNeedsPlan': 'ต้องเช่าบอทก่อนถึงจะเปิดโหมดจริงได้',
      'aiTrade.liveHint':
          'โหมดจริงจะส่งสัญญาณให้คุณกดยืนยันในกระเป๋าเอง ระบบไม่ถือกุญแจของคุณ',
      'aiTrade.demoHint':
          'ทดลองด้วยเครดิตจำลอง ใช้ราคาจริงจากตลาด ไม่มีเงินจริงเกี่ยวข้อง',

      // พอร์ตทดลอง
      'aiTrade.demoTitle': 'พอร์ตทดลอง',
      'aiTrade.demoSubtitle': 'ลองกลยุทธ์ด้วยราคาจริงก่อนตัดสินใจเช่า',
      'aiTrade.demoBalance': 'เครดิตทดลองคงเหลือ',
      'aiTrade.demoEquity': 'มูลค่าพอร์ตรวม',
      'aiTrade.demoStarting': 'ทุนตั้งต้น',
      'aiTrade.demoPnl': 'กำไร/ขาดทุนที่ปิดแล้ว',
      'aiTrade.demoOpenPositions': 'ของที่ถืออยู่',
      'aiTrade.demoNoPositions': 'ยังไม่มีของที่ถืออยู่',
      'aiTrade.demoReset': 'ล้างพอร์ต',
      'aiTrade.demoResetConfirm':
          'ล้างพอร์ตทดลองกลับไปตั้งต้น? ประวัติการเทรดทดลองทั้งหมดจะหายไป',
      'aiTrade.demoResetOk': 'ล้างพอร์ตทดลองแล้ว',
      'aiTrade.demoResetsLeft': 'ล้างได้อีก {count} ครั้งวันนี้',
      'aiTrade.demoResetNone': 'วันนี้ล้างครบแล้ว พรุ่งนี้เริ่มใหม่ได้',
      'aiTrade.demoStartHint':
          'สร้างบอทสักตัวแล้วกดเริ่ม บอทจะเทรดด้วยเครดิตทดลองให้ดูทันที',
      'aiTrade.demoSummary': 'สรุปผลการทดลอง',
      'aiTrade.demoTrades': 'จำนวนไม้',
      'aiTrade.demoClosed': 'ไม้ที่ปิดแล้ว',
      'aiTrade.demoWins': 'ไม้ที่กำไร',
      'aiTrade.demoLosses': 'ไม้ที่ขาดทุน',
      'aiTrade.demoWinRate': 'อัตราชนะ',
      'aiTrade.demoFees': 'ค่าธรรมเนียมรวม',
      'aiTrade.demoNoData': 'ยังไม่มีข้อมูลพอสรุป',
      'aiTrade.demoAssumptions':
          'จำลองด้วยค่าธรรมเนียม {fee}% และ slippage {slippage} bps ทุกไม้ — ตั้งใจให้ผลออกมาแย่กว่าจริงเล็กน้อย จะได้ไม่หลอกตัวเอง',

      // ประวัติการเทรด
      'aiTrade.tradeLog': 'ประวัติการเทรด',
      'aiTrade.tradeLogEmpty': 'ยังไม่มีไม้ที่เทรด',
      'aiTrade.tradeBuy': 'ซื้อ',
      'aiTrade.tradeSell': 'ขาย',
      'aiTrade.tradeReason': 'เหตุผล',
      'aiTrade.tradePrice': 'ราคา',
      'aiTrade.tradeAmount': 'จำนวน',
      'aiTrade.tradeFee': 'ค่าธรรมเนียม',
      'aiTrade.tradePnl': 'กำไร/ขาดทุน',
      'aiTrade.tradeTime': 'เวลา',
      'aiTrade.entryPrice': 'ราคาต้นทุนเฉลี่ย',
      'aiTrade.entryCount': 'จำนวนครั้งที่เข้า',
      'aiTrade.unrealized': 'ยังไม่ปิด',
      'aiTrade.unpriced': 'ยังตีราคาไม่ได้',
      'aiTrade.chartMarkersBot': 'ไม้ของบอท',
      'aiTrade.chartMarkersMine': 'ไม้ที่เราวางเอง',
      'aiTrade.costBasis': 'เงินที่ลงไป',

      // ด่านความเสี่ยง
      'aiTrade.riskGate': 'ด่านความเสี่ยง',
      'aiTrade.riskNow': 'ความเสี่ยงตอนนี้',
      'aiTrade.riskCalm': 'ปกติ',
      'aiTrade.riskCaution': 'ระวัง',
      'aiTrade.riskElevated': 'เสี่ยงสูง',
      'aiTrade.riskPanic': 'ตื่นตระหนก',
      'aiTrade.riskSizeMultiplier': 'ขนาดไม้ที่อนุญาต',
      'aiTrade.riskForceExit': 'บังคับเทออก',
      'aiTrade.riskWhy': 'เหตุผล',
      'aiTrade.riskNoReason': 'ตลาดปกติ ไม่มีสัญญาณอันตราย',
      'aiTrade.newsHeadlines': 'ข่าวที่บอทกำลังจับตา',
      'aiTrade.newsNone': 'ยังไม่มีข่าวเสี่ยงในช่วง 3 ชั่วโมงที่ผ่านมา',
      'aiTrade.newsScanned': 'อัปเดตข่าวทุก 15 นาที',

      // การตัดสินใจล่าสุดของบอท
      'aiTrade.lastDecision': 'การตัดสินใจล่าสุด',
      'aiTrade.awaitingConfirm': 'รอคุณยืนยัน',
      'aiTrade.noDecisionYet': 'บอทยังไม่ได้ตัดสินใจรอบแรก',

      // ที่บอทเดิน / ราคา
      'aiTrade.runsInCloud': 'รันบนคลาวด์',
      'aiTrade.runsInBrowser': 'รันในเบราว์เซอร์',
      'aiTrade.tpixPerDay': 'TPIX/วัน',
      'aiTrade.totalCostTpix': '{days} วัน = {tpix} TPIX',
      'aiTrade.freeForever': 'ฟรี',
      'aiTrade.noPaymentNeeded': 'ไม่ต้องจ่ายอะไรเลย',
      'aiTrade.startFree': 'เริ่มใช้ฟรี',
      'aiTrade.payWithTpix': 'ชำระด้วย TPIX เท่านั้น',

      // เทียบแพลนฟรีกับแพลนคลาวด์
      'aiTrade.compareFreeTitle': 'แพลนฟรี — บอทเดินในเบราว์เซอร์ของคุณ',
      'aiTrade.compareFreeBody':
          'บอททำงานอยู่ในแท็บนี้ ต้องเปิดหน้าเว็บทิ้งไว้ตลอด ถ้าปิดแท็บ ปิดเครื่อง หรือเน็ตหลุด บอทจะหยุดทันทีและไม่เดินต่อจนกว่าคุณจะกลับมาเปิดใหม่ ใช้ได้ 1 บอท กับกลยุทธ์ Grid และ DCA',
      'aiTrade.compareCloudTitle': 'แพลนเสียเงิน — เซิร์ฟเวอร์เดินให้ตลอด 24 ชม.',
      'aiTrade.compareCloudBody':
          'บอทอยู่บนเซิร์ฟเวอร์ของเรา ปิดเบราว์เซอร์ ปิดคอม หรือแม้แต่นอนหลับ บอทก็ยังเฝ้าตลาดและทำงานต่อ พร้อมด่านความเสี่ยงจากข่าวทุก 15 นาที กลยุทธ์ครบ และรันได้หลายบอทพร้อมกัน',
      'aiTrade.liveOpenNotice':
          'โหมดจริงเปิดแล้ว — บอทจะเสนอสัญญาณให้คุณกดยืนยันเอง ระบบไม่ถือกุญแจกระเป๋าของคุณ จึงส่งคำสั่งแทนไม่ได้',
      'aiTrade.demoOnlyNotice':
          'ตอนนี้เปิดให้ใช้เฉพาะโหมดทดลอง (เทรดด้วยเครดิตจำลองที่ราคาจริง) ทุกแพลนจึงยังไม่มีการส่งคำสั่งด้วยเงินจริง เราจะเปิดโหมดจริงเมื่อระบบผ่านการทดสอบครบถ้วนแล้ว',

      // ลูปเดินบอทฝั่งไคลเอนต์ (ถ้อยคำเวอร์ชันเว็บ)
      'aiTrade.browserBotRunning': 'บอทกำลังเดินอยู่ในแท็บนี้',
      'aiTrade.browserBotWarning': 'อย่าปิดแท็บนี้ ถ้าปิดบอทจะหยุดทำงานทันที',
      'aiTrade.browserBotStopped': 'บอทหยุดแล้วเพราะออกจากหน้านี้',
      'aiTrade.upgradeForCloud': 'อัปเกรดเพื่อให้บอทเดินต่อแม้ปิดเบราว์เซอร์',
      'aiTrade.lastTickAt': 'เดินรอบล่าสุดเมื่อ',

      // ── เพิ่มสำหรับแอพโดยเฉพาะ (เว็บไม่มี) ──
      // ลูปเดินบอทในแอพ — ใช้ชุดนี้แทน browserBot* บนมือถือ
      'aiTrade.runsInApp': 'รันในแอพ',
      'aiTrade.appBotRunning': 'บอทกำลังเดินอยู่ในแอพ',
      'aiTrade.appBotWarning':
          'ต้องเปิดหน้านี้ค้างไว้ ถ้าปิดแอพหรือสลับไปทำอย่างอื่น บอทจะหยุดทันที',
      'aiTrade.appBotStopped': 'บอทหยุดแล้วเพราะออกจากหน้านี้',
      'aiTrade.upgradeForCloudApp': 'อัปเกรดเพื่อให้บอทเดินต่อแม้ปิดแอพ',
      'aiTrade.compareFreeBodyApp':
          'บอททำงานอยู่ในแอพ ต้องเปิดหน้านี้ค้างไว้ตลอด ถ้าปิดแอพ สลับไปแอพอื่นนานๆ หรือเน็ตหลุด บอทจะหยุดทันทีและไม่เดินต่อจนกว่าคุณจะกลับมาเปิดใหม่ ใช้ได้ 1 บอท กับกลยุทธ์ Grid และ DCA',
      'aiTrade.tickNextIn': 'รอบถัดไปในอีก {n} วินาที',
      'aiTrade.tickSkipped': 'ยังไม่ถึงรอบถัดไป',

      // มุมมองตลาดของ AI (เว็บฮาร์ดโค้ดไทยไว้ ไม่มีคีย์)
      'aiTrade.marketViewTitle': 'มุมมองตลาดของ AI',
      'aiTrade.marketViewSub':
          'สิ่งที่บอทใช้ประกอบการตัดสินใจจริง ไม่ใช่แค่คำแนะนำ',
      'aiTrade.marketViewRefresh': 'รีเฟรช',
      'aiTrade.marketViewLoading': 'กำลังโหลด...',
      'aiTrade.marketViewNone': 'ยังไม่มีมุมมองล่าสุด',
      'aiTrade.marketViewStillRules':
          'บอทยังทำงานปกติ — ตัดสินใจจากกฎที่ตรวจย้อนหลังได้ทั้งหมด',
      'aiTrade.marketViewShadow':
          'กำลังอยู่ในช่วงเก็บสถิติ — AI วิเคราะห์และบันทึกไว้ แต่ยังไม่มีผลต่อการเทรด',
      'aiTrade.marketViewOff': 'ยังไม่ได้เปิดใช้การวิเคราะห์ตลาดด้วย AI',
      'aiTrade.marketStance': 'ท่าทีตลาด',
      'aiTrade.marketConfidence': 'ความมั่นใจ',
      'aiTrade.marketSizeMultiplier': 'ตัวคูณขนาดไม้',
      'aiTrade.marketShortlist': 'เหรียญที่ AI คัดไว้รอบนี้',
      'aiTrade.marketCoins': 'มุมมองรายเหรียญ',
      'aiTrade.marketScopeShort': 'รอบสั้น',
      'aiTrade.marketScopeLong': 'รอบใหญ่',
      'aiTrade.marketAssessedAgo': 'ประเมิน {ago}',
      'aiTrade.regimeRiskOn': 'ตลาดเปิดรับความเสี่ยง',
      'aiTrade.regimeNeutral': 'ตลาดเป็นกลาง',
      'aiTrade.regimeRiskOff': 'ตลาดหลบความเสี่ยง',
      'aiTrade.stanceBuy': 'น่าซื้อ',
      'aiTrade.stanceHold': 'ถือ',
      'aiTrade.stanceAvoid': 'เลี่ยง',
      'aiTrade.stanceExit': 'ควรออก',

      // คำบอกเวลา (ใช้กับ marketAssessedAgo และเวลาเดินรอบล่าสุด)
      'aiTrade.justNow': 'เมื่อครู่',
      'aiTrade.minutesAgo': '{m} นาทีที่แล้ว',
      'aiTrade.hoursMinutesAgo': '{h} ชั่วโมง {m} นาทีที่แล้ว',

      // สถิติย้อนหลัง (/analytics — เว็บยังไม่ได้ใช้)
      'aiTrade.analyticsTitle': 'สถิติย้อนหลัง',
      'aiTrade.analyticsSub':
          'ผลจริงของทุกไม้ที่ปิดแล้ว รวมค่าธรรมเนียมและ slippage ไว้หมดแล้ว',
      'aiTrade.analyticsOverall': 'ภาพรวม',
      'aiTrade.analyticsByStrategy': 'แยกตามกลยุทธ์',
      'aiTrade.analyticsByPair': 'แยกตามคู่เทรด',
      'aiTrade.analyticsByRisk': 'แยกตามความเสี่ยงตอนเข้าไม้',
      'aiTrade.analyticsEmpty':
          'ยังไม่มีไม้ที่ปิดแล้ว — เปิดบอทเดินสักพักแล้วกลับมาดูใหม่',
      'aiTrade.statAvgPnl': 'กำไรเฉลี่ยต่อไม้',
      'aiTrade.statBestTrade': 'ไม้ที่ดีที่สุด',
      'aiTrade.statWorstTrade': 'ไม้ที่แย่ที่สุด',
      'aiTrade.statProfitFactor': 'กำไรต่อขาดทุน',
      'aiTrade.statExpectancy': 'กำไรคาดหวังต่อไม้',
      'aiTrade.statMaxDrawdown': 'ขาดทุนสะสมสูงสุด',
      'aiTrade.statTotalCost': 'ต้นทุนรวม (ค่าธรรมเนียม + slippage)',
      'aiTrade.statSlippage': 'slippage รวม',
      'aiTrade.statUndecided': 'ยังตัดสินไม่ได้ — ไม้ที่ปิดแล้วยังน้อยเกินไป',
      'aiTrade.statNoLoss': 'ยังไม่มีไม้ขาดทุน — ตัวเลขนี้ยังตัดสินอะไรไม่ได้',

      // พอร์ตแยกรายกลยุทธ์ + กำไรรวม (ข้อมูลที่เซิร์ฟเวอร์ส่งมาแต่เว็บไม่ได้วาด)
      'aiTrade.demoTotalPnl': 'กำไร/ขาดทุนรวม',
      'aiTrade.demoUnrealized': 'กำไร/ขาดทุนที่ยังไม่ปิด',
      'aiTrade.demoPortfolios': 'ผลแยกรายกลยุทธ์',
      'aiTrade.demoPortfoliosSub': 'คำตอบว่ากลยุทธ์ไหนทำเงินได้จริงในพอร์ตของคุณ',
      'aiTrade.demoPortfolioLegacy': 'พอร์ตรวม (ของเก่า)',
      'aiTrade.demoResetWarnStats':
          'สถิติย้อนหลังทั้งหมดจะหายไปด้วย เพราะใช้ข้อมูลไม้ชุดเดียวกัน',

      // ประวัติเครดิต (/credits — เว็บยังไม่ได้ใช้)
      'aiTrade.creditHistory': 'ประวัติเครดิต',
      'aiTrade.creditHistoryEmpty': 'ยังไม่มีรายการเครดิต',
      'aiTrade.creditBalanceAfter': 'คงเหลือ',
      'aiTrade.creditTypeTopup': 'เติมเครดิต',
      'aiTrade.creditTypeCharge': 'ตัดเครดิต',
      'aiTrade.creditTypeRefund': 'คืนเครดิต',
      'aiTrade.creditTypeBonus': 'โบนัส',
      'aiTrade.creditTypeAdjustment': 'ปรับยอด',
      'aiTrade.welcomeAlready': 'รับเครดิตต้อนรับไปแล้วก่อนหน้านี้',

      // บอทถูกระงับ / เตือนก่อนทำสิ่งที่ย้อนไม่ได้
      'aiTrade.botBanned': 'ถูกทีมงานระงับ',
      'aiTrade.botBannedBody': 'บอทตัวนี้ถูกระงับไว้ จึงกดเริ่มไม่ได้',
      'aiTrade.strategyRiskLabel': 'ความเสี่ยงของกลยุทธ์',
      'aiTrade.marketRiskLabel': 'ตลาดตอนนี้',
      'aiTrade.stopToFreeQuota':
          'กด "หยุด" บอทตัวเก่าเพื่อคืนโควตา — การพักไว้ยังนับว่าใช้โควตาอยู่',
      'aiTrade.confirmLiveTitle': 'สลับไปโหมดจริง?',
      'aiTrade.confirmLiveBody':
          'โหมดจริงเกี่ยวข้องกับเงินจริง บอทจะเสนอสัญญาณให้คุณกดยืนยันในกระเป๋าเอง ระบบไม่ถือกุญแจของคุณ',
      'aiTrade.positionOpenWarn':
          'บอทตัวนี้ยังถือของอยู่ — สลับโหมดแล้วไม้เดิมจะไม่หายไปไหน แต่จะมองไม่เห็นจนกว่าจะสลับกลับ',
      'aiTrade.pairLockedWhileOpen':
          'เปลี่ยนคู่เทรดไม่ได้ขณะยังถือของอยู่ — ปิดไม้เดิมก่อน ไม่งั้นจะค้างบนคู่เก่าโดยไม่มีใครดูแล',
      'aiTrade.editWhileRunning':
          'บอทกำลังทำงานอยู่ — ค่าที่แก้จะมีผลกับรอบถัดไปทันที',
      'aiTrade.deleteWithPositionWarn':
          'บอทตัวนี้ยังถือของอยู่ ลบแล้วไม้ที่ถือและประวัติในพอร์ตทดลองจะหายไปด้วย',
      'aiTrade.planCapNotice': 'แพลนของคุณจำกัดทุนต่อไม้ไว้ที่ \${n}',

      // สถานะการโหลด / เครือข่าย
      'aiTrade.refreshing': 'กำลังอัปเดต...',
      'aiTrade.lastUpdated': 'อัปเดตล่าสุด',
      'aiTrade.pausedInBackground': 'หยุดอัปเดตชั่วคราวเพราะแอพอยู่เบื้องหลัง',
      'aiTrade.kycOpenWeb': 'ยืนยันตัวตนบนเว็บ',
      'aiTrade.viewOnWeb': 'เปิดดูบนเว็บ',

      // ── ข้อความตามรหัสข้อผิดพลาดของเซิร์ฟเวอร์ ──
      // ใช้ t('aiTrade.err.<CODE>') ถ้าไม่เจอคีย์ ให้ fallback เป็น error.message
      'aiTrade.err.NO_WALLET': 'กรุณาเชื่อมกระเป๋าก่อน',
      'aiTrade.err.INVALID_WALLET':
          'ที่อยู่กระเป๋าไม่ถูกต้อง — ลองเชื่อมกระเป๋าใหม่อีกครั้ง',
      'aiTrade.err.WALLET_NOT_VERIFIED':
          'การยืนยันกระเป๋าหมดอายุแล้ว — เซ็นข้อความอีกครั้งเพื่อดูข้อมูลบอท',
      'aiTrade.err.WALLET_IP_MISMATCH':
          'เครือข่ายเปลี่ยนระหว่างใช้งาน — กรุณายืนยันกระเป๋าอีกครั้ง',
      'aiTrade.err.KYC_REQUIRED': 'ต้องยืนยันตัวตนก่อนใช้เช่าบอทเทรด AI',
      'aiTrade.err.PLAN_NOT_FOUND': 'ไม่พบแพลนนี้แล้ว — โหลดรายการแพลนใหม่',
      'aiTrade.err.INSUFFICIENT_CREDITS':
          'เครดิตการทำงานไม่พอ — เติมเครดิตก่อนเริ่มใช้งานบอท',
      'aiTrade.err.SALES_CLOSED':
          'ยังไม่เปิดให้เช่า — ระหว่างนี้ใช้โหมดทดลองได้เต็มที่ ไม่มีค่าใช้จ่าย',
      'aiTrade.err.SUBSCRIBE_IN_PROGRESS':
          'กำลังทำรายการเช่าของกระเป๋านี้อยู่ กรุณารอสักครู่แล้วลองใหม่',
      'aiTrade.err.TOPUP_UNAVAILABLE':
          'ยังไม่เปิดให้เติมเครดิต — รอประกาศเปิดระบบชำระเงินก่อน',
      'aiTrade.err.INVALID_PACK': 'ไม่พบแพ็กเครดิตนี้ — โหลดรายการใหม่',
      'aiTrade.err.NO_SUBSCRIPTION':
          'ยังไม่ได้เช่าบอท AI TRADE — เลือกแพลนก่อนเริ่มใช้งาน',
      'aiTrade.err.STRATEGY_LOCKED': 'กลยุทธ์นี้ต้องใช้แพลนระดับสูงกว่า',
      'aiTrade.err.BOT_LIMIT_REACHED':
          'จำนวนบอทเต็มโควตาของแพลนแล้ว — กดหยุดบอทตัวเก่าเพื่อคืนโควตา',
      'aiTrade.err.PAIR_NO_CANDLES':
          'คู่นี้ยังไม่มีข้อมูลแท่งเทียนให้บอทใช้ตัดสินใจ — เลือกคู่อื่นก่อน',
      'aiTrade.err.BOT_NOT_FOUND': 'ไม่พบบอทตัวนี้ — อาจถูกลบไปแล้ว',
      'aiTrade.err.BOT_BANNED': 'บอทตัวนี้ถูกทีมงานระงับไว้',
      'aiTrade.err.BOT_NOT_RUNNING': 'บอทตัวนี้ไม่ได้ทำงานอยู่',
      'aiTrade.err.CLOUD_BOT':
          'บอทของแพลนนี้เดินบนคลาวด์อยู่แล้ว ไม่ต้องสั่งจากแอพ',
      'aiTrade.err.LIVE_DISABLED': 'ตอนนี้เปิดให้ใช้เฉพาะโหมดทดลองก่อน',
      'aiTrade.err.RESET_LIMIT': 'ล้างพอร์ตทดลองได้วันละ 3 ครั้ง',
      'aiTrade.err.VALIDATION_ERROR': 'ข้อมูลที่กรอกยังไม่ถูกต้อง — ตรวจอีกครั้ง',
      'aiTrade.err.RATE_LIMITED': 'เรียกข้อมูลถี่เกินไป — รอสักครู่แล้วลองใหม่',
      'aiTrade.err.NETWORK': 'ต่ออินเทอร์เน็ตไม่ได้ — ตรวจสัญญาณแล้วลองใหม่',
      'aiTrade.err.TIMEOUT': 'เซิร์ฟเวอร์ตอบช้าเกินไป — ลองใหม่อีกครั้ง',
      'aiTrade.err.BAD_PAYLOAD': 'เซิร์ฟเวอร์ตอบรูปแบบที่อ่านไม่ได้',
      'aiTrade.err.REQUEST_FAILED': 'ทำรายการไม่สำเร็จ — ลองใหม่อีกครั้ง',
      'aiTrade.err.UNKNOWN': 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ',
    },
    'en': {
      // Navigation
      'nav.home': 'Home',
      'nav.markets': 'Markets',
      'nav.trade': 'Trade',
      'nav.portfolio': 'Portfolio',
      'nav.settings': 'Settings',
      'nav.ai': 'AI',
      'nav.market': 'Market',
      'nav.swap': 'Swap',
      'nav.wallet': 'Wallet',

      // Home
      'home.welcome': 'Hello',
      'home.portfolio_value': 'Portfolio Value',
      'home.favorites': 'Favorites',
      'home.top_gainers': 'Top Gainers',
      'home.top_losers': 'Top Losers',
      'home.recent_trades': 'Recent Trades',

      // Markets
      'markets.search': 'Search coins...',
      'markets.all': 'All',
      'markets.spot': 'Spot',
      'markets.favorites': 'Favorites',
      'markets.price': 'Price',
      'markets.change': 'Change',
      'markets.volume': 'Volume',

      // Trade
      'trade.buy': 'Buy',
      'trade.sell': 'Sell',
      'trade.limit': 'Limit',
      'trade.market': 'Market',
      'trade.price': 'Price',
      'trade.amount': 'Amount',
      'trade.total': 'Total',
      'trade.orderbook': 'Order Book',
      'trade.recent_trades': 'Recent Trades',
      'trade.open_orders': 'Open Orders',
      'trade.balance': 'Balance',

      // Portfolio
      'portfolio.title': 'Portfolio',
      'portfolio.total_value': 'Total Value',
      'portfolio.assets': 'Assets',
      'portfolio.history': 'History',
      'portfolio.pnl': 'PnL',

      // Settings
      'settings.title': 'Settings',
      'settings.wallet': 'Wallet',
      'settings.connect_wallet': 'Connect Wallet',
      'settings.disconnect': 'Disconnect',
      'settings.chain': 'Network',
      'settings.language': 'Language',
      'settings.currency': 'Currency',
      'settings.biometric': 'Biometric',
      'settings.notifications': 'Notifications',
      'settings.about': 'About',
      'settings.version': 'Version',
      'settings.check_update': 'Check for Updates',
      'settings.update_available': 'Update Available',
      'settings.profile': 'Profile',
      'settings.edit_profile': 'Edit Profile',

      // Profile
      'profile.title': 'My Profile',
      'profile.name': 'Display name',
      'profile.email': 'Email',
      'profile.avatar': 'Avatar (URL)',
      'profile.referral_code': 'Referral code',
      'profile.kyc_status': 'KYC status',
      'profile.total_trades': 'Total trades',
      'profile.set_name': 'Set name',
      'profile.set_email': 'Add email',
      'profile.guest': 'New user',
      'profile.update_success': 'Profile saved',
      'profile.update_failed': 'Failed to save profile',
      'profile.invalid_email': 'Invalid email',
      'profile.name_too_long': 'Name too long (max 50 chars)',
      'profile.verify_first': 'Please verify wallet first',

      // Wallet
      'wallet.create': 'Create New Wallet',
      'wallet.import': 'Import Wallet',
      'wallet.backup': 'Backup Recovery Phrase',
      'wallet.backup_warning': 'Write down these 12 words in a safe place',
      'wallet.confirm_backup': "I've saved it",
      'wallet.import_hint': 'Enter 12 words separated by spaces...',
      'wallet.import_button': 'Import',
      'wallet.invalid_mnemonic': 'Invalid recovery phrase',
      'wallet.creating': 'Creating...',
      'wallet.importing': 'Importing...',
      'wallet.connected': 'Connected',

      // Common
      'common.cancel': 'Cancel',
      'common.confirm': 'Confirm',
      'common.save': 'Save',
      'common.done': 'Done',
      'common.error': 'An error occurred',
      'common.retry': 'Retry',
      'common.loading': 'Loading...',
      'common.no_data': 'No data',
      'common.copied': 'Copied!',
      'common.coming_soon': 'Coming soon',
      'common.later': 'Later',
      'common.download': 'Download',
      'common.downloading': 'Downloading...',
      'common.search_pairs': 'Search pairs...',
      'common.chart': 'Chart',
      'common.spread': 'Spread',

      // Update
      'update.checking': 'Checking for updates...',
      'update.latest': 'You are on the latest version',
      'update.available': 'Update Available',

      // Wallet status
      'wallet.verified': 'Verified',
      'wallet.pending': 'Pending',
      'wallet.address_copied': 'Address copied!',

      // Portfolio
      'portfolio.no_assets': 'No assets yet',
      'portfolio.assets_count': 'assets',

      // Trade
      'trade.order_success': 'Order placed successfully',
      'trade.order_failed': 'Failed to place order',
      'trade.invalid_amount': 'Please enter a valid amount',
      'trade.invalid_price': 'Please enter a valid price',
      'trade.fee': 'Fee',
      'trade.below_min': 'Amount below minimum',
      'trade.above_max': 'Amount exceeds maximum',
      'trade.platform_not_ready': 'Platform not ready — contact admin',
      'trade.stop_limit': 'Stop-Limit',
      'trade.trigger_price': 'Trigger Price',
      'trade.invalid_trigger': 'Please enter a valid trigger price',

      // Bridge
      'bridge.title': 'Cross-chain Bridge',
      'bridge.from_chain': 'From Chain',
      'bridge.to_chain': 'To Chain',
      'bridge.amount': 'Amount',
      'bridge.fee': 'Bridge Fee',
      'bridge.estimated_time': 'Estimated Time',
      'bridge.minutes': 'min',
      'bridge.submit': 'Start Bridge',
      'bridge.disabled': 'Bridge service is not available',
      'bridge.min_amount': 'Min',
      'bridge.max_amount': 'Max',

      // Peer app (cross-app discovery)
      'peer.open_wallet': 'Open TPIX Wallet',
      'peer.wallet_desc': 'Your wallet is installed on this device',
      'peer.install_wallet': 'Install TPIX Wallet',
      'peer.install_wallet_desc': 'Manage your wallets and tokens',
      'peer.connect_title': 'Connect wallet from Wallet app?',
      'peer.connect_desc': 'TPIX Wallet wants to share this address',
      'peer.connect_accept': 'Connect',

      // ─────────────────────────────────────────────────────────────
      // AI TRADE — cloud trading bot
      // ต้องมีคีย์ครบเท่าฝั่ง 'th' ทุกตัว ไม่งั้น t() จะคืนชื่อคีย์ออกจอ
      // ─────────────────────────────────────────────────────────────

      // Hero / intro
      'aiTrade.badge': 'CLOUD BOT',
      'aiTrade.title': 'AI TRADE',
      'aiTrade.backToBoard': 'Back to the board',
      'aiTrade.intro':
          'Rent a trading bot that runs on TPIX cloud around the clock. Pick a strategy, set your own risk limits, and let it work — the same setup shows on web and in the app, because it is tied to one wallet.',
      'aiTrade.headline': 'Let a bot trade for you 24/7',
      'aiTrade.subheadline':
          'Bots run on TPIX cloud, so nothing has to stay open on your machine. One wallet, shared between web and app.',

      // Wallet connect / verify
      'aiTrade.connectToStart': 'Connect wallet to start',
      'aiTrade.connectPrompt': 'Connect your wallet to use AI TRADE',
      'aiTrade.connectHint':
          'Bots and credits are tied directly to your wallet address',
      'aiTrade.connectWallet': 'Connect wallet',
      'aiTrade.needVerify': 'Wallet needs verifying',
      'aiTrade.needVerifyBody':
          'AI TRADE is tied to your wallet. Please reconnect and sign the verification message.',
      'aiTrade.reconnect': 'Reconnect wallet',
      'aiTrade.verifyNow': 'Sign to verify wallet',
      'aiTrade.verifying': 'Waiting for signature...',
      'aiTrade.verifyOk': 'Wallet verified',
      'aiTrade.verifyFailed':
          'Not verified yet — you need to approve the signature request in your wallet',
      'aiTrade.verifyNeedUnlock':
          'Unlock your TPIX wallet with its password first, then try again',
      'aiTrade.verifyExpiredHint':
          'Verification lasts 4 hours. Reopening the page after it expires needs another signature (no gas).',

      // Risk gate — cannot assess
      'aiTrade.riskUnavailable': 'Cannot assess',
      'aiTrade.riskUnavailableBody':
          'There is not enough price history for {pair} to assess price risk, so these numbers are not meaningful yet (the news gate still runs). Pick another pair if you want the full assessment.',

      // Activation card
      'aiTrade.cloudBot': 'Cloud trading bot',
      'aiTrade.pitch':
          'Rent by the day, pay with work credits · {count} strategies ready',
      'aiTrade.chip247': 'Cloud',
      'aiTrade.chipRisk': 'Risk caps',
      'aiTrade.chipSync': 'In sync',
      'aiTrade.chipWebApp': 'Web + app',
      'aiTrade.credits': 'Credits left',
      'aiTrade.creditsFull': 'Work credits',
      'aiTrade.required': 'Required',
      'aiTrade.activate': 'Activate AI TRADE',
      'aiTrade.seeAllPlans': 'See all plans and strategies',
      'aiTrade.configure': 'Configure strategies in detail',
      'aiTrade.configureShort': 'Detailed setup',
      'aiTrade.running': 'Active',
      'aiTrade.tradingNow': 'Trading now',
      'aiTrade.botsUnit': '{count} bots',
      'aiTrade.daysLeft': '{days} days left',
      'aiTrade.botsQuota': 'Bots {used}',
      'aiTrade.noBots': 'No bots yet — create your first one in settings',
      'aiTrade.noBotsHint': 'Pick a strategy that fits your style',

      // Bot controls
      'aiTrade.start': 'Start',
      'aiTrade.pause': 'Pause',
      'aiTrade.edit': 'Edit',
      'aiTrade.remove': 'Delete',
      'aiTrade.close': 'Close',
      'aiTrade.cancel': 'Cancel',

      // Rental gate
      'aiTrade.gateTitle': 'Activate AI TRADE',
      'aiTrade.gateSub':
          'Rent by the day. Cancel anytime, unused days are refunded.',
      'aiTrade.gateWarn':
          'You have not rented a bot yet — you need enough work credits before a bot can run on the cloud. Credits are charged per rented day.',
      'aiTrade.rentDays': 'Days to rent',
      'aiTrade.days': '{n} days',
      'aiTrade.choosePlan': 'Choose a plan',
      'aiTrade.rentFor': 'Rent {days} days',
      'aiTrade.renewFor': 'Renew +{days} days',
      'aiTrade.topupFirst': 'Top up first',
      'aiTrade.notEnough': 'Not enough credits — {n} short',
      'aiTrade.notEnoughLong':
          'Not enough work credits — {n} short. Top up before starting a bot.',
      'aiTrade.packHint':
          'Pick a pack to create a top-up request — credits arrive once payment is confirmed',

      // Server feature flags
      'aiTrade.salesClosed':
          'AI TRADE is still under test — renting is not open yet. Meanwhile the demo mode with simulated credits at real market prices is free to use.',
      'aiTrade.teamMode':
          'Team mode — every function is open, no rental or credits needed',
      'aiTrade.topupClosed':
          'Credit top-up is not open yet — we will announce it when payments go live. Until then the welcome credits are yours to test with.',
      'aiTrade.botStale':
          '⚠️ This bot has not run for {n} minutes — the execution service may be down. Please tell the team.',

      // Top up
      'aiTrade.topupTitle': 'Top up work credits',
      'aiTrade.topupSub':
          'Pick a pack to create a request — credits land after the team confirms payment',
      'aiTrade.topupOk':
          'Top-up request sent — credits arrive once payment is confirmed',
      'aiTrade.bonus': '{n} bonus',

      // Rental results
      'aiTrade.activated': 'AI TRADE is active — go set up your strategies',
      'aiTrade.planActivated': 'Plan activated — you can create bots now',
      'aiTrade.welcomeFree': 'Claim free welcome credits',
      'aiTrade.welcomeOk': 'Welcome credits claimed',
      'aiTrade.currentPlan': 'Current plan',
      'aiTrade.notRented': 'Not rented',
      'aiTrade.botsUsed': 'Bots used',
      'aiTrade.cancelPlan': 'Cancel',
      'aiTrade.switchConfirm':
          'Switching ends your "{from}" plan right now ({days} days left) and refunds the unused part. Continue?',
      'aiTrade.cancelConfirm':
          'Cancel the rental and stop every bot? Unused days are refunded.',
      'aiTrade.cancelOk': 'Cancelled — unused days refunded',

      // Plan picker
      'aiTrade.choosePlanTitle': 'Choose a rental plan',
      'aiTrade.dayCount': 'Days',
      'aiTrade.creditsPerDay': 'credits/day',
      'aiTrade.totalCost': '{days} days = {credits} credits',
      'aiTrade.maxBots': 'Up to {n} bots',
      'aiTrade.capPerTrade': 'Capital per trade up to \${n}',
      'aiTrade.capUnlimited': 'No capital cap',

      // Bot list
      'aiTrade.myBots': 'My bots',
      'aiTrade.newBot': 'New bot',
      'aiTrade.quotaFull': 'Your plan bot quota is full',
      'aiTrade.noBotsLong': 'No bots yet — hit New bot to pick your first strategy',

      // Bot builder
      'aiTrade.editBot': 'Edit bot',
      'aiTrade.createBot': 'New bot',
      'aiTrade.saveEdit': 'Save changes',
      'aiTrade.createdOk': 'Bot created — hit Start to run it',
      'aiTrade.savedOk': 'Changes saved',
      'aiTrade.botName': 'Bot name',
      'aiTrade.botNamePlaceholder': 'e.g. BTC grid overnight',
      'aiTrade.pair': 'Pair',
      'aiTrade.strategy': 'Strategy',
      'aiTrade.timeframe': 'Timeframe',
      'aiTrade.nameRequired': 'Name the bot before saving',
      'aiTrade.needPlan': 'Rent a plan before creating a bot — pick one above',
      'aiTrade.deleteConfirm': 'Delete bot {name}?',
      'aiTrade.deletedOk': 'Bot deleted',
      'aiTrade.defaultBotName': 'Bot {n}',

      // Risk limits
      'aiTrade.riskFrame': 'Risk limits',
      'aiTrade.riskNote': 'the server always clamps these to your plan ceiling',
      'aiTrade.maxPosition': 'Max capital per trade (USD)',
      'aiTrade.stopLoss': 'Stop Loss (%)',
      'aiTrade.takeProfit': 'Take Profit (%)',
      'aiTrade.maxDailyLoss': 'Max daily loss (USD)',
      'aiTrade.riskLow': 'Low risk',
      'aiTrade.riskMedium': 'Medium risk',
      'aiTrade.riskHigh': 'High risk',

      // Strategy tiers
      'aiTrade.tierFree': '',
      'aiTrade.tierBasic': 'All plans',
      'aiTrade.tierPro': 'Pro and up',
      'aiTrade.tierVip': 'VIP only',
      'aiTrade.needTier': 'Needs the {tier} plan',
      'aiTrade.notReady': 'Not live',

      // AI advisor
      'aiTrade.advisorTitle': 'AI advisor',
      'aiTrade.advisorSub':
          "Reads your bots' track record and suggests what to adjust",
      'aiTrade.advisorAsk': 'Get advice',
      'aiTrade.advisorAsking': 'Analysing...',
      'aiTrade.advisorRefresh': 'Ask again',
      'aiTrade.advisorDisclaimer':
          'An opinion to weigh, not a trade instruction — your bots still follow the rules you set.',
      'aiTrade.advisorNeedWallet': 'Connect a wallet to get advice',
      'aiTrade.advisorNeedTrades':
          'No trades to analyse yet — let a bot run for a while first',

      // All strategies
      'aiTrade.allStrategies': 'All strategies',
      'aiTrade.allStrategiesSub':
          'Every strategy runs on the same cloud, takes your own parameters, and stops on demand',
      'aiTrade.openBoard': 'Open the trading board',
      'aiTrade.on': 'On',
      'aiTrade.off': 'Off',

      // Demo / live mode
      'aiTrade.modeDemo': 'Demo mode',
      'aiTrade.modeLive': 'Live mode',
      'aiTrade.modeDemoShort': 'Demo',
      'aiTrade.modeLiveShort': 'Live',
      'aiTrade.switchToDemo': 'Switch to demo',
      'aiTrade.switchToLive': 'Switch to live',
      'aiTrade.modeSwitched': 'Mode switched',
      'aiTrade.liveNeedsPlan': 'Rent a bot before enabling live mode',
      'aiTrade.liveHint':
          'Live mode sends you a signal to confirm in your own wallet. We never hold your keys.',
      'aiTrade.demoHint':
          'Practise with demo credits at real market prices. No real money involved.',

      // Demo portfolio
      'aiTrade.demoTitle': 'Demo portfolio',
      'aiTrade.demoSubtitle': 'Test a strategy at real prices before you rent',
      'aiTrade.demoBalance': 'Demo credits left',
      'aiTrade.demoEquity': 'Total portfolio value',
      'aiTrade.demoStarting': 'Starting capital',
      'aiTrade.demoPnl': 'Closed profit / loss',
      'aiTrade.demoOpenPositions': 'Open positions',
      'aiTrade.demoNoPositions': 'No open positions yet',
      'aiTrade.demoReset': 'Reset portfolio',
      'aiTrade.demoResetConfirm':
          'Reset the demo portfolio to its starting capital? All demo trade history will be lost.',
      'aiTrade.demoResetOk': 'Demo portfolio reset',
      'aiTrade.demoResetsLeft': '{count} resets left today',
      'aiTrade.demoResetNone': 'No resets left today — the quota refills tomorrow',
      'aiTrade.demoStartHint':
          'Create a bot and start it. It will trade with demo credits straight away.',
      'aiTrade.demoSummary': 'Demo results',
      'aiTrade.demoTrades': 'Trades',
      'aiTrade.demoClosed': 'Closed trades',
      'aiTrade.demoWins': 'Winning trades',
      'aiTrade.demoLosses': 'Losing trades',
      'aiTrade.demoWinRate': 'Win rate',
      'aiTrade.demoFees': 'Total fees',
      'aiTrade.demoNoData': 'Not enough data to summarise yet',
      'aiTrade.demoAssumptions':
          'Simulated with {fee}% fees and {slippage} bps slippage on every trade — deliberately slightly worse than reality, so the numbers do not flatter themselves.',

      // Trade log
      'aiTrade.tradeLog': 'Trade history',
      'aiTrade.tradeLogEmpty': 'No trades yet',
      'aiTrade.tradeBuy': 'Buy',
      'aiTrade.tradeSell': 'Sell',
      'aiTrade.tradeReason': 'Reason',
      'aiTrade.tradePrice': 'Price',
      'aiTrade.tradeAmount': 'Amount',
      'aiTrade.tradeFee': 'Fee',
      'aiTrade.tradePnl': 'Profit / loss',
      'aiTrade.tradeTime': 'Time',
      'aiTrade.entryPrice': 'Average entry price',
      'aiTrade.entryCount': 'Entries',
      'aiTrade.unrealized': 'Open',
      'aiTrade.unpriced': 'No price yet',
      'aiTrade.chartMarkersBot': 'Bot trades',
      'aiTrade.chartMarkersMine': 'My trades',
      'aiTrade.costBasis': 'Capital deployed',

      // Risk gate
      'aiTrade.riskGate': 'Risk gate',
      'aiTrade.riskNow': 'Current risk',
      'aiTrade.riskCalm': 'Calm',
      'aiTrade.riskCaution': 'Caution',
      'aiTrade.riskElevated': 'Elevated',
      'aiTrade.riskPanic': 'Panic',
      'aiTrade.riskSizeMultiplier': 'Allowed position size',
      'aiTrade.riskForceExit': 'Forced exit',
      'aiTrade.riskWhy': 'Why',
      'aiTrade.riskNoReason': 'Market is calm, no danger signals',
      'aiTrade.newsHeadlines': 'News the bot is watching',
      'aiTrade.newsNone': 'No risky news in the last 3 hours',
      'aiTrade.newsScanned': 'News refreshed every 15 minutes',

      // Latest decision
      'aiTrade.lastDecision': 'Latest decision',
      'aiTrade.awaitingConfirm': 'Awaiting your confirmation',
      'aiTrade.noDecisionYet': 'The bot has not made its first decision yet',

      // Where it runs / pricing
      'aiTrade.runsInCloud': 'Runs in the cloud',
      'aiTrade.runsInBrowser': 'Runs in your browser',
      'aiTrade.tpixPerDay': 'TPIX/day',
      'aiTrade.totalCostTpix': '{days} days = {tpix} TPIX',
      'aiTrade.freeForever': 'Free',
      'aiTrade.noPaymentNeeded': 'Nothing to pay',
      'aiTrade.startFree': 'Start free',
      'aiTrade.payWithTpix': 'Paid in TPIX only',

      // Free vs cloud comparison
      'aiTrade.compareFreeTitle': 'Free — the bot runs inside your browser',
      'aiTrade.compareFreeBody':
          'The bot lives in this tab, so this page has to stay open. Close the tab, shut the machine or lose your connection and the bot stops immediately — it will not resume until you open the page again. One bot, with the Grid and DCA strategies.',
      'aiTrade.compareCloudTitle': 'Paid — our servers run it around the clock',
      'aiTrade.compareCloudBody':
          'The bot lives on our servers. Close your browser, shut your computer, go to sleep — it keeps watching the market and trading. Includes the 15-minute news risk gate, every strategy, and several bots at once.',
      'aiTrade.liveOpenNotice':
          'Live mode is open — the bot proposes signals for you to confirm yourself. We never hold your wallet keys, so we cannot place orders for you.',
      'aiTrade.demoOnlyNotice':
          'Only demo mode is open right now — trading uses simulated credits at real market prices, so no plan places real orders yet. Live mode opens once the system has been fully proven.',

      // Client-side bot loop (web wording)
      'aiTrade.browserBotRunning': 'Your bot is running in this tab',
      'aiTrade.browserBotWarning':
          'Keep this tab open — closing it stops the bot immediately',
      'aiTrade.browserBotStopped': 'The bot stopped because you left this page',
      'aiTrade.upgradeForCloud':
          'Upgrade to keep it running with your browser closed',
      'aiTrade.lastTickAt': 'Last cycle',

      // ── App-only additions (no web equivalent) ──
      // In-app bot loop — use these instead of browserBot* on mobile
      'aiTrade.runsInApp': 'Runs inside the app',
      'aiTrade.appBotRunning': 'Your bot is running inside the app',
      'aiTrade.appBotWarning':
          'Keep this screen open — closing the app or switching away stops the bot immediately',
      'aiTrade.appBotStopped': 'The bot stopped because you left this screen',
      'aiTrade.upgradeForCloudApp':
          'Upgrade to keep it running with the app closed',
      'aiTrade.compareFreeBodyApp':
          'The bot lives inside the app, so this screen has to stay open. Close the app, switch away for a while or lose your connection and the bot stops immediately — it will not resume until you come back. One bot, with the Grid and DCA strategies.',
      'aiTrade.tickNextIn': 'Next cycle in {n}s',
      'aiTrade.tickSkipped': 'Not time for the next cycle yet',

      // AI market view (hardcoded Thai on web, keyed here)
      'aiTrade.marketViewTitle': 'AI market view',
      'aiTrade.marketViewSub':
          'What the bot actually weighs when deciding — not just advice',
      'aiTrade.marketViewRefresh': 'Refresh',
      'aiTrade.marketViewLoading': 'Loading...',
      'aiTrade.marketViewNone': 'No recent market view',
      'aiTrade.marketViewStillRules':
          'Bots keep working as usual — they decide from rules that can be checked afterwards',
      'aiTrade.marketViewShadow':
          'Collecting statistics — the AI analyses and records its view, but it does not affect trading yet',
      'aiTrade.marketViewOff': 'AI market analysis is not enabled yet',
      'aiTrade.marketStance': 'Market stance',
      'aiTrade.marketConfidence': 'Confidence',
      'aiTrade.marketSizeMultiplier': 'Position size multiplier',
      'aiTrade.marketShortlist': 'Coins the AI shortlisted this round',
      'aiTrade.marketCoins': 'Per-coin view',
      'aiTrade.marketScopeShort': 'short cycle',
      'aiTrade.marketScopeLong': 'long cycle',
      'aiTrade.marketAssessedAgo': 'Assessed {ago}',
      'aiTrade.regimeRiskOn': 'Market is risk-on',
      'aiTrade.regimeNeutral': 'Market is neutral',
      'aiTrade.regimeRiskOff': 'Market is risk-off',
      'aiTrade.stanceBuy': 'Worth buying',
      'aiTrade.stanceHold': 'Hold',
      'aiTrade.stanceAvoid': 'Avoid',
      'aiTrade.stanceExit': 'Should exit',

      // Relative time words
      'aiTrade.justNow': 'just now',
      'aiTrade.minutesAgo': '{m} minutes ago',
      'aiTrade.hoursMinutesAgo': '{h}h {m}m ago',

      // Track record (/analytics — unused on web)
      'aiTrade.analyticsTitle': 'Track record',
      'aiTrade.analyticsSub':
          'Real results from every closed trade, fees and slippage already included',
      'aiTrade.analyticsOverall': 'Overall',
      'aiTrade.analyticsByStrategy': 'By strategy',
      'aiTrade.analyticsByPair': 'By pair',
      'aiTrade.analyticsByRisk': 'By risk level at entry',
      'aiTrade.analyticsEmpty':
          'No closed trades yet — let a bot run for a while and come back',
      'aiTrade.statAvgPnl': 'Average per trade',
      'aiTrade.statBestTrade': 'Best trade',
      'aiTrade.statWorstTrade': 'Worst trade',
      'aiTrade.statProfitFactor': 'Profit factor',
      'aiTrade.statExpectancy': 'Expectancy per trade',
      'aiTrade.statMaxDrawdown': 'Max drawdown',
      'aiTrade.statTotalCost': 'Total cost (fees + slippage)',
      'aiTrade.statSlippage': 'Total slippage',
      'aiTrade.statUndecided': 'Not conclusive yet — too few closed trades',
      'aiTrade.statNoLoss':
          'No losing trade yet — this number cannot judge anything so far',

      // Per-strategy portfolios + combined P&L (sent by server, unused on web)
      'aiTrade.demoTotalPnl': 'Total profit / loss',
      'aiTrade.demoUnrealized': 'Open profit / loss',
      'aiTrade.demoPortfolios': 'Results by strategy',
      'aiTrade.demoPortfoliosSub':
          'Which strategy actually makes money in your portfolio',
      'aiTrade.demoPortfolioLegacy': 'Combined portfolio (legacy)',
      'aiTrade.demoResetWarnStats':
          'Your whole track record goes with it — both read the same trades.',

      // Credit ledger (/credits — unused on web)
      'aiTrade.creditHistory': 'Credit history',
      'aiTrade.creditHistoryEmpty': 'No credit entries yet',
      'aiTrade.creditBalanceAfter': 'Balance',
      'aiTrade.creditTypeTopup': 'Top-up',
      'aiTrade.creditTypeCharge': 'Charge',
      'aiTrade.creditTypeRefund': 'Refund',
      'aiTrade.creditTypeBonus': 'Bonus',
      'aiTrade.creditTypeAdjustment': 'Adjustment',
      'aiTrade.welcomeAlready': 'You already claimed the welcome credits',

      // Banned bot / destructive warnings
      'aiTrade.botBanned': 'Suspended by the team',
      'aiTrade.botBannedBody': 'This bot is suspended, so it cannot be started',
      'aiTrade.strategyRiskLabel': 'Strategy risk',
      'aiTrade.marketRiskLabel': 'Market right now',
      'aiTrade.stopToFreeQuota':
          'Stop an old bot to free the quota — pausing still counts against it',
      'aiTrade.confirmLiveTitle': 'Switch to live mode?',
      'aiTrade.confirmLiveBody':
          'Live mode involves real money. The bot proposes a signal for you to confirm in your own wallet — we never hold your keys.',
      'aiTrade.positionOpenWarn':
          'This bot still holds a position — switching modes does not close it, it just hides until you switch back',
      'aiTrade.pairLockedWhileOpen':
          'You cannot change the pair while a position is open — close it first, or it is left on the old pair with nobody watching it',
      'aiTrade.editWhileRunning':
          'This bot is running — your changes take effect from the next cycle',
      'aiTrade.deleteWithPositionWarn':
          'This bot still holds a position. Deleting it drops that position and its demo history too.',
      'aiTrade.planCapNotice': 'Your plan caps capital per trade at \${n}',

      // Loading / network status
      'aiTrade.refreshing': 'Updating...',
      'aiTrade.lastUpdated': 'Last updated',
      'aiTrade.pausedInBackground': 'Updates paused while the app is in the background',
      'aiTrade.kycOpenWeb': 'Verify identity on the web',
      'aiTrade.viewOnWeb': 'Open on the web',

      // ── Server error codes ──
      // Use t('aiTrade.err.<CODE>'); fall back to error.message when unknown
      'aiTrade.err.NO_WALLET': 'Connect a wallet first',
      'aiTrade.err.INVALID_WALLET':
          'That wallet address is not valid — try reconnecting your wallet',
      'aiTrade.err.WALLET_NOT_VERIFIED':
          'Wallet verification expired — sign again to load your bots',
      'aiTrade.err.WALLET_IP_MISMATCH':
          'Your network changed — please verify your wallet again',
      'aiTrade.err.KYC_REQUIRED': 'Identity verification is required to rent AI bots',
      'aiTrade.err.PLAN_NOT_FOUND': 'That plan is gone — reload the plan list',
      'aiTrade.err.INSUFFICIENT_CREDITS':
          'Not enough work credits — top up before starting a bot',
      'aiTrade.err.SALES_CLOSED':
          'Renting is not open yet — the demo mode is free to use in the meantime',
      'aiTrade.err.SUBSCRIBE_IN_PROGRESS':
          'A rental request for this wallet is still running — wait a moment and try again',
      'aiTrade.err.TOPUP_UNAVAILABLE':
          'Credit top-up is not open yet — we will announce it when payments go live',
      'aiTrade.err.INVALID_PACK': 'That credit pack no longer exists — reload the list',
      'aiTrade.err.NO_SUBSCRIPTION':
          'You have not rented AI TRADE yet — pick a plan to get started',
      'aiTrade.err.STRATEGY_LOCKED': 'This strategy needs a higher plan',
      'aiTrade.err.BOT_LIMIT_REACHED':
          'Your plan bot quota is full — stop an old bot to free it up',
      'aiTrade.err.PAIR_NO_CANDLES':
          'This pair has no candle data for the bot to work with — pick another one',
      'aiTrade.err.BOT_NOT_FOUND': 'Bot not found — it may have been deleted',
      'aiTrade.err.BOT_BANNED': 'This bot was suspended by the team',
      'aiTrade.err.BOT_NOT_RUNNING': 'This bot is not running',
      'aiTrade.err.CLOUD_BOT':
          'Bots on this plan already run in the cloud — no need to drive them from the app',
      'aiTrade.err.LIVE_DISABLED': 'Only demo mode is open right now',
      'aiTrade.err.RESET_LIMIT': 'The demo portfolio can be reset 3 times a day',
      'aiTrade.err.VALIDATION_ERROR': 'Some fields are not valid — please check them',
      'aiTrade.err.RATE_LIMITED': 'Too many requests — wait a moment and try again',
      'aiTrade.err.NETWORK': 'No internet connection — check your signal and retry',
      'aiTrade.err.TIMEOUT': 'The server took too long — please try again',
      'aiTrade.err.BAD_PAYLOAD': 'The server returned something we cannot read',
      'aiTrade.err.REQUEST_FAILED': 'That did not go through — please try again',
      'aiTrade.err.UNKNOWN': 'Something went wrong',
    },
  };
}
