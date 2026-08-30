/// TPIX TRADE — AI TRADE: โซนเลือกแพลนเช่าบอท
///
/// นี่คือหน้าที่ผู้ใช้ตัดสินใจจ่ายเงิน — กฎที่ยึดทั้งไฟล์:
///   1. ธงฟีเจอร์อ่านจากเซิร์ฟเวอร์ (`catalog.features.*`) เท่านั้น ห้ามฮาร์ดโค้ด
///   2. ปุ่มที่กดไม่ได้ ต้องปิดตั้งแต่แรกเห็น + มีเหตุผลอยู่ใต้ปุ่ม
///      ห้ามปล่อยให้กดแล้วค่อยเด้ง error (ผู้ใช้จะคิดว่าระบบพัง)
///   3. กระเป๋าทีมงาน (`status.isAdmin`) ข้ามด่านขายได้ แต่ต้องมีป้ายบอกเสมอ
///   4. ทุกการกดที่เสียเครดิตต้องผ่านกล่องยืนยันที่บอกตัวเลขครบ
///   5. กันกดรัวด้วยธงภายใน + `isWorking` ของหน้าจอแม่
///
/// โมเดลทั้งหมดมาจาก `lib/models/ai_bot_models.dart` (แหล่งเดียวของทั้งฟีเจอร์)
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/locale/locale_provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../models/ai_bot_models.dart';
import '../../providers/accent_provider.dart';
import '../common/glass_card.dart';
import '../common/gradient_button.dart';
import '../common/shimmer_loading.dart';

// ── ตัวช่วยจัดรูปตัวเลข (ใช้ร่วมกับโซนเครดิต) ────────────────

String aiFormatInt(num value, bool isThai) =>
    NumberFormat.decimalPattern(isThai ? 'th_TH' : 'en_US').format(value.round());

String aiFormatMoney(num value, bool isThai, {int digits = 2}) =>
    NumberFormat.decimalPattern(isThai ? 'th_TH' : 'en_US')
        .format(double.parse(value.toStringAsFixed(digits)));

// ── ตัวช่วยอ่านเพดานทุน/กลยุทธ์ของแพลน ────────────────────

/// เพดานทุนต่อไม้ที่ "บังคับจริง" — null = ไม่มีเพดาน
double? _capUsd(AiBotPlan plan) => plan.hasCapitalCap ? plan.maxCapitalUsd : null;

/// เซิร์ฟเวอร์ส่ง 0 มา = ไม่ใช่เพดาน แต่ก็ห้ามแปลว่า "ไม่จำกัด"
bool _capUnspecified(AiBotPlan plan) =>
    plan.maxCapitalUsd != null && !plan.hasCapitalCap;

/// กลยุทธ์ของแพลนที่ใช้ได้จริง (ปลดล็อกแล้ว และเซิร์ฟเวอร์ยังเปิดใช้อยู่)
int _usableStrategies(AiBotCatalog catalog, AiBotPlan plan) {
  var n = 0;
  for (final code in plan.strategies) {
    final s = catalog.strategy(code);
    if (s == null || s.available) n++;
  }
  return n;
}

// ═══════════════════════════════════════════════════════════
// แถบแจ้งสถานะการขาย (ปิดขาย / โหมดทีมงาน)
// ═══════════════════════════════════════════════════════════

/// แถบบนสุดของโซนเช่า — บอกล่วงหน้าว่ากดเช่าได้ไหม ก่อนผู้ใช้จะเลื่อนลงไปเจอปุ่ม
class AiSalesNoticeBanner extends StatelessWidget {
  final AiBotFeatures features;
  final bool isAdmin;

  const AiSalesNoticeBanner({
    super.key,
    required this.features,
    required this.isAdmin,
  });

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final accent = context.watch<AccentProvider>();
    final th = locale.isThai;

    if (isAdmin) {
      return _AiNoticeBox(
        icon: Icons.workspace_premium_rounded,
        strong: true,
        title: th ? 'โหมดทีมงาน' : 'Team mode',
        body: th
            ? 'กระเป๋านี้อยู่ในรายชื่อทีมงาน — เช่าและใช้ได้ทุกฟังก์ชันโดยไม่ต้องรอเปิดขาย'
            : 'This wallet is on the team list — every function is open, no rental gate.',
        accent: accent,
      );
    }

    if (features.salesOpen) return const SizedBox.shrink();

    return _AiNoticeBox(
      icon: Icons.lock_clock_rounded,
      title: th ? 'ยังไม่เปิดให้เช่า' : 'Rentals are not open yet',
      body: th
          ? 'AI TRADE อยู่ระหว่างทดสอบ — ปุ่มเช่าจึงถูกปิดไว้ ระหว่างนี้ใช้โหมดทดลอง '
              'ด้วยเครดิตจำลองที่ราคาจริงได้เต็มที่ ไม่มีค่าใช้จ่าย'
          : 'AI TRADE is still under test, so renting is disabled. Meanwhile the demo '
              'mode trades simulated credits at real market prices, free of charge.',
      accent: accent,
    );
  }
}

// ═══════════════════════════════════════════════════════════
// โซนเลือกแพลน
// ═══════════════════════════════════════════════════════════

class AiPlanSection extends StatefulWidget {
  /// แคตตาล็อกจากเซิร์ฟเวอร์ — null = ยังโหลดไม่ได้
  final AiBotCatalog? catalog;

  /// สถานะกระเป๋า — null = ยังไม่เชื่อม หรือยังไม่ผ่านการยืนยัน
  final AiBotStatus? status;

  final bool isLoadingCatalog;

  /// มีคำสั่งเขียนข้อมูลของหน้าจอค้างอยู่ (ปิดปุ่มทั้งโซน)
  final bool isWorking;

  final bool walletConnected;

  /// กระเป๋าเชื่อมแล้วแต่ยังไม่ได้เซ็นยืนยัน (status = null เพราะโดน 403)
  final bool needsVerification;

  final VoidCallback? onConnectWallet;
  final VoidCallback? onVerifyWallet;

  /// โหลดแคตตาล็อกใหม่เมื่อครั้งก่อนล้มเหลว
  final VoidCallback? onRetryCatalog;

  /// พาไปโซนเติมเครดิต (ใช้ตอนเครดิตไม่พอ)
  final VoidCallback? onNeedTopup;

  /// เช่า/ต่ออายุ — หน้าจอแม่เป็นคนยิง API และแจ้งผลเอง
  final Future<void> Function(String planCode, int days) onRent;

  /// ยกเลิกการเช่า — null = ซ่อนปุ่ม
  final Future<void> Function()? onCancelPlan;

  const AiPlanSection({
    super.key,
    required this.catalog,
    required this.status,
    required this.onRent,
    this.isLoadingCatalog = false,
    this.isWorking = false,
    this.walletConnected = false,
    this.needsVerification = false,
    this.onConnectWallet,
    this.onVerifyWallet,
    this.onRetryCatalog,
    this.onNeedTopup,
    this.onCancelPlan,
  });

  @override
  State<AiPlanSection> createState() => _AiPlanSectionState();
}

class _AiPlanSectionState extends State<AiPlanSection> {
  /// จำนวนวันที่เลือก — เก็บในโซนนี้เอง ค่าเริ่มต้น 7 วันถ้ามีให้เลือก
  int _days = 7;

  /// รหัสแพลนที่กำลังส่งคำขออยู่ (กันกดรัว/กดสองแพลนพร้อมกัน)
  String? _pendingCode;

  /// กำลังยกเลิกการเช่าอยู่
  bool _cancelling = false;

  @override
  void initState() {
    super.initState();
    _days = _pickDefaultDays(widget.catalog?.rentalDays);
  }

  @override
  void didUpdateWidget(covariant AiPlanSection oldWidget) {
    super.didUpdateWidget(oldWidget);
    // แคตตาล็อกเปลี่ยนแล้วจำนวนวันเดิมหายไป → ดึงกลับเข้าช่วงที่เลือกได้จริง
    final days = widget.catalog?.rentalDays;
    if (days != null && days.isNotEmpty && !days.contains(_days)) {
      _days = _pickDefaultDays(days);
    }
  }

  int _pickDefaultDays(List<int>? options) {
    if (options == null || options.isEmpty) return 7;
    if (options.contains(7)) return 7;
    return options.first;
  }

  bool get _busy => widget.isWorking || _pendingCode != null || _cancelling;

  // ── การกระทำ ────────────────────────────────────────────

  Future<void> _rent(AiBotPlan plan) async {
    if (_busy) return;

    final th = context.read<LocaleProvider>().isThai;
    final status = widget.status;
    final sub = status?.subscription;
    final cost = plan.totalCredits(_days);
    final daysLeft = sub?.daysRemaining ?? 0;

    // กำลังทิ้งแพลนที่จ่ายเงินไปแล้วเพื่อไปแพลนอื่น = ต้องเตือนเรื่องคืนเครดิต
    final switchingAway = sub != null &&
        !sub.isFree &&
        daysLeft > 0 &&
        sub.planCode != null &&
        sub.planCode != plan.code;

    if (cost > 0 || switchingAway) {
      final ok = await showAiRentConfirmDialog(
        context,
        plan: plan,
        days: _days,
        creditsBefore: status?.credits ?? 0,
        currentPlanLabel: switchingAway ? sub.label(th) : null,
        currentDaysRemaining: switchingAway ? daysLeft : 0,
        adminBypass: (status?.isAdmin ?? false) &&
            !(widget.catalog?.features.salesOpen ?? false) &&
            cost > 0,
      );
      if (!mounted) return;
      if (ok != true) return;
    }

    setState(() => _pendingCode = plan.code);
    try {
      await widget.onRent(plan.code, _days);
    } finally {
      if (mounted) setState(() => _pendingCode = null);
    }
  }

  Future<void> _cancel() async {
    final handler = widget.onCancelPlan;
    final status = widget.status;
    final sub = status?.subscription;
    if (handler == null || status == null || sub == null || _busy) return;

    final th = context.read<LocaleProvider>().isThai;
    final ok = await showAiCancelRentalDialog(
      context,
      planLabel: sub.label(th),
      daysRemaining: sub.daysRemaining,
      activeBots: status.bots.where((b) => b.countsTowardQuota).length,
    );
    if (!mounted) return;
    if (ok != true) return;

    setState(() => _cancelling = true);
    try {
      await handler();
    } finally {
      if (mounted) setState(() => _cancelling = false);
    }
  }

  // ── UI ─────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final accent = context.watch<AccentProvider>();
    final th = locale.isThai;
    final catalog = widget.catalog;

    if (catalog == null) {
      return widget.isLoadingCatalog
          ? const _PlanSkeleton()
          : _CatalogUnavailable(onRetry: widget.onRetryCatalog);
    }

    final status = widget.status;
    final sub = status?.subscription;
    final features = catalog.features;
    final isAdmin = status?.isAdmin ?? false;
    final paidActive = sub != null && !sub.isFree && sub.daysRemaining > 0;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _SectionTitle(
          icon: Icons.workspace_premium_rounded,
          title: th ? 'เลือกแพลนเช่าบอท' : 'Choose a rental plan',
          subtitle: th
              ? 'จ่ายด้วยเครดิตการทำงาน ยกเลิกได้ตลอด คืนเครดิตของวันที่เหลือ'
              : 'Paid with work credits. Cancel anytime — unused days are refunded.',
        ),
        const SizedBox(height: 14),

        _DaysPicker(
          options: catalog.rentalDays,
          selected: _days,
          enabled: !_busy,
          onSelect: (d) => setState(() => _days = d),
        ),
        const SizedBox(height: 14),

        // เทียบให้เห็นก่อนเห็นราคา — คนที่เข้าใจผิดตรงนี้จะเช่าไปแล้วรู้สึกไม่คุ้ม
        const _ExecutionCompare(),
        const SizedBox(height: 12),

        // โหมดจริงยังปิดอยู่ไหม — ต้องบอกก่อนจ่ายเงิน
        _AiNoticeBox(
          icon: features.liveTrading ? Icons.bolt_rounded : Icons.science_outlined,
          strong: features.liveTrading,
          title: features.liveTrading
              ? (th ? 'โหมดจริงเปิดแล้ว' : 'Live mode is open')
              : (th ? 'ตอนนี้เปิดเฉพาะโหมดทดลอง' : 'Demo mode only for now'),
          body: features.liveTrading
              ? (th
                  ? 'บอทจะเสนอสัญญาณให้คุณกดยืนยันในกระเป๋าเอง ระบบไม่ถือกุญแจของคุณ '
                      'จึงส่งคำสั่งแทนไม่ได้'
                  : 'The bot proposes signals for you to confirm in your own wallet. '
                      'We never hold your keys, so we cannot place orders for you.')
              : (th
                  ? 'ทุกแพลนยังเทรดด้วยเครดิตจำลองที่ราคาจริง ยังไม่มีการส่งคำสั่งด้วยเงินจริง '
                      'เราจะเปิดโหมดจริงเมื่อระบบผ่านการทดสอบครบถ้วน'
                  : 'Every plan still trades simulated credits at real prices — no real '
                      'orders yet. Live mode opens once the system is fully proven.'),
          accent: accent,
        ),
        const SizedBox(height: 16),

        for (var i = 0; i < catalog.plans.length; i++) ...[
          _PlanCard(
            plan: catalog.plans[i],
            previous: i > 0 ? catalog.plans[i - 1] : null,
            catalog: catalog,
            status: status,
            days: _days,
            isAdmin: isAdmin,
            busy: _busy,
            pending: _pendingCode == catalog.plans[i].code,
            walletConnected: widget.walletConnected,
            needsVerification: widget.needsVerification,
            onRent: () => _rent(catalog.plans[i]),
            onConnectWallet: widget.onConnectWallet,
            onVerifyWallet: widget.onVerifyWallet,
            onNeedTopup: widget.onNeedTopup,
          ),
          const SizedBox(height: 12),
        ],

        // ยกเลิกการเช่า — โผล่เฉพาะคนที่เช่าแบบเสียเงินอยู่จริง
        if (paidActive && widget.onCancelPlan != null)
          _CancelRentalRow(
            busy: _busy,
            cancelling: _cancelling,
            daysRemaining: sub.daysRemaining,
            onCancel: _cancel,
          ),
      ],
    );
  }
}

// ── การ์ดแพลนหนึ่งใบ ───────────────────────────────────────

class _PlanCard extends StatelessWidget {
  final AiBotPlan plan;
  final AiBotPlan? previous;
  final AiBotCatalog catalog;
  final AiBotStatus? status;
  final int days;
  final bool isAdmin;
  final bool busy;
  final bool pending;
  final bool walletConnected;
  final bool needsVerification;
  final VoidCallback onRent;
  final VoidCallback? onConnectWallet;
  final VoidCallback? onVerifyWallet;
  final VoidCallback? onNeedTopup;

  const _PlanCard({
    required this.plan,
    required this.previous,
    required this.catalog,
    required this.status,
    required this.days,
    required this.isAdmin,
    required this.busy,
    required this.pending,
    required this.walletConnected,
    required this.needsVerification,
    required this.onRent,
    required this.onConnectWallet,
    required this.onVerifyWallet,
    required this.onNeedTopup,
  });

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final accent = context.watch<AccentProvider>();
    final th = locale.isThai;

    final cost = plan.totalCredits(days);
    final credits = status?.credits ?? 0;
    final currentCode = status?.subscription?.planCode;
    final isCurrent = currentCode != null && currentCode == plan.code;
    final salesOpen = catalog.features.salesOpen;
    final salesBlocked = cost > 0 && !salesOpen && !isAdmin;
    final adminBypass = cost > 0 && !salesOpen && isAdmin;
    final canAfford = credits >= cost;

    final variant = isCurrent
        ? GlassVariant.gold
        : (plan.badge != null ? GlassVariant.brand : GlassVariant.standard);

    final desc = plan.describe(th);

    return GlassCard(
      variant: variant,
      borderRadius: 18,
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
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                    color: AppColors.textPrimary,
                    letterSpacing: -0.2,
                  ),
                ),
              ),
              if (isCurrent)
                _MiniPill(
                  icon: Icons.check_circle_rounded,
                  label: th ? 'ใช้อยู่' : 'Current',
                  accent: accent,
                )
              else if (plan.badge != null)
                _MiniPill(
                  icon: Icons.star_rounded,
                  label: _badgeLabel(plan.badge!, th),
                  accent: accent,
                ),
            ],
          ),
          const SizedBox(height: 8),

          // บอทเดินที่ไหน — ต้องอยู่เหนือราคาเสมอ
          _ExecutionPill(runsInCloud: plan.runsInCloud, accent: accent),

          if (desc != null && desc.trim().isNotEmpty) ...[
            const SizedBox(height: 8),
            Text(
              desc,
              style: GoogleFonts.inter(
                fontSize: 11.5,
                height: 1.45,
                color: AppColors.textSecondary,
              ),
            ),
          ],

          const SizedBox(height: 12),
          _PriceBlock(plan: plan, days: days, cost: cost, th: th, accent: accent),

          const SizedBox(height: 12),
          _SpecRow(plan: plan, catalog: catalog, th: th, accent: accent),

          // ได้อะไรเพิ่มจากขั้นก่อนหน้า — ตัวช่วยเทียบที่เร็วที่สุด
          if (previous != null) ...[
            const SizedBox(height: 10),
            _UpgradeDelta(plan: plan, previous: previous!, th: th, accent: accent),
          ],

          if (plan.featureList(th).isNotEmpty) ...[
            const SizedBox(height: 12),
            for (final f in plan.featureList(th).take(5))
              Padding(
                padding: const EdgeInsets.only(bottom: 5),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(Icons.check_rounded, size: 13, color: accent.g2),
                    const SizedBox(width: 6),
                    Expanded(
                      child: Text(
                        f,
                        style: GoogleFonts.inter(
                          fontSize: 11.5,
                          height: 1.4,
                          color: AppColors.textSecondary,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
          ],

          const SizedBox(height: 14),
          _PlanCta(
            th: th,
            accent: accent,
            cost: cost,
            credits: credits,
            days: days,
            isCurrent: isCurrent,
            salesBlocked: salesBlocked,
            adminBypass: adminBypass,
            canAfford: canAfford,
            busy: busy,
            pending: pending,
            walletConnected: walletConnected,
            needsVerification: needsVerification,
            hasStatus: status != null,
            onRent: onRent,
            onConnectWallet: onConnectWallet,
            onVerifyWallet: onVerifyWallet,
            onNeedTopup: onNeedTopup,
          ),
        ],
      ),
    );
  }

  String _badgeLabel(String badge, bool th) {
    switch (badge.toUpperCase()) {
      case 'FREE':
        return th ? 'ฟรี' : 'FREE';
      case 'POPULAR':
        return th ? 'ยอดนิยม' : 'POPULAR';
      case 'VIP':
        return 'VIP';
      default:
        return badge;
    }
  }
}

// ── บล็อกราคา ─────────────────────────────────────────────

class _PriceBlock extends StatelessWidget {
  final AiBotPlan plan;
  final int days;
  final int cost;
  final bool th;
  final AccentProvider accent;

  const _PriceBlock({
    required this.plan,
    required this.days,
    required this.cost,
    required this.th,
    required this.accent,
  });

  @override
  Widget build(BuildContext context) {
    if (plan.creditsPerDay <= 0) {
      return Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        decoration: BoxDecoration(
          color: accent.goldTint,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: accent.goldBorder, width: 1),
        ),
        child: Row(
          children: [
            Icon(Icons.card_giftcard_rounded, size: 15, color: accent.g2),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                th ? 'ใช้ฟรี ไม่ต้องจ่ายเครดิต' : 'Free — no credits charged',
                style: GoogleFonts.inter(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: accent.g1,
                ),
              ),
            ),
          ],
        ),
      );
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 11),
      decoration: BoxDecoration(
        gradient: AppGradients.cardSubtle,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              Text(
                aiFormatInt(plan.creditsPerDay, th),
                style: AppTheme.mono(
                  fontSize: 22,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
              ),
              const SizedBox(width: 6),
              Text(
                th ? 'เครดิต/วัน' : 'credits/day',
                style: GoogleFonts.inter(
                  fontSize: 11,
                  fontWeight: FontWeight.w600,
                  color: AppColors.textTertiary,
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Row(
            children: [
              Icon(Icons.calculate_outlined, size: 13, color: accent.g2),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  th
                      ? '${aiFormatInt(days, th)} วัน = ${aiFormatInt(cost, th)} เครดิต'
                      : '$days days = ${aiFormatInt(cost, th)} credits',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTheme.mono(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w700,
                    color: accent.g1,
                  ),
                ),
              ),
            ],
          ),
          if (plan.priceTpixPerDay > 0) ...[
            const SizedBox(height: 4),
            Text(
              th
                  ? 'ราคาป้าย ${aiFormatMoney(plan.priceTpixPerDay, th, digits: 0)} TPIX/วัน '
                      '· รวม ${aiFormatMoney(plan.totalPrice(days), th, digits: 0)} TPIX'
                  : 'List ${aiFormatMoney(plan.priceTpixPerDay, th, digits: 0)} TPIX/day '
                      '· ${aiFormatMoney(plan.totalPrice(days), th, digits: 0)} TPIX total',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppTheme.mono(
                fontSize: 10.5,
                fontWeight: FontWeight.w600,
                color: AppColors.textTertiary,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

// ── แถวสเปก 3 ช่อง ────────────────────────────────────────

class _SpecRow extends StatelessWidget {
  final AiBotPlan plan;
  final AiBotCatalog catalog;
  final bool th;
  final AccentProvider accent;

  const _SpecRow({
    required this.plan,
    required this.catalog,
    required this.th,
    required this.accent,
  });

  @override
  Widget build(BuildContext context) {
    final cap = _capUsd(plan);
    final String capText;
    if (cap != null) {
      capText = '\$${aiFormatMoney(cap, th, digits: 0)}';
    } else if (_capUnspecified(plan)) {
      // 0 ไม่ใช่ "ไม่จำกัด" — ห้ามโฆษณาเกินจริง
      capText = '—';
    } else {
      capText = th ? 'ไม่จำกัด' : 'Unlimited';
    }

    final usable = _usableStrategies(catalog, plan);
    final total = plan.strategies.length;
    final stratText =
        (total == 0 || usable == total) ? aiFormatInt(usable, th) : '$usable/$total';

    return Row(
      children: [
        Expanded(
          child: _SpecTile(
            icon: Icons.smart_toy_outlined,
            value: aiFormatInt(plan.maxBots, th),
            label: th ? 'บอทสูงสุด' : 'Max bots',
            accent: accent,
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: _SpecTile(
            icon: Icons.account_balance_wallet_outlined,
            value: capText,
            label: th ? 'ทุนต่อไม้' : 'Per trade',
            accent: accent,
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: _SpecTile(
            icon: Icons.auto_graph_rounded,
            value: stratText,
            label: th ? 'กลยุทธ์' : 'Strategies',
            accent: accent,
          ),
        ),
      ],
    );
  }
}

class _SpecTile extends StatelessWidget {
  final IconData icon;
  final String value;
  final String label;
  final AccentProvider accent;

  const _SpecTile({
    required this.icon,
    required this.value,
    required this.label,
    required this.accent,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(12),
        gradient: AppGradients.cardSubtle,
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: Column(
        children: [
          Icon(icon, size: 15, color: accent.g2),
          const SizedBox(height: 6),
          FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(
              value,
              maxLines: 1,
              style: AppTheme.mono(
                fontSize: 13.5,
                fontWeight: FontWeight.w700,
                color: AppColors.textPrimary,
              ),
            ),
          ),
          const SizedBox(height: 3),
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: GoogleFonts.inter(
              fontSize: 9.5,
              fontWeight: FontWeight.w600,
              color: AppColors.textTertiary,
              letterSpacing: 0.3,
            ),
          ),
        ],
      ),
    );
  }
}

// ── "ได้อะไรเพิ่ม" จากแพลนก่อนหน้า ─────────────────────────

class _UpgradeDelta extends StatelessWidget {
  final AiBotPlan plan;
  final AiBotPlan previous;
  final bool th;
  final AccentProvider accent;

  const _UpgradeDelta({
    required this.plan,
    required this.previous,
    required this.th,
    required this.accent,
  });

  List<String> _deltas() {
    final out = <String>[];

    final botDiff = plan.maxBots - previous.maxBots;
    if (botDiff > 0) {
      out.add(th ? '+$botDiff บอท' : '+$botDiff bots');
    }

    if (!previous.runsInCloud && plan.runsInCloud) {
      out.add(th ? 'เดินบนคลาวด์ 24 ชม.' : 'Runs on cloud 24/7');
    }

    final prevCap = _capUsd(previous);
    final cap = _capUsd(plan);
    if (prevCap != null && cap == null && !_capUnspecified(plan)) {
      out.add(th ? 'ทุนต่อไม้ไม่จำกัด' : 'No capital cap');
    } else if (prevCap != null && cap != null && cap > prevCap) {
      out.add(th
          ? 'ทุนต่อไม้ \$${aiFormatMoney(cap, th, digits: 0)}'
          : 'Per trade \$${aiFormatMoney(cap, th, digits: 0)}');
    }

    final added =
        plan.strategies.where((c) => !previous.strategies.contains(c)).length;
    if (added > 0) {
      out.add(th ? '+$added กลยุทธ์' : '+$added strategies');
    }

    return out;
  }

  @override
  Widget build(BuildContext context) {
    final items = _deltas();
    if (items.isEmpty) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          th ? 'เพิ่มจาก ${previous.label(th)}' : 'Added over ${previous.label(th)}',
          style: GoogleFonts.inter(
            fontSize: 10,
            fontWeight: FontWeight.w600,
            color: AppColors.textTertiary,
            letterSpacing: 0.4,
          ),
        ),
        const SizedBox(height: 6),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final item in items)
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(999),
                  color: accent.goldTint,
                  border: Border.all(color: accent.goldBorder, width: 1),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.arrow_upward_rounded, size: 10, color: accent.g2),
                    const SizedBox(width: 4),
                    Text(
                      item,
                      style: GoogleFonts.inter(
                        fontSize: 10,
                        fontWeight: FontWeight.w700,
                        color: accent.g1,
                        letterSpacing: 0.2,
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ),
      ],
    );
  }
}

// ── ปุ่มของการ์ดแพลน + เหตุผลใต้ปุ่ม ───────────────────────

class _PlanCta extends StatelessWidget {
  final bool th;
  final AccentProvider accent;
  final int cost;
  final double credits;
  final int days;
  final bool isCurrent;
  final bool salesBlocked;
  final bool adminBypass;
  final bool canAfford;
  final bool busy;
  final bool pending;
  final bool walletConnected;
  final bool needsVerification;
  final bool hasStatus;
  final VoidCallback onRent;
  final VoidCallback? onConnectWallet;
  final VoidCallback? onVerifyWallet;
  final VoidCallback? onNeedTopup;

  const _PlanCta({
    required this.th,
    required this.accent,
    required this.cost,
    required this.credits,
    required this.days,
    required this.isCurrent,
    required this.salesBlocked,
    required this.adminBypass,
    required this.canAfford,
    required this.busy,
    required this.pending,
    required this.walletConnected,
    required this.needsVerification,
    required this.hasStatus,
    required this.onRent,
    required this.onConnectWallet,
    required this.onVerifyWallet,
    required this.onNeedTopup,
  });

  @override
  Widget build(BuildContext context) {
    // ── 1) ยังไม่เชื่อมกระเป๋า ──
    if (!walletConnected) {
      return _ctaColumn(
        button: GradientButton(
          text: th ? 'เชื่อมกระเป๋าเพื่อเช่า' : 'Connect wallet to rent',
          icon: Icons.account_balance_wallet_rounded,
          variant: ButtonVariant.outline,
          height: 44,
          onPressed: onConnectWallet,
        ),
        reason: th
            ? 'แพลนและเครดิตผูกกับที่อยู่กระเป๋าของคุณโดยตรง'
            : 'Plans and credits are tied directly to your wallet address',
      );
    }

    // ── 2) เชื่อมแล้วแต่ยังไม่ได้เซ็นยืนยัน ──
    if (!hasStatus) {
      return _ctaColumn(
        button: GradientButton(
          text: th ? 'เซ็นยืนยันกระเป๋า' : 'Sign to verify wallet',
          icon: Icons.draw_rounded,
          variant: ButtonVariant.outline,
          height: 44,
          onPressed: needsVerification ? onVerifyWallet : null,
        ),
        reason: needsVerification
            ? (th
                ? 'ต้องเซ็นข้อความสั้นๆ ก่อน (ไม่เสียค่าแก๊ส) ระบบถึงจะรู้ว่าเป็นกระเป๋าของคุณ'
                : 'Sign a short message first (no gas) so we know the wallet is yours')
            : (th
                ? 'กำลังอ่านสถานะกระเป๋า — รอสักครู่'
                : 'Loading your wallet status — one moment'),
      );
    }

    // ── 3) แพลนฟรีที่ใช้อยู่ ──
    if (isCurrent && cost <= 0) {
      return _ctaColumn(
        button: GradientButton(
          text: th ? 'แพลนที่ใช้อยู่' : 'Current plan',
          variant: ButtonVariant.outline,
          height: 44,
          onPressed: null,
        ),
        reason:
            th ? 'ใช้งานอยู่แล้ว ไม่มีอะไรต้องจ่าย' : 'Already active — nothing to pay',
      );
    }

    // ── 4) ปิดขายอยู่ (และไม่ใช่ทีมงาน) ──
    if (salesBlocked) {
      return _ctaColumn(
        button: GradientButton(
          text: th ? 'ยังไม่เปิดให้เช่า' : 'Rentals closed',
          icon: Icons.lock_outline_rounded,
          height: 44,
          onPressed: null,
        ),
        reason: th
            ? 'AI TRADE อยู่ระหว่างทดสอบ — ระหว่างนี้ใช้แพลนฟรีกับโหมดทดลองได้เต็มที่'
            : 'AI TRADE is under test — use the free plan and demo mode in the meantime',
        warn: true,
      );
    }

    // ── 5) เครดิตไม่พอ ──
    if (cost > 0 && !canAfford) {
      final short = (cost - credits).ceil();
      return _ctaColumn(
        button: GradientButton(
          text: th ? 'เติมเครดิตก่อน' : 'Top up first',
          icon: Icons.add_card_rounded,
          variant: ButtonVariant.outline,
          height: 44,
          onPressed: busy ? null : onNeedTopup,
        ),
        reason: th
            ? 'เครดิตไม่พอ ขาดอีก ${aiFormatInt(short, th)} เครดิต '
                '(มีอยู่ ${aiFormatMoney(credits, th, digits: 0)})'
            : 'Not enough credits — ${aiFormatInt(short, th)} short '
                '(you have ${aiFormatMoney(credits, th, digits: 0)})',
        warn: true,
      );
    }

    // ── 6) กดได้จริง ──
    final String label;
    if (isCurrent) {
      label = th ? 'ต่ออายุ +$days วัน' : 'Renew +$days days';
    } else if (cost <= 0) {
      label = th ? 'เริ่มใช้ฟรี' : 'Start free';
    } else {
      label = th ? 'เช่า $days วัน' : 'Rent $days days';
    }

    return _ctaColumn(
      button: GradientButton(
        text: label,
        icon: isCurrent ? Icons.autorenew_rounded : Icons.rocket_launch_rounded,
        variant: ButtonVariant.gold,
        height: 46,
        isLoading: pending,
        onPressed: busy ? null : onRent,
      ),
      reason: adminBypass
          ? (th
              ? 'ข้ามด่านขายด้วยสิทธิ์ทีมงาน — จะตัดเครดิตตามจริง'
              : 'Bypassing the sales gate with team rights — credits are still charged')
          : (cost > 0
              ? (th
                  ? 'ตัด ${aiFormatInt(cost, th)} เครดิตทันทีเมื่อยืนยัน '
                      '· เหลือ ${aiFormatMoney(credits - cost, th, digits: 0)}'
                  : '${aiFormatInt(cost, th)} credits are charged on confirm '
                      '· ${aiFormatMoney(credits - cost, th, digits: 0)} left')
              : null),
      gold: adminBypass,
    );
  }

  Widget _ctaColumn({
    required Widget button,
    String? reason,
    bool warn = false,
    bool gold = false,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        button,
        if (reason != null) ...[
          const SizedBox(height: 7),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(
                warn ? Icons.info_outline_rounded : Icons.circle,
                size: warn ? 12 : 5,
                color: warn || gold ? accent.g2 : AppColors.textTertiary,
              ),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  reason,
                  style: GoogleFonts.inter(
                    fontSize: 10.5,
                    height: 1.4,
                    fontWeight: warn || gold ? FontWeight.w600 : FontWeight.w400,
                    color: warn || gold ? accent.g1 : AppColors.textTertiary,
                  ),
                ),
              ),
            ],
          ),
        ],
      ],
    );
  }
}

// ── ตัวเลือกจำนวนวัน ──────────────────────────────────────

class _DaysPicker extends StatelessWidget {
  final List<int> options;
  final int selected;
  final bool enabled;
  final ValueChanged<int> onSelect;

  const _DaysPicker({
    required this.options,
    required this.selected,
    required this.enabled,
    required this.onSelect,
  });

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final accent = context.watch<AccentProvider>();
    final th = locale.isThai;

    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: AppColors.bgInputStrong,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: Row(
        children: [
          for (var i = 0; i < options.length; i++)
            Expanded(
              child: GestureDetector(
                onTap: enabled ? () => onSelect(options[i]) : null,
                behavior: HitTestBehavior.opaque,
                child: AnimatedContainer(
                  duration: accent.reduceMotion
                      ? Duration.zero
                      : const Duration(milliseconds: 180),
                  margin: EdgeInsets.only(right: i == options.length - 1 ? 0 : 4),
                  padding: const EdgeInsets.symmetric(vertical: 9),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(10),
                    gradient: options[i] == selected ? accent.goldGradient : null,
                  ),
                  child: Text(
                    th ? '${options[i]} วัน' : '${options[i]}d',
                    textAlign: TextAlign.center,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: GoogleFonts.inter(
                      fontSize: 11.5,
                      fontWeight: FontWeight.w700,
                      color: options[i] == selected
                          ? AppColors.goldTextOn
                          : AppColors.textSecondary,
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

// ── การ์ดเทียบ "เดินในแอพ" กับ "เดินบนคลาวด์" ───────────────

class _ExecutionCompare extends StatelessWidget {
  const _ExecutionCompare();

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final accent = context.watch<AccentProvider>();
    final th = locale.isThai;

    Widget box({
      required IconData icon,
      required String title,
      required String body,
      required bool gold,
    }) {
      return Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(14),
          gradient: AppGradients.cardSubtle,
          border: Border.all(
            color: gold ? accent.goldBorder : AppColors.bgCardBorder,
            width: 1,
          ),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 30,
              height: 30,
              decoration: BoxDecoration(
                color: accent.goldTint,
                borderRadius: BorderRadius.circular(9),
              ),
              child: Icon(icon, size: 16, color: accent.g2),
            ),
            const SizedBox(width: 10),
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
                  const SizedBox(height: 3),
                  Text(
                    body,
                    style: GoogleFonts.inter(
                      fontSize: 11,
                      height: 1.45,
                      color: AppColors.textSecondary,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      );
    }

    return Column(
      children: [
        box(
          icon: Icons.phone_iphone_rounded,
          gold: false,
          title: th
              ? 'แพลนฟรี — บอทเดินอยู่ในแอพ'
              : 'Free — the bot runs inside this app',
          body: th
              ? 'ต้องเปิดหน้านี้ค้างไว้ ปิดแอพ สลับไปแอพอื่นนานๆ หรือเน็ตหลุด '
                  'บอทจะหยุดทันทีและไม่เดินต่อจนกว่าจะกลับมาเปิดใหม่'
              : 'This page has to stay open. Close the app, switch away for long, or '
                  'lose connection and the bot stops until you come back.',
        ),
        const SizedBox(height: 8),
        box(
          icon: Icons.cloud_done_rounded,
          gold: true,
          title: th
              ? 'แพลนเสียเงิน — เซิร์ฟเวอร์เดินให้ 24 ชม.'
              : 'Paid — our servers run it 24/7',
          body: th
              ? 'บอทอยู่บนเซิร์ฟเวอร์ของเรา ปิดแอพหรือปิดเครื่องก็ยังเฝ้าตลาดต่อ '
                  'พร้อมด่านความเสี่ยงจากข่าวทุก 15 นาที และรันได้หลายบอทพร้อมกัน'
              : 'The bot lives on our servers and keeps watching the market with the '
                  '15-minute news risk gate, even with the app closed.',
        ),
      ],
    );
  }
}

// ── แถวยกเลิกการเช่า ──────────────────────────────────────

class _CancelRentalRow extends StatelessWidget {
  final bool busy;
  final bool cancelling;
  final int daysRemaining;
  final VoidCallback onCancel;

  const _CancelRentalRow({
    required this.busy,
    required this.cancelling,
    required this.daysRemaining,
    required this.onCancel,
  });

  @override
  Widget build(BuildContext context) {
    final th = context.watch<LocaleProvider>().isThai;

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(14),
        color: AppColors.tradingRedBg,
        border: Border.all(
          color: AppColors.tradingRed.withValues(alpha: 0.3),
          width: 1,
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(
                Icons.report_gmailerrorred_rounded,
                size: 18,
                color: AppColors.tradingRed,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  th ? 'ยกเลิกการเช่า' : 'Cancel the rental',
                  style: GoogleFonts.inter(
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            th
                ? 'บอททุกตัวจะถูกหยุด และคืนเครดิตของวันที่เหลืออีก $daysRemaining วันให้'
                : 'Every bot stops and the credits for the remaining $daysRemaining days are refunded',
            style: GoogleFonts.inter(
              fontSize: 11,
              height: 1.45,
              color: AppColors.textSecondary,
            ),
          ),
          const SizedBox(height: 10),
          SizedBox(
            width: double.infinity,
            child: OutlinedButton.icon(
              onPressed: busy ? null : onCancel,
              icon: cancelling
                  ? const SizedBox(
                      width: 14,
                      height: 14,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        color: AppColors.tradingRed,
                      ),
                    )
                  : const Icon(Icons.close_rounded, size: 15),
              label: Text(
                th ? 'ยกเลิกและหยุดบอททั้งหมด' : 'Cancel and stop every bot',
                style: GoogleFonts.inter(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                ),
              ),
              style: OutlinedButton.styleFrom(
                foregroundColor: AppColors.tradingRed,
                padding: const EdgeInsets.symmetric(vertical: 11),
                side: BorderSide(
                  color: AppColors.tradingRed.withValues(alpha: 0.45),
                ),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════
// กล่องยืนยัน
// ═══════════════════════════════════════════════════════════

/// ยืนยันก่อนตัดเครดิต — บอกตัวเลขให้ครบ ห้ามให้ผู้ใช้เดา
Future<bool?> showAiRentConfirmDialog(
  BuildContext context, {
  required AiBotPlan plan,
  required int days,
  required double creditsBefore,
  String? currentPlanLabel,
  int currentDaysRemaining = 0,
  bool adminBypass = false,
}) {
  final th = context.read<LocaleProvider>().isThai;
  final cost = plan.totalCredits(days);
  final after = creditsBefore - cost;

  return showDialog<bool>(
    context: context,
    builder: (dialogContext) => AlertDialog(
      backgroundColor: AppColors.bgElevated,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: const BorderSide(color: AppColors.bgCardBorder),
      ),
      title: Text(
        th ? 'ยืนยันการเช่าบอท' : 'Confirm rental',
        style: GoogleFonts.inter(
          color: AppColors.textPrimary,
          fontSize: 16,
          fontWeight: FontWeight.w800,
        ),
      ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _dialogRow(th ? 'แพลน' : 'Plan', plan.label(th)),
          _dialogRow(th ? 'จำนวนวัน' : 'Days', '$days'),
          _dialogRow(
            th ? 'ตัดเครดิต' : 'Charged',
            cost > 0 ? aiFormatInt(cost, th) : (th ? 'ไม่มี' : 'None'),
          ),
          _dialogRow(
            th ? 'เครดิตคงเหลือหลังตัด' : 'Credits after',
            aiFormatMoney(after < 0 ? 0 : after, th, digits: 0),
          ),
          if (currentPlanLabel != null) ...[
            const SizedBox(height: 10),
            Text(
              th
                  ? 'การเปลี่ยนแพลนจะจบแพลน "$currentPlanLabel" ที่เหลืออีก '
                      '$currentDaysRemaining วันทันที และคืนเครดิตส่วนที่ยังไม่ได้ใช้'
                  : 'Switching ends your "$currentPlanLabel" plan right now '
                      '($currentDaysRemaining days left) and refunds the unused part.',
              style: GoogleFonts.inter(
                fontSize: 11,
                height: 1.45,
                color: AppColors.textSecondary,
              ),
            ),
          ],
          if (adminBypass) ...[
            const SizedBox(height: 8),
            Text(
              th
                  ? 'กำลังใช้สิทธิ์ทีมงานข้ามด่านขาย — เครดิตยังถูกตัดตามจริง'
                  : 'Using team rights to bypass the sales gate — credits are still charged.',
              style: GoogleFonts.inter(
                fontSize: 10.5,
                height: 1.4,
                color: AppColors.gold1,
              ),
            ),
          ],
          const SizedBox(height: 8),
          Text(
            th
                ? 'ค่าความเสี่ยงของบอททุกตัวจะถูกปรับให้อยู่ในเพดานของแพลนใหม่'
                : 'Every bot risk limit is re-clamped to the new plan ceiling.',
            style: GoogleFonts.inter(
              fontSize: 10.5,
              height: 1.4,
              color: AppColors.textTertiary,
            ),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(dialogContext, false),
          child: Text(
            th ? 'ยกเลิก' : 'Cancel',
            style: const TextStyle(color: AppColors.textTertiary),
          ),
        ),
        ElevatedButton(
          onPressed: () => Navigator.pop(dialogContext, true),
          style: ElevatedButton.styleFrom(
            backgroundColor: AppColors.gold2,
            foregroundColor: AppColors.goldTextOn,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(10),
            ),
          ),
          child: Text(th ? 'ยืนยัน' : 'Confirm'),
        ),
      ],
    ),
  );
}

/// ยืนยันก่อนยกเลิกการเช่า — เป็นการกระทำที่ทำลายของ ต้องบอกผลให้ครบ
Future<bool?> showAiCancelRentalDialog(
  BuildContext context, {
  required String planLabel,
  required int daysRemaining,
  required int activeBots,
}) {
  final th = context.read<LocaleProvider>().isThai;

  return showDialog<bool>(
    context: context,
    builder: (dialogContext) => AlertDialog(
      backgroundColor: AppColors.bgElevated,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: BorderSide(color: AppColors.tradingRed.withValues(alpha: 0.3)),
      ),
      title: Text(
        th ? 'ยกเลิกการเช่า?' : 'Cancel the rental?',
        style: GoogleFonts.inter(
          color: AppColors.textPrimary,
          fontSize: 16,
          fontWeight: FontWeight.w800,
        ),
      ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _dialogRow(th ? 'แพลนที่ใช้อยู่' : 'Current plan', planLabel),
          _dialogRow(
            th ? 'วันที่เหลือ' : 'Days left',
            th ? '$daysRemaining วัน' : '$daysRemaining days',
          ),
          _dialogRow(
            th ? 'บอทที่จะถูกหยุด' : 'Bots that will stop',
            th ? '$activeBots ตัว' : '$activeBots',
          ),
          const SizedBox(height: 10),
          Text(
            th
                ? 'บอททุกตัวที่กำลังทำงานหรือพักอยู่จะถูกหยุดทันที และเครดิตของวันที่ '
                    'ยังไม่ได้ใช้จะถูกคืนเข้าบัญชี'
                : 'Every running or paused bot stops immediately, and the credits for '
                    'the unused days are refunded to your balance.',
            style: GoogleFonts.inter(
              fontSize: 11,
              height: 1.45,
              color: AppColors.textSecondary,
            ),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(dialogContext, false),
          child: Text(
            th ? 'ไม่ยกเลิก' : 'Keep it',
            style: const TextStyle(color: AppColors.textTertiary),
          ),
        ),
        ElevatedButton(
          onPressed: () => Navigator.pop(dialogContext, true),
          style: ElevatedButton.styleFrom(
            backgroundColor: AppColors.tradingRed,
            foregroundColor: Colors.white,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(10),
            ),
          ),
          child: Text(th ? 'ยกเลิกการเช่า' : 'Cancel rental'),
        ),
      ],
    ),
  );
}

Widget _dialogRow(String k, String v) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Flexible(
            child: Text(
              k,
              style: GoogleFonts.inter(
                fontSize: 12,
                color: AppColors.textSecondary,
              ),
            ),
          ),
          const SizedBox(width: 12),
          Flexible(
            child: Text(
              v,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              textAlign: TextAlign.right,
              style: AppTheme.mono(
                fontSize: 12.5,
                fontWeight: FontWeight.w700,
                color: AppColors.textPrimary,
              ),
            ),
          ),
        ],
      ),
    );

// ═══════════════════════════════════════════════════════════
// ชิ้นส่วนร่วม
// ═══════════════════════════════════════════════════════════

class _AiNoticeBox extends StatelessWidget {
  final IconData icon;
  final String title;
  final String body;
  final AccentProvider accent;
  final bool strong;

  const _AiNoticeBox({
    required this.icon,
    required this.title,
    required this.body,
    required this.accent,
    this.strong = false,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(13),
      decoration: BoxDecoration(
        color: accent.goldTint,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: accent.goldBorder,
          width: strong ? 1.2 : 1,
        ),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 18, color: accent.g2),
          const SizedBox(width: 10),
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
                const SizedBox(height: 3),
                Text(
                  body,
                  style: GoogleFonts.inter(
                    fontSize: 11,
                    height: 1.45,
                    color: AppColors.textSecondary,
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

class _MiniPill extends StatelessWidget {
  final IconData icon;
  final String label;
  final AccentProvider accent;

  const _MiniPill({
    required this.icon,
    required this.label,
    required this.accent,
  });

  @override
  Widget build(BuildContext context) {
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

class _ExecutionPill extends StatelessWidget {
  final bool runsInCloud;
  final AccentProvider accent;

  const _ExecutionPill({required this.runsInCloud, required this.accent});

  @override
  Widget build(BuildContext context) {
    final th = context.watch<LocaleProvider>().isThai;
    final label = runsInCloud
        ? (th ? 'เดินบนคลาวด์ตลอด 24 ชม.' : 'Runs on the cloud 24/7')
        : (th ? 'เดินในแอพ — ปิดแอพแล้วหยุด' : 'Runs in the app — stops when closed');

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: runsInCloud ? accent.goldTint : AppColors.bgInputStrong,
        border: Border.all(
          color: runsInCloud ? accent.goldBorder : AppColors.bgCardBorder,
          width: 1,
        ),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            runsInCloud ? Icons.cloud_done_rounded : Icons.phone_iphone_rounded,
            size: 12,
            color: runsInCloud ? accent.g2 : AppColors.textTertiary,
          ),
          const SizedBox(width: 5),
          Flexible(
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: GoogleFonts.inter(
                fontSize: 10,
                fontWeight: FontWeight.w700,
                color: runsInCloud ? accent.g1 : AppColors.textSecondary,
                letterSpacing: 0.2,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  final IconData icon;
  final String title;
  final String? subtitle;

  const _SectionTitle({
    required this.icon,
    required this.title,
    this.subtitle,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
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
                style: GoogleFonts.inter(
                  fontSize: 14.5,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                  letterSpacing: -0.2,
                ),
              ),
            ),
          ],
        ),
        if (subtitle != null) ...[
          const SizedBox(height: 6),
          Text(
            subtitle!,
            style: GoogleFonts.inter(
              fontSize: 11.5,
              height: 1.45,
              color: AppColors.textTertiary,
            ),
          ),
        ],
      ],
    );
  }
}

class _PlanSkeleton extends StatelessWidget {
  const _PlanSkeleton();

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const ShimmerBox(width: 180, height: 20, borderRadius: 8),
        const SizedBox(height: 14),
        const ShimmerBox(width: double.infinity, height: 42, borderRadius: 14),
        const SizedBox(height: 14),
        for (var i = 0; i < 3; i++) ...[
          const ShimmerBox(width: double.infinity, height: 190, borderRadius: 18),
          const SizedBox(height: 12),
        ],
      ],
    );
  }
}

class _CatalogUnavailable extends StatelessWidget {
  final VoidCallback? onRetry;

  const _CatalogUnavailable({required this.onRetry});

  @override
  Widget build(BuildContext context) {
    final th = context.watch<LocaleProvider>().isThai;
    final accent = context.watch<AccentProvider>();

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        color: accent.goldTint,
        border: Border.all(color: accent.goldBorder, width: 1),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.wifi_off_rounded, size: 20, color: accent.g2),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      th ? 'ยังโหลดรายการแพลนไม่ได้' : 'Could not load the plans',
                      style: GoogleFonts.inter(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color: AppColors.textPrimary,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      th
                          ? 'ราคาและสิทธิ์ของแต่ละแพลนมาจากเซิร์ฟเวอร์ จึงยังแสดงให้ดูไม่ได้ '
                              'ตรวจอินเทอร์เน็ตแล้วลองใหม่'
                          : 'Prices and limits come from the server, so nothing can be '
                              'shown yet. Check your connection and try again.',
                      style: GoogleFonts.inter(
                        fontSize: 11,
                        height: 1.45,
                        color: AppColors.textSecondary,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          ElevatedButton.icon(
            onPressed: onRetry,
            icon: const Icon(Icons.refresh_rounded, size: 15),
            label: Text(
              th ? 'ลองใหม่' : 'Retry',
              style: GoogleFonts.inter(fontSize: 12, fontWeight: FontWeight.w700),
            ),
            style: ElevatedButton.styleFrom(
              backgroundColor: accent.g2,
              foregroundColor: AppColors.goldTextOn,
              padding: const EdgeInsets.symmetric(vertical: 11),
              elevation: 0,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(10),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Developed by Xman Studio
