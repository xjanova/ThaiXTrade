/// TPIX TRADE — AI TRADE: การ์ดสรุปเครดิต/แพลน + โซนเติมเครดิต
///
/// ทั้งไฟล์ยึดกฎเดียวกับโซนแพลน:
///   • ปุ่มที่ยังกดไม่ได้ ต้องปิดพร้อมเหตุผลตั้งแต่แรกเห็น (เช่น ยังไม่เปิดเติมเครดิต)
///   • ตัวเลขที่ยังไม่รู้ค่า แสดง "—" ไม่ใช่ 0 (0 กับ "ไม่รู้" คนละเรื่อง)
///   • `isActive` ของเซิร์ฟเวอร์เป็น true แม้แพลนฟรี → ตัดสิน "เช่าจริง" จาก
///     `subscription.isFree == false` และ `daysRemaining > 0` เท่านั้น
///   • รับเครดิตต้อนรับเป็น idempotent — กดซ้ำได้แต่ยอดไม่เพิ่ม ต้องบอกผู้ใช้ตรงๆ
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
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
import 'ai_plan_section.dart' show aiFormatInt, aiFormatMoney;

// ═══════════════════════════════════════════════════════════
// การ์ดสรุป: เครดิต · แพลน · โควตาบอท
// ═══════════════════════════════════════════════════════════

class AiWalletSummaryCard extends StatefulWidget {
  /// null = ยังไม่เชื่อมกระเป๋า หรือยังไม่ผ่านการยืนยัน
  final AiBotStatus? status;

  final bool isLoading;
  final bool isWorking;
  final bool walletConnected;
  final bool needsVerification;

  final VoidCallback? onConnectWallet;
  final VoidCallback? onVerifyWallet;

  /// รับเครดิตต้อนรับ (ทำได้ครั้งเดียวต่อกระเป๋า — เซิร์ฟเวอร์กันซ้ำเอง)
  final Future<void> Function()? onClaimWelcome;

  /// เปิดดูประวัติเครดิต — null = ซ่อนปุ่ม
  final VoidCallback? onOpenCreditHistory;

  const AiWalletSummaryCard({
    super.key,
    required this.status,
    this.isLoading = false,
    this.isWorking = false,
    this.walletConnected = false,
    this.needsVerification = false,
    this.onConnectWallet,
    this.onVerifyWallet,
    this.onClaimWelcome,
    this.onOpenCreditHistory,
  });

  @override
  State<AiWalletSummaryCard> createState() => _AiWalletSummaryCardState();
}

class _AiWalletSummaryCardState extends State<AiWalletSummaryCard> {
  bool _claiming = false;

  Future<void> _claim() async {
    final handler = widget.onClaimWelcome;
    if (handler == null || _claiming || widget.isWorking) return;

    setState(() => _claiming = true);
    try {
      await handler();
    } finally {
      if (mounted) setState(() => _claiming = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final accent = context.watch<AccentProvider>();
    final th = locale.isThai;
    final status = widget.status;

    // ── ยังไม่มีสถานะ: ต้องมีทางออกบนจอเสมอ ห้ามโชว์การ์ดเปล่า ──
    if (status == null) {
      return GlassCard(
        variant: GlassVariant.standard,
        borderRadius: 18,
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
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
                  child: Icon(Icons.toll_rounded, size: 16, color: accent.g2),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    th ? 'เครดิตการทำงาน' : 'Work credits',
                    style: GoogleFonts.inter(
                      fontSize: 14,
                      fontWeight: FontWeight.w700,
                      color: AppColors.textPrimary,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            if (widget.isLoading)
              const ShimmerBox(width: 160, height: 30, borderRadius: 8)
            else
              Text(
                widget.walletConnected
                    ? (th
                        ? 'ยืนยันกระเป๋าก่อน ระบบถึงจะดึงเครดิตและแพลนของคุณมาแสดงได้ '
                            '(เซ็นข้อความสั้นๆ ไม่เสียค่าแก๊ส)'
                        : 'Verify your wallet so we can load your credits and plan '
                            '(sign a short message, no gas).')
                    : (th
                        ? 'เชื่อมกระเป๋าเพื่อดูเครดิต แพลนที่ใช้อยู่ และโควตาบอทของคุณ'
                        : 'Connect a wallet to see your credits, plan and bot quota.'),
                style: GoogleFonts.inter(
                  fontSize: 11.5,
                  height: 1.45,
                  color: AppColors.textSecondary,
                ),
              ),
            const SizedBox(height: 12),
            GradientButton(
              text: widget.walletConnected
                  ? (th ? 'เซ็นยืนยันกระเป๋า' : 'Sign to verify wallet')
                  : (th ? 'เชื่อมกระเป๋า' : 'Connect wallet'),
              icon: widget.walletConnected
                  ? Icons.draw_rounded
                  : Icons.account_balance_wallet_rounded,
              variant: ButtonVariant.gold,
              height: 44,
              onPressed: widget.walletConnected
                  ? (widget.needsVerification ? widget.onVerifyWallet : null)
                  : widget.onConnectWallet,
            ),
          ],
        ),
      );
    }

    final credits = status.credits;
    final showWelcome = credits <= 0 && widget.onClaimWelcome != null;
    final sub = status.subscription;
    // เช่าแบบเสียเงินอยู่จริงไหม — `isActive` ของเซิร์ฟเวอร์เป็น true แม้แพลนฟรี
    final paidActive = sub != null && !sub.isFree && sub.daysRemaining > 0;
    // maxBots = 0 แปลว่ายังไม่รู้โควตา ไม่ใช่ "เต็ม"
    final quotaFull = status.quota.maxBots > 0 && status.quota.isFull;

    return GlassCard(
      variant: GlassVariant.gold,
      borderRadius: 20,
      padding: const EdgeInsets.all(18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  th ? 'เครดิตการทำงาน' : 'WORK CREDITS',
                  style: GoogleFonts.inter(
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textTertiary,
                    letterSpacing: 1.2,
                  ),
                ),
              ),
              if (status.isAdmin)
                _Pill(
                  icon: Icons.workspace_premium_rounded,
                  label: th ? 'ทีมงาน' : 'TEAM',
                  accent: accent,
                ),
            ],
          ),
          const SizedBox(height: 8),

          if (widget.isLoading)
            const ShimmerBox(width: 150, height: 34, borderRadius: 8)
          else
            Row(
              crossAxisAlignment: CrossAxisAlignment.baseline,
              textBaseline: TextBaseline.alphabetic,
              children: [
                Flexible(
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: Alignment.centerLeft,
                    child: Text(
                      aiFormatMoney(credits, th, digits: 0),
                      maxLines: 1,
                      style: AppTheme.mono(
                        fontSize: 30,
                        fontWeight: FontWeight.w600,
                        color: AppColors.textPrimary,
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Text(
                  th ? 'เครดิต' : 'credits',
                  style: GoogleFonts.inter(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textTertiary,
                  ),
                ),
              ],
            ),

          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: _MiniStat(
                  icon: Icons.card_membership_rounded,
                  value: sub != null
                      ? sub.label(th)
                      : (th ? 'ยังไม่ได้เช่า' : 'Not rented'),
                  label: th ? 'แพลนที่ใช้อยู่' : 'Current plan',
                  accent: accent,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: _MiniStat(
                  icon: Icons.event_available_rounded,
                  // แพลนฟรีไม่มีวันหมดที่มีความหมาย → ไม่หลอกด้วยเลขวัน
                  value: paidActive
                      ? (th ? '${sub.daysRemaining} วัน' : '${sub.daysRemaining}d')
                      : '—',
                  label: th ? 'เหลืออีก' : 'Time left',
                  accent: accent,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: _MiniStat(
                  icon: Icons.smart_toy_outlined,
                  value: '${status.quota.usedBots}/${status.quota.maxBots}',
                  label: th ? 'บอทที่ใช้ไป' : 'Bots used',
                  accent: accent,
                  warn: quotaFull,
                ),
              ),
            ],
          ),

          // บอกให้ชัดว่าบอทเดินที่ไหน — คนละเรื่องกับจำนวนบอท
          if (sub != null) ...[
            const SizedBox(height: 12),
            Row(
              children: [
                Icon(
                  status.runsInCloud
                      ? Icons.cloud_done_rounded
                      : Icons.phone_iphone_rounded,
                  size: 13,
                  color: status.runsInCloud ? accent.g2 : AppColors.textTertiary,
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    status.runsInCloud
                        ? (th
                            ? 'บอทเดินบนคลาวด์ — ปิดแอพแล้วยังทำงานต่อ'
                            : 'Bots run on the cloud — they keep working with the app closed')
                        : (th
                            ? 'บอทเดินในแอพ — ต้องเปิดหน้านี้ค้างไว้ ปิดแอพแล้วบอทหยุด'
                            : 'Bots run inside the app — keep this page open or they stop'),
                    style: GoogleFonts.inter(
                      fontSize: 10.5,
                      height: 1.4,
                      fontWeight: FontWeight.w600,
                      color: status.runsInCloud
                          ? accent.g1
                          : AppColors.textSecondary,
                    ),
                  ),
                ),
              ],
            ),
          ],

          if (quotaFull) ...[
            const SizedBox(height: 8),
            Text(
              th
                  ? 'โควตาบอทเต็มแล้ว — กด "หยุด" บอทตัวเก่าเพื่อคืนโควตา (กด "พัก" ไม่คืน)'
                  : 'Bot quota is full — Stop an old bot to free a slot (Pause does not).',
              style: GoogleFonts.inter(
                fontSize: 10.5,
                height: 1.4,
                fontWeight: FontWeight.w600,
                color: accent.g1,
              ),
            ),
          ],

          if (showWelcome) ...[
            const SizedBox(height: 14),
            GradientButton(
              text: th ? 'รับเครดิตต้อนรับฟรี' : 'Claim free welcome credits',
              icon: Icons.redeem_rounded,
              variant: ButtonVariant.gold,
              height: 44,
              isLoading: _claiming,
              onPressed: widget.isWorking || _claiming ? null : _claim,
            ),
            const SizedBox(height: 6),
            Text(
              th
                  ? 'รับได้ครั้งเดียวต่อกระเป๋า — ถ้าเคยรับไปแล้ว ยอดจะไม่เพิ่มขึ้นอีก'
                  : 'One claim per wallet — if you already claimed, the balance stays the same.',
              style: GoogleFonts.inter(
                fontSize: 10.5,
                height: 1.4,
                color: AppColors.textTertiary,
              ),
            ),
          ],

          if (widget.onOpenCreditHistory != null) ...[
            const SizedBox(height: 12),
            GestureDetector(
              onTap: widget.onOpenCreditHistory,
              behavior: HitTestBehavior.opaque,
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(Icons.receipt_long_rounded, size: 13, color: accent.g2),
                  const SizedBox(width: 6),
                  Text(
                    th ? 'ดูประวัติเครดิต' : 'Credit history',
                    style: GoogleFonts.inter(
                      fontSize: 11.5,
                      fontWeight: FontWeight.w700,
                      color: accent.g1,
                    ),
                  ),
                  const SizedBox(width: 2),
                  Icon(Icons.chevron_right_rounded, size: 15, color: accent.g2),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════
// โซนเติมเครดิต
// ═══════════════════════════════════════════════════════════

class AiTopupSection extends StatefulWidget {
  final List<CreditPack> packs;

  /// อ่านจาก `catalog.features.credit_topup` เท่านั้น
  final bool enabled;

  final bool walletConnected;
  final bool isWorking;

  /// ส่งคำขอเติมเครดิต — หน้าจอแม่เป็นคนยิง API และแจ้งผล
  final Future<void> Function(String packCode)? onTopup;

  final VoidCallback? onConnectWallet;

  const AiTopupSection({
    super.key,
    required this.packs,
    required this.enabled,
    this.walletConnected = false,
    this.isWorking = false,
    this.onTopup,
    this.onConnectWallet,
  });

  @override
  State<AiTopupSection> createState() => _AiTopupSectionState();
}

class _AiTopupSectionState extends State<AiTopupSection> {
  String? _pendingCode;

  bool get _busy => widget.isWorking || _pendingCode != null;

  Future<void> _topup(CreditPack pack) async {
    final handler = widget.onTopup;
    if (handler == null || _busy || !widget.enabled) return;

    setState(() => _pendingCode = pack.code);
    try {
      await handler(pack.code);
    } finally {
      if (mounted) setState(() => _pendingCode = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final accent = context.watch<AccentProvider>();
    final th = locale.isThai;

    if (widget.packs.isEmpty) return const SizedBox.shrink();

    final blocked = !widget.enabled;

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
              child: Icon(Icons.add_card_rounded, size: 16, color: accent.g2),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                th ? 'เติมเครดิตการทำงาน' : 'Top up work credits',
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
        const SizedBox(height: 6),
        Text(
          th
              ? 'เลือกแพ็กเพื่อสร้างคำขอ — เครดิตจะเข้าบัญชีหลังทีมงานยืนยันการชำระเงิน'
              : 'Pick a pack to create a request — credits arrive once the team confirms payment',
          style: GoogleFonts.inter(
            fontSize: 11.5,
            height: 1.45,
            color: AppColors.textTertiary,
          ),
        ),

        if (blocked) ...[
          const SizedBox(height: 12),
          Container(
            padding: const EdgeInsets.all(13),
            decoration: BoxDecoration(
              color: accent.goldTint,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: accent.goldBorder, width: 1),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(Icons.hourglass_empty_rounded, size: 18, color: accent.g2),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    th
                        ? 'ยังไม่เปิดให้เติมเครดิต — รอประกาศเปิดระบบชำระเงินก่อน '
                            'ระหว่างนี้ใช้เครดิตต้อนรับทดลองระบบได้เต็มที่'
                        : 'Credit top-up is not open yet — we will announce it when '
                            'payments go live. Until then the welcome credits are yours to test with.',
                    style: GoogleFonts.inter(
                      fontSize: 11,
                      height: 1.45,
                      color: AppColors.textSecondary,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],

        const SizedBox(height: 12),
        LayoutBuilder(
          builder: (context, constraints) {
            const gap = 10.0;
            final tileWidth = (constraints.maxWidth - gap) / 2;
            return Wrap(
              spacing: gap,
              runSpacing: gap,
              children: [
                for (final pack in widget.packs)
                  SizedBox(
                    width: tileWidth,
                    child: _PackTile(
                      pack: pack,
                      th: th,
                      accent: accent,
                      blocked: blocked,
                      needWallet: !widget.walletConnected,
                      busy: _busy,
                      pending: _pendingCode == pack.code,
                      onTap: () {
                        if (!widget.walletConnected) {
                          widget.onConnectWallet?.call();
                          return;
                        }
                        _topup(pack);
                      },
                    ),
                  ),
              ],
            );
          },
        ),

        if (!blocked && !widget.walletConnected) ...[
          const SizedBox(height: 8),
          Text(
            th
                ? 'เชื่อมกระเป๋าก่อนถึงจะสร้างคำขอเติมเครดิตได้'
                : 'Connect a wallet before creating a top-up request',
            style: GoogleFonts.inter(
              fontSize: 10.5,
              fontWeight: FontWeight.w600,
              color: accent.g1,
            ),
          ),
        ],
      ],
    );
  }
}

class _PackTile extends StatelessWidget {
  final CreditPack pack;
  final bool th;
  final AccentProvider accent;
  final bool blocked;
  final bool needWallet;
  final bool busy;
  final bool pending;
  final VoidCallback onTap;

  const _PackTile({
    required this.pack,
    required this.th,
    required this.accent,
    required this.blocked,
    required this.needWallet,
    required this.busy,
    required this.pending,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final disabled = blocked || busy;

    return Opacity(
      opacity: disabled ? 0.45 : 1,
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: disabled ? null : onTap,
          borderRadius: BorderRadius.circular(16),
          child: Ink(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 14),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(16),
              gradient: AppGradients.cardSubtle,
              border: Border.all(
                color: pack.bonus > 0 ? accent.goldBorder : AppColors.bgCardBorder,
                width: 1,
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: FittedBox(
                        fit: BoxFit.scaleDown,
                        alignment: Alignment.centerLeft,
                        child: Text(
                          aiFormatInt(pack.credits, th),
                          maxLines: 1,
                          style: AppTheme.mono(
                            fontSize: 19,
                            fontWeight: FontWeight.w700,
                            color: AppColors.textPrimary,
                          ),
                        ),
                      ),
                    ),
                    if (pending)
                      SizedBox(
                        width: 14,
                        height: 14,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: accent.g2,
                        ),
                      ),
                  ],
                ),
                const SizedBox(height: 2),
                Text(
                  th ? 'เครดิต' : 'credits',
                  style: GoogleFonts.inter(
                    fontSize: 10,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textTertiary,
                    letterSpacing: 0.4,
                  ),
                ),
                if (pack.bonus > 0) ...[
                  const SizedBox(height: 7),
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(999),
                      color: accent.goldTint,
                      border: Border.all(color: accent.goldBorder, width: 1),
                    ),
                    child: Text(
                      th
                          ? 'โบนัส +${aiFormatInt(pack.bonus, th)}'
                          : '+${aiFormatInt(pack.bonus, th)} bonus',
                      style: GoogleFonts.inter(
                        fontSize: 9.5,
                        fontWeight: FontWeight.w700,
                        color: accent.g1,
                        letterSpacing: 0.3,
                      ),
                    ),
                  ),
                ],
                const SizedBox(height: 9),
                Text(
                  '${aiFormatMoney(pack.priceTpix, th, digits: 0)} TPIX',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTheme.mono(
                    fontSize: 11.5,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textSecondary,
                  ),
                ),
                if (blocked || needWallet) ...[
                  const SizedBox(height: 6),
                  Text(
                    blocked
                        ? (th ? 'ยังไม่เปิด' : 'Not open yet')
                        : (th ? 'ต้องเชื่อมกระเป๋า' : 'Wallet required'),
                    style: GoogleFonts.inter(
                      fontSize: 9.5,
                      fontWeight: FontWeight.w600,
                      color: AppColors.textTertiary,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

// ── ชิ้นส่วนร่วม ───────────────────────────────────────────

class _MiniStat extends StatelessWidget {
  final IconData icon;
  final String value;
  final String label;
  final AccentProvider accent;
  final bool warn;

  const _MiniStat({
    required this.icon,
    required this.value,
    required this.label,
    required this.accent,
    this.warn = false,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 11),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(14),
        gradient: AppGradients.cardSubtle,
        border: Border.all(
          color: warn ? accent.goldBorder : AppColors.bgCardBorder,
          width: 1,
        ),
      ),
      child: Column(
        children: [
          Icon(icon, size: 15, color: accent.g2),
          const SizedBox(height: 7),
          FittedBox(
            fit: BoxFit.scaleDown,
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

class _Pill extends StatelessWidget {
  final IconData icon;
  final String label;
  final AccentProvider accent;

  const _Pill({
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

/// Developed by Xman Studio
