/// TPIX TRADE — การ์ดกระเป๋าบอท: กระเป๋าแยกที่บอทใช้ในโหมดจริง
///
/// เจ้าของสั่ง: "บอทจะล็อกกระเป๋าแยกไปต่างหาก ผู้ใช้ต้องโอนไปใส่กระเป๋าบอทก่อน
/// เปิดบอทแล้วยังเทรดเองได้ปกติ ไม่กระทบกัน — คนละกระเป๋ากับที่ผู้ใช้เทรด"
///
/// สามอย่างที่การ์ดนี้ทำ: โชว์ที่อยู่ให้โอนเข้า · โชว์ยอดของบอท · ถอนกลับกระเป๋า
/// ของตัวเอง (ปลายทางเดียว — ไม่มีช่องกรอกที่อยู่อื่น เซิร์ฟเวอร์ก็ไม่รับ)
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../models/ai_bot_models.dart';
import '../../providers/accent_provider.dart';
import '../../providers/ai_bot_provider.dart';
import '../common/glass_card.dart';
import '../common/gradient_button.dart';

class BotWalletCard extends StatefulWidget {
  final bool th;

  const BotWalletCard({super.key, required this.th});

  @override
  State<BotWalletCard> createState() => _BotWalletCardState();
}

class _BotWalletCardState extends State<BotWalletCard> {
  final _amount = TextEditingController();
  String _asset = 'USDT';
  bool _copied = false;
  String? _message;
  bool _messageOk = true;

  @override
  void initState() {
    super.initState();
    // โหลดครั้งแรกหลังเฟรม — provider แจ้งเปลี่ยนระหว่าง build ไม่ได้
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      final bot = context.read<AiBotProvider>();
      if (bot.botWallet == null && bot.isWalletConnected) bot.loadBotWallet();
    });
  }

  @override
  void dispose() {
    _amount.dispose();
    super.dispose();
  }

  String _fmt(double v, [int digits = 4]) {
    final s = v.toStringAsFixed(digits);
    return s.contains('.') ? s.replaceFirst(RegExp(r'\.?0+$'), '') : s;
  }

  Future<void> _copy(String address) async {
    await Clipboard.setData(ClipboardData(text: address));
    if (!mounted) return;
    setState(() => _copied = true);
    Future.delayed(const Duration(milliseconds: 1500), () {
      if (mounted) setState(() => _copied = false);
    });
  }

  Future<void> _act(Future<bool> Function() action) async {
    final th = widget.th;
    setState(() => _message = null);
    final ok = await action();
    if (!mounted) return;
    final bot = context.read<AiBotProvider>();
    setState(() {
      _messageOk = ok;
      _message = ok
          ? (th ? 'ทำรายการแล้ว' : 'Done')
          : bot.errorText(th);
    });
  }

  Future<void> _withdraw() async {
    final th = widget.th;
    final value = double.tryParse(_amount.text.trim().replaceAll(',', ''));
    if (value == null || value <= 0) {
      setState(() {
        _messageOk = false;
        _message = th ? 'กรอกจำนวนที่ถูกต้อง' : 'Enter a valid amount';
      });
      return;
    }
    final bot = context.read<AiBotProvider>();
    await _act(() => bot.withdrawBotWallet(_asset, value));
    if (mounted && _messageOk) _amount.clear();
  }

  void _useMax(BotWalletInfo w) {
    final asset = w.assets.where((a) => a.symbol == _asset).firstOrNull;
    if (asset == null) return;
    var max = asset.balance;
    if (asset.isNative) max = (max - w.gasReserve).clamp(0, double.infinity);
    _amount.text = max > 0 ? _fmt((max * 1e6).floorToDouble() / 1e6, 6) : '';
  }

  Color _statusColor(String status) {
    switch (status) {
      case 'confirmed':
        return AppColors.tradingGreen;
      case 'failed':
        return AppColors.tradingRed;
      case 'cancelled':
        return AppColors.textTertiary;
      default:
        return const Color(0xFF38BDF8);
    }
  }

  @override
  Widget build(BuildContext context) {
    final th = widget.th;
    final bot = context.watch<AiBotProvider>();
    final accent = context.watch<AccentProvider>();
    final state = bot.botWallet;
    final wallet = state?.wallet;
    final busy = bot.isWorking;

    // ให้ตัวเลือกสินทรัพย์ตรงกับที่กระเป๋ามีจริงเสมอ
    if (wallet != null &&
        wallet.assets.isNotEmpty &&
        !wallet.assets.any((a) => a.symbol == _asset)) {
      _asset = wallet.assets.first.symbol;
    }

    return GlassCard(
      variant: GlassVariant.standard,
      borderRadius: AppTheme.radiusLg,
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.account_balance_wallet_rounded, size: 18, color: accent.g2),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  th ? 'กระเป๋าบอท' : 'Bot wallet',
                  style: GoogleFonts.inter(
                    fontSize: 14,
                    fontWeight: FontWeight.w800,
                    color: AppColors.textPrimary,
                  ),
                ),
              ),
              if (wallet != null)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                  decoration: BoxDecoration(
                    color: AppColors.bgInput,
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Text(
                    wallet.chainName,
                    style: GoogleFonts.inter(
                      fontSize: 10,
                      fontWeight: FontWeight.w700,
                      color: AppColors.textSecondary,
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            th
                ? 'กระเป๋าแยกที่บอทใช้ในโหมดจริง — โอนทุนของบอทเข้ามาที่นี่ กระเป๋าที่คุณเทรดเองไม่ถูกแตะ และถอนกลับได้เฉพาะกระเป๋าของคุณเอง'
                : 'A separate wallet the bot trades from in live mode — fund it here. Your own trading wallet is never touched, and withdrawals only go back to your wallet',
            style: GoogleFonts.inter(
              fontSize: 11,
              color: AppColors.textTertiary,
              height: 1.5,
            ),
          ),
          const SizedBox(height: 12),

          if (state == null) ...[
            Text(
              bot.isLoadingBotWallet
                  ? (th ? 'กำลังโหลด…' : 'Loading…')
                  : (th ? 'เชื่อมและยืนยันกระเป๋าก่อนเพื่อดูกระเป๋าบอท' : 'Connect and verify your wallet to see the bot wallet'),
              style: GoogleFonts.inter(fontSize: 11.5, color: AppColors.textSecondary),
            ),
          ] else if (!state.enabled) ...[
            _note(
              th
                  ? 'กระเป๋าบอทจะเปิดพร้อมกับโหมดจริง เมื่อระบบผ่านการทดสอบ — ตอนนี้บอททุกตัวใช้พอร์ตทดลอง'
                  : 'The bot wallet opens together with live mode once the system has been fully proven — every bot uses the demo portfolio for now',
            ),
          ] else if (wallet == null) ...[
            _note(
              th
                  ? 'ระบบจะสร้างกระเป๋าบนเชน BSC ให้หนึ่งใบ ผูกกับกระเป๋าที่คุณยืนยันแล้ว กุญแจถูกเข้ารหัสสองชั้นและใช้ได้เฉพาะตอนบอทส่งคำสั่งเท่านั้น'
                  : 'We generate one BSC wallet bound to your verified address. Its key is double-encrypted and only usable when the bot sends orders',
            ),
            const SizedBox(height: 12),
            GradientButton(
              text: th ? 'สร้างกระเป๋าบอท' : 'Create bot wallet',
              icon: Icons.add_rounded,
              onPressed: busy ? null : () => _act(bot.createBotWallet),
            ),
          ] else ...[
            // ── ที่อยู่สำหรับโอนเข้า ──
            _label(th ? 'ที่อยู่สำหรับโอนเข้า' : 'Deposit address'),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              decoration: BoxDecoration(
                color: AppColors.bgInput,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: AppColors.bgCardBorder),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      wallet.address,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTheme.mono(fontSize: 12, color: AppColors.textPrimary),
                    ),
                  ),
                  const SizedBox(width: 8),
                  GestureDetector(
                    onTap: () => _copy(wallet.address),
                    child: Icon(
                      _copied ? Icons.check_rounded : Icons.copy_rounded,
                      size: 16,
                      color: _copied ? AppColors.tradingGreen : AppColors.textSecondary,
                    ),
                  ),
                  if (wallet.explorerUrl != null) ...[
                    const SizedBox(width: 10),
                    GestureDetector(
                      onTap: () => launchUrl(Uri.parse(wallet.explorerUrl!), mode: LaunchMode.externalApplication),
                      child: Icon(Icons.open_in_new_rounded, size: 16, color: accent.g2),
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: 6),
            Text(
              th
                  ? 'โอน USDT (BSC) เป็นทุนของบอท และ ${wallet.nativeSymbol} เล็กน้อยไว้จ่ายค่าแก๊ส — ส่งบนเชน BSC เท่านั้น'
                  : 'Send USDT (BSC) as the bot\'s capital plus a little ${wallet.nativeSymbol} for gas — BSC network only',
              style: GoogleFonts.inter(fontSize: 10.5, color: AppColors.textTertiary, height: 1.5),
            ),

            // ── ยอดของบอท ──
            const SizedBox(height: 12),
            Row(
              children: [
                for (final a in wallet.assets) ...[
                  Expanded(
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
                      decoration: BoxDecoration(
                        color: AppColors.bgInput,
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            a.isNative ? '${a.symbol} · ${th ? 'แก๊ส' : 'gas'}' : a.symbol,
                            style: GoogleFonts.inter(fontSize: 10, color: AppColors.textTertiary),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            _fmt(a.balance, a.isNative ? 5 : 2),
                            style: AppTheme.mono(fontSize: 14, fontWeight: FontWeight.w700, color: AppColors.textPrimary),
                          ),
                        ],
                      ),
                    ),
                  ),
                  if (a != wallet.assets.last) const SizedBox(width: 8),
                ],
              ],
            ),
            const SizedBox(height: 6),
            Row(
              children: [
                Expanded(
                  child: Text(
                    wallet.balancesAt == null
                        ? (th ? 'ยังไม่เคยอ่านยอด' : 'Balances not read yet')
                        : '${th ? 'ยอด ณ' : 'As of'} ${TimeOfDay.fromDateTime(wallet.balancesAt!.toLocal()).format(context)}',
                    style: GoogleFonts.inter(fontSize: 10, color: AppColors.textTertiary),
                  ),
                ),
                GestureDetector(
                  onTap: busy ? null : () => _act(bot.refreshBotWallet),
                  child: Text(
                    th ? 'อ่านยอดใหม่' : 'Refresh',
                    style: GoogleFonts.inter(fontSize: 11, fontWeight: FontWeight.w700, color: accent.g2),
                  ),
                ),
              ],
            ),

            // ── ถอนกลับกระเป๋าของฉัน ──
            const SizedBox(height: 14),
            _label(th ? 'ถอนกลับกระเป๋าของฉัน' : 'Withdraw to my wallet'),
            Row(
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10),
                  decoration: BoxDecoration(
                    color: AppColors.bgInput,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: AppColors.bgCardBorder),
                  ),
                  child: DropdownButtonHideUnderline(
                    child: DropdownButton<String>(
                      value: _asset,
                      dropdownColor: AppColors.bgCard,
                      style: AppTheme.mono(fontSize: 12, color: AppColors.textPrimary),
                      items: wallet.assets
                          .map((a) => DropdownMenuItem(value: a.symbol, child: Text(a.symbol)))
                          .toList(),
                      onChanged: (v) => setState(() => _asset = v ?? _asset),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 12),
                    decoration: BoxDecoration(
                      color: AppColors.bgInput,
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: AppColors.bgCardBorder),
                    ),
                    child: Row(
                      children: [
                        Expanded(
                          child: TextField(
                            controller: _amount,
                            keyboardType: const TextInputType.numberWithOptions(decimal: true),
                            inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
                            style: AppTheme.mono(fontSize: 13),
                            cursorColor: accent.g2,
                            decoration: InputDecoration(
                              border: InputBorder.none,
                              isDense: true,
                              hintText: th ? 'จำนวน' : 'Amount',
                              hintStyle: const TextStyle(color: AppColors.textDisabled),
                              contentPadding: const EdgeInsets.symmetric(vertical: 12),
                            ),
                          ),
                        ),
                        GestureDetector(
                          onTap: () => _useMax(wallet),
                          child: Text('MAX', style: GoogleFonts.inter(fontSize: 10, fontWeight: FontWeight.w800, color: AppColors.textSecondary)),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            GradientButton(
              text: th ? 'ถอนกลับกระเป๋าของฉัน' : 'Withdraw to my wallet',
              icon: Icons.arrow_outward_rounded,
              onPressed: busy || wallet.hasPendingWithdraw ? null : _withdraw,
            ),
            const SizedBox(height: 6),
            Text(
              th
                  ? 'ถอนได้ปลายทางเดียวคือกระเป๋าที่คุณยืนยันไว้ · ระบบส่งให้ภายในไม่กี่นาที · ต้องเหลือ ${wallet.nativeSymbol} ไว้จ่ายแก๊ส'
                  : 'The only destination is your verified wallet · sent within minutes · keep some ${wallet.nativeSymbol} for gas',
              style: GoogleFonts.inter(fontSize: 10, color: AppColors.textTertiary, height: 1.5),
            ),

            if (_message != null) ...[
              const SizedBox(height: 10),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                decoration: BoxDecoration(
                  color: (_messageOk ? AppColors.tradingGreen : AppColors.tradingRed).withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Text(
                  _message!,
                  style: GoogleFonts.inter(fontSize: 11, color: _messageOk ? AppColors.tradingGreen : AppColors.tradingRed),
                ),
              ),
            ],

            // ── รายการโอน ──
            if (state.transfers.isNotEmpty) ...[
              const SizedBox(height: 14),
              _label(th ? 'รายการโอน' : 'Transfers'),
              for (final tr in state.transfers.take(10))
                Container(
                  margin: const EdgeInsets.only(bottom: 6),
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  decoration: BoxDecoration(
                    color: AppColors.bgInput,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                        decoration: BoxDecoration(
                          color: _statusColor(tr.status).withValues(alpha: 0.14),
                          borderRadius: BorderRadius.circular(6),
                        ),
                        child: Text(
                          tr.statusLabel(th),
                          style: GoogleFonts.inter(fontSize: 9.5, fontWeight: FontWeight.w700, color: _statusColor(tr.status)),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Text(
                        '${_fmt(tr.amount, 6)} ${tr.asset}',
                        style: AppTheme.mono(fontSize: 12, color: AppColors.textPrimary),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          tr.failureReason ?? (tr.createdAt == null ? '' : TimeOfDay.fromDateTime(tr.createdAt!.toLocal()).format(context)),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: GoogleFonts.inter(fontSize: 10, color: AppColors.textTertiary),
                        ),
                      ),
                      if (tr.txUrl != null)
                        GestureDetector(
                          onTap: () => launchUrl(Uri.parse(tr.txUrl!), mode: LaunchMode.externalApplication),
                          child: Padding(
                            padding: const EdgeInsets.only(left: 6),
                            child: Icon(Icons.open_in_new_rounded, size: 14, color: accent.g2),
                          ),
                        ),
                      if (tr.cancellable)
                        GestureDetector(
                          onTap: busy ? null : () => _act(() => bot.cancelBotWalletWithdraw(tr.id)),
                          child: Padding(
                            padding: const EdgeInsets.only(left: 8),
                            child: Text(
                              th ? 'ยกเลิก' : 'Cancel',
                              style: GoogleFonts.inter(fontSize: 10.5, fontWeight: FontWeight.w700, color: AppColors.tradingRed),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
            ],
          ],
        ],
      ),
    );
  }

  Widget _label(String text) => Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Text(
          text,
          style: GoogleFonts.inter(
            fontSize: 11,
            fontWeight: FontWeight.w600,
            color: AppColors.textTertiary,
            letterSpacing: 0.2,
          ),
        ),
      );

  Widget _note(String text) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        decoration: BoxDecoration(
          color: AppColors.bgInput,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Text(
          text,
          style: GoogleFonts.inter(fontSize: 11.5, color: AppColors.textSecondary, height: 1.5),
        ),
      );
}
