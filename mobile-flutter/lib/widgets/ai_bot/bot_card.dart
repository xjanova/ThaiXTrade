/// TPIX TRADE — การ์ดบอท AI TRADE
/// อ่านออกในแวบเดียว: ชื่อ · คู่เทรด · กลยุทธ์ · โหมด · สถานะ · สัญญาณชีพ · ผลตอบแทน
///
/// รับ payload ดิบจาก `presentBot()` ของเซิร์ฟเวอร์ (`/ai-bot/status.bots[]`,
/// `/ai-bot/bots`, `POST|PUT /ai-bot/bots/...`) โดยตรง เพื่อไม่ผูกกับโมเดลใด
///
/// กับดักที่การ์ดนี้จัดการให้แล้ว (มาจากบทเรียนจริงบนเว็บ):
///   • `banned: true` เกิดพร้อม `status: "running"` ได้ → ป้ายระงับต้องทับสถานะ
///     ไม่งั้นผู้ใช้เห็น "กำลังทำงาน" ที่ไม่มีอะไรเกิดขึ้นเลย
///   • ไฟ "เดินอยู่" ต้องหมายถึงเดินจริง — เกิน 10 นาทีไม่ขยับถือว่าเงียบผิดปกติ
///   • `risk_level` = ความเสี่ยงของ "กลยุทธ์" ส่วน `stats.last_risk` = ความเสี่ยง
///     "ตลาดตอนนี้" คนละเรื่องกัน จึงแยกป้ายกัน
///   • `position` ไม่มีราคาตลาด — กำไรลอยต้องส่งมาจาก `/demo` เท่านั้น
///     ห้ามเอา `cost_basis` มาโชว์เป็นมูลค่าพอร์ต
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';

import '../../core/locale/locale_provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../providers/accent_provider.dart';
import '../common/glass_card.dart';
import 'bot_json.dart';

/// เกินเท่านี้ถือว่าบอทเงียบผิดปกติ — สองเท่าของรอบที่ช้าที่สุด (5 นาที)
const int kBotStaleMinutes = 10;

/// คำนำหน้าที่เซิร์ฟเวอร์เขียนไว้ใน `last_reason` เมื่อโหมดจริงเสนอสัญญาณ
const String kAwaitingPrefix = '[รอยืนยัน]';

/// การ์ดบอทหนึ่งตัว
class BotCard extends StatefulWidget {
  /// payload ดิบของบอท (โครง `presentBot()`)
  final Map<String, dynamic> bot;

  /// กำไรลอยของบอทตัวนี้ จาก `/ai-bot/demo` → `positions[].unrealized_pnl`
  /// null = ไม่มีของถืออยู่ หรือยังไม่ได้โหลดพอร์ตทดลอง
  final double? unrealizedPnl;

  /// เปอร์เซ็นต์กำไรลอย จาก `positions[].unrealized_pct`
  final double? unrealizedPct;

  /// `positions[].priced` — false แปลว่าตีราคาไม่ได้ ตัวเลขข้างบนไม่จริง
  final bool positionPriced;

  /// มี action ของหน้าจอกำลังทำงานอยู่ → ปิดปุ่มทั้งใบกันกดรัว
  final bool busy;

  /// `catalog.features.live_trading` — ใช้บอกว่าโหมดจริงยังไม่เปิด
  final bool liveEnabled;

  /// เหลืออีกกี่วินาทีถึงรอบถัดไป (จาก `tick` ที่ตอบ `skipped: true`)
  final int? nextTickInSeconds;

  /// แพลนปัจจุบันเดินบอทให้บนคลาวด์ไหม — ถ้าไม่ใช่ ต้องบอกว่าปิดแอพแล้วหยุด
  final bool runsInCloud;

  final VoidCallback? onStart;
  final VoidCallback? onPause;
  final VoidCallback? onStop;
  final VoidCallback? onEdit;
  final VoidCallback? onDelete;
  final VoidCallback? onToggleMode;
  final VoidCallback? onTap;

  const BotCard({
    super.key,
    required this.bot,
    this.unrealizedPnl,
    this.unrealizedPct,
    this.positionPriced = true,
    this.busy = false,
    this.liveEnabled = false,
    this.nextTickInSeconds,
    this.runsInCloud = true,
    this.onStart,
    this.onPause,
    this.onStop,
    this.onEdit,
    this.onDelete,
    this.onToggleMode,
    this.onTap,
  });

  @override
  State<BotCard> createState() => _BotCardState();
}

class _BotCardState extends State<BotCard> with SingleTickerProviderStateMixin {
  late final AnimationController _pulse;

  @override
  void initState() {
    super.initState();
    // จุดสถานะ "เดินอยู่" หายใจช้าๆ — หยุดเองเมื่อผู้ใช้ปิดอนิเมชัน
    _pulse = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1600),
    );
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  void _syncPulse(bool shouldRun) {
    if (shouldRun) {
      if (!_pulse.isAnimating) _pulse.repeat(reverse: true);
    } else {
      if (_pulse.isAnimating) _pulse.stop();
    }
  }

  // ── ตัวอ่านค่าจาก payload ────────────────────────────────
  String get _name => jsonStr(widget.bot['name']) ?? '—';
  String get _pair => (jsonStr(widget.bot['pair']) ?? '—').toUpperCase();
  String get _status => jsonStr(widget.bot['status']) ?? 'stopped';
  String get _mode => jsonStr(widget.bot['mode']) ?? 'demo';
  bool get _banned => jsonBool(widget.bot['banned']);
  String? get _bannedReason => jsonStr(widget.bot['banned_reason']);
  String? get _lastReason => jsonStr(widget.bot['last_reason']);
  DateTime? get _lastRunAt => jsonDate(widget.bot['last_run_at']);
  Map<String, dynamic> get _stats => jsonMap(widget.bot['stats']);
  Map<String, dynamic> get _risk => jsonMap(widget.bot['risk']);
  Map<String, dynamic>? get _position {
    final p = widget.bot['position'];
    return p is Map ? jsonMap(p) : null;
  }

  String _strategyLabel(bool th) {
    final thName = jsonStr(widget.bot['strategy_name_th']);
    final enName = jsonStr(widget.bot['strategy_name']);
    final code = jsonStr(widget.bot['strategy']) ?? '—';
    if (th) return thName ?? enName ?? code;
    return enName ?? thName ?? code;
  }

  /// นาทีที่ผ่านไปตั้งแต่บอทเดินรอบล่าสุด — null เมื่อไม่ได้กำลังเดิน
  /// หรือยังไม่เคยเดินเลย (สองกรณีนี้ไม่ถือว่า "เงียบผิดปกติ")
  int? get _minutesSinceRun {
    if (_status != 'running') return null;
    final at = _lastRunAt;
    if (at == null) return null;
    final m = DateTime.now().difference(at).inMinutes;
    return m < 0 ? 0 : m;
  }

  bool get _isStale {
    final m = _minutesSinceRun;
    return m != null && m >= kBotStaleMinutes;
  }

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final accent = context.watch<AccentProvider>();
    final th = locale.isThai;

    final running = _status == 'running';
    final healthy = running && !_isStale && !_banned;
    _syncPulse(healthy && !accent.reduceMotion);

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: GlassCard(
        variant: healthy ? GlassVariant.brand : GlassVariant.standard,
        borderRadius: 16,
        padding: const EdgeInsets.fromLTRB(14, 13, 14, 12),
        onTap: widget.onTap,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _buildHeader(th, accent),
            const SizedBox(height: 8),
            _buildIdentityLine(th, accent),
            const SizedBox(height: 10),
            const Divider(color: AppColors.divider, height: 1),
            const SizedBox(height: 10),
            _buildVitals(th),
            ..._buildAlerts(th),
            if (_lastReason != null) ...[
              const SizedBox(height: 10),
              _buildReason(th, accent),
            ],
            const SizedBox(height: 12),
            _buildActions(th),
          ],
        ),
      ),
    );
  }

  // ── หัวการ์ด: จุดสถานะ + ชื่อ + ชิปโหมด ────────────────
  Widget _buildHeader(bool th, AccentProvider accent) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        _StatusDot(
          color: _statusColor(accent),
          pulse: _pulse,
          animate: _status == 'running' &&
              !_isStale &&
              !_banned &&
              !accent.reduceMotion,
        ),
        const SizedBox(width: 9),
        Expanded(
          child: Text(
            _name,
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
        const SizedBox(width: 8),
        _ModeChip(
          mode: _mode,
          liveEnabled: widget.liveEnabled,
          th: th,
          onTap: widget.busy ? null : widget.onToggleMode,
        ),
      ],
    );
  }

  // ── บรรทัดตัวตน: คู่เทรด · กลยุทธ์ · กรอบเวลา + ป้ายสถานะ/ความเสี่ยง ──
  Widget _buildIdentityLine(bool th, AccentProvider accent) {
    final timeframe = jsonStr(widget.bot['timeframe']) ?? '—';
    final sl = jsonDouble(_risk['stop_loss_pct']);
    final tp = jsonDouble(_risk['take_profit_pct']);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          '$_pair · ${_strategyLabel(th)} · $timeframe',
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: AppTheme.mono(
            fontSize: 11.5,
            fontWeight: FontWeight.w600,
            color: AppColors.textSecondary,
          ),
        ),
        if (sl != null && tp != null) ...[
          const SizedBox(height: 4),
          Text(
            th
                ? 'ตัดขาดทุน ${_trimNum(sl)}%  ·  ทำกำไร ${_trimNum(tp)}%'
                : 'SL ${_trimNum(sl)}%  ·  TP ${_trimNum(tp)}%',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: AppTheme.mono(
              fontSize: 10.5,
              fontWeight: FontWeight.w500,
              color: AppColors.textTertiary,
            ),
          ),
        ],
        const SizedBox(height: 8),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            _MiniChip(
              label: _statusText(th),
              color: _statusColor(accent),
            ),
            _MiniChip(
              label: _strategyRiskText(th),
              color: AppColors.textSecondary,
            ),
            if (_marketRiskText(th) != null)
              _MiniChip(
                label: _marketRiskText(th)!,
                color: _marketRiskColor(),
              ),
          ],
        ),
      ],
    );
  }

  // ── สัญญาณชีพ: เดินล่าสุดเมื่อไร + ผลตอบแทน ───────────────
  Widget _buildVitals(bool th) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: _VitalBlock(
            label: th ? 'เดินรอบล่าสุด' : 'Last cycle',
            value: _lastRunText(th),
            valueColor: _isStale ? AppColors.tradingRed : AppColors.textPrimary,
            mono: false,
          ),
        ),
        const SizedBox(width: 10),
        Expanded(child: _buildPnlBlock(th)),
      ],
    );
  }

  Widget _buildPnlBlock(bool th) {
    final pos = _position;

    // ไม่มีของถืออยู่ในโหมดปัจจุบัน — บอกตรงๆ ไม่ใช่โชว์ 0
    if (pos == null) {
      return _VitalBlock(
        label: th ? 'ของที่ถืออยู่' : 'Open position',
        value: th ? 'ยังไม่มี' : 'None',
        valueColor: AppColors.textTertiary,
        mono: false,
      );
    }

    // มีของถือแต่ตีราคาไม่ได้ → ห้ามโชว์กำไร 0 ปลอมๆ
    if (!widget.positionPriced || widget.unrealizedPnl == null) {
      return _VitalBlock(
        label: th ? 'กำไรลอย' : 'Open P&L',
        value: th ? 'ยังตีราคาไม่ได้' : 'No price yet',
        valueColor: AppColors.textTertiary,
        mono: false,
      );
    }

    final pnl = widget.unrealizedPnl!;
    final pct = widget.unrealizedPct;
    final color = pnl > 0
        ? AppColors.tradingGreen
        : (pnl < 0 ? AppColors.tradingRed : AppColors.textPrimary);

    return _VitalBlock(
      label: th ? 'กำไรลอย' : 'Open P&L',
      value: _signedMoney(pnl) +
          (pct == null ? '' : '  (${pct >= 0 ? '+' : '-'}'
              '${pct.abs().toStringAsFixed(2)}%)'),
      valueColor: color,
      mono: true,
    );
  }

  // ── แถบเตือน (ระงับ / เงียบผิดปกติ / รอบถัดไป / เดินในแอพ) ──
  List<Widget> _buildAlerts(bool th) {
    final out = <Widget>[];

    if (_banned) {
      out.add(const SizedBox(height: 10));
      out.add(_AlertBox(
        icon: Icons.gpp_maybe_rounded,
        danger: true,
        title: th ? 'ทีมงานระงับบอทตัวนี้ไว้' : 'This bot is suspended',
        detail: _bannedReason ??
            (th
                ? 'ยังเริ่มบอทไม่ได้จนกว่าทีมงานจะปลดระงับ'
                : 'It cannot start until the team lifts the suspension'),
      ));
    }

    if (_isStale) {
      final m = _minutesSinceRun ?? kBotStaleMinutes;
      out.add(const SizedBox(height: 10));
      out.add(_AlertBox(
        icon: Icons.warning_amber_rounded,
        danger: true,
        title: th
            ? 'บอทไม่ได้เดินมา $m นาที'
            : 'No cycle for $m minutes',
        detail: th
            ? 'ตัวประมวลผลอาจมีปัญหา — แจ้งทีมงานได้เลย'
            : 'The execution service may be down — please tell the team',
      ));
    }

    if (!widget.runsInCloud && _status == 'running' && !_banned) {
      out.add(const SizedBox(height: 10));
      out.add(_AlertBox(
        icon: Icons.phone_iphone_rounded,
        danger: false,
        title: th ? 'บอทเดินอยู่ในแอพนี้' : 'Running inside this app',
        detail: widget.nextTickInSeconds != null
            ? (th
                ? 'ปิดแอพแล้วบอทหยุดทันที · รอบถัดไปอีก ${widget.nextTickInSeconds} วินาที'
                : 'Closing the app stops it · next cycle in ${widget.nextTickInSeconds}s')
            : (th
                ? 'ปิดแอพหรือออกจากหน้านี้แล้วบอทหยุดทันที'
                : 'Closing the app or leaving this page stops it'),
      ));
    }

    return out;
  }

  // ── เหตุผลล่าสุดที่บอทตัดสินใจ ──────────────────────────
  Widget _buildReason(bool th, AccentProvider accent) {
    final raw = _lastReason!;
    final awaiting = raw.startsWith(kAwaitingPrefix);
    final text =
        awaiting ? raw.replaceFirst(kAwaitingPrefix, '').trim() : raw;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 9),
      decoration: BoxDecoration(
        color: AppColors.bgInputStrong,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.psychology_alt_rounded, size: 12, color: accent.g2),
              const SizedBox(width: 5),
              Text(
                th ? 'การตัดสินใจล่าสุด' : 'Latest decision',
                style: GoogleFonts.inter(
                  fontSize: 10,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textTertiary,
                  letterSpacing: 0.4,
                ),
              ),
              if (awaiting) ...[
                const SizedBox(width: 6),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                  decoration: BoxDecoration(
                    color: accent.goldTint,
                    borderRadius: BorderRadius.circular(999),
                    border: Border.all(color: accent.goldBorder, width: 1),
                  ),
                  child: Text(
                    th ? 'รอคุณยืนยัน' : 'Awaiting you',
                    style: GoogleFonts.inter(
                      fontSize: 9,
                      fontWeight: FontWeight.w700,
                      color: accent.g1,
                      letterSpacing: 0.5,
                    ),
                  ),
                ),
              ],
            ],
          ),
          const SizedBox(height: 5),
          Text(
            text.isEmpty
                ? (th
                    ? 'บอทยังไม่ได้ตัดสินใจรอบแรก'
                    : 'No decision yet')
                : text,
            maxLines: 3,
            overflow: TextOverflow.ellipsis,
            style: GoogleFonts.inter(
              fontSize: 11.5,
              height: 1.35,
              color: AppColors.textSecondary,
            ),
          ),
        ],
      ),
    );
  }

  // ── ปุ่มสั่งงาน ────────────────────────────────────────
  Widget _buildActions(bool th) {
    final running = _status == 'running';
    final stopped = _status == 'stopped';
    final canStart = !widget.busy && !_banned && widget.onStart != null;

    return Row(
      children: [
        // เริ่ม / พัก — ปุ่มหลัก
        Expanded(
          flex: 3,
          child: running
              ? _ActionButton(
                  icon: Icons.pause_rounded,
                  label: th ? 'พัก' : 'Pause',
                  onTap: widget.busy ? null : widget.onPause,
                  primary: false,
                )
              : _ActionButton(
                  icon: Icons.play_arrow_rounded,
                  label: th ? 'เริ่ม' : 'Start',
                  onTap: canStart ? widget.onStart : null,
                  primary: true,
                ),
        ),
        const SizedBox(width: 8),
        // หยุด — ปลดโควตาบอท (พักไม่ปลด) จึงต้องเข้าถึงได้เสมอ
        if (!stopped)
          Expanded(
            flex: 2,
            child: _ActionButton(
              icon: Icons.stop_rounded,
              label: th ? 'หยุด' : 'Stop',
              onTap: widget.busy ? null : widget.onStop,
              primary: false,
            ),
          ),
        if (!stopped) const SizedBox(width: 8),
        _IconAction(
          icon: Icons.tune_rounded,
          tooltip: th ? 'แก้ไขบอท' : 'Edit bot',
          onTap: widget.busy ? null : widget.onEdit,
        ),
        const SizedBox(width: 8),
        _IconAction(
          icon: Icons.delete_outline_rounded,
          tooltip: th ? 'ลบบอท' : 'Delete bot',
          danger: true,
          onTap: widget.busy ? null : widget.onDelete,
        ),
      ],
    );
  }

  // ── ข้อความ / สี ───────────────────────────────────────
  Color _statusColor(AccentProvider accent) {
    if (_banned || _isStale) return AppColors.tradingRed;
    switch (_status) {
      case 'running':
        return accent.g2;
      case 'paused':
        return AppColors.textSecondary;
      default:
        return AppColors.textDisabled;
    }
  }

  String _statusText(bool th) {
    if (_banned) return th ? 'ถูกระงับ' : 'Suspended';
    switch (_status) {
      case 'running':
        return _isStale
            ? (th ? 'เงียบผิดปกติ' : 'Stalled')
            : (th ? 'กำลังทำงาน' : 'Running');
      case 'paused':
        return th ? 'พักอยู่' : 'Paused';
      case 'stopped':
        return th ? 'หยุดแล้ว' : 'Stopped';
      default:
        return th ? 'ร่าง' : 'Draft';
    }
  }

  String _strategyRiskText(bool th) {
    switch (jsonStr(widget.bot['risk_level']) ?? 'medium') {
      case 'low':
        return th ? 'กลยุทธ์เสี่ยงต่ำ' : 'Low-risk strategy';
      case 'high':
        return th ? 'กลยุทธ์เสี่ยงสูง' : 'High-risk strategy';
      default:
        return th ? 'กลยุทธ์เสี่ยงกลาง' : 'Medium-risk strategy';
    }
  }

  String? _marketRiskText(bool th) {
    final lv = jsonStr(_stats['last_risk']);
    if (lv == null) return null;
    switch (lv) {
      case 'calm':
        return th ? 'ตลาด: ปกติ' : 'Market: calm';
      case 'caution':
        return th ? 'ตลาด: ระวัง' : 'Market: caution';
      case 'elevated':
        return th ? 'ตลาด: เสี่ยงสูง' : 'Market: elevated';
      case 'panic':
        return th ? 'ตลาด: ตื่นตระหนก' : 'Market: panic';
      default:
        return null;
    }
  }

  Color _marketRiskColor() {
    switch (jsonStr(_stats['last_risk'])) {
      case 'panic':
      case 'elevated':
        return AppColors.tradingRed;
      default:
        return AppColors.textSecondary;
    }
  }

  String _lastRunText(bool th) {
    final at = _lastRunAt;
    if (at == null) {
      return th ? 'ยังไม่เคยเดิน' : 'Never run';
    }
    final m = DateTime.now().difference(at).inMinutes;
    if (m < 1) return th ? 'เมื่อครู่' : 'just now';
    if (m < 60) {
      return th ? '$m นาทีที่แล้ว' : '${m}m ago';
    }
    final h = m ~/ 60;
    final r = m % 60;
    return th ? '$h ชม. $r นาทีที่แล้ว' : '${h}h ${r}m ago';
  }
}

// ─────────────────────────────────────────────────────────
// ชิ้นส่วนภายใน
// ─────────────────────────────────────────────────────────

/// จุดสถานะ 8px — หายใจเมื่อบอทเดินอยู่จริง
class _StatusDot extends StatelessWidget {
  final Color color;
  final AnimationController pulse;
  final bool animate;

  const _StatusDot({
    required this.color,
    required this.pulse,
    required this.animate,
  });

  @override
  Widget build(BuildContext context) {
    final dot = Container(
      width: 9,
      height: 9,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: color,
        boxShadow: [
          BoxShadow(
            color: color.withValues(alpha: 0.5),
            blurRadius: 8,
            spreadRadius: 1,
          ),
        ],
      ),
    );

    if (!animate) return dot;

    return AnimatedBuilder(
      animation: pulse,
      builder: (_, child) => Opacity(
        opacity: 0.55 + (pulse.value * 0.45),
        child: child,
      ),
      child: dot,
    );
  }
}

/// ชิปโหมด ทดลอง / จริง — กดสลับได้ทันที
class _ModeChip extends StatelessWidget {
  final String mode;
  final bool liveEnabled;
  final bool th;
  final VoidCallback? onTap;

  const _ModeChip({
    required this.mode,
    required this.liveEnabled,
    required this.th,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final isLive = mode == 'live';
    final locked = !liveEnabled && !isLive;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(999),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(999),
            color: isLive ? accent.goldTint : AppColors.bgInputStrong,
            border: Border.all(
              color: isLive ? accent.goldBorder : AppColors.bgCardBorder,
              width: isLive ? 1.2 : 1,
            ),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                locked
                    ? Icons.lock_outline_rounded
                    : (isLive
                        ? Icons.bolt_rounded
                        : Icons.science_outlined),
                size: 11,
                color: isLive ? accent.g2 : AppColors.textTertiary,
              ),
              const SizedBox(width: 4),
              Text(
                isLive ? (th ? 'จริง' : 'Live') : (th ? 'ทดลอง' : 'Demo'),
                style: GoogleFonts.inter(
                  fontSize: 9.5,
                  fontWeight: FontWeight.w700,
                  color: isLive ? accent.g1 : AppColors.textSecondary,
                  letterSpacing: 0.6,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// ป้ายเล็กบอกสถานะ / ความเสี่ยง
class _MiniChip extends StatelessWidget {
  final String label;
  final Color color;

  const _MiniChip({required this.label, required this.color});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: color.withValues(alpha: 0.12),
        border: Border.all(color: color.withValues(alpha: 0.3), width: 1),
      ),
      child: Text(
        label,
        style: GoogleFonts.inter(
          fontSize: 9.5,
          fontWeight: FontWeight.w700,
          color: color,
          letterSpacing: 0.4,
        ),
      ),
    );
  }
}

/// บล็อกตัวเลข/ข้อความหนึ่งช่องพร้อมป้ายกำกับ
class _VitalBlock extends StatelessWidget {
  final String label;
  final String value;
  final Color valueColor;
  final bool mono;

  const _VitalBlock({
    required this.label,
    required this.value,
    required this.valueColor,
    required this.mono,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
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
        const SizedBox(height: 3),
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: Alignment.centerLeft,
          child: Text(
            value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: mono
                ? AppTheme.mono(
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                    color: valueColor,
                  )
                : GoogleFonts.inter(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w700,
                    color: valueColor,
                  ),
          ),
        ),
      ],
    );
  }
}

/// แถบเตือนพร้อมเหตุผล — ไม่มีความล้มเหลวไหนที่ไม่มีคำอธิบายบนจอ
class _AlertBox extends StatelessWidget {
  final IconData icon;
  final String title;
  final String detail;
  final bool danger;

  const _AlertBox({
    required this.icon,
    required this.title,
    required this.detail,
    required this.danger,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final tint = danger ? AppColors.tradingRedBg : accent.goldTint;
    final border = danger
        ? AppColors.tradingRed.withValues(alpha: 0.3)
        : accent.goldBorder;
    final iconColor = danger ? AppColors.tradingRed : accent.g2;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 9),
      decoration: BoxDecoration(
        color: tint,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: border, width: 1),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 15, color: iconColor),
          const SizedBox(width: 9),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: GoogleFonts.inter(
                    fontSize: 11.5,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  detail,
                  style: GoogleFonts.inter(
                    fontSize: 10.5,
                    height: 1.3,
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

/// ปุ่มสั่งงานแบบข้อความ + ไอคอน
class _ActionButton extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  final bool primary;

  const _ActionButton({
    required this.icon,
    required this.label,
    required this.onTap,
    required this.primary,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final enabled = onTap != null;
    final fg = !enabled
        ? AppColors.textDisabled
        : (primary ? AppColors.goldTextOn : AppColors.textSecondary);

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Ink(
          height: 38,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(12),
            gradient: primary && enabled ? accent.goldGradient : null,
            color: primary && enabled ? null : AppColors.bgInputStrong,
            border: Border.all(
              color: primary && enabled
                  ? Colors.transparent
                  : AppColors.bgCardBorder,
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
    );
  }
}

/// ปุ่มไอคอนสี่เหลี่ยม 38×38
class _IconAction extends StatelessWidget {
  final IconData icon;
  final String tooltip;
  final VoidCallback? onTap;
  final bool danger;

  const _IconAction({
    required this.icon,
    required this.tooltip,
    required this.onTap,
    this.danger = false,
  });

  @override
  Widget build(BuildContext context) {
    final enabled = onTap != null;
    final fg = !enabled
        ? AppColors.textDisabled
        : (danger ? AppColors.tradingRed : AppColors.textSecondary);

    return Tooltip(
      message: tooltip,
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(12),
          child: Ink(
            width: 38,
            height: 38,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(12),
              color: AppColors.bgInputStrong,
              border: Border.all(color: AppColors.bgCardBorder, width: 1),
            ),
            child: Icon(icon, size: 17, color: fg),
          ),
        ),
      ),
    );
  }
}

// ── ตัวช่วยจัดรูปตัวเลข ──────────────────────────────────

/// ตัดศูนย์ท้ายทศนิยมทิ้ง (5.00 → 5, 2.50 → 2.5)
String _trimNum(double v) {
  if (v == v.roundToDouble()) return v.toStringAsFixed(0);
  return v.toStringAsFixed(2).replaceFirst(RegExp(r'0+$'), '');
}

/// เครื่องหมายลบต้องอยู่หน้าสัญลักษณ์สกุลเงิน — `-$420.25` ไม่ใช่ `$-420.25`
String _signedMoney(double n) {
  final sign = n < 0 ? '-' : '+';
  return '$sign\$${n.abs().toStringAsFixed(2)}';
}
