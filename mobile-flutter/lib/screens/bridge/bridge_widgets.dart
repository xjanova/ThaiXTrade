/// TPIX TRADE — ชิ้นส่วนหน้าจอของสะพาน USDT (ธีมเดียวกับทั้งแอป: GlassCard / GradientButton / AppColors)
///
/// ดัดแปลงจาก TPIX Wallet (lib/widgets/bridge_widgets.dart) — พฤติกรรมเดิมทุกข้อ:
///   ที่อยู่ยาวต้องเห็นครบทุกตัวอักษร (ห้ามตัดเป็น …) · กล่องยืนยันคืน true เฉพาะกดยืนยัน
///   · ลิงก์จากเซิร์ฟเวอร์เปิดได้เฉพาะ https · ห้ามโชว์คีย์ข้อความดิบ
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/locale/locale_provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_fonts.dart';
import '../../core/theme/app_theme.dart';
import '../../providers/accent_provider.dart';
import '../../services/bridge_rules.dart';
import '../../widgets/common/glass_card.dart';
import '../../widgets/common/gradient_button.dart';

/// แปลข้อความ + แทนค่า {key} — คีย์ที่แอปรุ่นนี้ไม่รู้จัก (เช่น สถานะใหม่จากเซิร์ฟเวอร์)
/// ใช้ [fallbackKey] แทน ห้ามโชว์ชื่อคีย์ดิบ
String bridgeT(LocaleProvider l, String key, [Map<String, String> params = const {}, String? fallbackKey]) {
  var text = l.has(key) ? l.t(key) : (fallbackKey != null ? l.t(fallbackKey) : l.t('bridge.errTxFailed'));
  params.forEach((k, v) => text = text.replaceAll('{$k}', v));
  return text;
}

/// ที่อยู่/แฮชแบบย่อ — 0x12345678…abcdef
String bridgeShort(String? s, {int head = 10, int tail = 6}) {
  if (s == null || s.isEmpty) return '—';
  return s.length <= head + tail + 1 ? s : '${s.substring(0, head)}…${s.substring(s.length - tail)}';
}

Color bridgeToneColor(BridgeTone tone) => switch (tone) {
      BridgeTone.success => AppColors.tradingGreen,
      BridgeTone.info => AppColors.brandCyan,
      BridgeTone.warning => AppColors.brandWarm,
      BridgeTone.danger => AppColors.tradingRed,
      BridgeTone.muted => AppColors.textTertiary,
    };

void bridgeSnack(BuildContext context, String msg, {bool error = false}) {
  if (!context.mounted) return;
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(
      content: Text(msg),
      backgroundColor: error ? AppColors.tradingRed : AppColors.gold3,
      duration: Duration(seconds: error ? 6 : 4),
    ));
}

/// เปิดลิงก์ในเบราว์เซอร์ — https เท่านั้น (ลิงก์ส่วนใหญ่มาจากเซิร์ฟเวอร์)
Future<void> openBridgeUrl(BuildContext context, String? url) async {
  final safe = safeHttpsUrl(url);
  if (safe == null) return;
  final failText = context.read<LocaleProvider>().t('bridge.openFailed');
  var ok = false;
  try {
    ok = await launchUrl(Uri.parse(safe), mode: LaunchMode.externalApplication);
  } catch (_) {}
  if (!ok && context.mounted) bridgeSnack(context, failText, error: true);
}

/// ช่องกรอกจำนวน: ตัวเลข + จุดทศนิยมเดียว · จุลภาค (คีย์บอร์ดบางภาษา) แปลงเป็นจุด
final List<TextInputFormatter> bridgeAmountFormatters = [
  TextInputFormatter.withFunction((oldValue, newValue) {
    final text = newValue.text.replaceAll(',', '.');
    if (!RegExp(r'^\d*\.?\d*$').hasMatch(text) || text.length > 40) return oldValue;
    return newValue.copyWith(text: text);
  }),
];

class BridgeCard extends StatelessWidget {
  final Widget child;
  const BridgeCard({super.key, required this.child});

  @override
  Widget build(BuildContext context) => GlassCard(
        variant: GlassVariant.elevated,
        borderRadius: 16,
        padding: const EdgeInsets.all(16),
        child: child,
      );
}

class BridgeSectionTitle extends StatelessWidget {
  final String text;
  final Widget? trailing;
  const BridgeSectionTitle(this.text, {super.key, this.trailing});

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Row(
          children: [
            Expanded(
              child: Text(text,
                  style: AppFonts.ui(fontSize: 14.5, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
            ),
            ?trailing,
          ],
        ),
      );
}

/// ป้ายเล็กเหนือช่องกรอก
class BridgeLabel extends StatelessWidget {
  final String text;
  const BridgeLabel(this.text, {super.key});

  @override
  Widget build(BuildContext context) =>
      Text(text, style: AppFonts.ui(fontSize: 11.5, color: AppColors.textTertiary));
}

/// แถบข้อความสี (ยังไม่เปิด / หยุดชั่วคราว / แจ้งเตือน)
class BridgeBanner extends StatelessWidget {
  final String? title;
  final String text;
  final Color color;
  final IconData icon;

  const BridgeBanner({super.key, this.title, required this.text, required this.color, required this.icon});

  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(12),
          color: color.withValues(alpha: 0.10),
          border: Border.all(color: color.withValues(alpha: 0.32)),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, color: color, size: 20),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (title != null) ...[
                    Text(title!, style: AppFonts.ui(fontSize: 13.5, fontWeight: FontWeight.w700, color: color)),
                    const SizedBox(height: 4),
                  ],
                  Text(text, style: AppFonts.ui(fontSize: 12, height: 1.45, color: AppColors.textSecondary)),
                ],
              ),
            ),
          ],
        ),
      );
}

/// กล่องคำเตือนตัวใหญ่ — ความผิดพลาดแบบนี้เรียกเงินคืนไม่ได้ (ข้อแรกตัวหนา)
class BridgeWarningBox extends StatelessWidget {
  final String title;
  final List<String> items;

  const BridgeWarningBox({super.key, required this.title, required this.items});

  @override
  Widget build(BuildContext context) {
    final red = AppColors.tradingRed;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(12),
        color: red.withValues(alpha: 0.07),
        border: Border.all(color: red.withValues(alpha: 0.40), width: 1.4),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.warning_amber_rounded, color: red, size: 18),
              const SizedBox(width: 6),
              Expanded(
                child: Text(title, style: AppFonts.ui(fontSize: 13, fontWeight: FontWeight.w700, color: red)),
              ),
            ],
          ),
          const SizedBox(height: 8),
          for (var i = 0; i < items.length; i++)
            Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('•  ', style: AppFonts.ui(fontSize: 12, color: AppColors.textSecondary)),
                  Expanded(
                    child: Text(
                      items[i],
                      style: AppFonts.ui(
                        fontSize: 12,
                        height: 1.45,
                        color: i == 0 ? AppColors.textPrimary : AppColors.textSecondary,
                        fontWeight: i == 0 ? FontWeight.w700 : FontWeight.w400,
                      ),
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

/// แถว ป้าย : ค่า
class BridgeKv extends StatelessWidget {
  final String label;
  final String value;
  final Color? valueColor;

  const BridgeKv(this.label, this.value, {super.key, this.valueColor});

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(flex: 5, child: Text(label, style: AppFonts.ui(fontSize: 12, color: AppColors.textTertiary))),
            const SizedBox(width: 8),
            Expanded(
              flex: 6,
              child: Text(
                value,
                textAlign: TextAlign.end,
                style: AppTheme.mono(fontSize: 12, color: valueColor ?? AppColors.textPrimary),
              ),
            ),
          ],
        ),
      );
}

/// ปุ่มเลือกแบบแท็บ
class BridgeSegmented<T> extends StatelessWidget {
  final List<(T, String)> options;
  final T value;
  final ValueChanged<T>? onChanged;

  const BridgeSegmented({super.key, required this.options, required this.value, this.onChanged});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      padding: const EdgeInsets.all(3),
      decoration: BoxDecoration(
        color: AppColors.bgInput,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.bgCardBorder),
      ),
      child: Row(
        children: [
          for (final o in options)
            Expanded(
              child: Semantics(
                selected: o.$1 == value,
                button: true,
                child: InkWell(
                  borderRadius: BorderRadius.circular(10),
                  onTap: onChanged == null || o.$1 == value ? null : () => onChanged!(o.$1),
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 160),
                    padding: const EdgeInsets.symmetric(vertical: 9, horizontal: 4),
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(10),
                      color: o.$1 == value ? accent.goldTint : Colors.transparent,
                      border: Border.all(color: o.$1 == value ? accent.goldBorder : Colors.transparent),
                    ),
                    alignment: Alignment.center,
                    child: Text(
                      o.$2,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppFonts.ui(
                        fontSize: 12.5,
                        fontWeight: o.$1 == value ? FontWeight.w700 : FontWeight.w500,
                        color: o.$1 == value ? accent.g1 : AppColors.textSecondary,
                      ),
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

/// ตัวเลือกเชน — รับเฉพาะเชนที่เลือกได้ (เชนที่ปิดอยู่ไม่ถูกส่งเข้ามาเลย)
class BridgeChainPicker extends StatelessWidget {
  final List<({int id, String title, String subtitle})> chains;
  final int? selected;
  final ValueChanged<int>? onSelect;

  const BridgeChainPicker({super.key, required this.chains, required this.selected, this.onSelect});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final ch in chains)
          InkWell(
            key: ValueKey('bridge-chain-${ch.id}'),
            borderRadius: BorderRadius.circular(12),
            onTap: onSelect == null ? null : () => onSelect!(ch.id),
            child: Container(
              constraints: const BoxConstraints(minWidth: 140),
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(12),
                color: ch.id == selected ? accent.goldTint : AppColors.bgTertiary,
                border: Border.all(color: ch.id == selected ? accent.goldBorder : AppColors.bgCardBorder),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(ch.title,
                      style: AppFonts.ui(fontSize: 13, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                  const SizedBox(height: 2),
                  Text(ch.subtitle, style: AppFonts.ui(fontSize: 11, color: AppColors.textTertiary)),
                ],
              ),
            ),
          ),
      ],
    );
  }
}

/// ช่องกรอก (จำนวน / ที่อยู่ / tx hash)
class BridgeInput extends StatelessWidget {
  final TextEditingController controller;
  final String hint;
  final bool enabled;
  final bool amount;
  final ValueChanged<String>? onChanged;
  final String? errorText;
  final String? warningText;
  final Widget? suffix;

  const BridgeInput({
    super.key,
    required this.controller,
    required this.hint,
    this.enabled = true,
    this.amount = false,
    this.onChanged,
    this.errorText,
    this.warningText,
    this.suffix,
  });

  @override
  Widget build(BuildContext context) {
    final red = AppColors.tradingRed;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 2),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(10),
            color: AppColors.bgInput,
            border: Border.all(color: errorText != null ? red.withValues(alpha: 0.6) : AppColors.bgCardBorder),
          ),
          child: Row(
            children: [
              Expanded(
                child: TextField(
                  controller: controller,
                  enabled: enabled,
                  onChanged: onChanged,
                  autocorrect: false,
                  enableSuggestions: false,
                  keyboardType: amount ? const TextInputType.numberWithOptions(decimal: true) : TextInputType.text,
                  inputFormatters: amount ? bridgeAmountFormatters : null,
                  style: AppTheme.mono(fontSize: amount ? 18 : 12.5, color: AppColors.textPrimary),
                  decoration: InputDecoration(
                    hintText: hint,
                    hintStyle: AppFonts.ui(fontSize: amount ? 16 : 12.5, color: AppColors.textTertiary),
                    border: InputBorder.none,
                    isDense: true,
                    contentPadding: const EdgeInsets.symmetric(vertical: 11),
                  ),
                ),
              ),
              ?suffix,
            ],
          ),
        ),
        if (errorText != null)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(errorText!, style: AppFonts.ui(fontSize: 11.5, color: red)),
          )
        else if (warningText != null)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(warningText!, style: AppFonts.ui(fontSize: 11.5, color: AppColors.brandWarm)),
          ),
      ],
    );
  }
}

/// ช่องติ๊กยอมรับ (ต้องติ๊กใหม่ทุกครั้งที่เปิดหน้า / สลับกระเป๋า)
class BridgeAckTile extends StatelessWidget {
  final bool value;
  final ValueChanged<bool>? onChanged;
  final String text;

  const BridgeAckTile({super.key, required this.value, required this.onChanged, required this.text});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return InkWell(
      borderRadius: BorderRadius.circular(8),
      onTap: onChanged == null ? null : () => onChanged!(!value),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 24,
              height: 24,
              child: Checkbox(
                value: value,
                onChanged: onChanged == null ? null : (v) => onChanged!(v ?? false),
                activeColor: accent.g2,
                checkColor: AppColors.goldTextOn,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Text(text, style: AppFonts.ui(fontSize: 11.5, height: 1.45, color: AppColors.textSecondary)),
            ),
          ],
        ),
      ),
    );
  }
}

/// ปุ่มหลัก — กำลังทำงาน = หมุน + กดไม่ได้ ([loadingLabel] บอกว่ากำลังทำอะไร)
class BridgePrimaryButton extends StatelessWidget {
  final String label;
  final VoidCallback? onPressed;
  final bool loading;
  final String? loadingLabel;

  const BridgePrimaryButton({super.key, required this.label, required this.onPressed, this.loading = false, this.loadingLabel});

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          GradientButton(text: label, onPressed: loading ? null : onPressed, isLoading: loading, height: 48),
          if (loading && loadingLabel != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(loadingLabel!,
                  textAlign: TextAlign.center,
                  style: AppFonts.ui(fontSize: 11.5, color: AppColors.textSecondary)),
            ),
        ],
      );
}

/// ที่อยู่เต็ม + ปุ่มคัดลอก (ผู้ใช้ต้องตรวจได้ทุกตัวอักษร ห้ามตัดเป็น …)
class BridgeAddressLine extends StatelessWidget {
  final String label;
  final String address;

  const BridgeAddressLine({super.key, required this.label, required this.address});

  @override
  Widget build(BuildContext context) {
    final l = context.watch<LocaleProvider>();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        BridgeLabel(label),
        const SizedBox(height: 2),
        Row(
          children: [
            Expanded(child: SelectableText(address, style: AppTheme.mono(fontSize: 11.5, color: AppColors.textPrimary))),
            IconButton(
              tooltip: l.t('bridge.copy'),
              visualDensity: VisualDensity.compact,
              icon: Icon(Icons.copy_rounded, size: 16, color: AppColors.textTertiary),
              onPressed: () async {
                await Clipboard.setData(ClipboardData(text: address));
                if (context.mounted) bridgeSnack(context, l.t('bridge.copied'));
              },
            ),
          ],
        ),
      ],
    );
  }
}

/// ลิงก์เล็ก (เปิดเบราว์เซอร์) — ไม่มี URL = ไม่แสดง
class BridgeLink extends StatelessWidget {
  final String label;
  final String? url;

  const BridgeLink({super.key, required this.label, required this.url});

  @override
  Widget build(BuildContext context) {
    if (url == null) return const SizedBox.shrink();
    final color = AppColors.brandCyan;
    return InkWell(
      onTap: () => openBridgeUrl(context, url),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Flexible(child: Text(label, style: AppFonts.ui(fontSize: 11.5, color: color, fontWeight: FontWeight.w600))),
            const SizedBox(width: 4),
            Icon(Icons.open_in_new_rounded, size: 12, color: color),
          ],
        ),
      ),
    );
  }
}

/// ป้ายสถานะ
class BridgeStateChip extends StatelessWidget {
  final String label;
  final BridgeTone tone;

  const BridgeStateChip({super.key, required this.label, required this.tone});

  @override
  Widget build(BuildContext context) {
    final color = bridgeToneColor(tone);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(8),
        color: color.withValues(alpha: 0.14),
        border: Border.all(color: color.withValues(alpha: 0.35)),
      ),
      child: Text(label, style: AppFonts.ui(fontSize: 10.5, fontWeight: FontWeight.w700, color: color)),
    );
  }
}

/// ขั้นตอนการทำงาน (เลขลำดับ + ข้อความ)
class BridgeHowItWorks extends StatelessWidget {
  final String title;
  final List<String> steps;

  const BridgeHowItWorks({super.key, required this.title, required this.steps});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return BridgeCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          BridgeSectionTitle(title),
          for (var i = 0; i < steps.length; i++)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 20,
                    height: 20,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(shape: BoxShape.circle, color: accent.goldTint),
                    child: Text('${i + 1}',
                        style: AppFonts.ui(fontSize: 10, fontWeight: FontWeight.w700, color: accent.g1)),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(steps[i], style: AppFonts.ui(fontSize: 12, height: 1.45, color: AppColors.textSecondary)),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

/// กล่องยืนยันก่อนส่ง — คืน true เมื่อกดยืนยันเท่านั้น (ปัดทิ้ง/ยกเลิก = false)
Future<bool> showBridgeConfirmSheet(
  BuildContext context, {
  required String title,
  required List<(String, String)> rows,
  List<String> notes = const [],
  required String confirmLabel,
  bool danger = false,
}) async {
  final l = context.read<LocaleProvider>();
  final ok = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    backgroundColor: Colors.transparent,
    builder: (sheetCtx) => Container(
      constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(sheetCtx).height * 0.9),
      decoration: BoxDecoration(
        color: AppColors.bgSecondary,
        borderRadius: const BorderRadius.vertical(top: Radius.circular(22)),
        border: Border.all(color: AppColors.bgCardBorder),
      ),
      child: SafeArea(
        top: false,
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: Container(
                  width: 40,
                  height: 4,
                  decoration: BoxDecoration(borderRadius: BorderRadius.circular(2), color: AppColors.textTertiary.withValues(alpha: 0.4)),
                ),
              ),
              const SizedBox(height: 16),
              Text(title, style: AppFonts.ui(fontSize: 17, fontWeight: FontWeight.w800, color: AppColors.textPrimary)),
              const SizedBox(height: 12),
              for (final r in rows)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 5),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(flex: 4, child: Text(r.$1, style: AppFonts.ui(fontSize: 12.5, color: AppColors.textTertiary))),
                      const SizedBox(width: 10),
                      // ที่อยู่ยาว — ตัดบรรทัดได้ ห้ามตัดเป็น … (ผู้ใช้ต้องตรวจได้ทุกตัวอักษร)
                      Expanded(
                        flex: 6,
                        child: SelectableText(r.$2,
                            textAlign: TextAlign.end, style: AppTheme.mono(fontSize: 12.5, color: AppColors.textPrimary)),
                      ),
                    ],
                  ),
                ),
              for (final n in notes)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(Icons.warning_amber_rounded, size: 16, color: AppColors.brandWarm),
                      const SizedBox(width: 6),
                      Expanded(child: Text(n, style: AppFonts.ui(fontSize: 12, height: 1.4, color: AppColors.brandWarm))),
                    ],
                  ),
                ),
              const SizedBox(height: 18),
              Row(
                children: [
                  Expanded(
                    child: GradientButton(
                      text: l.t('bridge.cancel'),
                      variant: ButtonVariant.outline,
                      height: 46,
                      onPressed: () => Navigator.pop(sheetCtx, false),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    flex: 2,
                    child: GradientButton(
                      key: const ValueKey('bridge-confirm'),
                      text: confirmLabel,
                      variant: danger ? ButtonVariant.sell : ButtonVariant.gold,
                      height: 46,
                      onPressed: () => Navigator.pop(sheetCtx, true),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    ),
  );
  return ok == true;
}

/// ถามก่อนลบรายการที่ติดตาม (ลบแค่ในเครื่อง แต่ผู้ใช้อาจต้องใช้ tx hash ติดต่อซัพพอร์ต)
Future<bool> confirmRemoveTracked(BuildContext context) async {
  final l = context.read<LocaleProvider>();
  final ok = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      backgroundColor: AppColors.bgSecondary,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      title: Text(l.t('bridge.removeTitle'),
          style: AppFonts.ui(fontSize: 16, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
      content: Text(l.t('bridge.removeBody'), style: AppFonts.ui(fontSize: 13, height: 1.45, color: AppColors.textSecondary)),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(ctx, false),
          child: Text(l.t('bridge.cancel'), style: AppFonts.ui(color: AppColors.textTertiary)),
        ),
        TextButton(
          onPressed: () => Navigator.pop(ctx, true),
          child: Text(l.t('bridge.remove'), style: AppFonts.ui(color: AppColors.tradingRed, fontWeight: FontWeight.w700)),
        ),
      ],
    ),
  );
  return ok == true;
}
