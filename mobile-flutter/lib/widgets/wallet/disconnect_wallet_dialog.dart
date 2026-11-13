/// TPIX TRADE — หน้ายืนยันตัดการเชื่อมต่อกระเป๋า (ใช้ร่วมกันทุกหน้าที่มีปุ่มตัดการเชื่อมต่อ)
///
/// กติกาเดียวทั้งแอป:
///   • กระเป๋าภายนอก / กระเป๋าที่ลิงก์จาก TPIX Wallet — ออกจากระบบกระเป๋านั้นในแอปเท่านั้น
///     กุญแจของกระเป๋าในแอป (ถ้ามี) ไม่ถูกแตะ และแอปจะกลับไปใช้กระเป๋าในแอปใบนั้น
///   • กระเป๋าในแอป — การตัดการเชื่อมต่อ = ลบกุญแจ + วลีกู้คืนออกจากเครื่องถาวร
///     ต้องผ่านหน้าเตือนที่บอกให้สำรองวลีกู้คืนก่อน และติ๊กยืนยันเองว่าสำรองแล้ว
///     จึงจะส่ง `deleteEmbeddedKey: true` ให้ WalletProvider.disconnect ได้
///
/// Developed by Xman Studio
library;

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/locale/locale_provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_fonts.dart';
import '../../providers/wallet_provider.dart';

/// ถามยืนยันแล้วตัดการเชื่อมต่อกระเป๋าที่ใช้อยู่ — คืน true เมื่อตัดแล้ว
///
/// แจ้งผลด้วย snackbar เอง (รวมกรณีแอปกลับไปใช้กระเป๋าในแอป)
Future<bool> confirmAndDisconnectWallet(BuildContext context) async {
  final wallet = context.read<WalletProvider>();
  final th = context.read<LocaleProvider>().isThai;
  if (!wallet.isConnected) return false;

  // จับไว้ก่อนเปิดหน้ายืนยัน — ผู้ใช้ยืนยันสำหรับกระเป๋าใบนี้ ชนิดนี้
  final kind = wallet.kind;
  final address = wallet.address;
  final embedded = kind == WalletKind.embedded;

  final ok = await showDialog<bool>(
    context: context,
    builder: (_) => _DisconnectDialog(embedded: embedded, th: th),
  );
  if (ok != true || !context.mounted) return false;

  // กระเป๋าถูกเปลี่ยนระหว่างที่หน้ายืนยันเปิดอยู่ (deep link เชื่อมใบอื่น) — คำยืนยันนี้ใช้ไม่ได้แล้ว
  // โดยเฉพาะ "ลบกุญแจ" ต้องไม่ไปตกกับกระเป๋าที่ผู้ใช้ไม่ได้ยืนยัน
  if (wallet.kind != kind ||
      wallet.address?.toLowerCase() != address?.toLowerCase()) {
    return false;
  }

  final done = await wallet.disconnect(deleteEmbeddedKey: embedded);
  if (!context.mounted) return done;
  if (!done) return false;

  // ตัดกระเป๋าภายนอกแล้วเครื่องนี้ยังมีกระเป๋าในแอปอยู่ — แอปกลับไปใช้ใบนั้น บอกให้ชัด
  final backOnEmbedded =
      !embedded && wallet.isConnected && wallet.kind == WalletKind.embedded;
  final String message;
  if (backOnEmbedded) {
    message = th
        ? 'ตัดการเชื่อมต่อแล้ว — กลับมาใช้กระเป๋าในแอป ${wallet.shortAddress}'
        : 'Disconnected — back on your in-app wallet ${wallet.shortAddress}';
  } else {
    message = th ? 'ตัดการเชื่อมต่อแล้ว' : 'Wallet disconnected';
  }
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(
    content: Text(message),
    duration: Duration(seconds: backOnEmbedded ? 4 : 2),
  ));
  return true;
}

class _DisconnectDialog extends StatefulWidget {
  final bool embedded;
  final bool th;

  const _DisconnectDialog({required this.embedded, required this.th});

  @override
  State<_DisconnectDialog> createState() => _DisconnectDialogState();
}

class _DisconnectDialogState extends State<_DisconnectDialog> {
  /// ผู้ใช้ติ๊กเองว่าสำรองวลีกู้คืนแล้ว — ปุ่มลบกดไม่ได้จนกว่าจะติ๊ก (เฉพาะกระเป๋าในแอป)
  bool _backedUp = false;

  @override
  Widget build(BuildContext context) {
    final th = widget.th;
    final embedded = widget.embedded;
    final canConfirm = !embedded || _backedUp;

    return AlertDialog(
      backgroundColor: AppColors.bgElevated,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(18),
        side: BorderSide(color: AppColors.tradingRed.withValues(alpha: 0.3)),
      ),
      title: Text(
        embedded
            ? (th
                ? 'ลบกระเป๋าในแอปออกจากเครื่อง?'
                : 'Remove the in-app wallet from this phone?')
            : (th ? 'ตัดการเชื่อมต่อ?' : 'Disconnect wallet?'),
        style: AppFonts.ui(
            color: AppColors.textPrimary,
            fontSize: 16,
            fontWeight: FontWeight.w800),
      ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            embedded
                ? (th
                    ? 'สำรองวลีกู้คืน 12 คำไว้ก่อน — การตัดการเชื่อมต่อจะลบกุญแจและวลีกู้คืนของกระเป๋านี้ออกจากเครื่องถาวร กู้คืนได้ด้วยวลีกู้คืนเท่านั้น ถ้ายังไม่ได้จดไว้ เงินในกระเป๋าจะเข้าถึงไม่ได้อีก'
                    : 'Back up your 12-word recovery phrase first. Disconnecting permanently deletes this wallet\'s keys and recovery phrase from this phone. Only the recovery phrase can restore it — without it the funds are lost.')
                : (th
                    ? 'จะออกจากระบบกระเป๋านี้ในแอป (กุญแจอยู่ในแอปกระเป๋าของคุณ ไม่ถูกลบ) ถ้าเครื่องนี้มีกระเป๋าในแอปอยู่ แอปจะกลับไปใช้กระเป๋าใบนั้น'
                    : 'You will be signed out of this wallet in the app (your keys stay in your wallet app). If this phone has an in-app wallet, the app switches back to it.'),
            style: AppFonts.ui(
                fontSize: 13, height: 1.45, color: AppColors.textSecondary),
          ),
          if (embedded) ...[
            const SizedBox(height: 10),
            InkWell(
              onTap: () => setState(() => _backedUp = !_backedUp),
              borderRadius: BorderRadius.circular(8),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: Row(
                  children: [
                    SizedBox(
                      width: 24,
                      height: 24,
                      child: Checkbox(
                        value: _backedUp,
                        activeColor: AppColors.tradingRed,
                        onChanged: (v) =>
                            setState(() => _backedUp = v ?? false),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        th
                            ? 'ฉันสำรองวลีกู้คืนของกระเป๋านี้ไว้แล้ว'
                            : 'I have backed up this wallet\'s recovery phrase',
                        style: AppFonts.ui(
                            fontSize: 12.5,
                            fontWeight: FontWeight.w600,
                            color: AppColors.textPrimary),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context, false),
          child: Text(th ? 'ยกเลิก' : 'Cancel',
              style: TextStyle(color: AppColors.textTertiary)),
        ),
        ElevatedButton(
          onPressed: canConfirm ? () => Navigator.pop(context, true) : null,
          style: ElevatedButton.styleFrom(
            backgroundColor: AppColors.tradingRed,
            foregroundColor: Colors.white,
          ),
          child: Text(embedded
              ? (th ? 'ลบกุญแจและตัดการเชื่อมต่อ' : 'Delete keys & disconnect')
              : (th ? 'ตัดการเชื่อมต่อ' : 'Disconnect')),
        ),
      ],
    );
  }
}
