/// TPIX TRADE — AI TRADE (หน้าบอทคลาวด์)
/// หน้าจอหลักที่ผูกกับ `/api/v1/ai-bot/*` ของเซิร์ฟเวอร์จริง ผ่าน [AiBotProvider]
/// ไม่ใช่เครื่องคิดสัญญาณในเครื่องแบบหน้าเก่า — ทุกตัวเลขบนจอนี้มาจากคลาวด์
///
/// สิ่งที่หน้านี้รับผิดชอบ
///   • แถบสรุปบนสุดที่ตอบได้ใน 2 วินาทีว่า "ตอนนี้เป็นยังไง"
///   • สถานะครบทุกทาง: ยังไม่เชื่อมกระเป๋า / ยังไม่เซ็นยืนยัน / ปิดขาย / โหมดทีมงาน
///   • โครงกระดูกตอนโหลด (shimmer) ไม่ใช่สปินเนอร์กลางจอ
///   • สถานะว่างที่ชี้ทางต่อเสมอ — ไม่มีจอตายที่ไม่บอกอะไร
///   • ดึงลงเพื่อรีเฟรช + หยุด poll ทันทีเมื่อแอพเข้าพื้นหลัง (โควตา ~15 คำขอ/นาที)
///
/// ธงฟีเจอร์ทุกตัวอ่านจาก `catalog.features` ของเซิร์ฟเวอร์ ไม่มีการฮาร์ดโค้ด
/// และระหว่างที่แคตตาล็อกยังโหลดไม่เสร็จจะถือว่า "ปิดไว้ก่อน" (AiBotFeatures.closed)
///
/// Developed by Xman Studio
library;

import 'dart:math' as math;
import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/locale/locale_provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../models/ai_bot_models.dart';
import '../../providers/accent_provider.dart';
import '../../providers/ai_bot_provider.dart';
import '../../providers/market_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../widgets/ai_bot/ai_monitor.dart';
import '../../widgets/common/app_background.dart';
import '../../widgets/common/glass_card.dart';
import '../../widgets/common/gradient_button.dart';
import '../../widgets/common/shimmer_loading.dart';
import '../../widgets/wallet/wallet_connect_sheet.dart';

class AiBotScreen extends StatefulWidget {
  const AiBotScreen({super.key});

  @override
  State<AiBotScreen> createState() => _AiBotScreenState();
}

class _AiBotScreenState extends State<AiBotScreen> with WidgetsBindingObserver {
  late final AiBotProvider _bot;

  /// กันกดรัวรายบอท (คนละชั้นกับ provider.isWorking ที่กันทั้งหน้า)
  int? _busyBotId;

  @override
  void initState() {
    super.initState();
    _bot = context.read<AiBotProvider>();
    WidgetsBinding.instance.addObserver(this);

    // เลื่อนไปหลังเฟรมแรก — ให้ ScaffoldMessenger พร้อมก่อนมีอะไรเด้ง
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      _bot.bootstrap();
      _bot.startPolling();
      // ฟีดการตัดสินใจเป็นแผงหลักของหน้านี้ ไม่ใช่แท็บซ่อน จึงเปิดตั้งแต่เข้า
      _bot.setWatchDecisions(true);
      _loadAnalytics();
    });
  }

  /*
   * ⚠️ แท็บนี้อยู่ใน `StatefulShellRoute.indexedStack` (core/router.dart)
   *    ซึ่งเก็บทุกแท็บไว้ใน IndexedStack — สลับไปแท็บอื่น `dispose()` ไม่ถูกเรียก
   *    ถ้าพึ่ง dispose อย่างเดียว ลูป poll กับลูปเดินบอทแพลนฟรีจะวิ่งต่อไปเรื่อยๆ
   *    ตลอดเวลาที่ผู้ใช้อยู่หน้าอื่น = กินโควตา (~15 คำขอ/นาที/IP ที่แชร์กันทั้งแอพ)
   *    กินแบต และสั่งบอทเข้า/ออกไม้โดยที่ผู้ใช้ไม่เห็นหน้าจอและไม่ได้ตั้งใจ
   *
   * go_router ห่อ branch ที่ไม่ได้ใช้งานด้วย `TickerMode(enabled: false)` ให้อยู่แล้ว
   * จึงใช้ค่านั้นเป็นสัญญาณตรงๆ ว่าผู้ใช้เห็นหน้านี้อยู่หรือเปล่า
   */
  bool? _visible;

  /// เวลาที่แอพถูกพักไป — ใช้ตัดสินว่าตอนกลับมาควรรีเฟรชไหม
  DateTime? _leftAt;

  /// ออกไปนานเกินนี้ถึงจะรีเฟรชชุดใหญ่ตอนกลับเข้ามา
  static const _resumeRefreshAfter = Duration(seconds: 45);

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();

    /*
     * ใช้ `TickerMode.of` ไม่ใช่ `TickerMode.valuesOf`
     *
     * valuesOf เพิ่งมีใน Flutter รุ่นใหม่ — CI พินรุ่น 3.38.5 ไว้ซึ่งยังไม่มีเมธอดนี้
     * แล้ว analyze ล้มด้วย undefined_method ทั้งที่บนเครื่องนักพัฒนา (3.41) ผ่าน
     * ตัว `of` ถูกประกาศ deprecated ในรุ่นใหม่แต่ยังทำงานถูก และเป็นตัวเดียว
     * ที่คอมไพล์ผ่านทั้งสองรุ่น
     */
    // ignore: deprecated_member_use
    final visible = TickerMode.of(context);
    if (visible == _visible) return;

    // ครั้งแรกปล่อยให้ initState เป็นคนเริ่ม — ไม่งั้นยิงซ้อนกันตั้งแต่เฟรมแรก
    final first = _visible == null;
    _visible = visible;
    if (first) return;

    if (visible) {
      _bot.startPolling();
      _bot.setWatchDecisions(true);
      _bot.refreshAll();
    } else {
      _bot.stopPolling();
      _bot.setWatchDecisions(false);
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // ปิดแอพ = หยุดยิง ไม่งั้นกินโควตาฟรีๆ และบอทแพลนฟรีก็เดินต่อไม่ได้อยู่แล้ว
    if (state == AppLifecycleState.resumed) {
      // กลับเข้าแอพตอนที่ผู้ใช้ค้างอยู่แท็บอื่น = ยังไม่ต้องเริ่มยิงของหน้านี้
      if (_visible == false) return;
      _bot.startPolling();

      /*
       * รีเฟรชเฉพาะตอนที่ออกไปนานพอจะมีอะไรเปลี่ยน
       *
       * การสลับไปแอพอื่นสั้นๆ เกิดบ่อยมากในเส้นทางปกติของหน้านี้ — กดเซ็นยืนยัน
       * เด้งไป TPIX Wallet แล้วกลับมา หรือแค่ดึงแถบแจ้งเตือนลงมาดู ยิงชุดใหญ่
       * ทุกครั้ง (สถานะ + พอร์ตทดลอง + ฟีด + สถิติ) จะกินโควตาที่แชร์กันทั้งแอพ
       * ทั้งที่ตัวเลขเพิ่งมาเมื่อสองวินาทีก่อน
       */
      final left = _leftAt;
      final away = left == null ? _resumeRefreshAfter : DateTime.now().difference(left);
      _leftAt = null;
      if (away >= _resumeRefreshAfter) _bot.refreshAll();
    } else {
      _leftAt ??= DateTime.now();
      _bot.stopPolling();
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _bot.stopPolling();
    _bot.setWatchDecisions(false);
    super.dispose();
  }

  // ── ตัวช่วยแจ้งผล ───────────────────────────────────────────────────────

  void _snack(String message, {bool ok = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          content: Text(message),
          /*
           * ⚠️ ไม่ใช้ tradingGreen ตรงนี้
           *
           * เขียว/แดงในแอพนี้สงวนไว้ให้ "ราคาขึ้น/ลง" กับ "กำไร/ขาดทุน" เท่านั้น
           * เอามาเป็นพื้นหลังแจ้งผลสำเร็จ (สร้างบอท · บันทึก · สลับโหมด) จะทำให้
           * สัญญาณสีที่ผู้ใช้เรียนรู้มาทั้งแอพเจือจาง — ใช้ทองซึ่งเป็นสีของแบรนด์แทน
           */
          backgroundColor: ok ? AppColors.gold3 : null,
          duration: Duration(seconds: ok ? 2 : 4),
        ),
      );
  }

  /// เหตุผลความล้มเหลวล่าสุด แปลเป็นสองภาษาผ่านตารางกลางของโมเดล
  /// ข้อความไทยจากเซิร์ฟเวอร์เป็นทางลงสุดท้ายเมื่อเจอรหัสที่ยังไม่รู้จัก
  String _failure(bool th) => AiBotErrorText.of(
        _bot.errorCode ?? 'UNKNOWN',
        _bot.errorMessage,
        th,
      );

  Future<bool> _confirm({
    required String title,
    required List<Widget> body,
    required String confirmLabel,
    bool danger = false,
  }) async {
    final th = context.read<LocaleProvider>().isThai;
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        backgroundColor: AppColors.bgElevated,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(20),
          side: BorderSide(
            color: danger
                ? AppColors.tradingRed.withValues(alpha: 0.3)
                : AppColors.bgCardBorder,
          ),
        ),
        title: Text(
          title,
          style: GoogleFonts.inter(
            color: AppColors.textPrimary,
            fontSize: 16,
            fontWeight: FontWeight.w800,
          ),
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: body,
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(
              th ? 'ยกเลิก' : 'Cancel',
              style: const TextStyle(color: AppColors.textTertiary),
            ),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(context, true),
            style: ElevatedButton.styleFrom(
              backgroundColor: danger ? AppColors.tradingRed : AppColors.gold2,
              foregroundColor: danger ? Colors.white : AppColors.goldTextOn,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(10),
              ),
            ),
            child: Text(confirmLabel),
          ),
        ],
      ),
    );
    return ok == true;
  }

  Widget _dialogText(String text, {Color? color}) => Text(
        text,
        style: GoogleFonts.inter(
          fontSize: 12.5,
          color: color ?? AppColors.textSecondary,
          height: 1.55,
        ),
      );

  // ── การกระทำ ────────────────────────────────────────────────────────────

  /*
   * ดึงลงเพื่อรีเฟรช
   *
   * ⚠️ ต้องแจ้งผลเมื่อล้มเหลว — เดิมสปินเนอร์หมุนแล้วหายไปเฉยๆ ผู้ใช้เน็ตหลุด
   *    จะเห็นตัวเลขเก่าค้างอยู่โดยไม่รู้ว่ามันไม่ได้อัปเดต แล้วตัดสินใจจากข้อมูลเก่า
   */
  Future<void> _refresh() async {
    await _bot.refreshAll();
    if (!mounted) return;
    if (_bot.hasError) {
      _snack(_failure(context.read<LocaleProvider>().isThai));
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

  Future<void> _verify() async {
    // provider กันเซ็นซ้อนอีกชั้นแล้ว แต่กันที่ปุ่มด้วยจะไม่มีป๊อปอัพซ้อนกัน
    if (_bot.isVerifying) return;
    HapticFeedback.selectionClick();
    final ok = await _bot.verifyWallet();
    if (!mounted) return;
    final th = context.read<LocaleProvider>().isThai;
    _snack(
      ok ? (th ? 'ยืนยันกระเป๋าแล้ว' : 'Wallet verified') : _failure(th),
      ok: ok,
    );
  }

  Future<void> _claimWelcome() async {
    final th = context.read<LocaleProvider>().isThai;
    // endpoint นี้ idempotent — กดซ้ำเซิร์ฟเวอร์ตอบสำเร็จแต่ไม่เพิ่มเงิน
    // จึงห้ามประกาศว่า "รับแล้ว!" จากผลลัพธ์ success เพียงอย่างเดียว
    final outcome = await _bot.claimWelcome();
    if (!mounted) return;
    switch (outcome) {
      case WelcomeOutcome.granted:
        _snack(th ? 'รับเครดิตต้อนรับเรียบร้อย' : 'Welcome credits claimed',
            ok: true);
      case WelcomeOutcome.alreadyClaimed:
        _snack(th
            ? 'รับเครดิตต้อนรับไปแล้วก่อนหน้านี้'
            : 'Welcome credits were already claimed');
      case WelcomeOutcome.failed:
        _snack(_failure(th));
    }
  }

  Future<void> _subscribe(AiBotPlan plan, int days) async {
    final th = context.read<LocaleProvider>().isThai;
    if (!context.read<WalletProvider>().isConnected) {
      _openConnectSheet();
      return;
    }

    final status = _bot.status;
    final current = status?.subscription;
    final cost = plan.totalCredits(days);

    if (!_bot.salesOpen && cost > 0) {
      _snack(AiBotErrorText.of('SALES_CLOSED', null, th));
      return;
    }
    if (cost > 0 && (status?.credits ?? 0) < cost) {
      final short = cost - (status?.credits ?? 0.0);
      _snack(th
          ? 'เครดิตไม่พอ ขาดอีก ${_int(short)} เครดิต — เติมเครดิตก่อน'
          : 'Not enough credits — ${_int(short)} short');
      return;
    }

    // เปลี่ยนแพลน = จบแพลนเดิมทันที ต้องถามก่อน
    if (current != null &&
        current.planCode != null &&
        current.planCode != plan.code) {
      final ok = await _confirm(
        title: th ? 'เปลี่ยนแพลน' : 'Switch plan',
        confirmLabel: th ? 'ยืนยัน' : 'Confirm',
        body: [
          _dialogText(th
              ? 'แพลน "${current.label(th)}" ที่เหลืออีก ${current.daysRemaining} วันจะจบทันที และคืนเครดิตส่วนที่ยังไม่ได้ใช้ให้'
              : 'Your "${current.label(th)}" plan (${current.daysRemaining} days left) ends right now and the unused part is refunded.'),
          const SizedBox(height: 8),
          _dialogText(
            th
                ? 'กรอบความเสี่ยงของบอททุกตัวจะถูกปรับให้อยู่ในเพดานของแพลนใหม่'
                : 'Every bot risk limit is re-clamped to the new plan ceiling.',
            color: AppColors.textTertiary,
          ),
        ],
      );
      if (!ok || !mounted) return;
    }

    HapticFeedback.selectionClick();
    final done = await _bot.subscribe(plan.code, days);
    if (!mounted) return;
    _snack(
      done
          ? (th
              ? 'เปิดใช้งานแพลนเรียบร้อย — สร้างบอทได้เลย'
              : 'Plan activated — you can create bots now')
          : _failure(th),
      ok: done,
    );
  }

  Future<void> _cancelPlan() async {
    final th = context.read<LocaleProvider>().isThai;
    final affected = _bot.bots.where((b) => b.countsTowardQuota).length;
    final ok = await _confirm(
      title: th ? 'ยกเลิกการเช่า' : 'Cancel rental',
      confirmLabel: th ? 'ยกเลิกการเช่า' : 'Cancel rental',
      danger: true,
      body: [
        _dialogText(th
            ? 'บอท $affected ตัวจะถูกหยุดทันที และคืนเครดิตของวันที่เหลือให้'
            : '$affected bot(s) stop immediately and the unused days are refunded.'),
      ],
    );
    if (!ok || !mounted) return;
    final done = await _bot.cancelPlan();
    if (!mounted) return;
    _snack(
      done
          ? (th
              ? 'ยกเลิกแล้ว — คืนเครดิตของวันที่เหลือเรียบร้อย'
              : 'Cancelled — unused days refunded')
          : _failure(th),
      ok: done,
    );
  }

  Future<void> _topup(CreditPack pack) async {
    final th = context.read<LocaleProvider>().isThai;
    if (!context.read<WalletProvider>().isConnected) {
      _openConnectSheet();
      return;
    }
    final request = await _bot.requestTopup(pack.code);
    if (!mounted) return;
    final done = request != null;
    _snack(
      done
          ? (th
              ? 'ส่งคำขอเติมเครดิตแล้ว — เครดิตจะเข้าหลังทีมงานยืนยันการชำระเงิน'
              : 'Top-up request sent — credits arrive once payment is confirmed')
          : _failure(th),
      ok: done,
    );
  }

  Future<void> _setBotState(AiBot bot, String action) async {
    if (_busyBotId != null) return;
    final th = context.read<LocaleProvider>().isThai;
    HapticFeedback.selectionClick();
    setState(() => _busyBotId = bot.id);
    final done = await _bot.setBotState(bot, action);
    if (!mounted) return;
    setState(() => _busyBotId = null);
    if (!done) _snack(_failure(th));
  }

  Future<void> _toggleMode(AiBot bot) async {
    if (_busyBotId != null) return;
    final th = context.read<LocaleProvider>().isThai;
    final next = bot.isLive ? 'demo' : 'live';

    if (next == 'live') {
      if (!_bot.liveEnabled) {
        _snack(AiBotErrorText.of('LIVE_DISABLED', null, th));
        return;
      }
      // เรื่องเงินจริง — ต้องเป็นการกระทำที่ตั้งใจ ไม่ใช่ปัดโดนแล้วเปลี่ยน
      final ok = await _confirm(
        title: th ? 'เปิดโหมดจริง' : 'Switch to live',
        confirmLabel: th ? 'เปิดโหมดจริง' : 'Go live',
        body: [
          _dialogText(th
              ? 'โหมดจริงจะเสนอสัญญาณให้คุณกดยืนยันในกระเป๋าเอง ระบบไม่ถือกุญแจของคุณ จึงส่งคำสั่งแทนไม่ได้'
              : 'Live mode proposes signals for you to confirm in your own wallet. We never hold your keys.'),
          if (bot.position != null) ...[
            const SizedBox(height: 8),
            _dialogText(
              th
                  ? 'ของที่ถืออยู่ในโหมดทดลองจะไม่ตามไป — ยังอยู่ครบ กลับมาโหมดทดลองก็เจอเหมือนเดิม'
                  : 'The demo position stays behind — it is safe and returns when you switch back.',
              color: AppColors.textTertiary,
            ),
          ],
        ],
      );
      if (!ok || !mounted) return;
    }

    setState(() => _busyBotId = bot.id);
    final done = await _bot.setBotMode(bot, next);
    if (!mounted) return;
    setState(() => _busyBotId = null);
    _snack(
      done ? (th ? 'สลับโหมดเรียบร้อย' : 'Mode switched') : _failure(th),
      ok: done,
    );
  }

  Future<void> _deleteBot(AiBot bot) async {
    final th = context.read<LocaleProvider>().isThai;
    final ok = await _confirm(
      title: th ? 'ลบบอท "${bot.name}"' : 'Delete "${bot.name}"',
      confirmLabel: th ? 'ลบทิ้ง' : 'Delete',
      danger: true,
      body: [
        _dialogText(th
            ? 'ลบแล้วเอาคืนไม่ได้ และประวัติไม้ของบอทตัวนี้จะหายจากพอร์ตทดลองด้วย'
            : 'This cannot be undone, and its trades disappear from the demo portfolio.'),
        if (bot.position != null) ...[
          const SizedBox(height: 8),
          _dialogText(
            th
                ? 'บอทตัวนี้ยังถือของอยู่ — ไม้ที่ค้างจะถูกทิ้งไปพร้อมกัน'
                : 'This bot still holds a position — it will be discarded too.',
            color: AppColors.tradingRed,
          ),
        ],
      ],
    );
    if (!ok || !mounted) return;

    setState(() => _busyBotId = bot.id);
    final done = await _bot.deleteBot(bot);
    if (!mounted) return;
    setState(() => _busyBotId = null);
    _snack(done ? (th ? 'ลบบอทแล้ว' : 'Bot deleted') : _failure(th), ok: done);
  }

  Future<void> _openEditor({AiBot? existing}) async {
    final th = context.read<LocaleProvider>().isThai;
    final catalog = _bot.catalog;
    if (catalog == null) {
      _snack(th
          ? 'ยังโหลดรายการกลยุทธ์ไม่เสร็จ — ดึงหน้าจอลงเพื่อรีเฟรชแล้วลองใหม่'
          : 'Strategies are still loading — pull to refresh and try again');
      return;
    }
    if (catalog.strategies.isEmpty) {
      _snack(th
          ? 'ยังไม่มีรายการกลยุทธ์จากเซิร์ฟเวอร์ — ดึงหน้าจอลงเพื่อรีเฟรช'
          : 'No strategies from the server yet — pull to refresh');
      return;
    }
    if (existing == null && !_bot.canCreateBot) {
      _snack(AiBotErrorText.of('BOT_LIMIT_REACHED', null, th));
      return;
    }

    /*
     * ⚠️ ชีตเป็นคนยิงบันทึกเอง แล้วปิดตัวเองเมื่อสำเร็จเท่านั้น
     *
     * เดิมชีตปิดก่อนแล้วหน้าจอค่อยยิง — พอเซิร์ฟเวอร์ตอบ 422 พร้อมบอกว่าช่องไหนผิด
     * ผู้ใช้จะเห็นข้อความ "ตรวจช่องที่มีข้อความสีแดง" บนหน้าจอที่ไม่มีช่องเหลืออยู่เลย
     * และค่าที่กรอกมาทั้งฟอร์ม (ชื่อ · คู่ · กลยุทธ์ · กรอบเวลา · พารามิเตอร์ ·
     * ตัวเลขความเสี่ยง 4 ช่อง) หายหมด ต้องเริ่มใหม่โดยไม่รู้ว่าผิดตรงไหน
     */
    final saved = await showModalBottomSheet<AiBot>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      builder: (_) => _BotEditorSheet(
        catalog: catalog,
        unlocked: _bot.status?.unlockedStrategies ?? const <String>[],
        existing: existing,
        pairs: _pairOptions(),
        defaultName: th
            ? 'บอท ${_bot.bots.length + 1}'
            : 'Bot ${_bot.bots.length + 1}',
        onSave: (draft) => existing == null
            ? _bot.createBot(draft)
            : _bot.updateBot(existing.id, draft),
      ),
    );
    if (!mounted) return;

    // ปิดโดยไม่มีผลลัพธ์ = ผู้ใช้กดยกเลิกเอง ไม่ต้องแจ้งอะไร
    if (saved == null) return;
    // มาถึงตรงนี้ได้เฉพาะตอนบันทึกสำเร็จ — ชีตปิดตัวเองเมื่อสำเร็จเท่านั้น
    _snack(
      existing == null
          ? (th
              ? 'สร้างบอทแล้ว — กด "เริ่ม" เพื่อให้บอททำงาน'
              : 'Bot created — hit Start to run it')
          : (th ? 'บันทึกการแก้ไขแล้ว' : 'Changes saved'),
      ok: true,
    );
  }

  Future<void> _resetDemo() async {
    final th = context.read<LocaleProvider>().isThai;
    final ok = await _confirm(
      title: th ? 'ล้างพอร์ตทดลอง' : 'Reset demo portfolio',
      confirmLabel: th ? 'ล้างพอร์ต' : 'Reset',
      danger: true,
      body: [
        _dialogText(th
            ? 'พอร์ตกลับไปตั้งต้น และประวัติไม้ทดลองทั้งหมดจะหายไป — สถิติย้อนหลังจะว่างตามไปด้วย'
            : 'The portfolio returns to its starting capital and every demo trade is erased — your analytics go blank too.'),
      ],
    );
    if (!ok || !mounted) return;
    final done = await _bot.resetDemo();
    if (!mounted) return;
    _snack(
      done
          ? (th ? 'ล้างพอร์ตทดลองแล้ว' : 'Demo portfolio reset')
          : _failure(th),
      ok: done,
    );
  }

  Future<void> _askAdvice() async {
    final th = context.read<LocaleProvider>().isThai;
    if (!context.read<WalletProvider>().isConnected) {
      _openConnectSheet();
      return;
    }
    HapticFeedback.selectionClick();
    final done = await _bot.askAdvice();
    if (!mounted) return;
    if (!done) _snack(_failure(th));
  }

  // ── ค่าที่คิดจากข้อมูลเซิร์ฟเวอร์ (ธงฟีเจอร์ทั้งหมดมาจาก provider) ────────

  List<AiBot> get _bots => _bot.bots;

  List<String> _pairOptions() {
    final tickers = context.read<MarketProvider>().tickers;
    final seen = <String>{};
    final out = <String>[];
    for (final t in tickers) {
      final base = t.baseAsset.trim().toUpperCase();
      final quote = t.quoteAsset.trim().toUpperCase();
      if (base.isEmpty || quote.isEmpty) continue;
      final pair = '$base/$quote';
      if (seen.add(pair)) out.add(pair);
    }
    if (out.isEmpty) out.add('BTC/USDT');
    return out;
  }

  // ── หน้าจอ ──────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final wallet = context.watch<WalletProvider>();
    final bot = context.watch<AiBotProvider>();
    final th = locale.isThai;

    final connected = wallet.isConnected;
    final needsVerify = connected && bot.needsVerification;
    final ready = connected && !needsVerify;
    final loadingCore = bot.isLoadingStatus && bot.status == null;

    return Scaffold(
      backgroundColor: Colors.transparent,
      body: AppBackground(
        child: SafeArea(
          bottom: false,
          child: RefreshIndicator(
            // ตามโทนโลหะที่ผู้ใช้เลือก เหมือนทุกจุดอื่นในหน้านี้
            color: context.watch<AccentProvider>().g2,
            backgroundColor: AppColors.bgSecondary,
            onRefresh: _refresh,
            child: CustomScrollView(
              physics: const AlwaysScrollableScrollPhysics(),
              slivers: [
                SliverToBoxAdapter(child: _Header(th: th)),

                // ── แถบสถานะระบบ เรียงตามความเร่งด่วน ──────────────────
                if (bot.isAdminWallet)
                  _block(_NoticeCard(
                    icon: Icons.workspace_premium_rounded,
                    title: th ? 'โหมดทีมงาน' : 'Team mode',
                    body: th
                        ? 'ใช้ได้ทุกฟังก์ชันโดยไม่ต้องเช่าหรือเติมเครดิต'
                        : 'Every function is open — no rental or credits needed',
                  )),

                if (!connected)
                  _block(_NoticeCard(
                    tone: _NoticeTone.action,
                    icon: Icons.account_balance_wallet_rounded,
                    title: th
                        ? 'เชื่อมกระเป๋าเพื่อเริ่มใช้ AI TRADE'
                        : 'Connect your wallet to use AI TRADE',
                    body: th
                        ? 'บอทและเครดิตผูกกับที่อยู่กระเป๋าของคุณโดยตรง — แพลนและกลยุทธ์ด้านล่างดูได้เลยโดยไม่ต้องเชื่อม'
                        : 'Bots and credits are tied directly to your wallet. The plans and strategies below are readable without connecting.',
                    actionLabel: th ? 'เชื่อมกระเป๋า' : 'Connect wallet',
                    actionIcon: Icons.link_rounded,
                    onAction: _openConnectSheet,
                  ))
                else if (needsVerify)
                  _block(_NoticeCard(
                    tone: _NoticeTone.action,
                    icon: Icons.verified_user_rounded,
                    title:
                        th ? 'ต้องยืนยันกระเป๋าก่อน' : 'Wallet needs verifying',
                    body: th
                        ? 'เซ็นข้อความสั้นๆ เพื่อยืนยันว่าเป็นเจ้าของกระเป๋านี้ — การยืนยันมีอายุ 4 ชั่วโมง และไม่เสียค่าแก๊ส'
                        : 'Sign a short message to prove you own this wallet. Verification lasts 4 hours and costs no gas.',
                    actionLabel: bot.isVerifying
                        ? (th ? 'รอเซ็นในกระเป๋า…' : 'Waiting for signature…')
                        : (th ? 'เซ็นยืนยันกระเป๋า' : 'Sign to verify'),
                    actionIcon: Icons.draw_rounded,
                    isLoading: bot.isVerifying,
                    onAction: bot.isVerifying ? null : _verify,
                  )),

                /*
                 * ── ความล้มเหลวที่ไม่ได้เกิดจากการกดปุ่ม ──────────────────
                 *
                 * รอบ poll เบื้องหลังกับการดึงลงรีเฟรชล้มได้ตลอด (เน็ตหลุด ·
                 * เซิร์ฟเวอร์ 500 · โดนจำกัดอัตรา) เดิมเงียบสนิท ผู้ใช้จึงเห็นการ์ด
                 * สรุปวาดครบสวยงามบอก "เครดิต 0 · บอท 0/0" แยกไม่ออกจากบัญชีใหม่
                 * ที่ยังไม่มีอะไรจริงๆ แล้วตัดสินใจจากตัวเลขที่ไม่ได้อัปเดต
                 *
                 * ไม่โชว์ทับเคสที่มีแบนเนอร์เฉพาะทางอยู่แล้ว (ยังไม่เซ็นยืนยัน)
                 * เพราะอันนั้นบอกทางแก้ที่ตรงกว่า
                 */
                if (bot.hasError && !bot.needsVerification)
                  _block(_NoticeCard(
                    tone: _NoticeTone.problem,
                    icon: Icons.cloud_off_rounded,
                    title: th
                        ? 'ข้อมูลบางส่วนยังไม่อัปเดต'
                        : 'Some data is out of date',
                    body: bot.errorText(th),
                    actionLabel: th ? 'ลองใหม่' : 'Retry',
                    actionIcon: Icons.refresh_rounded,
                    onAction: bot.isRefreshing ? null : _refresh,
                    isLoading: bot.isRefreshing,
                  )),

                /*
                 * ⚠️ ต้องมี `bot.hasCatalog` ด้วย
                 *
                 * ตอนแคตตาล็อกยังโหลดไม่สำเร็จ features ตกเป็น AiBotFeatures.closed
                 * ถ้าไม่กันไว้ หน้าจะประกาศว่า "ยังไม่เปิดให้เช่า" ทั้งที่ความจริงคือ
                 * แอพโหลดข้อมูลไม่ได้ — บอกผู้ใช้ผิดเรื่องและไม่มีทางแก้ให้เขาทำ
                 */
                if (bot.hasCatalog &&
                    !bot.features.salesOpen &&
                    !bot.isAdminWallet)
                  _block(_NoticeCard(
                    icon: Icons.science_rounded,
                    title:
                        th ? 'AI TRADE อยู่ระหว่างทดสอบ' : 'AI TRADE is in test',
                    body: th
                        ? 'ยังไม่เปิดให้เช่า ระหว่างนี้ใช้โหมดทดลองด้วยเครดิตจำลองที่ราคาจริงได้เต็มที่ ไม่มีค่าใช้จ่าย'
                        : 'Renting is not open yet. The demo mode — simulated credits at real market prices — is free to use.',
                  )),

                // ── สรุปภาพรวม: ตอบใน 2 วินาทีว่าตอนนี้เป็นยังไง ────────
                if (ready)
                  _block(loadingCore
                      ? const _HeroSkeleton()
                      : _HeroSummary(
                          th: th,
                          status: bot.status,
                          demo: bot.demo,
                        )),

                // ── บอทของฉัน ───────────────────────────────────────────
                if (ready) ...[
                  _block(_SectionHeader(
                    icon: Icons.smart_toy_rounded,
                    title: th ? 'บอทของฉัน' : 'My bots',
                    trailing: _MiniAction(
                      icon: Icons.add_rounded,
                      label: th ? 'บอทใหม่' : 'New bot',
                      enabled: bot.canCreateBot && !bot.isWorking,
                      onTap: () => _openEditor(),
                    ),
                  )),
                  if (bot.isLoadingStatus && _bots.isEmpty)
                    const SliverToBoxAdapter(
                      child: Padding(
                        padding: EdgeInsets.symmetric(horizontal: 18),
                        child: ShimmerList(itemCount: 3),
                      ),
                    )
                  else if (_bots.isEmpty)
                    _block(_EmptyBots(
                      th: th,
                      canCreate: bot.canCreateBot,
                      onCreate: () => _openEditor(),
                    ))
                  else
                    SliverPadding(
                      padding: const EdgeInsets.symmetric(horizontal: 18),
                      sliver: SliverList.builder(
                        itemCount: _bots.length,
                        itemBuilder: (_, i) {
                          final item = _bots[i];
                          return _BotRow(
                            th: th,
                            bot: item,
                            busy: _busyBotId == item.id || bot.isWorking,
                            liveEnabled: bot.liveEnabled,
                            onStart: () => _setBotState(item, 'start'),
                            onPause: () => _setBotState(item, 'pause'),
                            onStop: () => _setBotState(item, 'stop'),
                            onMode: () => _toggleMode(item),
                            onEdit: () => _openEditor(existing: item),
                            onDelete: () => _deleteBot(item),
                          );
                        },
                      ),
                    ),
                  if (bot.runsInApp && bot.appTickBots.isNotEmpty)
                    _block(_AppLoopBanner(
                      th: th,
                      lastTick: bot.lastAppTick,
                      running: bot.appLoopActive,
                      blockedCode: bot.appLoopBlockedCode,
                      onRetry: bot.retryAppLoop,
                      cooldownSeconds: bot.apiCooldownSeconds,
                    )),
                ],

                /*
                 * ── AI คิดอะไรอยู่ ───────────────────────────────────────
                 *
                 * แผงหลักของคำว่า "มอนิเตอร์" — ไม่ใช่แค่ผลลัพธ์ แต่คือเหตุผล
                 * ทุกรอบที่บอทคิด รวมรอบที่ตัดสินใจ "ไม่ทำอะไร" ซึ่งเกิดบ่อยกว่า
                 * การเข้าไม้หลายสิบเท่า และเป็นสิ่งที่บอกได้จริงว่าบอททำงานถูกหรือเปล่า
                 *
                 * ข้อมูลมาจาก GET /api/v1/ai-bot/decisions (ผูกกระเป๋าผู้เรียกเสมอ)
                 */
                if (ready) ...[
                  _block(_SectionHeader(
                    icon: Icons.psychology_alt_rounded,
                    title: th ? 'AI คิดอะไรอยู่' : 'What the AI is thinking',
                    trailing: _MiniAction(
                      icon: Icons.refresh_rounded,
                      label: th ? 'รีเฟรช' : 'Refresh',
                      enabled: !bot.isLoadingDecisions,
                      onTap: bot.refreshDecisions,
                    ),
                  )),
                  _block(AiDecisionFeedPanel(
                    decisions: bot.decisions,
                    loading: bot.isLoadingDecisions && bot.decisions.isEmpty,
                    loadingMore: bot.isLoadingMoreDecisions,
                    refreshing:
                        bot.isLoadingDecisions && bot.decisions.isNotEmpty,
                    hasMore: bot.decisionsHasMore,
                    // โชว์เหตุผลความล้มเหลวเฉพาะตอนที่ยังไม่มีอะไรให้ดูเลย
                    // มีของเก่าค้างอยู่แล้วขึ้นแถบแดงทับ = รบกวนโดยไม่ได้ช่วยอะไร
                    errorMessage: (bot.hasError && bot.decisions.isEmpty)
                        ? bot.errorText(th)
                        : null,
                    onRetry: bot.refreshDecisions,
                    onRefresh: bot.refreshDecisions,
                    onLoadMore: bot.loadMoreDecisions,
                    actedOnly: bot.decisionActedOnly,
                    onActedOnlyChanged: (v) =>
                        bot.setDecisionFilter(actedOnly: v),
                    filteredBotName: _filteredBotName(bot),
                    onClearBotFilter: bot.decisionBotFilter == null
                        ? null
                        : () => bot.setDecisionFilter(clearBot: true),
                    lastUpdatedAt: bot.decisions.isEmpty
                        ? null
                        : bot.decisions.first.createdAt,
                  )),
                ],

                // ── สรุปผลย้อนหลัง ──────────────────────────────────────
                if (ready) ...[
                  _block(_SectionHeader(
                    icon: Icons.query_stats_rounded,
                    title: th ? 'สรุปผลย้อนหลัง' : 'Track record',
                  )),
                  _block(AiAnalyticsPanel(
                    analytics: bot.analytics,
                    loading: bot.isLoadingAnalytics && bot.analytics == null,
                    refreshing:
                        bot.isLoadingAnalytics && bot.analytics != null,
                    errorMessage: (bot.hasError && bot.analytics == null)
                        ? bot.errorText(th)
                        : null,
                    onRetry: _loadAnalytics,
                    onRefresh: _loadAnalytics,
                    mode: _analyticsMode(bot).wire,
                    strategyLabelOf: (code) => _strategyLabel(bot, code, th),
                  )),
                ],

                // ── พอร์ตทดลอง ──────────────────────────────────────────
                if (ready) ...[
                  _block(_SectionHeader(
                    icon: Icons.savings_rounded,
                    title: th ? 'พอร์ตทดลอง' : 'Demo portfolio',
                    trailing: _MiniAction(
                      icon: Icons.restart_alt_rounded,
                      label: th ? 'ล้างพอร์ต' : 'Reset',
                      enabled: (bot.demo?.account.resetsLeft ?? 0) > 0 &&
                          !bot.isWorking,
                      onTap: _resetDemo,
                    ),
                  )),
                  _block(bot.demo == null
                      ? (bot.isLoadingDemo
                          ? const ShimmerBox(
                              width: double.infinity,
                              height: 132,
                              borderRadius: 16,
                            )
                          : _InfoCard(
                              title: th
                                  ? 'ยังไม่มีข้อมูลพอร์ตทดลอง'
                                  : 'No demo portfolio data yet',
                              body: th
                                  ? 'ดึงหน้าจอลงเพื่อโหลดใหม่'
                                  : 'Pull down to reload',
                            ))
                      : _DemoCard(th: th, demo: bot.demo!)),
                ],

                // ── มุมมองตลาดของ AI ────────────────────────────────────
                if (bot.analystEnabled) ...[
                  _block(_SectionHeader(
                    icon: Icons.insights_rounded,
                    title: th ? 'มุมมองตลาดของ AI' : 'AI market view',
                  )),
                  _block(_MarketViewCard(th: th, data: bot.marketView)),
                ],

                // ── แพลนเช่า ────────────────────────────────────────────
                _block(_SectionHeader(
                  icon: Icons.rocket_launch_rounded,
                  title: th ? 'แพลนเช่าบอท' : 'Rental plans',
                )),
                if (bot.catalog == null)
                  // กำลังโหลด = โครงกระดูก · โหลดไม่สำเร็จ = บอกเหตุผล + ปุ่มลองใหม่
                  // ปล่อยให้ shimmer ค้างถาวรคือการให้ผู้ใช้นั่งรอสิ่งที่ไม่มีวันมา
                  bot.isLoadingCatalog
                      ? const SliverToBoxAdapter(
                          child: Padding(
                            padding: EdgeInsets.symmetric(horizontal: 18),
                            child: ShimmerList(itemCount: 2),
                          ),
                        )
                      : _block(_InfoCard(
                          title: th
                              ? 'โหลดรายการแพลนไม่สำเร็จ'
                              : 'Could not load rental plans',
                          body: bot.hasError
                              ? bot.errorText(th)
                              : (th
                                  ? 'ดึงหน้าจอลงเพื่อลองใหม่'
                                  : 'Pull down to try again'),
                        ))
                else
                  _block(_PlansSection(
                    th: th,
                    catalog: bot.catalog!,
                    subscription: bot.status?.subscription,
                    salesOpen: bot.salesOpen,
                    liveEnabled: bot.liveEnabled,
                    working: bot.isWorking,
                    onRent: _subscribe,
                    onCancel: _cancelPlan,
                  )),

                // ── เครดิต ──────────────────────────────────────────────
                if (bot.catalog != null && bot.catalog!.packs.isNotEmpty) ...[
                  _block(_SectionHeader(
                    icon: Icons.bolt_rounded,
                    title: th ? 'เติมเครดิตการทำงาน' : 'Top up work credits',
                  )),
                  _block(_PacksSection(
                    th: th,
                    packs: bot.catalog!.packs,
                    topupOpen: bot.topupEnabled,
                    working: bot.isWorking,
                    showWelcome: ready && (bot.status?.credits ?? 0) <= 0,
                    onWelcome: _claimWelcome,
                    onPick: _topup,
                  )),
                ],

                // ── ที่ปรึกษา AI ────────────────────────────────────────
                _block(_SectionHeader(
                  icon: Icons.lightbulb_outline_rounded,
                  title: th ? 'ที่ปรึกษา AI' : 'AI advisor',
                )),
                _block(_AdvisorCard(
                  th: th,
                  advice: bot.advice,
                  asking: bot.isAskingAdvice,
                  ready: ready,
                  onAsk: _askAdvice,
                )),

                // เว้นล่างให้พ้นแถบนำทางลอย
                const SliverToBoxAdapter(child: SizedBox(height: 120)),
              ],
            ),
          ),
        ),
      ),
    );
  }

  /// ชื่อบอทที่ฟีดกำลังกรองอยู่ — ให้ผู้ใช้รู้ว่ากำลังเห็นไม่ครบทั้งกระเป๋า
  String? _filteredBotName(AiBotProvider bot) {
    final id = bot.decisionBotFilter;
    if (id == null) return null;
    for (final b in bot.bots) {
      if (b.id == id) return b.name;
    }
    return null;
  }

  /*
   * สถิติย้อนหลังของโหมดไหน
   *
   * อ่านจากบอทจริงของผู้ใช้ ไม่ใช่ธง live_enabled ของระบบ — ผู้ใช้ที่ยังไม่มี
   * บอทโหมดจริงสักตัว ต้องเห็นสถิติของพอร์ตทดลอง ไม่ใช่จอว่างที่บอกว่าไม่มีข้อมูล
   */
  AiBotMode _analyticsMode(AiBotProvider bot) =>
      bot.bots.any((b) => AiBotMode.fromWire(b.mode) == AiBotMode.live)
          ? AiBotMode.live
          : AiBotMode.demo;

  /// รหัสกลยุทธ์ → ชื่อที่อ่านได้ตามภาษา (ตกกลับเป็นรหัสเมื่อแคตตาล็อกยังไม่มา)
  String _strategyLabel(AiBotProvider bot, String code, bool th) {
    for (final s in bot.catalog?.strategies ?? const <AiBotStrategy>[]) {
      if (s.code == code) return th ? s.nameTh : s.name;
    }
    return code;
  }

  Future<void> _loadAnalytics() =>
      _bot.loadAnalytics(mode: _analyticsMode(_bot).wire);

  /// ทุกบล็อกใช้ระยะขอบเดียวกัน — 18 ซ้ายขวา เว้นล่าง 12
  SliverToBoxAdapter _block(Widget child) => SliverToBoxAdapter(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(18, 0, 18, 12),
          child: child,
        ),
      );
}

// ═══════════════════════════════════════════════════════════════════════════
// หัวหน้าจอ + แถบแจ้งสถานะ
// ═══════════════════════════════════════════════════════════════════════════

class _Header extends StatelessWidget {
  final bool th;
  const _Header({required this.th});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.auto_awesome_rounded, size: 22, color: accent.g2),
              const SizedBox(width: 10),
              Text(
                'AI TRADE',
                style: GoogleFonts.inter(
                  fontSize: 24,
                  fontWeight: FontWeight.w800,
                  color: AppColors.textPrimary,
                  letterSpacing: -0.4,
                ),
              ),
              const SizedBox(width: 10),
              _GoldPill(
                icon: Icons.cloud_rounded,
                label: th ? 'บอทคลาวด์' : 'CLOUD BOT',
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            th
                ? 'บอทเดินบนคลาวด์ของ TPIX — ตั้งค่าที่แอพหรือเว็บก็เห็นเหมือนกัน เพราะผูกกับกระเป๋าใบเดียว'
                : 'Bots run on TPIX cloud. Set them up in the app or on the web — one wallet, one view.',
            style: GoogleFonts.inter(
              fontSize: 12,
              color: AppColors.textTertiary,
              height: 1.5,
            ),
          ),
        ],
      ),
    );
  }
}

/// แถบแจ้งสถานะระบบ — ถ้าบอกปัญหา ต้องมีทางออกให้กดเสมอ
/*
 * ระดับความเร่งด่วนของแถบแจ้งเตือน
 *
 * เดิมทุกแถบหน้าตาเหมือนกันหมด (ทองล้วน) ตั้งแต่ "โหมดทีมงาน" ซึ่งเป็นข่าวดี
 * ไปจนถึง "โหลดข้อมูลไม่สำเร็จ" ซึ่งคือของพัง ผู้ใช้จึงต้องอ่านทุกแถบทุกครั้ง
 * ถึงจะรู้ว่าอันไหนต้องรีบทำ — สีมีหน้าที่บอกแทนก่อนที่ตาจะไปถึงตัวหนังสือ
 */
enum _NoticeTone {
  /// บอกให้ทราบเฉยๆ — ไม่มีอะไรต้องทำ
  info,

  /// ต้องให้ผู้ใช้ลงมือ ถึงจะใช้งานต่อได้
  action,

  /// มีอะไรไม่เป็นไปตามที่ควร
  problem,
}

class _NoticeCard extends StatelessWidget {
  final IconData icon;
  final String title;
  final String body;
  final String? actionLabel;
  final IconData? actionIcon;
  final VoidCallback? onAction;
  final bool isLoading;
  final _NoticeTone tone;

  const _NoticeCard({
    required this.icon,
    required this.title,
    required this.body,
    this.actionLabel,
    this.actionIcon,
    this.onAction,
    this.isLoading = false,
    this.tone = _NoticeTone.info,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();

    // ปัญหาใช้แดงชุดเดียวกับที่ฟอร์มใช้บอกช่องผิดอยู่แล้ว — ผู้ใช้อ่านออกทันที
    // ส่วน "ต้องลงมือ" เน้นด้วยขอบหนาขึ้น ไม่เปลี่ยนสี เพราะยังไม่ใช่เรื่องเสียหาย
    final problem = tone == _NoticeTone.problem;
    final fill = problem ? AppColors.tradingRedBg : accent.goldTint;
    final edge = problem ? AppColors.tradingRed : accent.goldBorder;
    final iconColor = problem ? AppColors.tradingRed : accent.g2;

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: fill,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: edge,
          width: tone == _NoticeTone.info ? 1 : 1.4,
        ),
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

class _InfoCard extends StatelessWidget {
  final String title;
  final String body;
  final IconData icon;
  const _InfoCard({
    required this.title,
    required this.body,
    this.icon = Icons.info_outline_rounded,
  });

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      variant: GlassVariant.standard,
      borderRadius: 16,
      padding: const EdgeInsets.all(16),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 18, color: AppColors.textTertiary),
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
                const SizedBox(height: 3),
                Text(
                  body,
                  style: GoogleFonts.inter(
                    fontSize: 11.5,
                    color: AppColors.textTertiary,
                    height: 1.5,
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

// ═══════════════════════════════════════════════════════════════════════════
// การ์ดสรุป (พระเอกของหน้า — หนึ่งใบต่อหน้าเท่านั้น)
// ═══════════════════════════════════════════════════════════════════════════

class _HeroSummary extends StatelessWidget {
  final bool th;
  final AiBotStatus? status;
  final AiBotDemo? demo;
  const _HeroSummary({
    required this.th,
    required this.status,
    required this.demo,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final sub = status?.subscription;
    final cloud = sub?.runsInCloud == true;

    // ใช้ `demo.equity` ที่คิดจาก balance รวมทุกพอร์ต + มูลค่าของที่ถืออยู่
    // (`summary.equity` ของเซิร์ฟเวอร์ใช้ balance ใบแรกใบเดียว จึงไม่ตรงกันเอง)
    final equity = demo?.equity;
    final realized = demo?.summary.realizedPnl ?? 0;
    final unrealized = demo?.summary.unrealizedPnl ?? 0;
    final total = demo?.totalPnl ?? 0;
    final running = status?.runningBots.length ?? 0;

    return GlassCard(
      variant: GlassVariant.hero,
      borderRadius: 22,
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  th ? 'มูลค่าพอร์ตทดลอง' : 'DEMO PORTFOLIO VALUE',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: GoogleFonts.inter(
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textTertiary,
                    letterSpacing: 1.2,
                  ),
                ),
              ),
              _GoldPill(
                icon: cloud
                    ? Icons.cloud_done_rounded
                    : Icons.phone_iphone_rounded,
                label: cloud
                    ? (th ? 'เดินบนคลาวด์' : 'CLOUD')
                    : (th ? 'เดินในแอพ' : 'IN APP'),
              ),
            ],
          ),
          const SizedBox(height: 10),
          _BigMoney(value: equity, th: th),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: _PnlChip(
                  label: th ? 'รวมทั้งหมด' : 'Total',
                  value: total,
                  th: th,
                  strong: true,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: _PnlChip(
                  label: th ? 'ปิดแล้ว' : 'Closed',
                  value: realized,
                  th: th,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: _PnlChip(
                  label: th ? 'ยังไม่ปิด' : 'Open',
                  value: unrealized,
                  th: th,
                ),
              ),
            ],
          ),
          if (demo?.hasUnpriced == true) ...[
            const SizedBox(height: 8),
            Row(
              children: [
                Icon(Icons.help_outline_rounded, size: 12, color: accent.g2),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    th
                        ? 'บางไม้ยังตีราคาไม่ได้ — ตัวเลขกำไรลอยจึงยังไม่ครบทุกไม้'
                        : 'Some positions have no live price — the open P&L is incomplete',
                    style: GoogleFonts.inter(
                      fontSize: 10.5,
                      color: AppColors.textTertiary,
                    ),
                  ),
                ),
              ],
            ),
          ],
          const SizedBox(height: 16),
          Row(
            children: [
              Expanded(
                child: _StatTile(
                  icon: Icons.bolt_rounded,
                  value: _int(status?.credits ?? 0),
                  label: th ? 'เครดิต' : 'CREDITS',
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _StatTile(
                  icon: Icons.play_circle_outline_rounded,
                  value: '$running/${status?.quota.maxBots ?? 0}',
                  label: th ? 'บอทที่เดินอยู่' : 'RUNNING',
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _StatTile(
                  icon: Icons.emoji_events_outlined,
                  // ยังไม่มีไม้ปิด = ไม่รู้ ไม่ใช่ 0% — ต้องขึ้นขีดกลาง
                  value: demo?.summary.winRate == null
                      ? '—'
                      : '${demo!.summary.winRate!.toStringAsFixed(1)}%',
                  label: th ? 'อัตราชนะ' : 'WIN RATE',
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Container(height: 1, color: AppColors.divider),
          const SizedBox(height: 12),
          Row(
            children: [
              Icon(Icons.card_membership_rounded, size: 14, color: accent.g2),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  _planLine(sub, th),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: GoogleFonts.inter(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textSecondary,
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  String _planLine(AiBotSubscription? sub, bool th) {
    if (sub == null) return th ? 'ยังไม่มีแพลน' : 'No plan yet';
    if (sub.isFree) {
      return th ? 'แพลน ${sub.label(th)} — ฟรี' : '${sub.label(th)} plan — free';
    }
    return th
        ? 'แพลน ${sub.label(th)} · เหลืออีก ${sub.daysRemaining} วัน'
        : '${sub.label(th)} · ${sub.daysRemaining} days left';
  }
}

class _BigMoney extends StatelessWidget {
  final double? value;
  final bool th;
  const _BigMoney({required this.value, required this.th});

  @override
  Widget build(BuildContext context) {
    final v = value;
    if (v == null) {
      return const ShimmerBox(width: 180, height: 34, borderRadius: 10);
    }
    final whole = v.truncateToDouble();
    final cents = ((v - whole).abs() * 100).round().toString().padLeft(2, '0');
    return FittedBox(
      fit: BoxFit.scaleDown,
      alignment: Alignment.centerLeft,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.baseline,
        textBaseline: TextBaseline.alphabetic,
        children: [
          Text(
            '\$${_money(whole, digits: 0, th: th)}',
            maxLines: 1,
            style: GoogleFonts.jetBrainsMono(
              fontSize: 30,
              fontWeight: FontWeight.w600,
              color: AppColors.textPrimary,
              letterSpacing: -0.5,
            ),
          ),
          Text(
            '.$cents',
            maxLines: 1,
            style: GoogleFonts.jetBrainsMono(
              fontSize: 19,
              fontWeight: FontWeight.w600,
              color: AppColors.textTertiary,
            ),
          ),
        ],
      ),
    );
  }
}

class _PnlChip extends StatelessWidget {
  final String label;
  final double value;
  final bool th;
  final bool strong;
  const _PnlChip({
    required this.label,
    required this.value,
    required this.th,
    this.strong = false,
  });

  @override
  Widget build(BuildContext context) {
    final color = value == 0
        ? AppColors.textSecondary
        : (value > 0 ? AppColors.tradingGreen : AppColors.tradingRed);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(12),
        color: strong ? AppColors.bgInputStrong : AppColors.bgInput,
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: GoogleFonts.inter(
              fontSize: 9.5,
              fontWeight: FontWeight.w600,
              color: AppColors.textTertiary,
              letterSpacing: 0.4,
            ),
          ),
          const SizedBox(height: 3),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              _signedMoney(value, th: th),
              maxLines: 1,
              style: AppTheme.mono(
                fontSize: strong ? 14 : 12.5,
                fontWeight: FontWeight.w700,
                color: color,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _StatTile extends StatelessWidget {
  final IconData icon;
  final String value;
  final String label;
  const _StatTile({
    required this.icon,
    required this.value,
    required this.label,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 12),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        gradient: AppGradients.cardSubtle,
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: Column(
        children: [
          Icon(icon, size: 16, color: accent.g2),
          const SizedBox(height: 7),
          FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(
              value,
              maxLines: 1,
              style: AppTheme.mono(fontSize: 15, color: AppColors.textPrimary),
            ),
          ),
          const SizedBox(height: 3),
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: GoogleFonts.inter(
              fontSize: 10,
              fontWeight: FontWeight.w600,
              color: AppColors.textTertiary,
              letterSpacing: 0.4,
            ),
          ),
        ],
      ),
    );
  }
}

class _SectionHeader extends StatelessWidget {
  final IconData icon;
  final String title;
  final Widget? trailing;
  const _SectionHeader({
    required this.icon,
    required this.title,
    this.trailing,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Padding(
      padding: const EdgeInsets.only(top: 6, bottom: 10),
      child: Row(
        children: [
          Container(
            width: 30,
            height: 30,
            decoration: BoxDecoration(
              color: accent.goldTint,
              borderRadius: BorderRadius.circular(9),
            ),
            child: Icon(icon, color: accent.g2, size: 16),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              title,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: GoogleFonts.inter(
                fontSize: 14.5,
                fontWeight: FontWeight.w700,
                color: AppColors.textPrimary,
                letterSpacing: -0.2,
              ),
            ),
          ),
          ?trailing,
        ],
      ),
    );
  }
}

class _MiniAction extends StatelessWidget {
  final IconData icon;
  final String label;
  final bool enabled;
  final VoidCallback onTap;
  const _MiniAction({
    required this.icon,
    required this.label,
    required this.enabled,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Opacity(
      opacity: enabled ? 1 : 0.45,
      child: GestureDetector(
        onTap: enabled ? onTap : null,
        behavior: HitTestBehavior.opaque,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 7),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(999),
            color: accent.goldTint,
            border: Border.all(color: accent.goldBorder, width: 1.2),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 12, color: accent.g2),
              const SizedBox(width: 5),
              Text(
                label,
                style: GoogleFonts.inter(
                  fontSize: 10.5,
                  fontWeight: FontWeight.w700,
                  color: accent.g1,
                  letterSpacing: 0.4,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _GoldPill extends StatelessWidget {
  final IconData icon;
  final String label;
  const _GoldPill({required this.icon, required this.label});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: accent.goldTint,
        border: Border.all(color: accent.goldBorder, width: 1.2),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 11, color: accent.g2),
          const SizedBox(width: 4),
          Text(
            label,
            style: GoogleFonts.inter(
              fontSize: 9.5,
              fontWeight: FontWeight.w700,
              color: accent.g1,
              letterSpacing: 0.6,
            ),
          ),
        ],
      ),
    );
  }
}

class _RowNote extends StatelessWidget {
  final IconData icon;
  final Color color;
  final String text;
  const _RowNote({
    required this.icon,
    required this.color,
    required this.text,
  });

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 13, color: color),
        const SizedBox(width: 6),
        Expanded(
          child: Text(
            text,
            style: GoogleFonts.inter(
              fontSize: 11,
              fontWeight: FontWeight.w600,
              color: color,
              height: 1.45,
            ),
          ),
        ),
      ],
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// บอทหนึ่งตัว
// ═══════════════════════════════════════════════════════════════════════════

class _BotRow extends StatelessWidget {
  final bool th;
  final AiBot bot;
  final bool busy;
  final bool liveEnabled;
  final VoidCallback onStart;
  final VoidCallback onPause;
  final VoidCallback onStop;
  final VoidCallback onMode;
  final VoidCallback onEdit;
  final VoidCallback onDelete;

  const _BotRow({
    required this.th,
    required this.bot,
    required this.busy,
    required this.liveEnabled,
    required this.onStart,
    required this.onPause,
    required this.onStop,
    required this.onMode,
    required this.onEdit,
    required this.onDelete,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final stale = bot.isStale;

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        gradient: AppGradients.cardSubtle,
        border: Border.all(
          color: bot.banned
              ? AppColors.tradingRed.withValues(alpha: 0.3)
              : AppColors.bgCardBorder,
          width: 1,
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              _StatusDot(status: bot.status, stale: stale, banned: bot.banned),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      bot.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: GoogleFonts.inter(
                        fontSize: 14,
                        fontWeight: FontWeight.w700,
                        color: AppColors.textPrimary,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      '${bot.pair} · ${bot.strategyLabel(th)} · ${bot.timeframe}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTheme.mono(
                        fontSize: 10.5,
                        fontWeight: FontWeight.w600,
                        color: AppColors.textTertiary,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              _ModeChip(
                th: th,
                live: bot.isLive,
                enabled: !busy && (bot.isLive || liveEnabled),
                onTap: onMode,
              ),
            ],
          ),

          // ป้ายแบนต้องทับสถานะ ไม่งั้นผู้ใช้เห็น "กำลังทำงาน" ที่ไม่มีอะไรเกิดขึ้น
          if (bot.banned) ...[
            const SizedBox(height: 10),
            _RowNote(
              icon: Icons.block_rounded,
              color: AppColors.tradingRed,
              text: (bot.bannedReason ?? '').trim().isEmpty
                  ? (th
                      ? 'บอทตัวนี้ถูกทีมงานระงับไว้ — เริ่มทำงานไม่ได้จนกว่าจะปลด'
                      : 'Suspended by the team — it cannot start until released')
                  : (th
                      ? 'ถูกระงับ: ${bot.bannedReason}'
                      : 'Suspended: ${bot.bannedReason}'),
            ),
          ] else if (stale) ...[
            const SizedBox(height: 10),
            _RowNote(
              icon: Icons.warning_amber_rounded,
              color: accent.g2,
              text: th
                  ? 'บอทไม่ได้เดินมา ${bot.minutesSinceRun} นาที — ระบบประมวลผลอาจมีปัญหา แจ้งทีมงานได้เลย'
                  : 'No cycle for ${bot.minutesSinceRun} minutes — the execution service may be down',
            ),
          ],

          const SizedBox(height: 10),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(
                bot.awaitingConfirm
                    ? Icons.pending_actions_rounded
                    : Icons.chat_bubble_outline_rounded,
                size: 12,
                color: bot.awaitingConfirm ? accent.g2 : AppColors.textDisabled,
              ),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  _reasonText(th),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: GoogleFonts.inter(
                    fontSize: 11,
                    color: bot.awaitingConfirm
                        ? accent.g1
                        : AppColors.textSecondary,
                    height: 1.45,
                  ),
                ),
              ),
            ],
          ),

          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: _RowButton(
                  icon: bot.isRunning
                      ? Icons.pause_rounded
                      : Icons.play_arrow_rounded,
                  label: bot.isRunning
                      ? (th ? 'พัก' : 'Pause')
                      : (th ? 'เริ่ม' : 'Start'),
                  primary: !bot.isRunning,
                  // ด่านทั้งหมดอยู่ที่ "เริ่ม" เท่านั้น — พัก/หยุด ต้องกดได้เสมอ
                  enabled: !busy && (bot.isRunning || !bot.banned),
                  onTap: bot.isRunning ? onPause : onStart,
                ),
              ),
              if (!bot.isStopped) ...[
                const SizedBox(width: 8),
                Expanded(
                  child: _RowButton(
                    icon: Icons.stop_rounded,
                    label: th ? 'หยุด' : 'Stop',
                    enabled: !busy,
                    onTap: onStop,
                  ),
                ),
              ],
              const SizedBox(width: 8),
              _SquareButton(
                icon: Icons.tune_rounded,
                tooltip: th ? 'แก้ไข' : 'Edit',
                enabled: !busy,
                onTap: onEdit,
              ),
              const SizedBox(width: 8),
              _SquareButton(
                icon: Icons.delete_outline_rounded,
                tooltip: th ? 'ลบ' : 'Delete',
                enabled: !busy,
                danger: true,
                onTap: onDelete,
              ),
            ],
          ),
        ],
      ),
    );
  }

  String _reasonText(bool th) {
    final clean = bot.cleanReason;
    if (clean == null || clean.trim().isEmpty) {
      return th ? 'บอทยังไม่ได้ตัดสินใจรอบแรก' : 'No decision yet';
    }
    if (bot.awaitingConfirm) {
      return '${th ? 'รอคุณยืนยัน' : 'Awaiting your confirmation'} · $clean';
    }
    return clean;
  }
}

/// ไฟสถานะ — ต้องหมายถึง "เดินอยู่จริง" ไม่ใช่ "ผู้ใช้กดเปิดไว้"
class _StatusDot extends StatefulWidget {
  final String status;
  final bool stale;
  final bool banned;
  const _StatusDot({
    required this.status,
    required this.stale,
    required this.banned,
  });

  @override
  State<_StatusDot> createState() => _StatusDotState();
}

class _StatusDotState extends State<_StatusDot>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;
  bool _reduceMotion = false;

  @override
  void initState() {
    super.initState();
    _ctrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1400),
      value: 1,
    );
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _reduceMotion = context.watch<AccentProvider>().reduceMotion;
    _sync();
  }

  @override
  void didUpdateWidget(covariant _StatusDot oldWidget) {
    super.didUpdateWidget(oldWidget);
    _sync();
  }

  bool get _alive =>
      widget.status == 'running' && !widget.stale && !widget.banned;

  /// เดินอยู่จริงเท่านั้นถึงจะกะพริบ และเคารพการปิดอนิเมชันของผู้ใช้
  void _sync() {
    if (_alive && !_reduceMotion) {
      if (!_ctrl.isAnimating) _ctrl.repeat(reverse: true);
    } else {
      if (_ctrl.isAnimating) _ctrl.stop();
      _ctrl.value = 1;
    }
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();

    Color color;
    if (widget.banned) {
      color = AppColors.tradingRed;
    } else if (widget.stale) {
      color = accent.g3;
    } else if (widget.status == 'running') {
      color = accent.g2;
    } else if (widget.status == 'paused') {
      color = AppColors.textTertiary;
    } else {
      color = AppColors.textDisabled;
    }

    final animate = _alive && !accent.reduceMotion;

    return AnimatedBuilder(
      animation: _ctrl,
      builder: (_, _) {
        final t = animate ? 0.45 + (_ctrl.value * 0.55) : 1.0;
        return Container(
          width: 9,
          height: 9,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: color.withValues(alpha: t),
            boxShadow: _alive
                ? [
                    BoxShadow(
                      color: color.withValues(alpha: 0.35 * t),
                      blurRadius: 8,
                      spreadRadius: 1,
                    ),
                  ]
                : null,
          ),
        );
      },
    );
  }
}

class _ModeChip extends StatelessWidget {
  final bool th;
  final bool live;
  final bool enabled;
  final VoidCallback onTap;
  const _ModeChip({
    required this.th,
    required this.live,
    required this.enabled,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Opacity(
      opacity: enabled ? 1 : 0.5,
      child: GestureDetector(
        onTap: enabled ? onTap : null,
        behavior: HitTestBehavior.opaque,
        /*
         * ⚠️ นี่คือสวิตช์สลับ "ทดลอง ↔ จริง" — พลาดกดคือเรื่องใหญ่ที่สุดในหน้านี้
         *    ตัวป้ายเองเล็ก (สูงราว 23dp) จึงห่อด้วยกรอบสูง 44dp ตามระยะนิ้วโป้ง
         *    ขั้นต่ำ โดยที่หน้าตายังเท่าเดิม — พื้นที่กดขยาย ไม่ใช่ตัวป้ายโตขึ้น
         */
        child: Container(
          constraints: const BoxConstraints(minHeight: 44, minWidth: 44),
          alignment: Alignment.center,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(999),
              gradient: live ? accent.goldGradient : null,
              color: live ? null : AppColors.bgInputStrong,
              border: Border.all(
                color: live ? Colors.transparent : AppColors.bgCardBorder,
                width: 1,
              ),
            ),
            child: Text(
              live ? (th ? 'จริง' : 'LIVE') : (th ? 'ทดลอง' : 'DEMO'),
              style: GoogleFonts.inter(
                fontSize: 10.5,
                fontWeight: FontWeight.w800,
                color: live ? AppColors.goldTextOn : AppColors.textSecondary,
                letterSpacing: 0.6,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _RowButton extends StatelessWidget {
  final IconData icon;
  final String label;
  final bool primary;
  final bool enabled;
  final VoidCallback onTap;
  const _RowButton({
    required this.icon,
    required this.label,
    required this.enabled,
    required this.onTap,
    this.primary = false,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final gilded = primary && enabled;
    final fg = gilded ? AppColors.goldTextOn : AppColors.textSecondary;
    return Opacity(
      opacity: enabled ? 1 : 0.4,
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: enabled ? onTap : null,
          borderRadius: BorderRadius.circular(12),
          child: Ink(
            height: 38,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(12),
              gradient: gilded ? accent.goldGradient : null,
              color: gilded ? null : AppColors.bgInputStrong,
              border: Border.all(
                color: gilded ? Colors.transparent : AppColors.bgCardBorder,
                width: 1,
              ),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(icon, size: 15, color: fg),
                const SizedBox(width: 5),
                Flexible(
                  child: Text(
                    label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: GoogleFonts.inter(
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                      color: fg,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _SquareButton extends StatelessWidget {
  final IconData icon;
  final bool enabled;
  final bool danger;
  final String tooltip;
  final VoidCallback onTap;
  const _SquareButton({
    required this.icon,
    required this.enabled,
    required this.tooltip,
    required this.onTap,
    this.danger = false,
  });

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: tooltip,
      child: Opacity(
        opacity: enabled ? 1 : 0.4,
        child: Material(
          color: Colors.transparent,
          child: InkWell(
            onTap: enabled ? onTap : null,
            borderRadius: BorderRadius.circular(12),
            child: Ink(
              width: 38,
              height: 38,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(12),
                color: AppColors.bgInputStrong,
                border: Border.all(color: AppColors.bgCardBorder, width: 1),
              ),
              child: Icon(
                icon,
                size: 16,
                color: danger ? AppColors.tradingRed : AppColors.textSecondary,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _EmptyBots extends StatelessWidget {
  final bool th;
  final bool canCreate;
  final VoidCallback onCreate;
  const _EmptyBots({
    required this.th,
    required this.canCreate,
    required this.onCreate,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return GlassCard(
      variant: GlassVariant.standard,
      borderRadius: 16,
      padding: const EdgeInsets.fromLTRB(20, 26, 20, 22),
      child: Column(
        children: [
          Container(
            width: 64,
            height: 64,
            decoration: BoxDecoration(
              gradient: accent.goldGradient,
              borderRadius: BorderRadius.circular(20),
              boxShadow: [
                BoxShadow(
                  color: accent.goldGlow.withValues(alpha: 0.32),
                  blurRadius: 22,
                  spreadRadius: -4,
                ),
              ],
            ),
            child: const Icon(Icons.smart_toy_rounded,
                color: AppColors.goldTextOn, size: 30),
          ),
          const SizedBox(height: 16),
          Text(
            th ? 'ยังไม่มีบอทสักตัว' : 'No bots yet',
            style: GoogleFonts.inter(
              fontSize: 16,
              fontWeight: FontWeight.w800,
              color: AppColors.textPrimary,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            th
                ? 'สร้างบอทตัวแรก เลือกกลยุทธ์กับกรอบความเสี่ยง แล้วปล่อยให้มันเทรดด้วยเครดิตทดลองที่ราคาจริงให้ดูก่อน'
                : 'Create your first bot, pick a strategy and risk limits, and let it trade with demo credits at real market prices.',
            textAlign: TextAlign.center,
            style: GoogleFonts.inter(
              fontSize: 12,
              color: AppColors.textTertiary,
              height: 1.6,
            ),
          ),
          const SizedBox(height: 18),
          SizedBox(
            width: 220,
            child: GradientButton(
              text: canCreate
                  ? (th ? 'สร้างบอทตัวแรก' : 'Create first bot')
                  : (th ? 'โควตาเต็มแล้ว' : 'Quota is full'),
              icon: Icons.add_rounded,
              height: 46,
              onPressed: canCreate ? onCreate : null,
            ),
          ),
          if (!canCreate) ...[
            const SizedBox(height: 8),
            Text(
              th
                  ? 'กด "หยุด" บอทตัวเก่าก่อน — การ "พัก" ยังกินโควตาอยู่'
                  : 'Stop an old bot first — pausing still uses the quota',
              textAlign: TextAlign.center,
              style: GoogleFonts.inter(
                fontSize: 10.5,
                color: AppColors.textTertiary,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// บอทแพลนฟรีเดินอยู่ในแอพเครื่องนี้เท่านั้น — ต้องบอกตรงๆ
/// และถ้าลูปถูกหยุดเพราะเซิร์ฟเวอร์ปฏิเสธ ต้องมีเหตุผล + ปุ่มลองใหม่
class _AppLoopBanner extends StatelessWidget {
  final bool th;
  final DateTime? lastTick;
  final bool running;
  final String? blockedCode;
  final VoidCallback onRetry;

  /// เหลืออีกกี่วินาทีถึงจะยิงได้ (0 = กดได้เลย)
  /// โดนจำกัดอัตราแล้วปุ่มต้องบอกว่าต้องรอ ไม่ใช่กดได้แต่ไม่เกิดอะไรขึ้น
  final int cooldownSeconds;

  const _AppLoopBanner({
    required this.th,
    required this.lastTick,
    required this.running,
    required this.blockedCode,
    required this.onRetry,
    this.cooldownSeconds = 0,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      padding: const EdgeInsets.all(13),
      decoration: BoxDecoration(
        color: accent.goldTint,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: accent.goldBorder, width: 1),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            blockedCode == null
                ? Icons.phone_iphone_rounded
                : Icons.pause_circle_outline_rounded,
            size: 18,
            color: accent.g2,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  blockedCode != null
                      ? (th
                          ? 'ลูปในแอพหยุดไว้ชั่วคราว'
                          : 'The in-app loop is paused')
                      : (running
                          ? (th
                              ? 'บอทกำลังเดินอยู่ในแอพเครื่องนี้'
                              : 'Your bot runs inside this app')
                          : (th
                              ? 'บอทตัวนี้ต้องเดินจากแอพเครื่องนี้'
                              : 'This bot has to be driven from the app')),
                  style: GoogleFonts.inter(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  blockedCode != null
                      ? AiBotErrorText.of(blockedCode!, null, th)
                      : (th
                          ? 'ปิดแอพหรือสลับไปแอพอื่น บอทจะหยุดทันที — อัปเกรดเป็นแพลนคลาวด์ถ้าอยากให้เดินตลอด 24 ชม.'
                          : 'Closing the app or switching away stops it — upgrade to a cloud plan to keep it running 24/7.'),
                  style: GoogleFonts.inter(
                    fontSize: 11,
                    color: AppColors.textSecondary,
                    height: 1.5,
                  ),
                ),
                if (lastTick != null && blockedCode == null) ...[
                  const SizedBox(height: 5),
                  Text(
                    '${th ? 'เดินรอบล่าสุด' : 'Last cycle'} · ${DateFormat('HH:mm:ss').format(lastTick!.toLocal())}',
                    style: AppTheme.mono(
                      fontSize: 10,
                      fontWeight: FontWeight.w600,
                      color: AppColors.textTertiary,
                    ),
                  ),
                ],
                if (blockedCode != null) ...[
                  const SizedBox(height: 10),
                  SizedBox(
                    height: 34,
                    child: GradientButton(
                      text: cooldownSeconds > 0
                          ? (th
                              ? 'รออีก $cooldownSeconds วินาที'
                              : 'Wait $cooldownSeconds sec')
                          : (th ? 'ลองเดินใหม่' : 'Try again'),
                      height: 34,
                      variant: ButtonVariant.outline,
                      onPressed: cooldownSeconds > 0 ? null : onRetry,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// พอร์ตทดลอง
// ═══════════════════════════════════════════════════════════════════════════

class _DemoCard extends StatelessWidget {
  final bool th;
  final AiBotDemo demo;
  const _DemoCard({required this.th, required this.demo});

  @override
  Widget build(BuildContext context) {
    final s = demo.summary;
    final a = demo.account;
    return GlassCard(
      variant: GlassVariant.elevated,
      borderRadius: 16,
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: _KeyValue(
                  label: th ? 'เครดิตทดลองคงเหลือ' : 'Demo credits left',
                  value: '\$${_money(a.balance, th: th)}',
                ),
              ),
              Expanded(
                child: _KeyValue(
                  label: th ? 'ทุนตั้งต้น' : 'Starting capital',
                  value: '\$${_money(a.startingBalance, digits: 0, th: th)}',
                  align: CrossAxisAlignment.end,
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Container(height: 1, color: AppColors.divider),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: _KeyValue(
                  label: th ? 'จำนวนไม้' : 'Trades',
                  value: '${s.tradeCount}',
                ),
              ),
              Expanded(
                child: _KeyValue(
                  label: th ? 'ปิดแล้ว' : 'Closed',
                  value: '${s.closedCount}',
                ),
              ),
              Expanded(
                child: _KeyValue(
                  label: th ? 'ชนะ/แพ้' : 'W / L',
                  value: '${s.wins}W / ${s.losses}L',
                ),
              ),
              Expanded(
                child: _KeyValue(
                  label: th ? 'ค่าธรรมเนียม' : 'Fees',
                  value: '\$${_money(s.totalFees, th: th)}',
                  align: CrossAxisAlignment.end,
                ),
              ),
            ],
          ),

          // ผลแยกรายกลยุทธ์ = คำตอบของ "กลยุทธ์ไหนดีกว่า" ซึ่งฝั่งเว็บยังไม่เคยแสดง
          if (demo.portfolios.length > 1) ...[
            const SizedBox(height: 14),
            Text(
              th ? 'ผลแยกรายกลยุทธ์' : 'By strategy',
              style: GoogleFonts.inter(
                fontSize: 11,
                fontWeight: FontWeight.w700,
                color: AppColors.textTertiary,
                letterSpacing: 0.4,
              ),
            ),
            const SizedBox(height: 8),
            ...demo.portfolios.map(
              (p) => Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        p.strategy ??
                            (th ? 'พอร์ตรวม (ของเก่า)' : 'Legacy pool'),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: GoogleFonts.inter(
                          fontSize: 11.5,
                          fontWeight: FontWeight.w600,
                          color: AppColors.textSecondary,
                        ),
                      ),
                    ),
                    Text(
                      _signedMoney(p.pnl, th: th),
                      style: AppTheme.mono(
                        fontSize: 11.5,
                        fontWeight: FontWeight.w700,
                        color: p.pnl == 0
                            ? AppColors.textSecondary
                            : (p.pnl > 0
                                ? AppColors.tradingGreen
                                : AppColors.tradingRed),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],

          const SizedBox(height: 12),
          Text(
            th
                ? 'จำลองด้วยค่าธรรมเนียม ${a.feeRate}% และ slippage ${a.slippageBps} bps ทุกไม้ (ไปกลับ ${a.roundTripBps.toStringAsFixed(0)} bps) — ตั้งใจให้แย่กว่าจริงเล็กน้อย จะได้ไม่หลอกตัวเอง · ล้างได้อีก ${a.resetsLeft} ครั้งวันนี้'
                : 'Simulated with ${a.feeRate}% fees and ${a.slippageBps} bps slippage per trade (${a.roundTripBps.toStringAsFixed(0)} bps round trip) — deliberately slightly worse than reality · ${a.resetsLeft} resets left today',
            style: GoogleFonts.inter(
              fontSize: 10.5,
              color: AppColors.textTertiary,
              height: 1.55,
            ),
          ),
        ],
      ),
    );
  }
}

class _KeyValue extends StatelessWidget {
  final String label;
  final String value;
  final CrossAxisAlignment align;
  const _KeyValue({
    required this.label,
    required this.value,
    this.align = CrossAxisAlignment.start,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: align,
      children: [
        Text(
          label,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: GoogleFonts.inter(
            fontSize: 10,
            fontWeight: FontWeight.w600,
            color: AppColors.textTertiary,
            letterSpacing: 0.3,
          ),
        ),
        const SizedBox(height: 4),
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: align == CrossAxisAlignment.end
              ? Alignment.centerRight
              : Alignment.centerLeft,
          child: Text(
            value,
            maxLines: 1,
            style: AppTheme.mono(
              fontSize: 13,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary,
            ),
          ),
        ),
      ],
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// มุมมองตลาดของ AI
// ═══════════════════════════════════════════════════════════════════════════

class _MarketViewCard extends StatelessWidget {
  final bool th;
  final AiMarketView? data;
  const _MarketViewCard({required this.th, required this.data});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final view = data?.view;

    // เปิดระบบแล้วแต่ยังไม่มีมุมมอง = ต้องบอกเหตุผล ห้ามซ่อนแผงเงียบๆ
    if (view == null) {
      return _InfoCard(
        icon: Icons.visibility_off_outlined,
        title: th ? 'ยังไม่มีมุมมองล่าสุด' : 'No recent market view',
        body: data?.reason ??
            (th
                ? 'บอทยังทำงานปกติ — ตัดสินใจจากกฎที่ตรวจย้อนหลังได้ทั้งหมด'
                : 'Bots keep working — they decide from fully auditable rules'),
      );
    }

    return GlassCard(
      variant: GlassVariant.brand,
      borderRadius: 16,
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (data?.shadow == true) ...[
            _RowNote(
              icon: Icons.science_outlined,
              color: accent.g2,
              text: th
                  ? 'ช่วงเก็บสถิติ — AI วิเคราะห์และบันทึกไว้ แต่ยังไม่มีผลต่อการเทรด'
                  : 'Shadow mode — the AI records its view but does not affect trading yet',
            ),
            const SizedBox(height: 12),
          ],
          Row(
            children: [
              Expanded(
                child: _KeyValue(
                  label: th ? 'ท่าทีตลาด' : 'Market stance',
                  value: _regimeText(view.regime, th),
                ),
              ),
              Expanded(
                child: _KeyValue(
                  label: th ? 'ความมั่นใจ' : 'Confidence',
                  value: '${view.confidencePct}%',
                ),
              ),
              Expanded(
                child: _KeyValue(
                  label: th ? 'ตัวคูณขนาดไม้' : 'Size multiplier',
                  value: '${view.sizeMultiplier.toStringAsFixed(2)}×',
                  align: CrossAxisAlignment.end,
                ),
              ),
            ],
          ),
          if ((view.summary ?? '').trim().isNotEmpty) ...[
            const SizedBox(height: 12),
            Text(
              view.summary!,
              style: GoogleFonts.inter(
                fontSize: 12,
                color: AppColors.textSecondary,
                height: 1.6,
              ),
            ),
          ],
          if (view.coins.isNotEmpty) ...[
            const SizedBox(height: 12),
            Container(height: 1, color: AppColors.divider),
            const SizedBox(height: 10),
            ...view.coins.take(6).map(
                  (c) => Padding(
                    padding: const EdgeInsets.only(bottom: 7),
                    child: Row(
                      children: [
                        SizedBox(
                          width: 52,
                          child: Text(
                            c.symbol,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: AppTheme.mono(
                              fontSize: 12,
                              fontWeight: FontWeight.w700,
                              color: AppColors.textPrimary,
                            ),
                          ),
                        ),
                        _StanceChip(stance: c.stance, th: th),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            c.why,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: GoogleFonts.inter(
                              fontSize: 11,
                              color: AppColors.textTertiary,
                            ),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Text(
                          '${c.score >= 0 ? '+' : ''}${c.score.toStringAsFixed(2)}',
                          style: AppTheme.mono(
                            fontSize: 11.5,
                            fontWeight: FontWeight.w700,
                            color: c.score >= 0
                                ? AppColors.tradingGreen
                                : AppColors.tradingRed,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
          ],
          const SizedBox(height: 8),
          Text(
            _footer(view, th),
            style: AppTheme.mono(
              fontSize: 10,
              fontWeight: FontWeight.w600,
              color:
                  view.expiringSoon ? accent.g2 : AppColors.textTertiary,
            ),
          ),
        ],
      ),
    );
  }

  String _footer(AiMarketViewBody view, bool th) {
    final age = view.ageMinutes;
    return <String>[
      if ((view.model ?? '').isNotEmpty) view.model!,
      th
          ? (view.scope == 'tactical' ? 'รอบสั้น' : 'รอบใหญ่')
          : (view.scope == 'tactical' ? 'tactical' : 'strategic'),
      if (age != null) '${th ? 'ประเมิน' : 'assessed'} ${_agoText(age, th)}',
    ].join(' · ');
  }
}

class _StanceChip extends StatelessWidget {
  final String stance;
  final bool th;
  const _StanceChip({required this.stance, required this.th});

  @override
  Widget build(BuildContext context) {
    final String label;
    switch (stance) {
      case 'buy':
        label = th ? 'น่าซื้อ' : 'Buy';
        break;
      case 'avoid':
        label = th ? 'เลี่ยง' : 'Avoid';
        break;
      case 'exit':
        label = th ? 'ควรออก' : 'Exit';
        break;
      default:
        label = th ? 'ถือ' : 'Hold';
    }
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: AppColors.bgInputStrong,
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: Text(
        label,
        style: GoogleFonts.inter(
          fontSize: 9.5,
          fontWeight: FontWeight.w700,
          color: AppColors.textSecondary,
          letterSpacing: 0.4,
        ),
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// แพลนเช่า
// ═══════════════════════════════════════════════════════════════════════════

class _PlansSection extends StatefulWidget {
  final bool th;
  final AiBotCatalog catalog;
  final AiBotSubscription? subscription;
  final bool salesOpen;
  final bool liveEnabled;
  final bool working;
  final void Function(AiBotPlan plan, int days) onRent;
  final VoidCallback onCancel;

  const _PlansSection({
    required this.th,
    required this.catalog,
    required this.subscription,
    required this.salesOpen,
    required this.liveEnabled,
    required this.working,
    required this.onRent,
    required this.onCancel,
  });

  @override
  State<_PlansSection> createState() => _PlansSectionState();
}

class _PlansSectionState extends State<_PlansSection> {
  late int _days;

  @override
  void initState() {
    super.initState();
    final options = _options;
    _days = options.contains(7) ? 7 : options.first;
  }

  /// เลือกจากรายการที่เซิร์ฟเวอร์ให้เท่านั้น — ค่าอื่นถูกปัดเหลือ 1 วันเงียบๆ
  List<int> get _options => widget.catalog.rentalDays.isEmpty
      ? const [1, 7, 30, 90]
      : widget.catalog.rentalDays;

  @override
  Widget build(BuildContext context) {
    final th = widget.th;
    final accent = context.watch<AccentProvider>();
    final currentCode = widget.subscription?.planCode;
    final options = _options;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Text(
              th ? 'จำนวนวัน' : 'Days',
              style: GoogleFonts.inter(
                fontSize: 12,
                fontWeight: FontWeight.w600,
                color: AppColors.textTertiary,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Container(
                padding: const EdgeInsets.all(4),
                decoration: BoxDecoration(
                  color: AppColors.bgInputStrong,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: AppColors.bgCardBorder, width: 1),
                ),
                child: Row(
                  children: [
                    for (int i = 0; i < options.length; i++)
                      Expanded(
                        child: GestureDetector(
                          onTap: () => setState(() => _days = options[i]),
                          behavior: HitTestBehavior.opaque,
                          child: AnimatedContainer(
                            duration: accent.reduceMotion
                                ? Duration.zero
                                : const Duration(milliseconds: 180),
                            margin: EdgeInsets.only(
                                right: i == options.length - 1 ? 0 : 4),
                            padding: const EdgeInsets.symmetric(vertical: 8),
                            decoration: BoxDecoration(
                              borderRadius: BorderRadius.circular(10),
                              gradient: options[i] == _days
                                  ? accent.goldGradient
                                  : null,
                            ),
                            child: Text(
                              '${options[i]}',
                              textAlign: TextAlign.center,
                              maxLines: 1,
                              style: GoogleFonts.inter(
                                fontSize: 11.5,
                                fontWeight: FontWeight.w700,
                                color: options[i] == _days
                                    ? AppColors.goldTextOn
                                    : AppColors.textSecondary,
                              ),
                            ),
                          ),
                        ),
                      ),
                  ],
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),

        if (!widget.liveEnabled)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: _InfoCard(
              title:
                  th ? 'ตอนนี้เปิดเฉพาะโหมดทดลอง' : 'Demo mode only for now',
              body: th
                  ? 'ทุกแพลนยังไม่ส่งคำสั่งด้วยเงินจริง — เทรดด้วยเครดิตจำลองที่ราคาจริงของตลาด'
                  : 'No plan places real orders yet — trading uses simulated credits at real market prices.',
            ),
          ),

        ...widget.catalog.plans.map(
          (plan) => _PlanCard(
            th: th,
            plan: plan,
            days: _days,
            isCurrent: currentCode == plan.code,
            enabled: !widget.working && (plan.isFree || widget.salesOpen),
            onRent: () => widget.onRent(plan, _days),
          ),
        ),

        if (widget.subscription != null && !widget.subscription!.isFree)
          Center(
            child: GestureDetector(
              onTap: widget.working ? null : widget.onCancel,
              behavior: HitTestBehavior.opaque,
              child: Padding(
                padding: const EdgeInsets.all(10),
                child: Text(
                  th ? 'ยกเลิกการเช่า' : 'Cancel rental',
                  style: GoogleFonts.inter(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: AppColors.tradingRed,
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }
}

class _PlanCard extends StatelessWidget {
  final bool th;
  final AiBotPlan plan;
  final int days;
  final bool isCurrent;
  final bool enabled;
  final VoidCallback onRent;

  const _PlanCard({
    required this.th,
    required this.plan,
    required this.days,
    required this.isCurrent,
    required this.enabled,
    required this.onRent,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final features = plan.featureList(th);

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: GlassCard(
        variant: isCurrent ? GlassVariant.gold : GlassVariant.standard,
        borderRadius: 16,
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    plan.label(th),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: GoogleFonts.inter(
                      fontSize: 15,
                      fontWeight: FontWeight.w800,
                      color: AppColors.textPrimary,
                      letterSpacing: -0.2,
                    ),
                  ),
                ),
                if ((plan.badge ?? '').isNotEmpty)
                  _GoldPill(icon: Icons.star_rounded, label: plan.badge!),
              ],
            ),
            const SizedBox(height: 8),

            // "บอทเดินที่ไหน" ต้องอยู่เหนือราคา — คนเข้าใจผิดตรงนี้แล้วรู้สึกไม่คุ้ม
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(
                  plan.runsInCloud
                      ? Icons.cloud_rounded
                      : Icons.phone_iphone_rounded,
                  size: 13,
                  color: accent.g2,
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    plan.runsInCloud
                        ? (th
                            ? 'เดินบนคลาวด์ตลอด 24 ชม. ปิดแอพก็ยังทำงาน'
                            : 'Runs on the cloud 24/7, even with the app closed')
                        : (th
                            ? 'เดินในแอพเครื่องนี้ — ปิดแอพแล้วบอทหยุดทันที'
                            : 'Runs inside this app — closing it stops the bot'),
                    style: GoogleFonts.inter(
                      fontSize: 11,
                      fontWeight: FontWeight.w600,
                      color: AppColors.textSecondary,
                      height: 1.45,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),

            Row(
              crossAxisAlignment: CrossAxisAlignment.baseline,
              textBaseline: TextBaseline.alphabetic,
              children: [
                Text(
                  plan.isFree
                      ? (th ? 'ฟรี' : 'Free')
                      : _money(plan.priceTpixPerDay, digits: 0, th: th),
                  style: AppTheme.mono(
                    fontSize: 20,
                    fontWeight: FontWeight.w700,
                    // "ฟรี" เป็นราคา ไม่ใช่กำไร — ใช้ทองของแบรนด์ ไม่ใช่เขียวเทรด
                    color: plan.isFree
                        ? AppColors.gold2
                        : AppColors.textPrimary,
                  ),
                ),
                if (!plan.isFree) ...[
                  const SizedBox(width: 6),
                  Text(
                    th ? 'TPIX/วัน' : 'TPIX/day',
                    style: GoogleFonts.inter(
                      fontSize: 11,
                      color: AppColors.textTertiary,
                    ),
                  ),
                ],
                const Spacer(),
                Flexible(
                  child: Text(
                    plan.isFree
                        ? (th ? 'ไม่ต้องจ่ายอะไรเลย' : 'Nothing to pay')
                        : '$days${th ? ' วัน' : 'd'} = ${_money(plan.totalPrice(days), digits: 0, th: th)} TPIX',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.end,
                    style: GoogleFonts.inter(
                      fontSize: 11,
                      fontWeight: FontWeight.w600,
                      color: AppColors.textTertiary,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),

            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                _MicroTag(
                    text: th
                        ? 'บอทสูงสุด ${plan.maxBots} ตัว'
                        : 'Up to ${plan.maxBots} bots'),
                // `0.00` ไม่ใช่ "ไม่จำกัด" — ต้องใช้ hasCapitalCap เท่านั้น
                _MicroTag(
                    text: plan.hasCapitalCap
                        ? (th
                            ? 'ทุนต่อไม้ ≤ \$${_money(plan.maxCapitalUsd!, digits: 0, th: th)}'
                            : 'Capital ≤ \$${_money(plan.maxCapitalUsd!, digits: 0, th: th)}')
                        : (th ? 'ไม่จำกัดทุนต่อไม้' : 'No capital cap')),
                _MicroTag(
                    text: th
                        ? '${plan.creditsPerDay} เครดิต/วัน'
                        : '${plan.creditsPerDay} credits/day'),
              ],
            ),

            if (features.isNotEmpty) ...[
              const SizedBox(height: 10),
              ...features.take(4).map(
                    (f) => Padding(
                      padding: const EdgeInsets.only(bottom: 4),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Icon(Icons.check_rounded, size: 12, color: accent.g2),
                          const SizedBox(width: 6),
                          Expanded(
                            child: Text(
                              f,
                              style: GoogleFonts.inter(
                                fontSize: 11,
                                color: AppColors.textSecondary,
                                height: 1.45,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
            ],

            const SizedBox(height: 14),
            GradientButton(
              text: isCurrent
                  ? (plan.isFree
                      ? (th ? 'แพลนที่ใช้อยู่' : 'Current plan')
                      : (th ? 'ต่ออายุ +$days วัน' : 'Renew +$days days'))
                  : (plan.isFree
                      ? (th ? 'เริ่มใช้ฟรี' : 'Start free')
                      : (th ? 'เช่า $days วัน' : 'Rent $days days')),
              height: 44,
              variant: isCurrent ? ButtonVariant.outline : ButtonVariant.gold,
              onPressed: enabled && !(isCurrent && plan.isFree) ? onRent : null,
            ),
            if (!enabled && !plan.isFree) ...[
              const SizedBox(height: 8),
              Text(
                th
                    ? 'ยังไม่เปิดให้เช่าแพลนนี้ — ระหว่างนี้ใช้โหมดทดลองได้เต็มที่'
                    : 'This plan is not rentable yet — the demo mode is free meanwhile',
                style: GoogleFonts.inter(
                  fontSize: 10.5,
                  color: AppColors.textTertiary,
                  height: 1.5,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _MicroTag extends StatelessWidget {
  final String text;
  const _MicroTag({required this.text});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: AppColors.bgInputStrong,
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: Text(
        text,
        style: GoogleFonts.inter(
          fontSize: 10,
          fontWeight: FontWeight.w600,
          color: AppColors.textSecondary,
        ),
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// แพ็กเครดิต
// ═══════════════════════════════════════════════════════════════════════════

class _PacksSection extends StatelessWidget {
  final bool th;
  final List<CreditPack> packs;
  final bool topupOpen;
  final bool working;
  final bool showWelcome;
  final VoidCallback onWelcome;
  final void Function(CreditPack pack) onPick;

  const _PacksSection({
    required this.th,
    required this.packs,
    required this.topupOpen,
    required this.working,
    required this.showWelcome,
    required this.onWelcome,
    required this.onPick,
  });

  @override
  Widget build(BuildContext context) {
    final enabled = topupOpen && !working;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (showWelcome)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: _NoticeCard(
              icon: Icons.card_giftcard_rounded,
              title: th ? 'รับเครดิตต้อนรับฟรี' : 'Claim free welcome credits',
              body: th
                  ? 'เครดิตชุดแรกสำหรับทดลองระบบ — รับได้ครั้งเดียวต่อกระเป๋า'
                  : 'A starter batch of credits to try the system — once per wallet',
              actionLabel: th ? 'รับเครดิตต้อนรับ' : 'Claim credits',
              actionIcon: Icons.redeem_rounded,
              onAction: working ? null : onWelcome,
            ),
          ),
        // ธงจากเซิร์ฟเวอร์ปิดอยู่ = ปิดปุ่มตั้งแต่แรก ไม่ปล่อยให้กดจนเจอ error
        if (!topupOpen)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: _InfoCard(
              icon: Icons.lock_clock_rounded,
              title: th ? 'ยังไม่เปิดให้เติมเครดิต' : 'Top-up is not open yet',
              body: th
                  ? 'รอประกาศเปิดระบบชำระเงิน ระหว่างนี้ใช้เครดิตต้อนรับทดลองได้เต็มที่'
                  : 'We will announce it when payments go live. Use the welcome credits meanwhile.',
            ),
          ),
        GridView.count(
          crossAxisCount: 2,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          mainAxisSpacing: 10,
          crossAxisSpacing: 10,
          childAspectRatio: 1.85,
          children: packs
              .map((p) => _PackTile(
                    th: th,
                    pack: p,
                    enabled: enabled,
                    onTap: () => onPick(p),
                  ))
              .toList(),
        ),
      ],
    );
  }
}

class _PackTile extends StatelessWidget {
  final bool th;
  final CreditPack pack;
  final bool enabled;
  final VoidCallback onTap;
  const _PackTile({
    required this.th,
    required this.pack,
    required this.enabled,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return Opacity(
      opacity: enabled ? 1 : 0.5,
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: enabled ? onTap : null,
          borderRadius: BorderRadius.circular(16),
          child: Ink(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(16),
              gradient: AppGradients.cardSubtle,
              border: Border.all(color: AppColors.bgCardBorder, width: 1),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: Text(
                    _int(pack.credits.toDouble()),
                    maxLines: 1,
                    style: AppTheme.mono(
                      fontSize: 19,
                      fontWeight: FontWeight.w700,
                      color: AppColors.textPrimary,
                    ),
                  ),
                ),
                if (pack.bonus > 0) ...[
                  const SizedBox(height: 2),
                  Text(
                    th ? 'โบนัส ${pack.bonus}' : '${pack.bonus} bonus',
                    style: GoogleFonts.inter(
                      fontSize: 10.5,
                      fontWeight: FontWeight.w700,
                      // โบนัสเครดิตเป็นของแถม ไม่ใช่กำไรจากการเทรด
                      color: AppColors.gold2,
                    ),
                  ),
                ],
                const SizedBox(height: 5),
                Text(
                  '${_int(pack.priceTpix.toDouble())} TPIX',
                  style: AppTheme.mono(
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textTertiary,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// ที่ปรึกษา AI
// ═══════════════════════════════════════════════════════════════════════════

class _AdvisorCard extends StatelessWidget {
  final bool th;
  final AiAdvice? advice;
  final bool asking;
  final bool ready;
  final VoidCallback onAsk;

  const _AdvisorCard({
    required this.th,
    required this.advice,
    required this.asking,
    required this.ready,
    required this.onAsk,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final a = advice;
    return GlassCard(
      variant: GlassVariant.standard,
      borderRadius: 16,
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            th
                ? 'อ่านสถิติย้อนหลังของบอทคุณแล้วให้ความเห็นว่าควรปรับตรงไหน'
                : "Reads your bots' track record and suggests what to adjust",
            style: GoogleFonts.inter(
              fontSize: 12,
              color: AppColors.textSecondary,
              height: 1.55,
            ),
          ),
          if (!ready) ...[
            const SizedBox(height: 10),
            _RowNote(
              icon: Icons.link_off_rounded,
              color: accent.g2,
              text: th
                  ? 'เชื่อมและยืนยันกระเป๋าก่อนถึงจะขอคำแนะนำได้'
                  : 'Connect and verify your wallet to get advice',
            ),
          ] else if (a != null) ...[
            const SizedBox(height: 12),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: AppColors.bgInput,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: AppColors.bgCardBorder, width: 1),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    a.ok
                        ? a.text
                        : (a.reason ??
                            (th
                                ? 'ยังขอคำแนะนำไม่ได้ในตอนนี้'
                                : 'Advice is not available right now')),
                    style: GoogleFonts.inter(
                      fontSize: 12,
                      color:
                          a.ok ? AppColors.textPrimary : AppColors.textTertiary,
                      height: 1.65,
                    ),
                  ),
                  if (a.ok) ...[
                    const SizedBox(height: 8),
                    Text(
                      a.provider,
                      style: AppTheme.mono(
                        fontSize: 9.5,
                        fontWeight: FontWeight.w600,
                        color: AppColors.textTertiary,
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ],
          const SizedBox(height: 14),
          GradientButton(
            text: asking
                ? (th ? 'กำลังวิเคราะห์…' : 'Analysing…')
                : (a?.ok == true
                    ? (th ? 'ขอใหม่' : 'Ask again')
                    : (th ? 'ขอคำแนะนำ' : 'Get advice')),
            icon: Icons.psychology_alt_rounded,
            height: 44,
            variant: ButtonVariant.outline,
            isLoading: asking,
            onPressed: ready && !asking ? onAsk : null,
          ),
          const SizedBox(height: 10),
          Text(
            th
                ? 'เป็นความเห็นประกอบการตัดสินใจ ไม่ใช่คำสั่งซื้อขาย — บอทยังเดินตามกฎที่คุณตั้งไว้เหมือนเดิม'
                : 'An opinion to weigh, not a trade instruction — your bots still follow the rules you set.',
            style: GoogleFonts.inter(
              fontSize: 10.5,
              color: AppColors.textTertiary,
              height: 1.55,
            ),
          ),
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// โครงกระดูกตอนโหลด
// ═══════════════════════════════════════════════════════════════════════════

class _HeroSkeleton extends StatelessWidget {
  const _HeroSkeleton();

  @override
  Widget build(BuildContext context) {
    return const GlassCard(
      variant: GlassVariant.hero,
      borderRadius: 22,
      padding: EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          /*
           * ระยะห่างทุกตัวต้องตรงกับ _HeroSummary เป๊ะๆ (10 · 12 · 16 · 14 · 12)
           * ไม่งั้นพอข้อมูลมาถึง การ์ดจะเปลี่ยนความสูงกะทันหัน เนื้อหาที่อยู่ใต้
           * ทั้งหน้ากระโดดตาม — ผู้ใช้ที่กำลังจะแตะปุ่มอยู่พอดีจะกดโดนของผิด
           */
          ShimmerBox(width: 140, height: 14, borderRadius: 6),
          SizedBox(height: 10),
          ShimmerBox(width: 200, height: 32, borderRadius: 10),
          SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                  child: ShimmerBox(width: 0, height: 46, borderRadius: 12)),
              SizedBox(width: 8),
              Expanded(
                  child: ShimmerBox(width: 0, height: 46, borderRadius: 12)),
              SizedBox(width: 8),
              Expanded(
                  child: ShimmerBox(width: 0, height: 46, borderRadius: 12)),
            ],
          ),
          SizedBox(height: 16),
          Row(
            children: [
              Expanded(
                  child: ShimmerBox(width: 0, height: 70, borderRadius: 16)),
              SizedBox(width: 10),
              Expanded(
                  child: ShimmerBox(width: 0, height: 70, borderRadius: 16)),
              SizedBox(width: 10),
              Expanded(
                  child: ShimmerBox(width: 0, height: 70, borderRadius: 16)),
            ],
          ),
          // เส้นคั่น + บรรทัดแพลน — ของจริงมี โครงกระดูกก็ต้องมี
          SizedBox(height: 14),
          Divider(height: 1, thickness: 1, color: AppColors.divider),
          SizedBox(height: 12),
          ShimmerBox(width: 190, height: 14, borderRadius: 6),
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// ชีตสร้าง / แก้ไขบอท
// ═══════════════════════════════════════════════════════════════════════════

class _BotEditorSheet extends StatefulWidget {
  final AiBotCatalog catalog;
  final List<String> unlocked;
  final AiBot? existing;
  final List<String> pairs;
  final String defaultName;

  /// บันทึกจริง — คืนบอทที่บันทึกแล้วเมื่อสำเร็จ · null เมื่อเซิร์ฟเวอร์ปฏิเสธ
  /// (ชีตจะอยู่ต่อพร้อมข้อความบอกเหตุผล ไม่ทิ้งค่าที่ผู้ใช้กรอกมา)
  final Future<AiBot?> Function(AiBotDraft draft) onSave;

  const _BotEditorSheet({
    required this.catalog,
    required this.unlocked,
    required this.existing,
    required this.pairs,
    required this.defaultName,
    required this.onSave,
  });

  @override
  State<_BotEditorSheet> createState() => _BotEditorSheetState();
}

class _BotEditorSheetState extends State<_BotEditorSheet> {
  /// ค่าทั้งฟอร์มอยู่ใน draft ก้อนเดียว — payload ถูกประกอบที่ AiBotDraft
  /// จุดเดียว ซึ่งเป็นที่ที่กับดัก `max_position_usd_requested` ถูกปิดไว้แล้ว
  late AiBotDraft _draft;

  late final TextEditingController _name;
  late final TextEditingController _maxPosition;
  late final TextEditingController _stopLoss;
  late final TextEditingController _takeProfit;
  late final TextEditingController _dailyLoss;

  late final List<String> _pairs;
  String? _formError;

  /// กำลังยิงบันทึกอยู่ — ใช้กันกดรัวและเปลี่ยนปุ่มเป็นสถานะรอ
  bool _saving = false;

  /// ช่องที่เซิร์ฟเวอร์บอกว่าผิด (จาก 422) — แปะใต้ช่องนั้นตรงๆ
  Map<String, List<String>> _serverErrors = const {};

  @override
  void initState() {
    super.initState();
    final e = widget.existing;

    _pairs = List<String>.from(widget.pairs);
    final startPair = e?.pair ??
        (_pairs.contains('BTC/USDT') ? 'BTC/USDT' : _pairs.first);
    if (!_pairs.contains(startPair)) _pairs.insert(0, startPair);

    if (e != null) {
      // เปิดฟอร์มแก้ไข: ช่อง "ทุนต่อไม้" ตั้งต้นด้วยค่าที่ผู้ใช้ตั้งใจ
      // ไม่ใช่ค่าที่เพดานแพลนบีบลงมา (AiBotDraft.fromBot จัดการให้แล้ว)
      _draft = AiBotDraft.fromBot(e);
    } else {
      _draft = AiBotDraft.blank(
        catalog: widget.catalog,
        strategy: _firstSelectable(),
        name: widget.defaultName,
        pair: startPair,
      );
    }

    _name = TextEditingController(text: _draft.name);
    _maxPosition = TextEditingController(text: _plain(_draft.maxPositionUsd));
    _stopLoss = TextEditingController(text: _plain(_draft.stopLossPct));
    _takeProfit = TextEditingController(text: _plain(_draft.takeProfitPct));
    _dailyLoss = TextEditingController(text: _plain(_draft.maxDailyLossUsd));
  }

  @override
  void dispose() {
    _name.dispose();
    _maxPosition.dispose();
    _stopLoss.dispose();
    _takeProfit.dispose();
    _dailyLoss.dispose();
    super.dispose();
  }

  AiBotStrategy _firstSelectable() {
    for (final s in widget.catalog.strategies) {
      if (_selectable(s)) return s;
    }
    return widget.catalog.strategies.first;
  }

  /// ปลดล็อกตามแพลน **และ** เปิดใช้จริงได้ — สองเงื่อนไขคนละเรื่องกัน
  /// (ตอนแก้บอทเดิม กลยุทธ์ของมันเองต้องเลือกได้เสมอ ไม่งั้นแก้ชื่อก็ไม่ได้)
  bool _selectable(AiBotStrategy s) {
    final own = widget.existing?.strategy == s.code;
    return own || (widget.unlocked.contains(s.code) && s.available);
  }

  List<String> _timeframes() {
    final s = widget.catalog.strategy(_draft.strategy);
    if (s != null && s.timeframes.isNotEmpty) return s.timeframes;
    return widget.catalog.timeframes.isEmpty
        ? const ['1h']
        : widget.catalog.timeframes;
  }

  bool _flag(String key, bool fallback) =>
      _draft.params[key] as bool? ?? fallback;

  void _setFlag(String key, bool value) {
    setState(() {
      _draft = _draft.copyWith(
        params: <String, dynamic>{..._draft.params, key: value},
      );
    });
  }

  /// เปลี่ยนกลยุทธ์ = พารามิเตอร์ชุดเดิมใช้ไม่ได้ ต้องรีเซ็ตพร้อมดันกรอบเวลา
  /// ให้อยู่ในชุดที่กลยุทธ์ใหม่รองรับ (ไม่งั้นโดน 422 ตอนกดบันทึก)
  void _pickStrategy(AiBotStrategy s) {
    setState(() => _draft = _draft.withStrategy(s, widget.catalog));
  }

  double? _read(TextEditingController c) =>
      double.tryParse(c.text.trim().replaceAll(',', ''));

  double _clamp(double v, LimitRange r) =>
      v < r.min ? r.min : (v > r.max ? r.max : v);

  Future<void> _submit() async {
    if (_saving) return; // กันกดรัว — คำขอซ้ำสร้างบอทซ้ำได้จริง
    final th = context.read<LocaleProvider>().isThai;
    final limits = widget.catalog.limits;

    final name = _name.text.trim();
    if (name.isEmpty) {
      setState(() => _formError =
          th ? 'ตั้งชื่อบอทก่อนบันทึก' : 'Name the bot before saving');
      return;
    }

    final pos = _read(_maxPosition);
    final sl = _read(_stopLoss);
    final tp = _read(_takeProfit);
    final dl = _read(_dailyLoss);
    if (pos == null || sl == null || tp == null || dl == null) {
      setState(() => _formError = th
          ? 'กรอกตัวเลขในกรอบความเสี่ยงให้ครบทุกช่อง'
          : 'Fill every risk field with a number');
      return;
    }

    final draft = _draft.copyWith(
      name: name,
      maxPositionUsd: _clamp(pos, limits.maxPositionUsd),
      stopLossPct: _clamp(sl, limits.stopLossPct),
      takeProfitPct: _clamp(tp, limits.takeProfitPct),
      maxDailyLossUsd: _clamp(dl, limits.maxDailyLossUsd),
    );

    setState(() {
      _saving = true;
      _formError = null;
      _serverErrors = const {};
    });

    final saved = await widget.onSave(draft);
    if (!mounted) return;

    if (saved != null) {
      Navigator.pop(context, saved);
      return;
    }

    // เซิร์ฟเวอร์ปฏิเสธ — อยู่ต่อ เก็บค่าที่กรอกไว้ครบ แล้วบอกว่าผิดตรงไหน
    final provider = context.read<AiBotProvider>();
    setState(() {
      _saving = false;
      _serverErrors = provider.fieldErrors;
      _formError = provider.errorText(th);
    });
  }

  /// ข้อความผิดพลาดของช่องนั้นจากเซิร์ฟเวอร์ (422) — null = ไม่มีปัญหา
  ///
  /// ชื่อคีย์ตามที่ validator ฝั่ง Laravel ส่งกลับมา เช่น `name` · `pair` ·
  /// `risk.max_position_usd` จึงรับได้ทั้งชื่อเต็มและชื่อท้าย
  String? _serverError(String field) {
    for (final entry in _serverErrors.entries) {
      final key = entry.key;
      if (key == field || key.endsWith('.$field')) {
        if (entry.value.isNotEmpty) return entry.value.first;
      }
    }
    return null;
  }

  /// แปะข้อความผิดพลาดใต้ช่อง — ไม่มีก็ไม่กินที่
  Widget _fieldIssue(String field) {
    final message = _serverError(field);
    if (message == null) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: _RowNote(
        icon: Icons.error_outline_rounded,
        color: AppColors.tradingRed,
        text: message,
      ),
    );
  }

  OutlineInputBorder _border(Color color, {double width = 1}) =>
      OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: BorderSide(color: color, width: width),
      );

  @override
  Widget build(BuildContext context) {
    final th = context.watch<LocaleProvider>().isThai;
    final accent = context.watch<AccentProvider>();
    final limits = widget.catalog.limits;
    final editing = widget.existing != null;
    final holding = widget.existing?.position != null;
    final selected = widget.catalog.strategy(_draft.strategy);

    return ClipRRect(
      borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 22, sigmaY: 22),
        child: Container(
          constraints: BoxConstraints(
            maxHeight: MediaQuery.of(context).size.height * 0.9,
          ),
          decoration: BoxDecoration(
            gradient: const LinearGradient(
              colors: [Color(0xF21A1C24), Color(0xF20E0F14)],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
            borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
            border: Border(
              top: BorderSide(color: accent.goldBorder, width: kGoldEdgeWidth),
            ),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 44,
                height: 4,
                margin: const EdgeInsets.only(top: 12),
                decoration: BoxDecoration(
                  gradient: accent.goldGradient,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
              Flexible(
                child: SingleChildScrollView(
                  padding: EdgeInsets.fromLTRB(
                    20,
                    18,
                    20,
                    26 + MediaQuery.of(context).viewInsets.bottom,
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        editing
                            ? (th ? 'แก้ไขบอท' : 'Edit bot')
                            : (th ? 'สร้างบอทใหม่' : 'New bot'),
                        style: GoogleFonts.inter(
                          fontSize: 18,
                          fontWeight: FontWeight.w800,
                          color: AppColors.textPrimary,
                        ),
                      ),
                      if (editing) ...[
                        const SizedBox(height: 6),
                        Text(
                          th
                              ? 'บันทึกแล้วมีผลทันที แม้บอทกำลังทำงานอยู่'
                              : 'Changes take effect immediately, even while the bot runs',
                          style: GoogleFonts.inter(
                            fontSize: 11,
                            color: AppColors.textTertiary,
                          ),
                        ),
                      ],
                      const SizedBox(height: 16),

                      _FieldLabel(th ? 'ชื่อบอท' : 'Bot name'),
                      TextField(
                        controller: _name,
                        maxLength: limits.maxNameLength,
                        style: GoogleFonts.inter(
                          color: AppColors.textPrimary,
                          fontSize: 14,
                        ),
                        cursorColor: accent.g2,
                        decoration: InputDecoration(
                          isDense: true,
                          filled: true,
                          counterText: '',
                          fillColor: AppColors.bgInput,
                          hintText: th
                              ? 'เช่น กริด BTC กลางคืน'
                              : 'e.g. BTC grid overnight',
                          hintStyle: GoogleFonts.inter(
                            color: AppColors.textDisabled,
                            fontSize: 14,
                          ),
                          contentPadding: const EdgeInsets.symmetric(
                              horizontal: 14, vertical: 13),
                          enabledBorder: _border(AppColors.bgCardBorder),
                          focusedBorder: _border(accent.g2, width: 1.5),
                          border: _border(AppColors.bgCardBorder),
                        ),
                      ),

                      const SizedBox(height: 14),
                      _FieldLabel(th ? 'คู่เทรด' : 'Pair'),
                      _PickerBox(
                        value: _draft.pair,
                        enabled: !holding,
                        options: _pairs,
                        onChanged: (v) =>
                            setState(() => _draft = _draft.copyWith(pair: v)),
                      ),
                      if (holding) ...[
                        const SizedBox(height: 6),
                        Text(
                          th
                              ? 'บอทตัวนี้ยังถือของอยู่ — เปลี่ยนคู่ไม่ได้ เพราะไม้เดิมจะค้างบนคู่เก่าโดยไม่มีใครดูแล'
                              : 'This bot holds a position — changing the pair would orphan it.',
                          style: GoogleFonts.inter(
                            fontSize: 10.5,
                            color: AppColors.textTertiary,
                            height: 1.5,
                          ),
                        ),
                      ],

                      const SizedBox(height: 14),
                      _FieldLabel(th ? 'กลยุทธ์' : 'Strategy'),
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: widget.catalog.strategies
                            .map((s) => _StrategyChip(
                                  th: th,
                                  strategy: s,
                                  selected: s.code == _draft.strategy,
                                  enabled: _selectable(s),
                                  onTap: () => _pickStrategy(s),
                                ))
                            .toList(),
                      ),
                      if (selected != null) ...[
                        const SizedBox(height: 8),
                        Text(
                          selected.blockedReason(th) ?? selected.describe(th),
                          style: GoogleFonts.inter(
                            fontSize: 11,
                            color: AppColors.textTertiary,
                            height: 1.5,
                          ),
                        ),
                      ],

                      const SizedBox(height: 14),
                      _FieldLabel(th ? 'กรอบเวลา' : 'Timeframe'),
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: _timeframes()
                            .map((tf) => _SmallChip(
                                  label: tf,
                                  selected: tf == _draft.timeframe,
                                  onTap: () => setState(() =>
                                      _draft = _draft.copyWith(timeframe: tf)),
                                ))
                            .toList(),
                      ),

                      const SizedBox(height: 18),
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Expanded(
                            child: Text(
                              th ? 'กรอบความเสี่ยง' : 'Risk limits',
                              style: GoogleFonts.inter(
                                fontSize: 13,
                                fontWeight: FontWeight.w700,
                                color: AppColors.textPrimary,
                              ),
                            ),
                          ),
                          Flexible(
                            child: Text(
                              th
                                  ? 'ตัดให้อยู่ในเพดานแพลนเสมอ'
                                  : 'clamped to your plan',
                              textAlign: TextAlign.end,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: GoogleFonts.inter(
                                fontSize: 10,
                                color: AppColors.textTertiary,
                              ),
                            ),
                          ),
                        ],
                      ),
                      if (widget.existing?.risk.isCapped == true) ...[
                        const SizedBox(height: 8),
                        _RowNote(
                          icon: Icons.compress_rounded,
                          color: accent.g2,
                          text: th
                              ? 'แพลนของคุณจำกัดทุนต่อไม้ไว้ที่ \$${_money(widget.existing!.risk.maxPositionUsd, digits: 0, th: th)} — ค่าที่ตั้งสูงกว่านั้นจะถูกบีบลงมา'
                              : 'Your plan caps capital per trade at \$${_money(widget.existing!.risk.maxPositionUsd, digits: 0, th: th)} — anything higher is clamped down',
                        ),
                      ],
                      const SizedBox(height: 10),
                      _NumberField(
                        label: th
                            ? 'ทุนสูงสุดต่อไม้ (USD)'
                            : 'Max capital / trade (USD)',
                        controller: _maxPosition,
                        suffix: 'USD',
                      ),
                      _fieldIssue('max_position_usd'),
                      const SizedBox(height: 8),
                      _NumberField(
                        label: th ? 'ตัดขาดทุน' : 'Stop loss',
                        controller: _stopLoss,
                        suffix: '%',
                      ),
                      _fieldIssue('stop_loss_pct'),
                      const SizedBox(height: 8),
                      _NumberField(
                        label: th ? 'ทำกำไร' : 'Take profit',
                        controller: _takeProfit,
                        suffix: '%',
                      ),
                      _fieldIssue('take_profit_pct'),
                      const SizedBox(height: 8),
                      _NumberField(
                        label: th
                            ? 'ขาดทุนสูงสุดต่อวัน (USD)'
                            : 'Max daily loss (USD)',
                        controller: _dailyLoss,
                        suffix: 'USD',
                      ),
                      _fieldIssue('max_daily_loss_usd'),

                      const SizedBox(height: 16),
                      _SwitchRow(
                        title: th
                            ? 'หยุดเทรดช่วงข่าวแรง'
                            : 'Pause on high-impact news',
                        value: _flag('news_filter', true),
                        onChanged: (v) => _setFlag('news_filter', v),
                      ),
                      _SwitchRow(
                        title: th
                            ? 'ให้ AI เลือกเหรียญให้'
                            : 'Let AI pick the coin',
                        value: _flag('auto_pair', false),
                        onChanged: (v) => _setFlag('auto_pair', v),
                      ),

                      if (_formError != null) ...[
                        const SizedBox(height: 12),
                        _RowNote(
                          icon: Icons.error_outline_rounded,
                          color: AppColors.tradingRed,
                          text: _formError!,
                        ),
                      ],

                      const SizedBox(height: 18),
                      GradientButton(
                        text: _saving
                            ? (th ? 'กำลังบันทึก…' : 'Saving…')
                            : (editing
                                ? (th ? 'บันทึกการแก้ไข' : 'Save changes')
                                : (th ? 'สร้างบอท' : 'Create bot')),
                        icon: Icons.check_rounded,
                        // ปิดปุ่มระหว่างยิง — กดซ้ำระหว่างรอสร้างบอทซ้ำได้จริง
                        onPressed: _saving ? null : _submit,
                      ),
                      const SizedBox(height: 8),
                      Center(
                        child: GestureDetector(
                          onTap: () => Navigator.pop(context),
                          behavior: HitTestBehavior.opaque,
                          child: Padding(
                            padding: const EdgeInsets.all(8),
                            child: Text(
                              th ? 'ยกเลิก' : 'Cancel',
                              style: GoogleFonts.inter(
                                fontSize: 13,
                                color: AppColors.textTertiary,
                              ),
                            ),
                          ),
                        ),
                      ),
                    ],
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

class _FieldLabel extends StatelessWidget {
  final String text;
  const _FieldLabel(this.text);

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 7),
      child: Text(
        text,
        style: GoogleFonts.inter(
          fontSize: 11.5,
          fontWeight: FontWeight.w600,
          color: AppColors.textTertiary,
          letterSpacing: 0.2,
        ),
      ),
    );
  }
}

class _PickerBox extends StatelessWidget {
  final String value;
  final List<String> options;
  final bool enabled;
  final ValueChanged<String> onChanged;
  const _PickerBox({
    required this.value,
    required this.options,
    required this.enabled,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    return Opacity(
      opacity: enabled ? 1 : 0.55,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14),
        decoration: BoxDecoration(
          color: AppColors.bgInput,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AppColors.bgCardBorder, width: 1),
        ),
        child: DropdownButtonHideUnderline(
          child: DropdownButton<String>(
            value: value,
            isExpanded: true,
            dropdownColor: AppColors.bgElevated,
            borderRadius: BorderRadius.circular(12),
            icon: const Icon(Icons.expand_more_rounded,
                color: AppColors.textTertiary, size: 20),
            style: AppTheme.mono(
              fontSize: 13.5,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary,
            ),
            onChanged: enabled
                ? (v) {
                    if (v != null) onChanged(v);
                  }
                : null,
            items: options
                .map((o) => DropdownMenuItem(value: o, child: Text(o)))
                .toList(),
          ),
        ),
      ),
    );
  }
}

class _StrategyChip extends StatelessWidget {
  final bool th;
  final AiBotStrategy strategy;
  final bool selected;
  final bool enabled;
  final VoidCallback onTap;

  const _StrategyChip({
    required this.th,
    required this.strategy,
    required this.selected,
    required this.enabled,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Opacity(
      opacity: enabled ? 1 : 0.45,
      child: GestureDetector(
        onTap: enabled ? onTap : null,
        behavior: HitTestBehavior.opaque,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(12),
            gradient: selected ? accent.goldGradient : null,
            color: selected ? null : AppColors.bgInputStrong,
            border: Border.all(
              color: selected ? Colors.transparent : AppColors.bgCardBorder,
              width: 1,
            ),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                strategy.label(th),
                style: GoogleFonts.inter(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color:
                      selected ? AppColors.goldTextOn : AppColors.textSecondary,
                ),
              ),
              // "ยังไม่เปิด" สำคัญกว่าป้ายระดับแพลน — อัปเกรดไปก็ยังใช้ไม่ได้
              if (!strategy.available) ...[
                const SizedBox(width: 6),
                Text(
                  th ? 'ยังไม่เปิด' : 'NOT LIVE',
                  style: GoogleFonts.inter(
                    // ข้อความนี้เปลี่ยนคำตอบว่า "เลือกอันนี้แล้วใช้ได้ไหม"
                    // เล็กและจางกว่าทุกอย่างรอบตัวจนอ่านไม่ทัน = เท่ากับไม่ได้บอก
                    fontSize: 10,
                    fontWeight: FontWeight.w800,
                    color: selected
                        ? AppColors.goldTextOn
                        : AppColors.textSecondary,
                    letterSpacing: 0.5,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _SmallChip extends StatelessWidget {
  final String label;
  final bool selected;
  final VoidCallback onTap;
  const _SmallChip({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(10),
          gradient: selected ? accent.goldGradient : null,
          color: selected ? null : AppColors.bgInputStrong,
          border: Border.all(
            color: selected ? Colors.transparent : AppColors.bgCardBorder,
            width: 1,
          ),
        ),
        child: Text(
          label,
          style: AppTheme.mono(
            fontSize: 12,
            fontWeight: FontWeight.w700,
            color: selected ? AppColors.goldTextOn : AppColors.textSecondary,
          ),
        ),
      ),
    );
  }
}

class _NumberField extends StatelessWidget {
  final String label;
  final TextEditingController controller;
  final String suffix;
  const _NumberField({
    required this.label,
    required this.controller,
    required this.suffix,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      decoration: BoxDecoration(
        color: AppColors.bgInput,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 14),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: GoogleFonts.inter(
                fontSize: 12,
                color: AppColors.textTertiary,
              ),
            ),
          ),
          const SizedBox(width: 8),
          SizedBox(
            width: 96,
            child: TextField(
              controller: controller,
              keyboardType:
                  const TextInputType.numberWithOptions(decimal: true),
              inputFormatters: [
                FilteringTextInputFormatter.allow(RegExp(r'[0-9.]')),
              ],
              style: AppTheme.mono(fontSize: 14),
              textAlign: TextAlign.right,
              cursorColor: accent.g2,
              decoration: const InputDecoration(
                border: InputBorder.none,
                isDense: true,
                hintText: '0',
                hintStyle: TextStyle(color: AppColors.textDisabled),
                contentPadding: EdgeInsets.symmetric(vertical: 13),
              ),
            ),
          ),
          const SizedBox(width: 8),
          Text(
            suffix,
            style: GoogleFonts.inter(
              fontSize: 11.5,
              fontWeight: FontWeight.w700,
              color: AppColors.textSecondary,
            ),
          ),
        ],
      ),
    );
  }
}

class _SwitchRow extends StatelessWidget {
  final String title;
  final bool value;
  final ValueChanged<bool> onChanged;
  const _SwitchRow({
    required this.title,
    required this.value,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        children: [
          Expanded(
            child: Text(
              title,
              style: GoogleFonts.inter(
                fontSize: 12.5,
                fontWeight: FontWeight.w600,
                color: AppColors.textSecondary,
              ),
            ),
          ),
          Switch(
            value: value,
            activeThumbColor: AppColors.goldTextOn,
            activeTrackColor: accent.g2,
            inactiveThumbColor: AppColors.textSecondary,
            inactiveTrackColor: AppColors.bgTertiary,
            onChanged: onChanged,
          ),
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// ตัวช่วยจัดรูปตัวเลข / เวลา
// ═══════════════════════════════════════════════════════════════════════════

String _money(double v, {int digits = 2, required bool th}) {
  final pattern = digits <= 0 ? '#,##0' : '#,##0.${'0' * digits}';
  return NumberFormat(pattern, th ? 'th_TH' : 'en_US').format(v);
}

/// เครื่องหมายลบต้องอยู่หน้าสัญลักษณ์สกุลเงิน — `-$420.25` ไม่ใช่ `$-420.25`
/// กวาดสายตาดูคอลัมน์กำไรขาดทุนเร็วๆ แล้วอ่านผิดง่ายมาก
String _signedMoney(double v, {required bool th}) =>
    '${v < 0 ? '-' : '+'}\$${_money(v.abs(), th: th)}';

String _int(double v) => NumberFormat('#,##0', 'en_US').format(v);

/// ตัดค่าที่มาจาก JSON ให้เป็นข้อความกรอกได้ (100.0 → "100")
String _plain(double v) =>
    v == v.roundToDouble() ? v.toInt().toString() : v.toString();

String _regimeText(String? regime, bool th) {
  switch (regime) {
    case 'risk_on':
      return th ? 'เปิดรับความเสี่ยง' : 'Risk on';
    case 'risk_off':
      return th ? 'หลบความเสี่ยง' : 'Risk off';
    case 'neutral':
      return th ? 'เป็นกลาง' : 'Neutral';
    case 'choppy':
      return th ? 'แกว่งไร้ทิศ' : 'Choppy';
    case null:
      return th ? 'ไม่ระบุ' : 'Unspecified';
    default:
      return regime;
  }
}

String _agoText(int minutes, bool th) {
  final m = math.max(0, minutes);
  if (m < 1) return th ? 'เมื่อครู่' : 'just now';
  if (m < 60) return th ? '$m นาทีที่แล้ว' : '${m}m ago';
  return th
      ? '${m ~/ 60} ชั่วโมง ${m % 60} นาทีที่แล้ว'
      : '${m ~/ 60}h ${m % 60}m ago';
}
