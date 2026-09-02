/// TPIX TRADE — AI TRADE Provider (บอทคลาวด์)
///
/// สมองของหน้า AI TRADE: เป็นตัวเดียวของทั้งแอพที่คุยกับ `/api/v1/ai-bot/*`
/// ลงทะเบียนที่ `main.dart` ผ่าน ChangeNotifierProxyProvider — ห้ามสร้างใหม่
/// ต่อหน้าจอ ไม่งั้นลูปเดินบอทจะซ้อนกันสองตัวแล้วกินโควตาจนโดน 429
///
/// หลักที่ยึดทั้งไฟล์
///   • ไม่ล้มเงียบ — ทุกความล้มเหลวเก็บ `errorCode` ไว้ให้หน้าจออธิบาย + เสนอทางออก
///   • ไม่ยิงซ้อน — งานเดียวกันกดซ้ำไม่ยิงซ้ำ (คิวคำขอจริงอยู่ใน AiBotApi)
///   • ไม่กินแบต — ลูปทั้งหมดหยุดทันทีเมื่อออกจากหน้าหรือแอพลงพื้นหลัง
///   • ไม่หลอกตา — optimistic update ย้อนกลับได้เมื่อเซิร์ฟเวอร์ปฏิเสธ
///
/// Developed by Xman Studio

library;

import 'dart:async';

import 'package:flutter/foundation.dart';

import '../models/ai_bot_models.dart';
import '../services/ai_bot_api.dart';
import '../services/api_result.dart';
import 'wallet_provider.dart';

/// ผลของการกดรับเครดิตต้อนรับ
///
/// เซิร์ฟเวอร์ตอบ success ทั้งตอนให้จริงและตอนเคยรับไปแล้ว (idempotent)
/// ถ้าไม่แยกสองกรณีนี้ จอจะขึ้น "รับ 100 เครดิตแล้ว!" ทุกครั้งที่กด
enum WelcomeOutcome { granted, alreadyClaimed, failed }

class AiBotProvider extends ChangeNotifier {
  AiBotProvider({AiBotApi? api}) : _api = api ?? AiBotApi();

  final AiBotApi _api;

  // ── จังหวะการทำงาน ──

  /// รอบดึงสถานะขณะเปิดหน้าอยู่
  ///
  /// ถี่พอให้เห็นบอทคลาวด์ขยับ แต่ยังห่างพอไม่ไปเบียดโควตาของหน้าอื่น
  /// ที่ใช้ IP เดียวกัน (งบจริงราว 15 คำขอ/นาที/IP และแชร์กับผู้ใช้คนอื่น
  /// ที่อยู่หลัง NAT ของค่ายมือถือด้วย)
  static const Duration statusPollInterval = Duration(seconds: 45);

  /// รอบสั่งบอทเดินเองของแพลนฟรี — เซิร์ฟเวอร์กันไว้ที่ 30 วินาทีต่อบอทอยู่แล้ว
  /// ยิงถี่กว่านี้ได้แค่ `skipped: true` แต่เสียโควตาฟรีๆ
  static const Duration appTickInterval = Duration(seconds: 30);

  /// ข้อมูลเก่ากว่านี้ถือว่าควรโหลดใหม่ตอนกลับเข้าหน้า
  static const Duration staleAfter = Duration(seconds: 20);

  /// เว้นระยะขั้นต่ำระหว่างการขอลายเซ็นอัตโนมัติ
  static const Duration _resignCooldown = Duration(seconds: 60);

  // ══════════════════════════════════════════════════════════
  // สถานะ
  // ══════════════════════════════════════════════════════════

  WalletProvider? _wallet;
  String? _boundAddress;

  AiBotCatalog? _catalog;
  AiBotStatus? _status;
  AiBotDemo? _demo;

  /// ไม้ของบอทในคู่ที่หน้าเทรดกำลังดู (ทุกโหมด) — เก็บคู่ไว้ด้วยกันเพื่อไม่เอาไม้ของ
  /// คู่เก่าไปปักบนกราฟคู่ใหม่ระหว่างรอโหลด
  String? _tradesPair;
  List<DemoTrade> _trades = const [];

  /// กระเป๋าบอท — กระเป๋าแยกสำหรับโหมดจริง (null = ยังไม่เคยโหลด)
  BotWalletState? _botWallet;
  bool _loadingBotWallet = false;
  AiMarketView? _marketView;
  AiRiskView? _risk;
  AiBotAnalytics? _analytics;
  CreditLedger? _credits;
  AiAdvice? _advice;

  final List<AiBotDecision> _decisions = [];
  int? _decisionsCursor;
  bool _decisionsHasMore = false;
  int? _decisionBotFilter;
  bool _decisionActedOnly = false;
  bool _watchDecisions = false;

  bool _loadingCatalog = false;
  bool _loadingStatus = false;
  bool _loadingDemo = false;
  bool _loadingMarketView = false;
  bool _loadingRisk = false;
  bool _loadingAnalytics = false;
  bool _loadingCredits = false;
  bool _loadingDecisions = false;
  bool _loadingMoreDecisions = false;
  bool _askingAdvice = false;
  bool _refreshing = false;
  bool _verifying = false;
  bool _bootstrapping = false;

  /// คีย์ของงานที่กำลังทำอยู่ — ใช้ปิดปุ่มเฉพาะตัวที่กด ไม่ต้องแช่แข็งทั้งหน้า
  final Set<String> _busy = {};

  String? _errorCode;
  String? _errorMessage;
  Map<String, List<String>> _fieldErrors = const {};

  bool _needsVerification = false;
  DateTime? _lastResignAt;

  DateTime? _statusLoadedAt;
  DateTime? _throttledUntil;

  bool _active = false;
  Timer? _statusTimer;
  Timer? _tickTimer;

  bool _appLoopActive = false;
  bool _tickCycleRunning = false;
  String? _appLoopBlockedCode;
  DateTime? _lastAppTick;
  int? _nextTickInSeconds;
  final List<AiBotTickLog> _tickLog = [];

  // ══════════════════════════════════════════════════════════
  // ข้อมูล
  // ══════════════════════════════════════════════════════════

  AiBotCatalog? get catalog => _catalog;
  AiBotStatus? get status => _status;
  AiBotDemo? get demo => _demo;

  /// ไม้ของบอทสำหรับปักบนกราฟของคู่นี้ — ใช้ชุดที่โหลดจาก /trades ถ้าเป็นคู่เดียวกัน
  /// ระหว่างรอ ใช้ไม้จากพอร์ตทดลองไปพลางก่อน (เหมือนหน้าเว็บ)
  List<DemoTrade> tradesFor(String pair) {
    final wanted = pair.replaceAll('-', '/').toUpperCase();
    if (_tradesPair == wanted) return _trades;
    return (_demo?.trades ?? const [])
        .where((t) => t.pair.replaceAll('-', '/').toUpperCase() == wanted)
        .toList(growable: false);
  }

  BotWalletState? get botWallet => _botWallet;
  bool get isLoadingBotWallet => _loadingBotWallet;
  static const String botWalletKey = 'bot-wallet';
  AiMarketView? get marketView => _marketView;
  AiRiskView? get risk => _risk;
  AiBotAnalytics? get analytics => _analytics;
  CreditLedger? get credits => _credits;
  AiAdvice? get advice => _advice;

  List<AiBot> get bots => _status?.bots ?? const [];
  List<AiBotDecision> get decisions => List.unmodifiable(_decisions);
  bool get decisionsHasMore => _decisionsHasMore;
  int? get decisionBotFilter => _decisionBotFilter;
  bool get decisionActedOnly => _decisionActedOnly;

  double get creditBalance => _status?.credits ?? 0;
  AiBotSubscription? get subscription => _status?.subscription;
  AiBotQuota get quota => _status?.quota ?? AiBotQuota.empty;

  // ══════════════════════════════════════════════════════════
  // สถานะการโหลด
  // ══════════════════════════════════════════════════════════

  bool get isLoadingCatalog => _loadingCatalog;
  bool get isLoadingStatus => _loadingStatus;
  bool get isLoadingDemo => _loadingDemo;
  bool get isLoadingMarketView => _loadingMarketView;
  bool get isLoadingRisk => _loadingRisk;
  bool get isLoadingAnalytics => _loadingAnalytics;
  bool get isLoadingCredits => _loadingCredits;
  bool get isLoadingDecisions => _loadingDecisions;
  bool get isLoadingMoreDecisions => _loadingMoreDecisions;
  bool get isAskingAdvice => _askingAdvice;
  bool get isVerifying => _verifying;

  /// กำลังรีเฟรชอยู่เบื้องหลัง — **ห้าม** เอาไปโชว์สปินเนอร์เต็มจอ
  bool get isRefreshing => _refreshing;

  /// มีงานเขียนข้อมูลค้างอยู่ไหม (ใช้ปิดปุ่มหลักของหน้า)
  bool get isWorking => _busy.isNotEmpty;

  /// งานชิ้นนั้นกำลังทำอยู่ไหม — เช่น `isBusy(AiBotProvider.botStateKey(12))`
  bool isBusy(String key) => _busy.contains(key);

  /// โหลดครั้งแรกจริงๆ (ยังไม่มีอะไรให้ดูเลย) → หน้าจอค่อยโชว์โครงกระดูก
  /// แยกจาก [isRefreshing] เพื่อไม่ให้ shimmer กระพริบทุกรอบ poll
  bool get isFirstLoad =>
      _status == null &&
      _catalog == null &&
      (_loadingCatalog || _loadingStatus);

  bool get hasStatus => _status != null;
  bool get hasCatalog => _catalog != null;

  // ══════════════════════════════════════════════════════════
  // ข้อผิดพลาด
  // ══════════════════════════════════════════════════════════

  String? get errorCode => _errorCode;
  String? get errorMessage => _errorMessage;
  Map<String, List<String>> get fieldErrors => _fieldErrors;
  bool get hasError => _errorCode != null;

  /// ข้อผิดพลาดของช่องในฟอร์ม (แปะใต้ TextField ตัวนั้น)
  String? fieldError(String field) {
    final list = _fieldErrors[field];
    return (list == null || list.isEmpty) ? null : list.first;
  }

  /// ข้อความสำหรับผู้ใช้ 2 ภาษา — ใช้กับ SnackBar/แบนเนอร์
  String errorText(bool isThai) {
    final code = _errorCode;
    if (code == null) return '';
    return AiBotErrorText.of(code, _errorMessage, isThai);
  }

  /// ต้องให้ผู้ใช้เซ็นยืนยันกระเป๋าใหม่ — หน้าจอต้องโชว์ปุ่ม ไม่ใช่จอเปล่า
  bool get needsVerification => _needsVerification;

  /// เครือข่ายเปลี่ยนกลางคัน (สลับ WiFi ↔ เน็ตมือถือ)
  /// ต้องมีข้อความคนละอันกับ "ลายเซ็นหมดอายุ" เพราะผู้ใช้ต้องรู้ว่าเกิดจากอะไร
  bool get verificationFailedByNetworkChange =>
      _errorCode == 'WALLET_IP_MISMATCH';

  /// กำลังโดนจำกัดจำนวนคำขออยู่ไหม
  bool get isThrottled {
    final until = _throttledUntil;
    return until != null && DateTime.now().isBefore(until);
  }

  /// เหลืออีกกี่วินาทีถึงจะยิงใหม่ได้
  int get throttleSecondsLeft {
    final until = _throttledUntil;
    if (until == null) return 0;
    final s = until.difference(DateTime.now()).inSeconds;
    return s < 0 ? 0 : s;
  }

  // ══════════════════════════════════════════════════════════
  // ธงฟีเจอร์ (อ่านจากเซิร์ฟเวอร์เท่านั้น — ห้ามฮาร์ดโค้ด)
  // ══════════════════════════════════════════════════════════

  /// ยังโหลดแคตตาล็อกไม่ได้ = ถือว่าปิดทุกอย่างไว้ก่อน
  /// เดาว่าเปิดแล้วปล่อยผู้ใช้กดจนเจอ error คือสิ่งที่ห้ามทำ
  AiBotFeatures get features => _catalog?.features ?? AiBotFeatures.closed;

  bool get liveEnabled => features.liveTrading;
  bool get topupEnabled => features.creditTopup;
  bool get analystEnabled => _catalog?.analystEnabled == true;

  /// กระเป๋าทีมงานเช่าได้แม้ปิดขาย (เซิร์ฟเวอร์ยกเว้นให้เพราะราคาเป็น 0)
  bool get isAdminWallet => _status?.isAdmin == true;
  bool get salesOpen => features.salesOpen || isAdminWallet;

  /// แอพต้องเดินบอทเองไหม (แพลนฟรี) — ตรงข้ามกับบอทที่เดินบนคลาวด์
  bool get runsInApp => _status != null && !_status!.runsInCloud;

  /// สร้างบอทเพิ่มได้ไหม — โควตาเต็มแล้วต้องปิดปุ่มพร้อมบอกเหตุผล
  /// ไม่ใช่ปล่อยให้กดแล้วเด้ง `BOT_LIMIT_REACHED`
  bool get canCreateBot => _status != null && !quota.isFull;

  // ── ลูปเดินบอทของแพลนฟรี ──

  /// บอทที่แอพต้องสั่งเดินเอง
  List<AiBot> get appTickBots => _status?.tickableBots ?? const [];

  bool get appLoopActive => _appLoopActive;
  DateTime? get lastAppTick => _lastAppTick;

  /// เหลืออีกกี่วินาทีถึงรอบถัดไป (เซิร์ฟเวอร์บอกมาตอน skipped)
  int? get nextTickInSeconds => _nextTickInSeconds;

  List<AiBotTickLog> get appTickLog => List.unmodifiable(_tickLog);

  /// เหตุที่ลูปถูกปิด (เช่น `CLOUD_BOT`) — null ถ้าไม่มีปัญหา
  String? get appLoopBlockedCode => _appLoopBlockedCode;

  // ── คีย์มาตรฐานของงานรายชิ้น ──
  static String botStateKey(int id) => 'bot_state:$id';
  static String botModeKey(int id) => 'bot_mode:$id';
  static String botDeleteKey(int id) => 'bot_delete:$id';
  static const String saveBotKey = 'bot_save';
  static const String subscribeKey = 'subscribe';
  static const String cancelKey = 'cancel';
  static const String welcomeKey = 'welcome';
  static const String topupKey = 'topup';
  static const String demoResetKey = 'demo_reset';

  // ══════════════════════════════════════════════════════════
  // ผูกกับกระเป๋า
  // ══════════════════════════════════════════════════════════

  /// ผูกกับ [WalletProvider] — `main.dart` เรียกให้อัตโนมัติทุกครั้งที่
  /// กระเป๋าแจ้งเปลี่ยน (ผ่าน ChangeNotifierProxyProvider) เรียกซ้ำไม่มีผล
  void bind(WalletProvider wallet) {
    if (identical(_wallet, wallet)) return;
    _wallet?.removeListener(_onWalletChanged);
    _wallet = wallet;
    _boundAddress = wallet.address;
    wallet.addListener(_onWalletChanged);
  }

  String? get _address => _wallet?.address;
  bool get isWalletConnected => _address != null;

  void _onWalletChanged() {
    final next = _wallet?.address;
    if (next == _boundAddress) return;

    // สลับกระเป๋า = ข้อมูลเดิมใช้ไม่ได้ทั้งหมด ต้องล้างก่อนโหลดใหม่
    // ปล่อยค้างไว้แม้แค่เสี้ยววินาที = ผู้ใช้เห็นเครดิตกับบอทของกระเป๋าอื่น
    _boundAddress = next;
    _resetWalletScopedState();
    notifyListeners();

    if (_active && next != null) {
      unawaited(bootstrap());
    }
  }

  void _resetWalletScopedState() {
    _status = null;
    _demo = null;
    _tradesPair = null;
    _trades = const [];
    _botWallet = null;
    _analytics = null;
    _credits = null;
    _advice = null;
    _decisions.clear();
    _decisionsCursor = null;
    _decisionsHasMore = false;
    _decisionBotFilter = null;
    _statusLoadedAt = null;
    _needsVerification = false;
    _lastResignAt = null;
    _appLoopBlockedCode = null;
    _lastAppTick = null;
    _nextTickInSeconds = null;
    _tickLog.clear();
    _busy.clear();
    _clearErrorSilently();
    _stopAppLoop();
  }

  // ══════════════════════════════════════════════════════════
  // วงจรชีวิตของหน้า
  // ══════════════════════════════════════════════════════════

  /// โหลดชุดแรกของหน้า — เรียกตอนเข้าหน้า และตอนแอพกลับมาเบื้องหน้า
  ///
  /// ถ้าข้อมูลยังสดอยู่จะไม่ยิงซ้ำให้เปลืองโควตา
  Future<void> bootstrap() async {
    if (_bootstrapping) return;
    _bootstrapping = true;
    try {
      // แคตตาล็อกกับมุมมองตลาดเป็น endpoint สาธารณะ — ต้องดูได้ก่อนเชื่อมกระเป๋า
      // หน้าจอจึงห้ามล็อกทั้งหน้าเพราะยังไม่ได้เชื่อม
      if (_catalog == null) await _loadCatalog();
      if (_marketView == null) await _loadMarketView();

      if (_address == null) return;

      final stale =
          _statusLoadedAt == null ||
          DateTime.now().difference(_statusLoadedAt!) > staleAfter;
      if (!stale) {
        _syncAppLoop();
        return;
      }

      await _loadStatus(silent: _status != null);
      if (_needsVerification) return;

      await _loadDemo(silent: _demo != null);
      if (_watchDecisions) {
        await _loadDecisionsHead(silent: _decisions.isNotEmpty);
      }
    } finally {
      _bootstrapping = false;
    }
  }

  /// เริ่มดึงสถานะเป็นรอบ — เรียกตอนเข้าหน้า / แอพกลับมาเบื้องหน้า
  void startPolling() {
    _active = true;
    _statusTimer?.cancel();
    _statusTimer = Timer.periodic(statusPollInterval, (_) => _pollTick());
    _syncAppLoop();
  }

  /// หยุดทุกลูป — **ต้องเรียกเสมอ** ตอนออกจากหน้าและตอนแอพลงพื้นหลัง
  ///
  /// ถ้าลืม ลูปจะเดินต่อหลังผู้ใช้ไปหน้าอื่น กลายเป็นบอทเดินเบื้องหลัง
  /// โดยที่ผู้ใช้ไม่เห็นและไม่ได้ตั้งใจ แถมกินแบตกับโควตาคำขอไปเรื่อยๆ
  void stopPolling() {
    if (!_active && _statusTimer == null && _tickTimer == null) return;
    _active = false;
    _statusTimer?.cancel();
    _statusTimer = null;
    _stopAppLoop();
    notifyListeners();
  }

  /// รอบ poll เบื้องหลัง — เงียบเสมอ ไม่ทำให้จอกระพริบ
  Future<void> _pollTick() async {
    if (!_active || _address == null) return;
    // โดนจำกัดคำขออยู่ → ข้ามรอบนี้ไป อย่าซ้ำเติม
    if (isThrottled) return;
    // ยังเซ็นไม่ผ่าน → ยิงไปก็ 403 ซ้ำๆ รอผู้ใช้กดปุ่มยืนยันเอง
    if (_needsVerification || _loadingStatus) return;

    await _loadStatus(silent: true, allowResign: false);

    if (_watchDecisions && _active && !isThrottled && !_needsVerification) {
      await _loadDecisionsHead(silent: true);
    }
  }

  /// ผู้ใช้ลากรีเฟรชเอง — โหลดใหม่ทุกอย่างที่หน้ากำลังแสดง
  Future<void> refreshAll() async {
    if (_refreshing) return;
    _refreshing = true;
    _clearErrorSilently();
    notifyListeners();

    try {
      if (_catalog == null) await _loadCatalog();
      await _loadMarketView();

      if (_address != null) {
        await _loadStatus(silent: true);
        if (!_needsVerification) {
          await _loadDemo(silent: true);
          if (_watchDecisions) await _loadDecisionsHead(silent: true);
          if (_analytics != null) await loadAnalytics(silent: true);
        }
      }
    } finally {
      _refreshing = false;
      notifyListeners();
    }
  }

  // ══════════════════════════════════════════════════════════
  // โหลดข้อมูล
  // ══════════════════════════════════════════════════════════

  Future<void> _loadCatalog() async {
    if (_loadingCatalog) return;
    _loadingCatalog = true;
    if (_catalog == null) notifyListeners();

    final res = await _api.fetchCatalog();

    _loadingCatalog = false;
    switch (res) {
      case ApiOk(:final data):
        _catalog = data;
      case ApiErr():
        // ยังไม่เคยโหลดสำเร็จเลย = หน้าจอทำอะไรไม่ได้ ต้องบอกผู้ใช้
        // แต่ถ้าเคยมีของเก่าอยู่แล้ว อย่าไปทับ error ของงานที่ผู้ใช้รอผลอยู่
        if (_catalog == null) _recordFailure(res.errorOrNull!);
    }
    notifyListeners();
  }

  Future<void> _loadMarketView() async {
    if (_loadingMarketView) return;
    _loadingMarketView = true;
    notifyListeners();

    final res = await _api.fetchMarketView();

    _loadingMarketView = false;
    if (res case ApiOk(:final data)) {
      _marketView = data;
    }
    // ล้มเหลวก็แค่ไม่มีแผงนี้ — ไม่ควรไปบังข้อผิดพลาดที่สำคัญกว่าบนจอ
    notifyListeners();
  }

  /*
   * โหลดสถานะรวมของกระเป๋า
   *
   * คืน true = ได้ยิงจริง · false = ข้ามเพราะมีรอบค้างอยู่
   * ⚠️ ผู้เรียกที่เอาผลไปตัดสินใจต่อ (เช่น verifyWallet) ต้องแยกสองกรณีนี้ให้ออก
   *    ไม่งั้นจะอ่านค่าเก่าแล้วสรุปผิดว่าล้มเหลว ทั้งที่ยังไม่ได้ถามเซิร์ฟเวอร์เลย
   */
  Future<bool> _loadStatus({
    bool silent = false,
    bool allowResign = true,
  }) async {
    if (_loadingStatus) return false;
    _loadingStatus = true;
    if (!silent) notifyListeners();

    final res = await _run(
      (wallet) => _api.fetchStatus(wallet),
      allowResign: allowResign,
    );

    _loadingStatus = false;
    switch (res) {
      case ApiOk(:final data):
        _status = data;
        _statusLoadedAt = DateTime.now();
        _needsVerification = false;
        _clearErrorSilently();
        _syncAppLoop();
      case ApiErr():
        _recordFailure(res.errorOrNull!);
    }
    notifyListeners();

    return true;
  }

  Future<void> _loadDemo({bool silent = false}) async {
    if (_loadingDemo) return;
    _loadingDemo = true;
    if (!silent) notifyListeners();

    final res = await _run((wallet) => _api.fetchDemo(wallet));

    _loadingDemo = false;
    switch (res) {
      case ApiOk(:final data):
        _demo = data;
      case ApiErr():
        _recordFailure(res.errorOrNull!);
    }
    notifyListeners();
  }

  /// ด่านความเสี่ยงของคู่ที่เลือกในฟอร์ม (สาธารณะ — ไม่ต้องมีกระเป๋า)
  Future<void> loadRisk(String pair) async {
    if (pair.trim().isEmpty) return;
    // ฟอร์มสร้างบอทเรียกทุกครั้งที่เปลี่ยนคู่เทรด — กดรัวสลับคู่แล้วยิงซ้อนกัน
    // คำตอบจะมาสลับลำดับ ทำให้จอโชว์ความเสี่ยงของคู่ที่ผู้ใช้ไม่ได้เลือกอยู่
    if (_loadingRisk) return;
    _loadingRisk = true;
    notifyListeners();

    final normalized = pair.replaceAll('-', '/').toUpperCase().trim();
    final res = await _api.fetchRisk(normalized);

    _loadingRisk = false;
    switch (res) {
      case ApiOk(:final data):
        _risk = data;
      case ApiErr():
        // คู่ที่ยังไม่มีข้อมูลไม่ใช่ความผิดของผู้ใช้ — เคลียร์แผงแทนที่จะขึ้น error
        // (หน้าจอจะโชว์ "ยังประเมินคู่นี้ไม่ได้" เอง ห้ามวาด "สงบ 0%")
        _risk = null;
    }
    notifyListeners();
  }

  Future<void> loadAnalytics({
    String mode = 'demo',
    bool silent = false,
  }) async {
    if (_loadingAnalytics) return;
    _loadingAnalytics = true;
    if (!silent) notifyListeners();

    final res = await _run(
      (wallet) =>
          _api.fetchAnalytics(wallet: wallet, mode: AiBotMode.fromWire(mode)),
    );

    _loadingAnalytics = false;
    switch (res) {
      case ApiOk(:final data):
        _analytics = data;
      case ApiErr():
        _recordFailure(res.errorOrNull!);
    }
    notifyListeners();
  }

  Future<void> loadCredits() async {
    if (_loadingCredits) return;
    _loadingCredits = true;
    notifyListeners();

    final res = await _run((wallet) => _api.fetchCredits(wallet));

    _loadingCredits = false;
    switch (res) {
      case ApiOk(:final data):
        _credits = data;
      case ApiErr():
        _recordFailure(res.errorOrNull!);
    }
    notifyListeners();
  }

  // ══════════════════════════════════════════════════════════
  // ประวัติการตัดสินใจ
  // ══════════════════════════════════════════════════════════

  /// บอกว่าหน้ากำลังเปิดแท็บประวัติอยู่ไหม — เปิดแล้วรอบ poll จะดึงให้ด้วย
  /// ปิดแล้วหยุดดึงทันที เพื่อไม่ให้กินโควตาโดยที่ผู้ใช้ไม่ได้ดู
  void setWatchDecisions(bool value) {
    if (_watchDecisions == value) return;
    _watchDecisions = value;
    if (value && _decisions.isEmpty && _address != null) {
      unawaited(refreshDecisions());
    }
  }

  /// เปลี่ยนตัวกรอง แล้วโหลดหน้าแรกใหม่
  void setDecisionFilter({int? botId, bool? actedOnly, bool clearBot = false}) {
    final nextBot = clearBot ? null : (botId ?? _decisionBotFilter);
    final nextActed = actedOnly ?? _decisionActedOnly;
    if (nextBot == _decisionBotFilter && nextActed == _decisionActedOnly) {
      return;
    }
    _decisionBotFilter = nextBot;
    _decisionActedOnly = nextActed;
    _decisions.clear();
    _decisionsCursor = null;
    _decisionsHasMore = false;
    notifyListeners();
    unawaited(refreshDecisions());
  }

  /// โหลดหน้าแรกของประวัติใหม่ทั้งหมด
  Future<void> refreshDecisions() => _loadDecisionsHead(silent: false);

  Future<void> _loadDecisionsHead({required bool silent}) async {
    if (_loadingDecisions) return;
    _loadingDecisions = true;
    if (!silent) notifyListeners();

    final res = await _run(
      (wallet) => _api.fetchDecisions(
        wallet: wallet,
        botId: _decisionBotFilter,
        actedOnly: _decisionActedOnly,
      ),
    );

    _loadingDecisions = false;
    switch (res) {
      case ApiOk(:final data):
        _decisions
          ..clear()
          ..addAll(data.decisions);
        _decisionsCursor = data.nextCursor;
        _decisionsHasMore = data.hasMore;
      case ApiErr():
        _recordFailure(res.errorOrNull!);
    }
    notifyListeners();
  }

  /// เลื่อนดูย้อนหลังอีกหนึ่งหน้า
  Future<void> loadMoreDecisions() async {
    if (_loadingMoreDecisions || _loadingDecisions) return;
    if (!_decisionsHasMore) return;
    final cursor = _decisionsCursor;
    if (cursor == null) return;

    _loadingMoreDecisions = true;
    notifyListeners();

    final res = await _run(
      (wallet) => _api.fetchDecisions(
        wallet: wallet,
        botId: _decisionBotFilter,
        actedOnly: _decisionActedOnly,
        beforeId: cursor,
      ),
    );

    _loadingMoreDecisions = false;
    switch (res) {
      case ApiOk(:final data):
        // กันแถวซ้ำเวลาบอทเขียนแถวใหม่ระหว่างที่ผู้ใช้กำลังเลื่อน
        final seen = _decisions.map((d) => d.id).toSet();
        _decisions.addAll(data.decisions.where((d) => !seen.contains(d.id)));
        _decisionsCursor = data.nextCursor;
        _decisionsHasMore = data.hasMore;
      case ApiErr():
        _recordFailure(res.errorOrNull!);
    }
    notifyListeners();
  }

  // ══════════════════════════════════════════════════════════
  // การยืนยันกระเป๋า
  // ══════════════════════════════════════════════════════════

  /// ผู้ใช้กดปุ่ม "เซ็นยืนยันกระเป๋า"
  ///
  /// ตัดสินผลจากการที่โหลดสถานะผ่านหรือไม่ **ไม่ใช่** จากค่าที่ปุ่มเซ็นคืนมา
  /// เพราะลายเซ็นที่ถูกปฏิเสธหรือหมดเวลาไม่ throw ขึ้นมา (WalletProvider กลืนไว้)
  /// ถ้าเชื่อผลปุ่ม จอจะขึ้น "สำเร็จ" ทั้งที่ยังเข้าไม่ได้
  Future<bool> verifyWallet() async {
    if (_verifying) return false;
    final wallet = _wallet;
    if (wallet == null || wallet.address == null) {
      _recordFailure(const ApiErr('NO_WALLET', 'กรุณาเชื่อมกระเป๋าก่อน'));
      notifyListeners();
      return false;
    }

    _verifying = true;
    _clearErrorSilently();
    notifyListeners();

    try {
      await wallet.verifyWithBackend();
    } catch (e) {
      if (kDebugMode) debugPrint('[AiBot] verify error: ${e.runtimeType}');
    }

    _verifying = false;
    _lastResignAt = DateTime.now();
    notifyListeners();

    /*
     * ยิงจริงเพื่อพิสูจน์ว่าผ่านแล้ว — ห้าม resign ซ้ำในรอบนี้ ไม่งั้นเด้งซ้อน
     *
     * ⚠️ ต้องเช็คว่า "ได้ยิงจริงไหม" ไม่ใช่แค่ await เฉยๆ
     *    เส้นทางที่ชนกันเกิดขึ้นจริงเสมอ: การเซ็นเปิดแอพกระเป๋า ทำให้แอพนี้เข้า
     *    พื้นหลัง พอกลับมา didChangeAppLifecycleState ยิง refreshAll() ทันที
     *    ซึ่งตั้ง _loadingStatus ค้างไว้ พอมาถึงบรรทัดนี้จึงถูกข้ามไปเงียบๆ
     *    แล้วเราอ่าน _needsVerification ค่าเก่า → บอกผู้ใช้ว่าล้มเหลวทั้งที่สำเร็จ
     *    เขาก็จะกดเซ็นซ้ำ เด้งไปแอพกระเป๋าอีกรอบโดยไม่จำเป็น
     */
    var fired = await _loadStatus(silent: true, allowResign: false);
    if (!fired) {
      // รอรอบที่ค้างอยู่ให้จบก่อน (สูงสุด 2 วิ) แล้วค่อยยิงของเราเอง
      for (var i = 0; i < 20 && _loadingStatus; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 100));
      }
      fired = await _loadStatus(silent: true, allowResign: false);
    }

    // ยังยิงไม่ได้จริงๆ = ตอบไม่ได้ว่าผ่านหรือไม่ อย่าเดาว่าล้มเหลว
    if (!fired) return !_needsVerification;

    if (!_needsVerification) {
      await _loadDemo(silent: true);
      return true;
    }
    return false;
  }

  /// ขอลายเซ็นใหม่แบบเงียบ — ใช้เฉพาะตอนผู้ใช้เป็นคนสั่งงานเท่านั้น
  ///
  /// จงใจไม่ทำในรอบ poll เบื้องหลัง เพราะกระเป๋าแบบเชื่อมโยงจะเด้งออกจากแอพ
  /// ไปหน้า TPIX Wallet — เด้งขึ้นมาเองตอนผู้ใช้แค่นั่งดูจอคือพฤติกรรมที่แย่มาก
  Future<bool> _silentResign() async {
    final wallet = _wallet;
    if (wallet == null || wallet.address == null || _verifying) return false;

    final last = _lastResignAt;
    if (last != null && DateTime.now().difference(last) < _resignCooldown) {
      return false;
    }

    _verifying = true;
    _lastResignAt = DateTime.now();
    notifyListeners();

    var ok = false;
    try {
      ok = await wallet.verifyWithBackend();
    } catch (e) {
      if (kDebugMode) debugPrint('[AiBot] silent resign: ${e.runtimeType}');
      ok = false;
    }

    _verifying = false;
    notifyListeners();
    return ok;
  }

  // ══════════════════════════════════════════════════════════
  // การกระทำของผู้ใช้
  // ══════════════════════════════════════════════════════════

  /// รับเครดิตต้อนรับ (ครั้งเดียวต่อกระเป๋า)
  Future<WelcomeOutcome> claimWelcome() async {
    if (!_beginTask(welcomeKey)) return WelcomeOutcome.failed;
    final before = creditBalance;

    final res = await _run((wallet) => _api.claimWelcome(wallet));
    _endTask(welcomeKey);

    switch (res) {
      case ApiOk(:final data):
        // endpoint นี้ไม่รีเฟรชสถานะให้ ต้องดึงเองเพื่อให้ยอดบนจอตรง
        await _loadStatus(silent: true);
        notifyListeners();
        // เซิร์ฟเวอร์คืน "ยอดคงเหลือใหม่" ไม่ใช่ "โบนัสที่เพิ่งได้"
        return data > before + 0.0001
            ? WelcomeOutcome.granted
            : WelcomeOutcome.alreadyClaimed;
      case ApiErr():
        _recordFailure(res.errorOrNull!);
        notifyListeners();
        return WelcomeOutcome.failed;
    }
  }

  /// ส่งคำขอเติมเครดิต — ยังไม่ได้เครดิตทันที ต้องรอทีมงานยืนยันการชำระเงิน
  Future<TopupRequest?> requestTopup(String packCode) async {
    // ธงปิดอยู่ก็ไม่ต้องยิงให้เสียโควตา — ปุ่มควรถูกปิดตั้งแต่แรกอยู่แล้ว
    if (!topupEnabled) {
      _recordFailure(
        const ApiErr(
          'TOPUP_UNAVAILABLE',
          'ยังไม่เปิดให้เติมเครดิต',
          status: 422,
        ),
      );
      notifyListeners();
      return null;
    }
    if (!_beginTask(topupKey)) return null;

    final res = await _run(
      (wallet) => _api.requestTopup(wallet: wallet, packCode: packCode),
    );
    _endTask(topupKey);

    switch (res) {
      case ApiOk(:final data):
        notifyListeners();
        return data;
      case ApiErr():
        _recordFailure(res.errorOrNull!);
        notifyListeners();
        return null;
    }
  }

  /// เช่า/ต่ออายุแพลน
  ///
  /// [days] ต้องเป็นค่าจาก `catalog.rentalDays` — ค่าอื่นเซิร์ฟเวอร์บีบเหลือ
  /// 1 วันเงียบๆ ผู้ใช้จ่ายไปแล้วถึงจะรู้
  Future<bool> subscribe(String planCode, int days) async {
    if (!salesOpen) {
      // ปิดขายไม่ใช่เรื่องเงิน — ห้ามพาไปหน้าเติมเครดิต
      _recordFailure(
        const ApiErr('SALES_CLOSED', 'ยังไม่เปิดให้เช่าบอท', status: 422),
      );
      notifyListeners();
      return false;
    }
    // กันกดรัว: เซิร์ฟเวอร์ล็อกไว้ 15 วิ แต่ถ้าปล่อยให้ยิงซ้ำในนาทีเดียวกัน
    // `expires_at` จะถูกบวกซ้ำทั้งที่ตัดเครดิตครั้งเดียว
    if (!_beginTask(subscribeKey)) return false;

    final res = await _run(
      (wallet) =>
          _api.subscribe(wallet: wallet, planCode: planCode, days: days),
    );
    _endTask(subscribeKey);

    switch (res) {
      case ApiOk(:final data):
        // คืน payload ของ /status เต็มก้อน — ไม่ต้องยิงซ้ำ และ "ต้องใช้" ก้อนนี้
        // เพราะการเปลี่ยนแพลนเขียนทับกรอบความเสี่ยงของบอททุกตัว
        // ค่าเก่าที่ค้างใน memory ใช้ไม่ได้แล้ว
        _status = data;
        _statusLoadedAt = DateTime.now();
        _clearErrorSilently();
        _syncAppLoop();
        notifyListeners();
        return true;
      case ApiErr():
        _recordFailure(res.errorOrNull!);
        notifyListeners();
        return false;
    }
  }

  /// ยกเลิกการเช่า — หยุดบอททุกตัวและคืนเครดิตตามวันที่เหลือ
  /// หน้าจอ **ต้อง** ถามยืนยันก่อน เซิร์ฟเวอร์ไม่ถามให้
  Future<bool> cancelPlan() async {
    if (!_beginTask(cancelKey)) return false;

    final res = await _run((wallet) => _api.cancelSubscription(wallet));
    _endTask(cancelKey);

    switch (res) {
      case ApiOk(:final data):
        _status = data;
        _statusLoadedAt = DateTime.now();
        _clearErrorSilently();
        _syncAppLoop();
        notifyListeners();
        await _loadDemo(silent: true);
        return true;
      case ApiErr():
        _recordFailure(res.errorOrNull!);
        notifyListeners();
        return false;
    }
  }

  /// สร้างบอทใหม่ — คืนบอทที่เซิร์ฟเวอร์บันทึกจริง
  ///
  /// เซิร์ฟเวอร์ clamp ค่าหลายตัวเงียบๆ (target_bps, range_pct, ทุนต่อไม้)
  /// ⇒ หน้าจอต้องใช้ค่าที่คืนมานี้เป็นความจริง ห้ามใช้ค่าในฟอร์ม
  Future<AiBot?> createBot(AiBotDraft draft) async {
    if (!_beginTask(saveBotKey)) return null;

    final res = await _run(
      (wallet) => _api.createBot(wallet: wallet, draft: draft),
    );
    _endTask(saveBotKey);

    switch (res) {
      case ApiOk(:final data):
        _clearErrorSilently();
        // โควตากับรายการบอทเปลี่ยน ต้องดึงสถานะใหม่
        await _loadStatus(silent: true);
        notifyListeners();
        return data;
      case ApiErr():
        _recordFailure(res.errorOrNull!);
        notifyListeners();
        return null;
    }
  }

  /// แก้บอทเดิม (PUT เต็มก้อน)
  Future<AiBot?> updateBot(int botId, AiBotDraft draft) async {
    if (!_beginTask(saveBotKey)) return null;

    final res = await _run(
      (wallet) => _api.updateBot(wallet: wallet, botId: botId, draft: draft),
    );
    _endTask(saveBotKey);

    switch (res) {
      case ApiOk(:final data):
        _clearErrorSilently();
        _replaceBot(data);
        _syncAppLoop();
        notifyListeners();
        return data;
      case ApiErr():
        _recordFailure(res.errorOrNull!);
        notifyListeners();
        return null;
    }
  }

  /// เริ่ม / พัก / หยุด บอท
  ///
  /// อัปเดตหน้าจอทันทีแล้วย้อนกลับถ้าเซิร์ฟเวอร์ปฏิเสธ — ปล่อยให้จอค้าง
  /// สถานะปลอมไว้คือการโกหกผู้ใช้เรื่องว่าบอทกำลังทำงานอยู่หรือเปล่า
  Future<bool> setBotState(AiBot bot, String action) async {
    final key = botStateKey(bot.id);
    if (!_beginTask(key)) return false;

    final previous = bot;
    final optimistic = switch (action) {
      'start' => 'running',
      'pause' => 'paused',
      'stop' => 'stopped',
      _ => bot.status,
    };
    _replaceBot(bot.copyWith(status: optimistic));
    notifyListeners();

    final res = await _run(
      (wallet) => _api.setBotState(
        wallet: wallet,
        botId: bot.id,
        action: AiBotStateAction.fromWire(action),
      ),
    );
    _endTask(key);

    switch (res) {
      case ApiOk(:final data):
        _replaceBot(data);
        _clearErrorSilently();
        _syncAppLoop();
        notifyListeners();
        return true;
      case ApiErr():
        _replaceBot(previous);
        _recordFailure(res.errorOrNull!);
        _syncAppLoop();
        notifyListeners();
        // บอทหายไปแล้ว (ลบจากอุปกรณ์อื่น) → ดึงรายการใหม่ให้ตรงความจริง
        if (res.errorOrNull!.code == 'BOT_NOT_FOUND') {
          await _loadStatus(silent: true);
        }
        return false;
    }
  }

  /// สลับโหมดทดลอง ↔ จริง
  ///
  /// หน้าจอควรถามยืนยันก่อนเข้าโหมดจริง — เป็นการตัดสินใจเรื่องเงินจริง
  /// และของที่ถืออยู่ในโหมดเดิม **ไม่ตามไปด้วย** (ยังอยู่ แค่มองไม่เห็น)
  Future<bool> setBotMode(AiBot bot, String mode) async {
    if (mode == 'live' && !liveEnabled) {
      _recordFailure(
        const ApiErr(
          'LIVE_DISABLED',
          'ตอนนี้เปิดให้ใช้เฉพาะโหมดทดลองก่อน',
          status: 422,
        ),
      );
      notifyListeners();
      return false;
    }

    final key = botModeKey(bot.id);
    if (!_beginTask(key)) return false;

    final previous = bot;
    _replaceBot(bot.copyWith(mode: mode));
    notifyListeners();

    final res = await _run(
      (wallet) => _api.setBotMode(
        wallet: wallet,
        botId: bot.id,
        mode: AiBotMode.fromWire(mode),
      ),
    );
    _endTask(key);

    switch (res) {
      case ApiOk(:final data):
        _replaceBot(data);
        _clearErrorSilently();
        notifyListeners();
        // ไม้คนละชุดกันคนละโหมด — ตัวเลขพอร์ตต้องโหลดใหม่ทันที
        await _loadDemo(silent: true);
        return true;
      case ApiErr():
        _replaceBot(previous);
        _recordFailure(res.errorOrNull!);
        notifyListeners();
        return false;
    }
  }

  /// ลบบอท — หน้าจอ **ต้อง** ถามยืนยัน และเตือนถ้ายังถือของอยู่
  Future<bool> deleteBot(AiBot bot) async {
    final key = botDeleteKey(bot.id);
    if (!_beginTask(key)) return false;

    final res = await _run(
      (wallet) => _api.deleteBot(wallet: wallet, botId: bot.id),
    );
    _endTask(key);

    switch (res) {
      case ApiOk():
        _clearErrorSilently();
        await _loadStatus(silent: true);
        await _loadDemo(silent: true);
        return true;
      case ApiErr():
        final err = res.errorOrNull!;
        // ลบไปแล้วจากที่อื่น = ผลลัพธ์ที่ผู้ใช้ต้องการอยู่ดี ไม่ต้องขึ้น error
        if (err.code == 'BOT_NOT_FOUND') {
          await _loadStatus(silent: true);
          return true;
        }
        _recordFailure(err);
        notifyListeners();
        return false;
    }
  }

  /// ล้างพอร์ตทดลอง — ทำลายทั้งไม้ที่ถืออยู่และประวัติ (รวมถึงสถิติย้อนหลัง)
  Future<bool> resetDemo() async {
    if (!_beginTask(demoResetKey)) return false;

    final res = await _run((wallet) => _api.resetDemo(wallet));
    _endTask(demoResetKey);

    switch (res) {
      case ApiOk(:final data):
        _demo = data;
        // /analytics กับ /decisions อ่านจากตารางเดียวกัน — ของเดิมไม่จริงแล้ว
        _analytics = null;
        _decisions.clear();
        _decisionsCursor = null;
        _decisionsHasMore = false;
        _clearErrorSilently();
        notifyListeners();
        return true;
      case ApiErr():
        _recordFailure(res.errorOrNull!);
        notifyListeners();
        return false;
    }
  }

  // ══════════════════════════════════════════════════════════
  // ไม้ของบอทสำหรับกราฟ + กระเป๋าบอท
  // ══════════════════════════════════════════════════════════

  /// ไม้ของบอทในคู่นี้ (ทุกโหมด) — หน้าเทรดเรียกตอนสลับคู่และตามรอบรีเฟรช
  /// เงียบเมื่อยังไม่ยืนยันกระเป๋า — ป้ายบนกราฟไม่ใช่เรื่องที่ต้องเด้งเตือน
  Future<void> loadTrades(String pair) async {
    final wanted = pair.replaceAll('-', '/').toUpperCase();
    final res = await _run((wallet) => _api.fetchTrades(wallet: wallet, pair: wanted));
    switch (res) {
      case ApiOk(:final data):
        _tradesPair = wanted;
        _trades = data;
        notifyListeners();
      case ApiErr():
        break;
    }
  }

  /// สถานะกระเป๋าบอท — โหลดตอนเปิดการ์ด และหลังทุกการกระทำ
  Future<void> loadBotWallet() async {
    if (_loadingBotWallet) return;
    _loadingBotWallet = true;
    notifyListeners();

    final res = await _run((wallet) => _api.fetchBotWallet(wallet));
    _loadingBotWallet = false;

    switch (res) {
      case ApiOk(:final data):
        _botWallet = data;
      case ApiErr():
        // 403 ยังไม่ยืนยันกระเป๋า — ปล่อยให้เส้นทางยืนยันปกติจัดการ ไม่เด้งเตือนซ้ำ
        break;
    }
    notifyListeners();
  }

  /// สร้าง / รีเฟรชยอด / ถอน / ยกเลิก — ทุกตัวคืน { wallet?, transfers? } บางส่วน
  Future<bool> _botWalletAction(
    Future<ApiResult<Map<String, dynamic>>> Function(String wallet) request,
  ) async {
    if (!_beginTask(botWalletKey)) return false;

    final res = await _run(request);
    _endTask(botWalletKey);

    switch (res) {
      case ApiOk(:final data):
        final current = _botWallet ??
            BotWalletState(enabled: true, chainId: 56);
        _botWallet = current.merge(data);
        _clearErrorSilently();
        notifyListeners();
        return true;
      case ApiErr():
        _recordFailure(res.errorOrNull!);
        notifyListeners();
        return false;
    }
  }

  Future<bool> createBotWallet() =>
      _botWalletAction((wallet) => _api.createBotWallet(wallet));

  Future<bool> refreshBotWallet() =>
      _botWalletAction((wallet) => _api.refreshBotWallet(wallet));

  /// ถอนกลับกระเป๋าของตัวเองเท่านั้น — ไม่มีพารามิเตอร์ปลายทางโดยตั้งใจ
  Future<bool> withdrawBotWallet(String asset, double amount) =>
      _botWalletAction((wallet) =>
          _api.withdrawBotWallet(wallet: wallet, asset: asset, amount: amount));

  Future<bool> cancelBotWalletWithdraw(int transferId) =>
      _botWalletAction((wallet) =>
          _api.cancelBotWalletWithdraw(wallet: wallet, transferId: transferId));

  /// ขอคำแนะนำจากที่ปรึกษา AI
  ///
  /// คืน true เมื่อ "ได้คำตอบมาแล้ว" — ตัวคำตอบเองอาจเป็น `ok:false`
  /// พร้อมเหตุผล (เช่น ยังไม่ได้ตั้งคีย์ผู้ให้บริการ) ซึ่งเป็นสถานะปกติของ
  /// ระบบตอนนี้ ไม่ใช่ความล้มเหลว
  Future<bool> askAdvice() async {
    if (_askingAdvice) return false;
    _askingAdvice = true;
    _clearErrorSilently();
    notifyListeners();

    final res = await _run((wallet) => _api.fetchAdvice(wallet));

    _askingAdvice = false;
    switch (res) {
      case ApiOk(:final data):
        _advice = data;
        notifyListeners();
        return true;
      case ApiErr():
        _recordFailure(res.errorOrNull!);
        notifyListeners();
        return false;
    }
  }

  // ══════════════════════════════════════════════════════════
  // ลูปเดินบอทของแพลนฟรี
  // ══════════════════════════════════════════════════════════

  /// เปิด/ปิดลูปให้ตรงกับสถานะล่าสุด — เรียกทุกครั้งที่ `_status` เปลี่ยน
  void _syncAppLoop() {
    final should =
        _active &&
        _appLoopBlockedCode == null &&
        runsInApp &&
        appTickBots.isNotEmpty;

    if (!should) {
      _stopAppLoop();
      return;
    }
    if (_tickTimer != null) return;

    _appLoopActive = true;
    _tickTimer = Timer.periodic(appTickInterval, (_) => _runTickCycle());
    // เดินรอบแรกทันที ไม่ต้องรอครบ 30 วิ
    unawaited(_runTickCycle());
  }

  void _stopAppLoop() {
    _tickTimer?.cancel();
    _tickTimer = null;
    _appLoopActive = false;
  }

  /// เดินบอททุกตัวหนึ่งรอบ — เรียงทีละตัว ห้ามยิงพร้อมกัน
  Future<void> _runTickCycle() async {
    if (_tickCycleRunning) return;
    if (!_active || isThrottled || _needsVerification) return;

    final targets = appTickBots;
    if (targets.isEmpty) {
      _stopAppLoop();
      return;
    }

    _tickCycleRunning = true;
    var acted = false;
    var reloadStatus = false;

    try {
      for (final bot in targets) {
        // เงื่อนไขเปลี่ยนกลางลูปได้เสมอ (ผู้ใช้ออกจากหน้า / โดน 429)
        // จึงต้องเช็คใหม่ทุกรอบ ไม่ใช่เช็คครั้งเดียวตอนเริ่ม
        if (!_active || isThrottled || _needsVerification) break;

        final res = await _run(
          (wallet) => _api.tickBot(wallet: wallet, botId: bot.id),
        );

        switch (res) {
          case ApiOk(:final data):
            // `skipped` ไม่ใช่ความล้มเหลว — แค่ยังไม่ถึงรอบ
            _nextTickInSeconds = data.skipped ? data.nextInSeconds : null;
            if (!data.skipped) {
              acted = true;
              _lastAppTick = DateTime.now();
              final fresh = data.bot;
              if (fresh != null) _replaceBot(fresh);
              _pushTickLog(
                AiBotTickLog(
                  at: DateTime.now(),
                  botId: bot.id,
                  botName: bot.name,
                  action: data.action ?? 'hold',
                  reason: data.reason,
                ),
              );
            }

          case ApiErr():
            final err = res.errorOrNull!;
            final fatal = switch (err.code) {
              // แพลนคลาวด์ไม่ต้องสั่งจากแอพ — ยิงต่อคือเสียโควตาเปล่า
              'CLOUD_BOT' => true,
              'NO_SUBSCRIPTION' => true,
              'STRATEGY_LOCKED' => true,
              'BOT_LIMIT_REACHED' => true,
              'RATE_LIMITED' => true,
              'WALLET_NOT_VERIFIED' => true,
              'WALLET_IP_MISMATCH' => true,
              _ => false,
            };

            if (fatal) {
              _appLoopBlockedCode = err.code;
              _recordFailure(err);
              _stopAppLoop();
              reloadStatus = err.code != 'RATE_LIMITED';
              break;
            }

            // บอทตัวนี้เดินต่อไม่ได้ แต่ตัวอื่นยังเดินได้
            if (err.code == 'BOT_NOT_RUNNING' ||
                err.code == 'BOT_BANNED' ||
                err.code == 'BOT_NOT_FOUND') {
              reloadStatus = true;
              continue;
            }

            // เน็ตสะดุดชั่วคราว — ข้ามรอบนี้ ไม่ต้องดับลูปทิ้ง
            if (kDebugMode) {
              debugPrint('[AiBot] tick ${bot.id} -> ${err.code}');
            }
        }

        if (_appLoopBlockedCode != null) break;
      }
    } finally {
      _tickCycleRunning = false;
    }

    notifyListeners();

    // ตัวเลขพอร์ตต้องตรงหลังบอทเดิน ไม่งั้นผู้ใช้เห็นไม้ใหม่แต่ยอดเก่า
    if (acted) {
      await _loadDemo(silent: true);
      if (_watchDecisions) await _loadDecisionsHead(silent: true);
    }
    if (reloadStatus) await _loadStatus(silent: true);
  }

  void _pushTickLog(AiBotTickLog entry) {
    _tickLog.insert(0, entry);
    if (_tickLog.length > 20) {
      _tickLog.removeRange(20, _tickLog.length);
    }
  }

  /// ผู้ใช้กดลองใหม่หลังลูปถูกปิดเพราะข้อผิดพลาด
  /*
   * กดลองใหม่หลังลูปเดินบอทถูกบล็อก
   *
   * ⚠️ ต้องล้าง "ตัวถอยฝั่งไคลเอนต์" ของ AiBotApi ด้วย ไม่ใช่แค่ธงใน provider
   *    ตอนโดน 429 ตัวไคลเอนต์ตั้งเวลาถอยไว้เอง แล้วปฏิเสธคำขอโดยไม่ยิงเน็ต
   *    คืนรหัส RATE_LIMITED ซึ่งอยู่ในรายการที่ดับลูปทันที — ผลคือกดปุ่มแล้ว
   *    แบนเนอร์กระพริบแล้วกลับมาบล็อกเหมือนเดิม ดูเหมือนปุ่มเสีย
   *
   * ยังไม่ครบเวลาถอย = ไม่ล้าง แต่คืนค่าให้หน้าจอบอกผู้ใช้ว่าต้องรออีกกี่วินาที
   * ซึ่งตรงไปตรงมากว่าปล่อยให้กดแล้วไม่เกิดอะไรขึ้น
   */
  void retryAppLoop() {
    if (apiCooldownSeconds > 0) {
      notifyListeners();
      return;
    }

    _api.clearCooldown();
    _appLoopBlockedCode = null;
    _throttledUntil = null;
    _syncAppLoop();
    notifyListeners();
  }

  /// เหลืออีกกี่วินาทีถึงจะยิงได้อีกครั้ง (0 = ยิงได้เลย)
  /// หน้าจอใช้ปิดปุ่มพร้อมนับถอยหลัง แทนที่จะให้กดแล้วเงียบ
  int get apiCooldownSeconds => _api.cooldownRemaining.inSeconds;

  // ══════════════════════════════════════════════════════════
  // ตัวช่วยภายใน
  // ══════════════════════════════════════════════════════════

  /// จองงานหนึ่งชิ้น — คืน false ถ้ากำลังทำอยู่แล้ว (กันกดรัว)
  bool _beginTask(String key) {
    if (_busy.contains(key)) return false;
    _busy.add(key);
    _clearErrorSilently();
    notifyListeners();
    return true;
  }

  void _endTask(String key) => _busy.remove(key);

  /// แทนบอทหนึ่งตัวในสถานะปัจจุบัน (ใช้กับ optimistic update)
  void _replaceBot(AiBot bot) {
    final s = _status;
    if (s == null || s.bot(bot.id) == null) return;
    _status = s.withBot(bot);
  }

  void _clearErrorSilently() {
    _errorCode = null;
    _errorMessage = null;
    _fieldErrors = const {};
  }

  /// ล้างข้อผิดพลาดจากภายนอก (เช่น ผู้ใช้ปิดแบนเนอร์)
  void clearError() {
    if (_errorCode == null) return;
    _clearErrorSilently();
    notifyListeners();
  }

  /// บันทึกความล้มเหลว — ทุกเส้นทางที่พลาดต้องผ่านตรงนี้ ห้ามกลืนเงียบ
  void _recordFailure(ApiErr err) {
    _errorCode = err.code;
    _errorMessage = err.message;
    _fieldErrors = err.fieldErrors;

    if (err.needsWalletSign) {
      _needsVerification = true;
      // ยิงต่อไปก็ 403 ซ้ำๆ — หยุดลูปไว้ก่อน รอผู้ใช้เซ็นใหม่
      _stopAppLoop();
    }

    if (err.isThrottled) {
      final seconds = err.retryAfterSeconds ?? 30;
      _throttledUntil = DateTime.now().add(Duration(seconds: seconds));
      _stopAppLoop();
      if (kDebugMode) debugPrint('[AiBot] throttled for ${seconds}s');
    }
  }

  /// ยิงคำขอที่ต้องใช้กระเป๋า พร้อมกู้สถานะเมื่อลายเซ็นหมดอายุ
  ///
  /// ลองใหม่ **ครั้งเดียว** ห้ามวนลูป — ถ้ายังไม่ผ่านต้องคืน error เดิม
  /// ให้หน้าจอเป็นคนอธิบายและเสนอปุ่มเซ็น
  Future<ApiResult<T>> _run<T>(
    Future<ApiResult<T>> Function(String wallet) call, {
    bool allowResign = true,
  }) async {
    final wallet = _address;
    if (wallet == null) {
      return ApiErr<T>('NO_WALLET', 'กรุณาเชื่อมกระเป๋าก่อน');
    }

    var res = await call(wallet);

    final err = res.errorOrNull;
    if (err != null && err.needsWalletSign && allowResign) {
      if (await _silentResign()) {
        // ระหว่างรอลายเซ็น ผู้ใช้อาจสลับกระเป๋าไปแล้ว
        if (_address == wallet) {
          res = await call(wallet);
        }
      }
    }
    return res;
  }

  // ══════════════════════════════════════════════════════════

  @override
  void dispose() {
    _statusTimer?.cancel();
    _statusTimer = null;
    _tickTimer?.cancel();
    _tickTimer = null;
    _wallet?.removeListener(_onWalletChanged);
    _wallet = null;
    super.dispose();
  }
}

/// Developed by Xman Studio
