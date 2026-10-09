/// TPIX TRADE — หน้า "ยืนยันตัวตน (KYC)"
///
/// เดิมแอปไม่มีหน้านี้ ด่าน KYC_REQUIRED พาผู้ใช้ออกไปหน้าเว็บ tpix.online/kyc
/// ซึ่งไม่ได้ล็อกอินค้างไว้ในเบราว์เซอร์ → ต้องเชื่อมกระเป๋าบนเว็บใหม่ทั้งหมด
/// ส่วนใหญ่เลิกกลางทาง ตอนนี้ยืนยันได้ในแอปโดยใช้ผล KYC ของบัญชี Thaiprompt
///
/// เส้นทางของผู้ใช้ (ทางหลัก)
///   1. ติ๊กยินยอม → กด "ยืนยันด้วย Thaiprompt" → `start` ได้ URL หน้าอนุญาต
///   2. เปิด URL ใน **เบราว์เซอร์ของระบบ** (ไม่ใช่ WebView ในแอป) เพราะต้องใช้
///      คุกกี้ล็อกอิน Thaiprompt ที่ผู้ใช้มีอยู่แล้ว และให้ระบบปฏิบัติการเป็นคนพา
///      redirect `tpixtrade://kyc?...` กลับเข้าแอป
///   3. DeepLinkService แลกรหัสรับผล (`complete`) → [KycStore] → หน้านี้อัปเดตเอง
///   4. ถ้ายังไม่เคยทำ eKYC ใน Thaiprompt: หน้านี้กลายเป็น "รอผล" — พาไปแอป
///      Thaiprompt แล้วถามผลซ้ำทุก 20 วินาทีระหว่างเปิดหน้า (สูงสุด 15 นาที)
///      และทันทีที่กลับเข้าแอป
///
/// ทางสำรองเสมอ (ยกเว้นผ่านแล้ว): ส่งเอกสารที่หน้าเว็บ — สำหรับคนไม่มีบัญชี
/// Thaiprompt และชาวต่างชาติ (แอปยังไม่มีหน้าอัปโหลดบัตร)
///
/// Developed by Xman Studio
library;

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/locale/locale_provider.dart';
import '../../core/theme/app_colors.dart';
import '../../models/kyc_models.dart';
import '../../providers/accent_provider.dart';
import '../../providers/kyc_store.dart';
import '../../providers/wallet_provider.dart';
import '../../services/api_result.dart';
import '../../services/kyc_api.dart';
import '../../widgets/common/app_background.dart';
import '../../widgets/common/glass_card.dart';
import '../../widgets/common/gradient_button.dart';
import '../../widgets/common/shimmer_loading.dart';
import '../../widgets/wallet/wallet_connect_sheet.dart';

/// เปิดลิงก์ภายนอก (เบราว์เซอร์ / แอปอื่น) — แยกเป็นชนิดให้เทสต์ส่งตัวปลอมได้
typedef KycExternalOpener = Future<bool> Function(Uri uri);

/// ข้อความ 2 ภาษาของความล้มเหลวจาก `/kyc/*` — ห้ามคืนค่าว่าง และห้ามโชว์
/// ข้อความ exception ดิบ (ทุกทางลงจบที่ข้อความของแอปเอง)
String kycErrorText(LocaleProvider locale, ApiErr err) {
  final secs = err.retryAfterSeconds;
  if (err.isThrottled && secs != null && secs > 0) {
    return locale.tp('kyc.err.waitSeconds', {'s': secs});
  }
  final key = 'kyc.err.${err.code}';
  if (locale.has(key)) return locale.t(key);
  final status = err.status;
  if (status != null && status >= 500) return locale.t('kyc.err.SERVER');
  // รหัสที่แอปยังไม่รู้จัก: ข้อความของเซิร์ฟเวอร์เป็นภาษาไทยล้วน — ใช้ได้เฉพาะเมื่อ
  // ผู้ใช้ตั้งภาษาไทย และไม่ใช่หน้า error ดิบของ Laravel (HTTP_404 ฯลฯ)
  final msg = err.message.trim();
  if (locale.isThai && msg.isNotEmpty && !err.code.startsWith('HTTP_')) {
    return msg;
  }
  return locale.t('kyc.err.UNKNOWN');
}

class KycScreen extends StatefulWidget {
  /// ไม่ส่ง = ใช้ตัวจริงของแอป ([KycApi] · [KycStore.I] · url_launcher)
  final KycApi? api;
  final KycStore? store;
  final KycExternalOpener? openExternal;

  const KycScreen({super.key, this.api, this.store, this.openExternal});

  /// ถามผลซ้ำทุกเท่าไหร่ระหว่างรอผู้ใช้ทำ eKYC
  /// (เซิร์ฟเวอร์ไม่ถาม Thaiprompt ซ้ำถ้าห่างกันไม่ถึง 10 วินาที และจำกัด 20 ครั้ง/นาที)
  static const Duration pollInterval = Duration(seconds: 20);

  /// ถามซ้ำนานสุดเท่านี้ต่อรอบ — eKYC ใช้ไม่กี่นาที เกินนี้คือผู้ใช้วางมือถือทิ้งไว้
  static const Duration pollWindow = Duration(minutes: 15);

  /// ห้ามยิง refresh ถี่กว่านี้เอง (ตรงกับช่วงที่เซิร์ฟเวอร์จะคืนค่าที่จำไว้อยู่ดี)
  static const Duration minRefreshGap = Duration(seconds: 10);

  @override
  State<KycScreen> createState() => _KycScreenState();
}

class _KycScreenState extends State<KycScreen> with WidgetsBindingObserver {
  late final KycApi _api = widget.api ?? KycApi();
  late final KycStore _store = widget.store ?? KycStore.I;
  late final WalletProvider _wallet;

  /// กระเป๋าที่หน้านี้กำลังแสดง — ทุกคำตอบที่กลับมาต้องเทียบกับตัวนี้ก่อนใช้
  /// (ผู้ใช้สลับกระเป๋าระหว่างรอได้ ห้ามเอาผลของกระเป๋าเก่าไปวาดให้กระเป๋าใหม่)
  String? _address;

  // ── สถานะการโหลด ──
  Future<void>? _loadFuture;
  ApiErr? _loadError;
  bool _needsSign = false;
  bool _ipMismatch = false;
  int _seenRevision = -1;

  // ── ปุ่มต่างๆ (กันกดรัว: ปุ่มกดไม่ได้ระหว่างที่งานของมันยังไม่จบ) ──
  bool _verifying = false;
  bool _consent = false;
  bool _starting = false;
  String? _startError;
  bool _refreshing = false;
  String? _refreshNote;
  bool _openingApp = false;
  bool _appMissing = false;

  /// กำลังส่งลิงก์เว็บ/ดาวน์โหลดให้ระบบเปิด — กดรัว = เปิดเบราว์เซอร์ซ้อนหลายแท็บ
  bool _openingLink = false;

  /// ผู้ใช้ออกไปเบราว์เซอร์/หน้าเว็บจากปุ่มของหน้านี้ — กลับมาแล้วควรโหลดใหม่
  bool _awaitingReturn = false;

  // ── ถามผลซ้ำอัตโนมัติ ──
  Timer? _pollTimer;
  DateTime? _pollStartedAt;
  bool _pollStopped = false;
  DateTime? _lastRefreshAt;

  /// หน้าอยู่บนสุดของ navigator ไหม (มีหน้าอื่นทับ = ไม่ต้องถาม)
  bool _visible = true;

  /// แอปอยู่หน้าจอไหม (อยู่เบื้องหลัง = ไม่ต้องถาม)
  bool _resumed = true;

  KycStatus? get _status => _store.statusFor(_address);

  KycView get _view => resolveKycView(
        walletConnected: _address != null,
        needsWalletSign: _needsSign,
        status: _status,
        loadFailed: _loadError != null,
      );

  @override
  void initState() {
    super.initState();
    _wallet = context.read<WalletProvider>();
    _address = _wallet.address;
    _seenRevision = _store.revision;
    _wallet.addListener(_onWalletChanged);
    _store.addListener(_onStoreChanged);
    WidgetsBinding.instance.addObserver(this);

    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      // deep link กำลังแลกรหัสอยู่ — รอผลจากตรงนั้น (คำตอบของ GET ที่ยิงตอนนี้
      // อาจเก่ากว่า) พอแลกเสร็จ _onStoreChanged จะโหลดให้เองถ้ายังไม่มีข้อมูล
      if (_store.isCompleting) return;
      _load();
    });
  }

  /*
   * ใช้ TickerMode เป็นสัญญาณว่า "ผู้ใช้เห็นหน้านี้อยู่ไหม" แบบเดียวกับหน้า AI TRADE
   *
   * หน้านี้ถูก push ทับ shell — ถ้ามีหน้าอื่น push ทับอีกชั้น (เช่น ตั้งค่า)
   * Overlay จะปิด TickerMode ของหน้านี้ให้เอง จึงหยุดถามผลได้โดยไม่ต้องพึ่ง RouteAware
   *
   * ใช้ `TickerMode.of` ไม่ใช่ `valuesOf` — CI พิน Flutter 3.38.5 ที่ยังไม่มี valuesOf
   */
  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    // ignore: deprecated_member_use
    final visible = TickerMode.of(context);
    if (visible == _visible) return;
    _visible = visible;
    _syncPolling();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed) {
      // ปิดแอป/สลับแอป = หยุดถาม ไม่งั้นกินโควตา (20 ครั้ง/นาที) และแบตฟรีๆ
      _resumed = false;
      _cancelTimer();
      return;
    }
    _resumed = true;
    // ระหว่างเซ็นยืนยันกระเป๋า แอปเด้งไป TPIX Wallet แล้วกลับมา — งานนั้นโหลดเองอยู่แล้ว
    if (!mounted || _verifying || !_visible || _address == null) return;

    if (_view == KycView.waitingThaiprompt) {
      // กลับมาจากแอป Thaiprompt = จังหวะที่ผลน่าจะเปลี่ยนที่สุด ถามทันที
      // และนับเวลาถามอัตโนมัติใหม่ (ผู้ใช้เพิ่งกลับมาทำต่อ ไม่ใช่วางทิ้งไว้)
      _restartPollWindow();
      final last = _lastRefreshAt;
      if (last == null ||
          DateTime.now().difference(last) >= KycScreen.minRefreshGap) {
        _refreshThaiprompt();
      }
    } else if (_awaitingReturn && !_store.isCompleting) {
      // กลับจากหน้าเว็บ/เบราว์เซอร์ที่เปิดจากปุ่มของหน้านี้ — อาจส่งเอกสารมาแล้ว
      _load();
    }
    _awaitingReturn = false;
    _syncPolling();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _wallet.removeListener(_onWalletChanged);
    _store.removeListener(_onStoreChanged);
    _cancelTimer();
    super.dispose();
  }

  // ══════════════════════════════════════════════════════════════
  //  ผู้ฟัง
  // ══════════════════════════════════════════════════════════════

  void _onWalletChanged() {
    final next = _wallet.address;
    if (next == _address || !mounted) return;
    // สลับ/ตัด/เชื่อมกระเป๋าระหว่างเปิดหน้านี้ — ทุกอย่างของกระเป๋าเก่าต้องทิ้ง
    _cancelTimer();
    setState(() {
      _address = next;
      _needsSign = false;
      _ipMismatch = false;
      _loadError = null;
      _startError = null;
      _refreshNote = null;
      _appMissing = false;
      _pollStopped = false;
      _pollStartedAt = null;
    });
    if (next != null) _load();
  }

  void _onStoreChanged() {
    if (!mounted) return;
    final rev = _store.revision;
    setState(() {
      // payload ใหม่เข้ามา (เช่น deep link แลกรหัสสำเร็จ) = กระเป๋าผ่านการยืนยันแล้ว
      if (rev != _seenRevision && _status != null) {
        _needsSign = false;
        _ipMismatch = false;
        _loadError = null;
      }
      _seenRevision = rev;
    });
    _syncPolling();

    // แลกรหัสจบแล้วแต่ยังไม่มีข้อมูลของกระเป๋านี้ (แลกไม่ผ่าน) — โหลดเองให้จอไม่ค้างโครงกระดูก
    if (!_store.isCompleting &&
        _status == null &&
        _loadFuture == null &&
        _address != null) {
      _load();
    }
  }

  // ══════════════════════════════════════════════════════════════
  //  การโหลด
  // ══════════════════════════════════════════════════════════════

  /// โหลดสถานะ — ถ้ามีรอบค้างอยู่ คืนรอบนั้นแทนการยิงซ้อน
  Future<void> _load() =>
      _loadFuture ??= _doLoad().whenComplete(() => _loadFuture = null);

  Future<void> _doLoad() async {
    final wallet = _address;
    if (wallet == null) return;
    final rev = _store.revision;

    final res = await _api.fetchStatus(wallet);
    if (!mounted || wallet != _address) return;

    switch (res) {
      case ApiOk<KycStatus>(:final data):
        // ระหว่างรอ มีของใหม่กว่ามาแล้ว (deep link แลกรหัส) — ทิ้งคำตอบนี้ ไม่งั้น
        // สถานะ "ยังไม่เชื่อม" ที่ยิงไปก่อนจะทับ "เชื่อมแล้ว" ที่เพิ่งได้
        if (_store.revision == rev) _store.update(wallet, data);
        setState(() {
          _needsSign = false;
          _ipMismatch = false;
          _loadError = null;
        });
        _syncPolling();
        // มีรหัสรับผลค้างจากตอนที่ยังไม่พร้อม (ยังไม่เชื่อม/ยังไม่เซ็น) — แลกต่อให้เลย
        unawaited(_completePendingIfAny());
      case ApiErr<KycStatus>():
        setState(() {
          if (res.needsWalletSign) {
            _needsSign = true;
            _ipMismatch = res.isIpMismatch;
            _loadError = null;
          } else {
            _loadError = res;
          }
        });
        if (res.needsWalletSign) _cancelTimer();
    }
  }

  Future<void> _completePendingIfAny() async {
    final wallet = _address;
    if (wallet == null || _store.isCompleting || !_store.hasPendingFor(wallet)) {
      return;
    }
    final outcome = await _store.completePending(api: _api, wallet: wallet);
    if (!mounted || outcome == null) return;
    if (outcome == KycCompleteOutcome.needsWalletSign) {
      setState(() => _needsSign = true);
    }
    final key = outcome.messageKey;
    if (key != null) {
      _snack(context.read<LocaleProvider>().t(key), ok: outcome.isSuccess);
    }
  }

  Future<void> _onPull() async {
    if (_address == null) return;
    if (_view == KycView.waitingThaiprompt) {
      await _refreshThaiprompt(manual: true);
      return;
    }
    await _load();
    if (!mounted) return;
    // มีข้อมูลเก่าอยู่บนจอแล้ว — ต้องบอกว่ารีเฟรชไม่สำเร็จ ไม่งั้นผู้ใช้คิดว่าเป็นของล่าสุด
    final err = _loadError;
    if (err != null && _status != null) {
      _snack(kycErrorText(context.read<LocaleProvider>(), err));
    }
  }

  // ══════════════════════════════════════════════════════════════
  //  การกระทำ
  // ══════════════════════════════════════════════════════════════

  void _back() {
    if (context.canPop()) {
      context.pop();
    } else {
      context.go('/home');
    }
  }

  void _openConnectSheet() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      builder: (_) => const WalletConnectSheet(),
    );
  }

  /// เซ็นยืนยันกระเป๋าใหม่ แล้วพิสูจน์ด้วยการโหลดสถานะ
  ///
  /// ตัดสินผลจากการโหลดผ่านหรือไม่ ไม่ใช่ค่าที่ปุ่มเซ็นคืนมา — ลายเซ็นที่ถูก
  /// ปฏิเสธ/หมดเวลาไม่ throw (WalletProvider กลืนไว้) เชื่อผลปุ่ม = ขึ้น "สำเร็จ"
  /// ทั้งที่ยังเข้าไม่ได้ (บทเรียนเดียวกับ AiBotProvider.verifyWallet)
  Future<void> _verifyWallet() async {
    if (_verifying) return;
    HapticFeedback.selectionClick();
    setState(() => _verifying = true);

    try {
      await _wallet.verifyWithBackend();
    } catch (e) {
      debugPrint('[Kyc] verify: ${e.runtimeType}');
    }
    if (!mounted) return;

    // รอบที่ค้างอยู่อาจยิงไปก่อนลายเซ็นผ่าน — รอให้จบแล้วยิงของเราเองใหม่
    final inFlight = _loadFuture;
    if (inFlight != null) await inFlight;
    if (!mounted) return;
    await _load();
    if (!mounted) return;

    setState(() => _verifying = false);
    final locale = context.read<LocaleProvider>();
    final err = _loadError;
    if (!_needsSign && err == null) {
      _snack(locale.t('kyc.sign.ok'), ok: true);
    } else if (err != null) {
      _snack(kycErrorText(locale, err));
    } else {
      _snack(locale.t('kyc.sign.failed'));
    }
  }

  Future<void> _startThaiprompt() async {
    if (_starting) return;
    if (!_consent) {
      setState(() =>
          _startError = context.read<LocaleProvider>().t('kyc.tp.consentRequired'));
      return;
    }
    final wallet = _address;
    if (wallet == null) {
      _openConnectSheet();
      return;
    }

    HapticFeedback.selectionClick();
    setState(() {
      _starting = true;
      _startError = null;
    });

    final res = await _api.startThaiprompt(wallet);
    if (!mounted) return;
    if (wallet != _address) {
      setState(() => _starting = false);
      return;
    }

    switch (res) {
      case ApiOk<Uri>(:final data):
        _awaitingReturn = true;
        final opened = await _openExternal(data);
        if (!mounted) return;
        setState(() {
          _starting = false;
          if (!opened) {
            _awaitingReturn = false;
            _startError = context.read<LocaleProvider>().t('kyc.browserFailed');
          }
        });
      case ApiErr<Uri>():
        final locale = context.read<LocaleProvider>();
        setState(() {
          _starting = false;
          if (res.needsWalletSign) {
            _needsSign = true;
            _ipMismatch = res.isIpMismatch;
          } else if (res.fieldError('consent') != null) {
            _startError = locale.t('kyc.tp.consentRequired');
          } else {
            _startError = kycErrorText(locale, res);
          }
        });
    }
  }

  /// ถามผลจาก Thaiprompt ซ้ำ — [manual] = ผู้ใช้กดเอง (แจ้งผลทุกกรณี)
  Future<void> _refreshThaiprompt({bool manual = false}) async {
    if (_refreshing) return;
    final wallet = _address;
    if (wallet == null) return;
    if (manual) {
      HapticFeedback.selectionClick();
      _restartPollWindow();
    }

    setState(() {
      _refreshing = true;
      if (manual) _refreshNote = null;
    });
    final rev = _store.revision;
    final wasApproved = _status?.isApproved == true;

    final res = await _api.refreshThaiprompt(wallet);
    _lastRefreshAt = DateTime.now();
    if (!mounted) return;
    if (wallet != _address) {
      setState(() => _refreshing = false);
      return;
    }

    switch (res) {
      case ApiOk<KycStatus>(:final data):
        if (_store.revision == rev) _store.update(wallet, data);
        setState(() {
          _refreshing = false;
          _refreshNote = null;
        });
        if (data.isApproved && !wasApproved) {
          _cancelTimer();
          // ผ่านแล้ว — โหลดสถานะเต็มอีกรอบให้ด่านทุกฟีเจอร์เป็นของล่าสุดแน่ๆ
          await _load();
          if (!mounted) return;
          _snack(context.read<LocaleProvider>().t('kyc.link.approved'), ok: true);
        } else if (data.thaipromptLink?.isApproved == true && !data.isApproved) {
          // Thaiprompt ผ่านแล้วแต่ใบของเรายังไม่ขยับ — ถามสถานะเต็มอีกรอบ
          await _load();
        }
      case ApiErr<KycStatus>():
        final attached = kycStatusAttachedTo(res);
        if (attached != null && _store.revision == rev) {
          _store.update(wallet, attached);
        }
        final locale = context.read<LocaleProvider>();
        final text = kycErrorText(locale, res);
        final stop = res.needsWalletSign ||
            res.isThrottled ||
            res.code == KycErrorCodes.thaipromptReconnect ||
            res.code == KycErrorCodes.thaipromptNotLinked;
        if (stop) _cancelTimer();
        setState(() {
          _refreshing = false;
          if (res.needsWalletSign) {
            _needsSign = true;
            _ipMismatch = res.isIpMismatch;
          }
          // โดนจำกัดอัตรา = หยุดถามอัตโนมัติจนกว่าผู้ใช้จะกดเอง/กลับเข้าแอปใหม่
          if (res.isThrottled) _pollStopped = true;
          _refreshNote = text;
        });
        if (manual) _snack(text);
        // เซิร์ฟเวอร์บอกว่ายังไม่ได้เชื่อม / ต้องเชื่อมใหม่แต่ไม่แนบสถานะมา — ถามสถานะเต็ม
        if (res.code == KycErrorCodes.thaipromptNotLinked ||
            (res.code == KycErrorCodes.thaipromptReconnect && attached == null)) {
          unawaited(_load());
        }
    }
  }

  Future<void> _openThaipromptApp(ThaipromptInfo tp) async {
    if (_openingApp) return;
    HapticFeedback.selectionClick();
    setState(() => _openingApp = true);
    final uri = tp.appLinkUri;
    final ok = uri != null && await _openExternal(uri);
    if (!mounted) return;
    setState(() {
      _openingApp = false;
      _appMissing = !ok;
    });
    if (!ok) _snack(context.read<LocaleProvider>().t('kyc.appMissing'));
  }

  Future<void> _openWebKyc(KycStatus? status) async {
    if (_openingLink) return;
    final uri = status?.webKycUri ?? Uri.parse(KycStatus.defaultWebKycUrl);
    _openingLink = true;
    _awaitingReturn = true;
    final ok = await _openExternal(uri);
    _openingLink = false;
    if (!mounted) return;
    if (!ok) {
      _awaitingReturn = false;
      _snack(context.read<LocaleProvider>().t('kyc.browserFailed'));
    }
  }

  Future<void> _openDownload(Uri uri) async {
    if (_openingLink) return;
    _openingLink = true;
    final ok = await _openExternal(uri);
    _openingLink = false;
    if (!mounted) return;
    if (!ok) _snack(context.read<LocaleProvider>().t('kyc.browserFailed'));
  }

  /// เปิดลิงก์นอกแอป — ไม่ throw และไม่ log URL เต็ม (URL หน้าอนุญาตมี state ของ OAuth)
  Future<bool> _openExternal(Uri uri) async {
    try {
      final opener = widget.openExternal;
      if (opener != null) return await opener(uri);
      return await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (e) {
      debugPrint('[Kyc] open ${uri.scheme}: ${e.runtimeType}');
      return false;
    }
  }

  void _snack(String message, {bool ok = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          content: Text(message),
          // ทองแทนเขียว — เขียว/แดงสงวนไว้ให้ราคาขึ้นลงและกำไรขาดทุน
          backgroundColor: ok ? AppColors.gold3 : null,
          duration: Duration(seconds: ok ? 3 : 4),
        ),
      );
  }

  // ══════════════════════════════════════════════════════════════
  //  ถามผลซ้ำอัตโนมัติ
  // ══════════════════════════════════════════════════════════════

  bool get _shouldPoll =>
      _visible &&
      _resumed &&
      !_verifying &&
      !_pollStopped &&
      _view == KycView.waitingThaiprompt;

  void _syncPolling() {
    if (!mounted) return;
    if (_shouldPoll) {
      if (_pollTimer != null) return;
      _pollStartedAt ??= DateTime.now();
      _pollTimer = Timer.periodic(KycScreen.pollInterval, (_) => _onPollTick());
    } else {
      _cancelTimer();
    }
  }

  void _onPollTick() {
    if (!mounted) {
      _cancelTimer();
      return;
    }
    final started = _pollStartedAt;
    if (started != null &&
        DateTime.now().difference(started) >= KycScreen.pollWindow) {
      // ครบ 15 นาที — หยุด แล้วบอกผู้ใช้ว่าต้องกดเองเมื่อทำเสร็จ
      _cancelTimer();
      setState(() => _pollStopped = true);
      return;
    }
    if (!_shouldPoll) {
      _cancelTimer();
      return;
    }
    _refreshThaiprompt();
  }

  void _restartPollWindow() {
    _pollStartedAt = DateTime.now();
    _pollStopped = false;
  }

  void _cancelTimer() {
    _pollTimer?.cancel();
    _pollTimer = null;
  }

  // ══════════════════════════════════════════════════════════════
  //  หน้าจอ
  // ══════════════════════════════════════════════════════════════

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final accent = context.watch<AccentProvider>();
    final status = _status;
    final view = _view;

    return Scaffold(
      backgroundColor: Colors.transparent,
      body: AppBackground(
        child: SafeArea(
          bottom: false,
          child: RefreshIndicator(
            color: accent.g2,
            backgroundColor: AppColors.bgSecondary,
            onRefresh: _onPull,
            child: CustomScrollView(
              physics: const AlwaysScrollableScrollPhysics(),
              slivers: [
                SliverToBoxAdapter(
                  child: _Header(locale: locale, onBack: _back),
                ),
                if (_store.isCompleting)
                  _block(_CompletingBanner(text: locale.t('kyc.completing'))),
                ..._content(view, status, locale),
                const SliverToBoxAdapter(child: SizedBox(height: 40)),
              ],
            ),
          ),
        ),
      ),
    );
  }

  List<Widget> _content(KycView view, KycStatus? status, LocaleProvider locale) {
    switch (view) {
      case KycView.noWallet:
        return [
          _block(_Notice(
            icon: Icons.account_balance_wallet_rounded,
            title: locale.t('kyc.noWallet.title'),
            body: locale.t('kyc.noWallet.body'),
            actionLabel: locale.t('kyc.connectWallet'),
            actionIcon: Icons.link_rounded,
            onAction: _openConnectSheet,
          )),
          _block(_fallback(status, locale)),
        ];

      case KycView.needsWalletSign:
        return [
          _block(_Notice(
            icon: Icons.verified_user_rounded,
            title: locale.t('kyc.sign.title'),
            body: locale.t(_ipMismatch ? 'kyc.sign.bodyIp' : 'kyc.sign.body'),
            actionLabel: _verifying
                ? locale.t('kyc.sign.waiting')
                : locale.t('kyc.sign.button'),
            actionIcon: Icons.draw_rounded,
            isLoading: _verifying,
            onAction: _verifying ? null : _verifyWallet,
          )),
          _block(_fallback(status, locale)),
        ];

      case KycView.loading:
        return [_block(const _LoadingSkeleton())];

      case KycView.loadFailed:
        return [
          _block(_Notice(
            tone: _NoticeTone.problem,
            icon: Icons.cloud_off_rounded,
            title: locale.t('kyc.loadFailed.title'),
            body: _loadError == null
                ? locale.t('kyc.err.UNKNOWN')
                : kycErrorText(locale, _loadError!),
            actionLabel: locale.t('kyc.retry'),
            actionIcon: Icons.refresh_rounded,
            isLoading: _loadFuture != null,
            onAction: _loadFuture != null ? null : _load,
          )),
          _block(_fallback(status, locale)),
        ];

      case KycView.approved:
        return [
          _block(_ApprovedCard(status: status!, locale: locale)),
          _block(_FeatureList(status: status, locale: locale, approved: true)),
          _block(_PrivacyNote(text: locale.t('kyc.privacy'))),
        ];

      case KycView.connectThaiprompt:
        return [
          ..._submissionNotice(status!, locale),
          _block(_connectCard(status, locale)),
          _block(_FeatureList(status: status, locale: locale, approved: false)),
          _block(_fallback(status, locale)),
          _block(_PrivacyNote(text: locale.t('kyc.privacy'))),
        ];

      case KycView.waitingThaiprompt:
        return [
          ..._submissionNotice(status!, locale),
          _block(_waitingCard(status, locale)),
          _block(_FeatureList(status: status, locale: locale, approved: false)),
          _block(_fallback(status, locale)),
          _block(_PrivacyNote(text: locale.t('kyc.privacy'))),
        ];

      case KycView.webOnly:
        return [
          ..._submissionNotice(status!, locale),
          _block(_Notice(
            tone: _NoticeTone.action,
            icon: Icons.upload_file_rounded,
            title: locale.t('kyc.web.title'),
            body: locale.t('kyc.web.body'),
            actionLabel: locale.t('kyc.fallback.button'),
            actionIcon: Icons.open_in_new_rounded,
            onAction: () => _openWebKyc(status),
          )),
          _block(_FeatureList(status: status, locale: locale, approved: false)),
        ];
    }
  }

  /// ใบที่ส่งทางเว็บยังรอตรวจ/ไม่ผ่าน — ต้องบอก ไม่งั้นผู้ใช้ส่งซ้ำเพราะคิดว่าหาย
  List<Widget> _submissionNotice(KycStatus status, LocaleProvider locale) {
    final sub = status.submission;
    if (sub == null || sub.viaThaiprompt) return const [];
    if (sub.isPending) {
      return [
        _block(_Notice(
          icon: Icons.hourglass_top_rounded,
          title: locale.t('kyc.sub.pending'),
          body: sub.submittedAt == null
              ? ''
              : _dateText(locale, sub.submittedAt!),
        )),
      ];
    }
    if (sub.isRejected) {
      final reason = sub.rejectReason;
      return [
        _block(_Notice(
          tone: _NoticeTone.problem,
          icon: Icons.error_outline_rounded,
          title: locale.t('kyc.sub.rejected'),
          body: reason == null ? '' : locale.tp('kyc.sub.reason', {'reason': reason}),
        )),
      ];
    }
    return const [];
  }

  Widget _connectCard(KycStatus status, LocaleProvider locale) {
    final accent = context.watch<AccentProvider>();
    final reconnect = status.thaipromptLink?.needsReconnect == true;

    return GlassCard(
      variant: GlassVariant.gold,
      borderRadius: 18,
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(Icons.badge_rounded, size: 22, color: accent.g2),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  locale.t('kyc.tp.title'),
                  style: GoogleFonts.inter(
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                    color: AppColors.textPrimary,
                  ),
                ),
              ),
              _Pill(label: locale.t('kyc.tp.recommended')),
            ],
          ),
          if (reconnect) ...[
            const SizedBox(height: 12),
            _InlineNote(
              icon: Icons.link_off_rounded,
              text: locale.t('kyc.tp.reconnect'),
              problem: true,
            ),
          ],
          const SizedBox(height: 14),
          _CaseRow(
            icon: Icons.bolt_rounded,
            title: locale.t('kyc.tp.caseVerified.title'),
            body: locale.t('kyc.tp.caseVerified.body'),
          ),
          const SizedBox(height: 10),
          _CaseRow(
            icon: Icons.phone_iphone_rounded,
            title: locale.t('kyc.tp.caseNew.title'),
            body: locale.t('kyc.tp.caseNew.body'),
          ),
          const SizedBox(height: 14),
          _ConsentRow(
            value: _consent,
            text: locale.t('kyc.tp.consent'),
            onChanged: _starting
                ? null
                : (v) => setState(() {
                      _consent = v;
                      if (v) _startError = null;
                    }),
          ),
          if (_startError != null) ...[
            const SizedBox(height: 10),
            _InlineNote(
              icon: Icons.error_outline_rounded,
              text: _startError!,
              problem: true,
            ),
          ],
          const SizedBox(height: 14),
          GradientButton(
            text: _starting ? locale.t('kyc.tp.starting') : locale.t('kyc.tp.start'),
            icon: Icons.verified_user_rounded,
            height: 48,
            isLoading: _starting,
            // ปิดไว้จนกว่าจะติ๊กยินยอม — ความยินยอมต้องมาจากผู้ใช้ ไม่ใช่ค่าเริ่มต้น
            onPressed: (_consent && !_starting) ? _startThaiprompt : null,
          ),
          const SizedBox(height: 10),
          Text(
            locale.t('kyc.tp.browserNote'),
            style: GoogleFonts.inter(
              fontSize: 11,
              color: AppColors.textTertiary,
              height: 1.5,
            ),
          ),
        ],
      ),
    );
  }

  Widget _waitingCard(KycStatus status, LocaleProvider locale) {
    final accent = context.watch<AccentProvider>();
    final link = status.thaipromptLink;
    final tp = status.thaiprompt;
    final linkStatus = link?.status ?? 'none';
    final rejected = link?.isRejected == true;
    final statusKey = switch (linkStatus) {
      'pending' => 'kyc.wait.status.pending',
      'rejected' => 'kyc.wait.status.rejected',
      'approved' => 'kyc.wait.status.approved',
      _ => 'kyc.wait.status.none',
    };
    final download = tp.downloadUri;
    final lastChecked = link?.lastCheckedAt;

    return GlassCard(
      variant: GlassVariant.gold,
      borderRadius: 18,
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(Icons.hourglass_top_rounded, size: 22, color: accent.g2),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  locale.t('kyc.wait.title'),
                  style: GoogleFonts.inter(
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                    color: AppColors.textPrimary,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          _InlineNote(
            icon: rejected ? Icons.error_outline_rounded : Icons.info_outline_rounded,
            text: locale.t(statusKey),
            problem: rejected,
          ),
          const SizedBox(height: 14),
          _Step(n: 1, text: locale.t('kyc.wait.step1')),
          _Step(n: 2, text: locale.t('kyc.wait.step2')),
          _Step(n: 3, text: locale.t('kyc.wait.step3')),
          _Step(n: 4, text: locale.t('kyc.wait.step4'), last: true),
          const SizedBox(height: 14),
          GradientButton(
            text: locale.t('kyc.wait.openApp'),
            icon: Icons.open_in_new_rounded,
            height: 48,
            isLoading: _openingApp,
            onPressed: _openingApp ? null : () => _openThaipromptApp(tp),
          ),
          if (_appMissing && download != null) ...[
            const SizedBox(height: 10),
            GradientButton(
              text: locale.t('kyc.downloadApp'),
              icon: Icons.download_rounded,
              variant: ButtonVariant.outline,
              height: 44,
              onPressed: () => _openDownload(download),
            ),
          ],
          const SizedBox(height: 10),
          GradientButton(
            text: _refreshing ? locale.t('kyc.wait.checking') : locale.t('kyc.wait.check'),
            icon: Icons.refresh_rounded,
            variant: ButtonVariant.outline,
            height: 44,
            isLoading: _refreshing,
            onPressed: _refreshing ? null : () => _refreshThaiprompt(manual: true),
          ),
          if (_refreshNote != null) ...[
            const SizedBox(height: 10),
            _InlineNote(
              icon: Icons.error_outline_rounded,
              text: _refreshNote!,
              problem: true,
            ),
          ],
          const SizedBox(height: 10),
          Text(
            [
              if (lastChecked != null)
                locale.tp('kyc.wait.lastChecked', {
                  'time': DateFormat('HH:mm').format(lastChecked.toLocal()),
                }),
              locale.t(_pollStopped ? 'kyc.wait.autoStopped' : 'kyc.wait.autoCheck'),
            ].join(' · '),
            style: GoogleFonts.inter(
              fontSize: 11,
              color: AppColors.textTertiary,
              height: 1.5,
            ),
          ),
        ],
      ),
    );
  }

  Widget _fallback(KycStatus? status, LocaleProvider locale) {
    final accent = context.watch<AccentProvider>();
    return GlassCard(
      variant: GlassVariant.standard,
      borderRadius: 16,
      padding: const EdgeInsets.fromLTRB(14, 12, 8, 12),
      child: Row(
        children: [
          Icon(Icons.public_rounded, size: 20, color: AppColors.textTertiary),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  locale.t('kyc.fallback.title'),
                  style: GoogleFonts.inter(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  locale.t('kyc.fallback.body'),
                  style: GoogleFonts.inter(
                    fontSize: 11,
                    color: AppColors.textTertiary,
                    height: 1.45,
                  ),
                ),
              ],
            ),
          ),
          TextButton(
            onPressed: () => _openWebKyc(status),
            child: Text(
              locale.t('kyc.fallback.button'),
              style: GoogleFonts.inter(
                fontSize: 12,
                fontWeight: FontWeight.w700,
                color: accent.g2,
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// ทุกบล็อกใช้ระยะขอบเดียวกัน — 18 ซ้ายขวา เว้นล่าง 12 (เหมือนหน้า AI TRADE)
  SliverToBoxAdapter _block(Widget child) => SliverToBoxAdapter(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(18, 0, 18, 12),
          child: child,
        ),
      );
}

/// วันที่แบบตัวเลข — ไม่ใช้ชื่อเดือนเพราะต้อง init ข้อมูลภาษาของ intl ก่อน
/// และตัวเลขอ่านได้เหมือนกันทั้งสองภาษา
String _dateText(LocaleProvider locale, DateTime at) => locale.tp(
      'kyc.approved.on',
      {'date': DateFormat('dd/MM/yyyy').format(at.toLocal())},
    );

// ═══════════════════════════════════════════════════════════════════════════
// ชิ้นส่วนหน้าจอ
// ═══════════════════════════════════════════════════════════════════════════

class _Header extends StatelessWidget {
  final LocaleProvider locale;
  final VoidCallback onBack;

  const _Header({required this.locale, required this.onBack});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(10, 10, 18, 14),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          GestureDetector(
            onTap: onBack,
            behavior: HitTestBehavior.opaque,
            child: Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: AppColors.bgCard,
                border: Border.all(color: AppColors.bgCardBorder, width: 1),
              ),
              child: Icon(Icons.chevron_left_rounded,
                  color: AppColors.textSecondary, size: 22),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    locale.t('kyc.title'),
                    style: GoogleFonts.inter(
                      fontSize: 18,
                      fontWeight: FontWeight.w800,
                      color: AppColors.textPrimary,
                      letterSpacing: -0.2,
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    locale.t('kyc.subtitle'),
                    style: GoogleFonts.inter(
                      fontSize: 11.5,
                      color: AppColors.textTertiary,
                      height: 1.45,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

enum _NoticeTone { action, problem }

/// แถบแจ้งสถานะ — ถ้าบอกปัญหา ต้องมีทางออกให้กดเสมอ (แบบเดียวกับหน้า AI TRADE)
class _Notice extends StatelessWidget {
  final IconData icon;
  final String title;
  final String body;
  final String? actionLabel;
  final IconData? actionIcon;
  final VoidCallback? onAction;
  final bool isLoading;
  final _NoticeTone tone;

  const _Notice({
    required this.icon,
    required this.title,
    required this.body,
    this.actionLabel,
    this.actionIcon,
    this.onAction,
    this.isLoading = false,
    this.tone = _NoticeTone.action,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final problem = tone == _NoticeTone.problem;
    final fill = problem ? AppColors.tradingRedBg : accent.goldTint;
    final edge = problem ? AppColors.tradingRed : accent.goldBorder;
    final iconColor = problem ? AppColors.tradingRed : accent.g2;

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: fill,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: edge, width: 1.4),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, size: 20, color: iconColor),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: GoogleFonts.inter(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color: AppColors.textPrimary,
                      ),
                    ),
                    if (body.isNotEmpty) ...[
                      const SizedBox(height: 3),
                      Text(
                        body,
                        style: GoogleFonts.inter(
                          fontSize: 11.5,
                          color: AppColors.textSecondary,
                          height: 1.55,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
          if (actionLabel != null) ...[
            const SizedBox(height: 12),
            GradientButton(
              text: actionLabel!,
              icon: actionIcon,
              height: 44,
              isLoading: isLoading,
              onPressed: onAction,
            ),
          ],
        ],
      ),
    );
  }
}

class _CompletingBanner extends StatelessWidget {
  final String text;
  const _CompletingBanner({required this.text});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: accent.goldTint,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: accent.goldBorder, width: 1),
      ),
      child: Row(
        children: [
          SizedBox(
            width: 16,
            height: 16,
            child: CircularProgressIndicator(strokeWidth: 2, color: accent.g2),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              text,
              style: GoogleFonts.inter(
                fontSize: 12.5,
                fontWeight: FontWeight.w600,
                color: AppColors.textPrimary,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ApprovedCard extends StatelessWidget {
  final KycStatus status;
  final LocaleProvider locale;

  const _ApprovedCard({required this.status, required this.locale});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final sub = status.submission;
    final via = sub?.viaThaiprompt == true
        ? locale.t('kyc.approved.viaThaiprompt')
        : locale.t('kyc.approved.viaDocuments');
    final level = (sub?.level ?? status.gate.approvedLevel) == KycLevel.enhanced
        ? locale.t('kyc.level.enhanced')
        : locale.t('kyc.level.basic');
    final date = sub?.effectiveDate;

    return GlassCard(
      variant: GlassVariant.hero,
      borderRadius: 22,
      padding: const EdgeInsets.all(20),
      child: Column(
        children: [
          Container(
            width: 64,
            height: 64,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: accent.goldGradient,
              boxShadow: [
                BoxShadow(
                  color: accent.goldGlow.withValues(alpha: 0.4),
                  blurRadius: 20,
                  spreadRadius: -4,
                ),
              ],
            ),
            child: Icon(Icons.verified_rounded,
                size: 34, color: AppColors.goldTextOn),
          ),
          const SizedBox(height: 14),
          Text(
            locale.t('kyc.approved.title'),
            textAlign: TextAlign.center,
            style: GoogleFonts.inter(
              fontSize: 18,
              fontWeight: FontWeight.w800,
              color: AppColors.textPrimary,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            '$via · $level',
            textAlign: TextAlign.center,
            style: GoogleFonts.inter(
              fontSize: 12.5,
              fontWeight: FontWeight.w600,
              color: accent.g2,
            ),
          ),
          if (date != null) ...[
            const SizedBox(height: 4),
            Text(
              _dateText(locale, date),
              textAlign: TextAlign.center,
              style: GoogleFonts.inter(
                fontSize: 11.5,
                color: AppColors.textTertiary,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// รายการบริการที่ต้องยืนยันตัวตน — โชว์เฉพาะตัวที่บังคับจริง
class _FeatureList extends StatelessWidget {
  final KycStatus status;
  final LocaleProvider locale;

  /// true = หน้า "ผ่านแล้ว" (หัวข้อบอกว่าปลดล็อกแล้ว)
  final bool approved;

  const _FeatureList({
    required this.status,
    required this.locale,
    required this.approved,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final features = status.gate.requiredFeatures;

    return GlassCard(
      variant: GlassVariant.standard,
      borderRadius: 16,
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            locale.t(approved ? 'kyc.features.unlocked' : 'kyc.features.required'),
            style: GoogleFonts.inter(
              fontSize: 12.5,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary,
            ),
          ),
          const SizedBox(height: 8),
          if (features.isEmpty)
            Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Text(
                locale.t('kyc.features.none'),
                style: GoogleFonts.inter(
                  fontSize: 11.5,
                  color: AppColors.textTertiary,
                ),
              ),
            )
          else
            for (final f in features)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Row(
                  children: [
                    Icon(
                      f.passed ? Icons.lock_open_rounded : Icons.lock_outline_rounded,
                      size: 16,
                      color: f.passed ? accent.g2 : AppColors.textTertiary,
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        locale.has(f.labelKey) ? locale.t(f.labelKey) : f.fallbackLabel,
                        style: GoogleFonts.inter(
                          fontSize: 12.5,
                          color: f.passed
                              ? AppColors.textPrimary
                              : AppColors.textSecondary,
                        ),
                      ),
                    ),
                    if (!f.passed && f.needsEnhanced)
                      Text(
                        locale.t('kyc.features.needsEnhanced'),
                        style: GoogleFonts.inter(
                          fontSize: 10.5,
                          fontWeight: FontWeight.w600,
                          color: AppColors.textTertiary,
                        ),
                      ),
                  ],
                ),
              ),
        ],
      ),
    );
  }
}

class _CaseRow extends StatelessWidget {
  final IconData icon;
  final String title;
  final String body;

  const _CaseRow({required this.icon, required this.title, required this.body});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 30,
          height: 30,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(9),
            color: accent.goldTint,
            border: Border.all(color: accent.goldBorder, width: 1),
          ),
          child: Icon(icon, size: 16, color: accent.g2),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: GoogleFonts.inter(
                  fontSize: 12.5,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
              ),
              const SizedBox(height: 2),
              Text(
                body,
                style: GoogleFonts.inter(
                  fontSize: 11.5,
                  color: AppColors.textSecondary,
                  height: 1.5,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// ช่องยินยอม — แตะได้ทั้งแถว (ช่องเล็กๆ บนมือถือกดยาก)
class _ConsentRow extends StatelessWidget {
  final bool value;
  final String text;
  final ValueChanged<bool>? onChanged;

  const _ConsentRow({
    required this.value,
    required this.text,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final enabled = onChanged != null;
    return Material(
      color: Colors.transparent,
      child: InkWell(
        key: const ValueKey('kyc-consent'),
        borderRadius: BorderRadius.circular(12),
        onTap: enabled ? () => onChanged!(!value) : null,
        child: Container(
          padding: const EdgeInsets.fromLTRB(4, 8, 10, 8),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(12),
            color: AppColors.bgInput,
            border: Border.all(
              color: value ? accent.goldBorder : AppColors.bgCardBorder,
              width: 1,
            ),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Checkbox(
                value: value,
                onChanged: enabled ? (v) => onChanged!(v ?? false) : null,
                activeColor: accent.g2,
                checkColor: AppColors.goldTextOn,
                side: BorderSide(color: AppColors.textTertiary, width: 1.4),
                materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                visualDensity: VisualDensity.compact,
              ),
              const SizedBox(width: 4),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Text(
                    text,
                    style: GoogleFonts.inter(
                      fontSize: 11.5,
                      color: AppColors.textSecondary,
                      height: 1.55,
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Step extends StatelessWidget {
  final int n;
  final String text;
  final bool last;

  const _Step({required this.n, required this.text, this.last = false});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Padding(
      padding: EdgeInsets.only(bottom: last ? 0 : 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 22,
            height: 22,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: accent.goldTint,
              border: Border.all(color: accent.goldBorder, width: 1),
            ),
            child: Text(
              '$n',
              style: GoogleFonts.inter(
                fontSize: 11,
                fontWeight: FontWeight.w800,
                color: accent.g2,
              ),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Text(
                text,
                style: GoogleFonts.inter(
                  fontSize: 12.5,
                  color: AppColors.textPrimary,
                  height: 1.45,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _InlineNote extends StatelessWidget {
  final IconData icon;
  final String text;
  final bool problem;

  const _InlineNote({
    required this.icon,
    required this.text,
    this.problem = false,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final color = problem ? AppColors.tradingRed : accent.g2;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        color: problem ? AppColors.tradingRedBg : AppColors.bgInput,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 15, color: color),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              text,
              style: GoogleFonts.inter(
                fontSize: 11.5,
                color: problem ? AppColors.tradingRed : AppColors.textSecondary,
                height: 1.5,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Pill extends StatelessWidget {
  final String label;
  const _Pill({required this.label});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        gradient: accent.goldGradient,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: GoogleFonts.inter(
          fontSize: 10,
          fontWeight: FontWeight.w800,
          color: AppColors.goldTextOn,
        ),
      ),
    );
  }
}

class _PrivacyNote extends StatelessWidget {
  final String text;
  const _PrivacyNote({required this.text});

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(Icons.lock_outline_rounded, size: 14, color: AppColors.textTertiary),
        const SizedBox(width: 8),
        Expanded(
          child: Text(
            text,
            style: GoogleFonts.inter(
              fontSize: 11,
              color: AppColors.textTertiary,
              height: 1.5,
            ),
          ),
        ),
      ],
    );
  }
}

/// โครงกระดูกตอนโหลดครั้งแรก — ไม่ใช่สปินเนอร์กลางจอ (แนวเดียวกับทั้งแอป)
class _LoadingSkeleton extends StatelessWidget {
  const _LoadingSkeleton();

  @override
  Widget build(BuildContext context) {
    return const Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ShimmerBox(width: double.infinity, height: 180, borderRadius: 18),
        SizedBox(height: 12),
        ShimmerBox(width: double.infinity, height: 110, borderRadius: 16),
        SizedBox(height: 12),
        ShimmerBox(width: double.infinity, height: 56, borderRadius: 16),
      ],
    );
  }
}

/// Developed by Xman Studio
