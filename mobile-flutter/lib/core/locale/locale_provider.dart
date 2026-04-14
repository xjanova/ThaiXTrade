/// TPIX TRADE — Locale & Language Provider
/// รองรับ ไทย/อังกฤษ (ดึง pattern จาก TPIX Wallet)
///
/// Developed by Xman Studio

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

  static const Map<String, Map<String, String>> _translations = {
    'th': {
      // Navigation
      'nav.home': 'หน้าหลัก',
      'nav.markets': 'ตลาด',
      'nav.trade': 'เทรด',
      'nav.portfolio': 'พอร์ต',
      'nav.settings': 'ตั้งค่า',

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

      // Peer app (cross-app discovery)
      'peer.open_wallet': 'เปิด TPIX Wallet',
      'peer.wallet_desc': 'กระเป๋าของคุณติดตั้งอยู่ในเครื่อง',
      'peer.install_wallet': 'ติดตั้ง TPIX Wallet',
      'peer.install_wallet_desc': 'จัดการกระเป๋าและเหรียญแบบครบครัน',
      'peer.connect_title': 'เชื่อมกระเป๋าจาก Wallet?',
      'peer.connect_desc': 'TPIX Wallet ส่งที่อยู่มาให้คุณ',
      'peer.connect_accept': 'เชื่อมต่อ',
    },
    'en': {
      // Navigation
      'nav.home': 'Home',
      'nav.markets': 'Markets',
      'nav.trade': 'Trade',
      'nav.portfolio': 'Portfolio',
      'nav.settings': 'Settings',

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

      // Peer app (cross-app discovery)
      'peer.open_wallet': 'Open TPIX Wallet',
      'peer.wallet_desc': 'Your wallet is installed on this device',
      'peer.install_wallet': 'Install TPIX Wallet',
      'peer.install_wallet_desc': 'Manage your wallets and tokens',
      'peer.connect_title': 'Connect wallet from Wallet app?',
      'peer.connect_desc': 'TPIX Wallet wants to share this address',
      'peer.connect_accept': 'Connect',
    },
  };
}
