/// TPIX TRADE — สะพาน USDT (BSC ⇄ TPIX Chain)
///
/// ฝาก: transfer(vault, จำนวน) ของ USDT จริงบนเชนต้นทาง → ตัวสแกนบนเซิร์ฟเวอร์เห็นเมื่อบล็อก final
///      → mint USDT_TPIX ให้ที่อยู่เดียวกันบนเชน 4289
/// ถอน: USDT_TPIX.bridgeBurn(จำนวน, เชนปลายทาง, ที่อยู่) บนเชน 4289 → จ่าย USDT จริงบนเชนปลายทาง
///
/// พอร์ตจากแผง USDT ของ TPIX Wallet (bridge_usdt_panel.dart) — ข้อมูลทั้งหมดจาก /api/v1/bridge/*
/// (เลิกอ่านค่า bridge ใน /api/v1/fees ซึ่งเป็นของสะพานรุ่นเก่าที่ถูกถอดแล้ว)
///
/// แอปไม่ได้ "แจ้ง" เซิร์ฟเวอร์ — แค่ถามสถานะด้วย tx hash · ปิดหน้ากลางทางก็ไม่เสียอะไร
/// ทุกปุ่มส่ง: ตรวจบนเชนก่อน ([BridgeOnChain.prepare]) → กล่องยืนยันโชว์ครบทุกหลัก
///   → (ลายนิ้วมือถ้าเปิดไว้) → ส่งผ่าน WalletProvider.sendChainTransaction (สลับเชนให้กระเป๋าภายนอกเอง
///   · กระเป๋าในแอปถาม chain id ของ RPC ก่อนเซ็น) → จด hash ลงเครื่องทันที
///
/// Developed by Xman Studio
library;

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/locale/locale_provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_fonts.dart';
import '../../core/theme/app_theme.dart';
import '../../core/theme/gradients.dart';
import '../../providers/accent_provider.dart';
import '../../providers/wallet_provider.dart';
import '../../services/biometric_service.dart';
import '../../services/bridge_rules.dart';
import '../../services/bridge_service.dart';
import '../../services/bridge_tracker.dart';
import '../../widgets/common/gradient_button.dart';
import '../../widgets/wallet/wallet_connect_sheet.dart';
import 'bridge_widgets.dart';

enum _Tab { deposit, withdraw, track }

/// ยืนยันตัวตนก่อนเซ็นด้วยกระเป๋าในแอป (เทสต์ใส่ตัวปลอมได้)
typedef BridgeAuthorizer = Future<bool> Function(String reason);

class BridgeScreen extends StatefulWidget {
  /// เทสต์ใส่ตัวปลอม — ค่าปริยายคือของจริง (API บน tpix.online + RPC ที่ตรึงในแอป)
  final BridgeApi? api;
  final BridgeOnChain? onChain;

  /// หน้าจอเป็นเจ้าของ tracker เสมอ (dispose ตอนปิดหน้า)
  final BridgeTracker? tracker;
  final BridgeAuthorizer? authorize;

  const BridgeScreen({super.key, this.api, this.onChain, this.tracker, this.authorize});

  @override
  State<BridgeScreen> createState() => _BridgeScreenState();
}

class _BridgeScreenState extends State<BridgeScreen> {
  late final BridgeApi _api = widget.api ?? BridgeApi.instance;
  late final BridgeOnChain _onChain = widget.onChain ?? const BridgeOnChain();
  late final BridgeTracker _tracker = widget.tracker ?? BridgeTracker(api: _api);

  UsdtBridgeInfo? _info;
  bool _loading = true;
  bool _loadFailed = false;
  bool _refreshing = false;
  int _loadSeq = 0;

  /// กระเป๋าที่ข้อมูลบนจอเป็นของมัน — เปลี่ยนเมื่อไหร่ต้องล้างของใบเก่า
  String? _owner;
  bool _started = false;
  _Tab _tab = _Tab.deposit;

  /// กำลังทำรายการ (ตั้งแต่แตะปุ่มจนจบ รวมช่วงตรวจ/กล่องยืนยัน/กระเป๋า) — ทุกปุ่มส่งปิดหมด
  bool _busy = false;
  String? _busyLabel;

  // ── ฝาก ──
  int? _sourceChainId;
  final _depositCtrl = TextEditingController();
  BigInt? _sourceBalance; // หน่วยของเหรียญต้นทาง · null = ยังไม่รู้
  bool _sourceBalanceFailed = false;
  int _sourceSeq = 0;
  // ต้องติ๊กยอมรับใหม่ทุกครั้งที่เปิดหน้า/สลับกระเป๋า — โอนจากกระดานเทรดคือความผิดพลาดที่เรียกเงินคืนไม่ได้
  bool _ackOwnWallet = false;

  // ── ถอน ──
  int? _targetChainId;
  final _withdrawCtrl = TextEditingController();
  final _targetCtrl = TextEditingController();
  BigInt? _tpixUsdtBalance; // 6 ทศนิยม · null = ยังไม่รู้
  bool _tpixBalanceFailed = false;
  int _tpixSeq = 0;

  // ── ติดตามด้วย tx hash ──
  String _manualKind = usdtDeposit;
  int? _manualChainId;
  final _manualCtrl = TextEditingController();

  // ── ประวัติ ──
  List<BridgeHistoryRow> _history = const [];
  int _historySeq = 0;

  @override
  void initState() {
    super.initState();
    _tracker.addListener(_onTracker);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      _started = true;
      _owner = context.read<WalletProvider>().address;
      _tracker.setOwner(_owner);
      if (_owner != null) _targetCtrl.text = _owner!;
      unawaited(_tracker.load());
      unawaited(_load());
    });
  }

  void _onTracker() {
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
    _tracker.removeListener(_onTracker);
    _tracker.dispose();
    _depositCtrl.dispose();
    _withdrawCtrl.dispose();
    _targetCtrl.dispose();
    _manualCtrl.dispose();
    super.dispose();
  }

  // ================================================================
  // โหลด
  // ================================================================

  Future<void> _load() async {
    final seq = ++_loadSeq;
    setState(() {
      if (_info != null) {
        _refreshing = true; // มีข้อมูลเดิมอยู่ — ไม่เอาสปินเนอร์เต็มจอมาบัง
      } else {
        _loading = true;
        _loadFailed = false;
      }
    });
    try {
      final info = await _api.fetchInfo();
      if (!mounted || seq != _loadSeq) return;
      final sourceBefore = _sourceChainId;
      setState(() {
        _info = info;
        _loading = false;
        _loadFailed = false;
        _refreshing = false;
        _sourceChainId = _pick(info.depositChains, _sourceChainId);
        _targetChainId = _pick(info.withdrawChains, _targetChainId);
        _manualChainId = _pick(info.chains.where((c) => c.serverEnabled && c.chainId != bridgeTpixChainId).toList(),
            _manualChainId);
      });
      unawaited(_refreshSourceBalance(reset: sourceBefore != _sourceChainId));
      unawaited(_refreshTpixBalance());
      unawaited(_loadHistory());
      unawaited(_tracker.pollOnce());
    } catch (_) {
      if (!mounted || seq != _loadSeq) return;
      final hadData = _info != null;
      setState(() {
        _loading = false;
        _refreshing = false;
        _loadFailed = !hadData;
      });
      if (hadData) bridgeSnack(context, context.read<LocaleProvider>().t('bridge.refreshFailed'), error: true);
    }
  }

  /// เลือกเชนเดิมถ้ายังใช้ได้ ไม่งั้นตัวแรกที่ใช้ได้ (ไม่มี = null)
  int? _pick(List<UsdtBridgeChain> usable, int? current) {
    if (current != null && usable.any((c) => c.chainId == current)) return current;
    return usable.isEmpty ? null : usable.first.chainId;
  }

  Future<void> _refreshSourceBalance({bool reset = false}) async {
    final seq = ++_sourceSeq;
    final info = _info;
    final owner = _owner;
    final chain = _source;
    if (reset && mounted) setState(() => _sourceBalance = null);
    if (owner == null || chain == null || !chain.depositable || !(info?.open ?? false)) return;
    try {
      final balance = await _onChain.tokenBalance(chain.chainId, chain.usdtAddress!, owner);
      if (!mounted || seq != _sourceSeq) return;
      setState(() {
        _sourceBalance = balance;
        _sourceBalanceFailed = false;
      });
    } catch (_) {
      // อ่านไม่ได้ ≠ ศูนย์ — ไม่บล็อกปุ่ม ด่านก่อนส่งอ่านยอดใหม่อีกรอบ
      if (mounted && seq == _sourceSeq) setState(() => _sourceBalanceFailed = true);
    }
  }

  Future<void> _refreshTpixBalance() async {
    final seq = ++_tpixSeq;
    final token = _info?.usdtTpix;
    final owner = _owner;
    if (owner == null || token == null || !(_info?.open ?? false)) return;
    try {
      final balance = await _onChain.tokenBalance(bridgeTpixChainId, token, owner);
      if (!mounted || seq != _tpixSeq) return;
      setState(() {
        _tpixUsdtBalance = balance;
        _tpixBalanceFailed = false;
      });
    } catch (_) {
      if (mounted && seq == _tpixSeq) setState(() => _tpixBalanceFailed = true);
    }
  }

  Future<void> _loadHistory() async {
    final seq = ++_historySeq;
    final owner = _owner;
    if (owner == null) return;
    try {
      final rows = await _api.fetchHistory(owner);
      if (!mounted || seq != _historySeq) return;
      setState(() => _history = rows);
    } catch (_) {
      // ประวัติเป็นของเสริม — ไม่โชว์ error
    }
  }

  /// สลับกระเป๋าขณะเปิดหน้านี้ — ล้างยอด/ประวัติ/การติ๊กยอมรับของใบเก่า
  void _onOwnerChanged(String? next) {
    final prev = _owner;
    _owner = next;
    _tracker.setOwner(next);
    setState(() {
      _ackOwnWallet = false;
      _sourceBalance = null;
      _tpixUsdtBalance = null;
      _sourceBalanceFailed = false;
      _tpixBalanceFailed = false;
      _history = const [];
      final target = _targetCtrl.text.trim().toLowerCase();
      if (target.isEmpty || target == prev?.toLowerCase()) _targetCtrl.text = next ?? '';
    });
    unawaited(_refreshSourceBalance());
    unawaited(_refreshTpixBalance());
    unawaited(_loadHistory());
  }

  Future<void> _refreshAll() async {
    await _load();
    await _tracker.pollOnce();
  }

  // ================================================================
  // ค่าที่คำนวณจากช่องกรอก (BigInt ทั้งหมด)
  // ================================================================

  /// เชนต้นทางที่เลือก — เฉพาะเชนที่ฝากได้เท่านั้น (ปิดอยู่ = null)
  UsdtBridgeChain? get _source {
    final c = _info?.chain(_sourceChainId ?? -1);
    return c != null && c.depositable ? c : null;
  }

  UsdtBridgeChain? get _target {
    final c = _info?.chain(_targetChainId ?? -1);
    return c != null && c.withdrawable ? c : null;
  }

  BigInt? get _depositUnits {
    final s = _source;
    return s == null ? null : parseAmountToUnits(_depositCtrl.text, s.usdtDecimals);
  }

  BigInt? get _depositUnits6 {
    final u = _depositUnits;
    final s = _source;
    return u == null || s == null ? null : toUsdt6(u, s.usdtDecimals);
  }

  AmountCheck _depositCheckFor(BigInt? units6) => checkDepositAmount(
        units6,
        _info?.limits,
        effectiveMax: _info?.depositMaxPerTx,
        remainingToday: _info?.minterRemainingToday,
      );

  AmountCheck get _depositCheck =>
      _depositCtrl.text.trim().isEmpty ? AmountCheck.ok : _depositCheckFor(_depositUnits6);

  bool get _depositInsufficient {
    final u = _depositUnits;
    final b = _sourceBalance;
    return u != null && b != null && u > b;
  }

  bool get _canDeposit =>
      (_info?.open ?? false) &&
      _source != null &&
      _owner != null &&
      !_busy &&
      _ackOwnWallet &&
      _depositUnits != null &&
      !_depositCheck.blocked &&
      !_depositInsufficient;

  BigInt? get _withdrawUnits => parseAmountToUnits(_withdrawCtrl.text, usdtTpixDecimals);

  AmountCheck get _withdrawCheck => _withdrawCtrl.text.trim().isEmpty
      ? AmountCheck.ok
      : checkWithdrawAmount(_withdrawUnits, fee: _target?.withdrawFee, limits: _info?.limits, balance: _tpixUsdtBalance);

  String? get _addressError =>
      _targetCtrl.text.trim().isNotEmpty && !isBridgeAddress(_targetCtrl.text) ? 'bridge.errAddressInvalid' : null;

  BigInt get _withdrawReceive {
    final u = _withdrawUnits;
    final t = _target;
    return u == null || t == null ? BigInt.zero : withdrawReceiveUnits(u, t.withdrawFee);
  }

  bool get _canWithdraw =>
      (_info?.open ?? false) &&
      _target != null &&
      _owner != null &&
      !_busy &&
      _withdrawCtrl.text.trim().isNotEmpty &&
      !_withdrawCheck.blocked &&
      _targetCtrl.text.trim().isNotEmpty &&
      _addressError == null;

  String _chainName(int? chainId) => _info?.chainName(chainId) ?? (chainId == null ? '—' : '#$chainId');

  // ================================================================
  // ธุรกรรม
  // ================================================================

  void _setBusyLabel(String? label) {
    if (mounted) setState(() => _busyLabel = label);
  }

  /// ห่อทุกปุ่มส่ง: กันแตะซ้ำตั้งแต่ครั้งแรก (ตั้งธงก่อน await ใดๆ) + แปลง error เป็นข้อความ (ห้าม Exception ดิบ)
  Future<void> _guard(Future<void> Function() flow) async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      await flow();
    } catch (e) {
      if (mounted) {
        final l = context.read<LocaleProvider>();
        final msg = e is BridgeCheckException
            ? bridgeT(l, e.key, e.params, 'bridge.errTxFailed')
            : l.t('bridge.errTxFailed');
        if (e is! BridgeCheckException) debugPrint('Bridge flow: ${e.runtimeType}');
        bridgeSnack(context, msg, error: true);
      }
    } finally {
      _busy = false;
      if (mounted) setState(() => _busyLabel = null);
    }
  }

  static String _failureKey(ChainSendFailure? f) => switch (f) {
        ChainSendFailure.notConnected => 'bridge.connectFirst',
        ChainSendFailure.walletChanged => 'bridge.errWalletChanged',
        ChainSendFailure.rejected => 'bridge.errRejected',
        ChainSendFailure.switchFailed => 'bridge.errSwitchFailed',
        ChainSendFailure.wrongChain => 'bridge.errWrongChain',
        ChainSendFailure.wouldRevert => 'bridge.errWouldRevert',
        ChainSendFailure.network => 'bridge.errNetwork',
        ChainSendFailure.rejectedByChain => 'bridge.errTxFailed',
        ChainSendFailure.unknownOutcome => 'bridge.errUnknownOutcome',
        ChainSendFailure.keyMissing => 'bridge.errKeyMissing',
        null => 'bridge.errTxFailed',
      };

  /// ยืนยันตัวตน (ถ้าเปิดลายนิ้วมือไว้) → ตรวจว่ากระเป๋ายังเป็นใบเดิม → ส่งผ่าน WalletProvider
  ///
  /// คืน tx hash (ตัวเล็ก) · null = หน้าถูกปิดก่อนส่ง (ยังไม่มีอะไรถูกส่ง) · ล้ม = throw [BridgeCheckException]
  Future<String?> _send(BridgeTxPlan plan, {required String owner, required String summary}) async {
    final l = context.read<LocaleProvider>();
    final wallet = context.read<WalletProvider>();
    if (wallet.address?.toLowerCase() != owner.toLowerCase()) {
      throw const BridgeCheckException('bridge.errWalletChanged');
    }
    if (wallet.kind == WalletKind.embedded && wallet.biometricEnabled) {
      final authorize = widget.authorize ?? BiometricService().authenticate;
      if (!await authorize(l.t('bridge.authReason'))) throw const BridgeCheckException('bridge.errAuthFailed');
      if (!mounted) return null;
    }
    _setBusyLabel(wallet.kind == WalletKind.embedded ? l.t('bridge.sending') : l.t('bridge.confirmInWallet'));
    final hash = await wallet.sendChainTransaction(
      chainId: plan.chainId,
      to: plan.to,
      data: plan.data,
      summary: summary,
      expectedFrom: owner,
      verifyChainId: true,
    );
    if (hash == null) throw BridgeCheckException(_failureKey(wallet.lastSendFailure));
    if (!isTxHash(hash)) throw const BridgeCheckException('bridge.errBadHash');
    return hash.trim().toLowerCase();
  }

  bool get _externalWallet {
    final w = context.read<WalletProvider>();
    return w.kind != WalletKind.embedded;
  }

  Future<void> _submitDeposit() => _guard(() async {
        final l = context.read<LocaleProvider>();
        final info = _info;
        final owner = _owner;
        final chain = _source;
        final units = _depositUnits;
        if (info == null || !info.open || owner == null || chain == null) return;
        if (units == null || !_ackOwnWallet) return;
        final units6 = toUsdt6(units, chain.usdtDecimals);
        final check = _depositCheckFor(units6);
        if (check.blocked) return;
        final vault = info.vaultAddress!;
        final token = chain.usdtAddress!;

        // ตรวจบนเชนก่อน (chain id · ทศนิยมจริง · ยอด · จำลองธุรกรรม · ค่าแก๊ส) — ยังไม่มีอะไรถูกส่ง
        _setBusyLabel(l.t('bridge.checking'));
        final plan = await _onChain.prepare(
          chainId: chain.chainId,
          from: owner,
          to: token,
          data: BridgeTx.encodeTransfer(vault, units),
          spend: (token: token, amount: units, decimals: chain.usdtDecimals),
        );
        if (!mounted) return;
        _setBusyLabel(null);

        final amountText = formatUnitsPlain(units, chain.usdtDecimals);
        final receive = depositReceiveUnits(units6, info.depositFee);
        final ok = await showBridgeConfirmSheet(
          context,
          title: l.t('bridge.confirmDepositTitle'),
          rows: [
            (l.t('bridge.from'), owner),
            (l.t('bridge.network'), '${chain.name} (${chain.chainId})'),
            (l.t('bridge.amount'), '$amountText ${chain.usdtSymbol}'),
            (bridgeT(l, 'bridge.usdtContractDecimals', {'decimals': '${chain.usdtDecimals}'}), token),
            (l.t('bridge.vaultAddress'), vault),
            (l.t('bridge.depositFee'), '${info.depositFee} USDT'),
            (l.t('bridge.youReceive'), '${formatUnitsPlain(receive, usdtTpixDecimals)} USDT · TPIX Chain'),
            (l.t('bridge.receiver'), owner),
            (l.t('bridge.networkFee'), '≈ ${BridgeTx.feeText(plan)} ${plan.nativeSymbol}'),
          ],
          notes: [
            if (check.warning != null) bridgeT(l, check.warning!, check.warningParams),
            if (info.minterPaused == true) l.t('bridge.minterPaused'),
            l.t('bridge.confirmDepositNote'),
            if (_externalWallet) bridgeT(l, 'bridge.externalWalletNote', {'chain': chain.name}),
          ],
          confirmLabel: l.t('bridge.sendUsdt'),
        );
        if (!ok || !mounted) return;

        final hash = await _send(
          plan,
          owner: owner,
          summary: 'TPIX Bridge: deposit $amountText USDT ${chain.name} → TPIX Chain',
        );
        if (hash == null) return;
        // จดทันทีที่ได้ hash (แม้หน้านี้ถูกปิดไปแล้ว — tracker เขียนลงเครื่องเอง) — เปิดใหม่ก็ติดตามต่อได้
        await _tracker.add(TrackedTransfer(
          kind: usdtDeposit,
          chainId: chain.chainId,
          txHash: hash,
          owner: owner,
          amount: formatUnitsPlain(units6, usdtTpixDecimals),
        ));
        if (!mounted) return;
        setState(() => _depositCtrl.clear());
        bridgeSnack(context, l.t('bridge.sentNotice'));
        unawaited(_refreshSourceBalance());
        unawaited(_loadHistory());
      });

  Future<void> _submitWithdraw() => _guard(() async {
        final l = context.read<LocaleProvider>();
        final info = _info;
        final owner = _owner;
        final chain = _target;
        final units = _withdrawUnits;
        final targetAddress = _targetCtrl.text.trim();
        if (info == null || !info.open || owner == null || chain == null) return;
        if (units == null || !isBridgeAddress(targetAddress)) return;
        final check = checkWithdrawAmount(units, fee: chain.withdrawFee, limits: info.limits, balance: _tpixUsdtBalance);
        if (check.blocked) return;
        final token = info.usdtTpix!;
        final receive = withdrawReceiveUnits(units, chain.withdrawFee);

        _setBusyLabel(l.t('bridge.checking'));
        final plan = await _onChain.prepare(
          chainId: bridgeTpixChainId,
          from: owner,
          to: token,
          data: BridgeTx.encodeBridgeBurn(units, chain.chainId, targetAddress),
          spend: (token: token, amount: units, decimals: usdtTpixDecimals),
        );
        if (!mounted) return;
        _setBusyLabel(null);

        final amountText = formatUnitsPlain(units, usdtTpixDecimals);
        final ok = await showBridgeConfirmSheet(
          context,
          title: l.t('bridge.confirmWithdrawTitle'),
          rows: [
            (l.t('bridge.from'), owner),
            (l.t('bridge.network'), 'TPIX Chain ($bridgeTpixChainId)'),
            (l.t('bridge.burnAmount'), '$amountText USDT'),
            (l.t('bridge.withdrawFee'), '${chain.withdrawFee} USDT'),
            (l.t('bridge.youReceive'), '${formatUnitsPlain(receive, usdtTpixDecimals)} ${chain.usdtSymbol}'),
            (l.t('bridge.targetChain'), '${chain.name} (${chain.chainId})'),
            (l.t('bridge.receiver'), targetAddress),
            (
              l.t('bridge.networkFee'),
              plan.gasPrice > BigInt.zero ? '≈ ${BridgeTx.feeText(plan)} ${plan.nativeSymbol}' : l.t('bridge.gasFree'),
            ),
          ],
          notes: [
            l.t('bridge.confirmWithdrawNote'),
            if (targetAddress.toLowerCase() != owner.toLowerCase()) l.t('bridge.notYourAddress'),
            if (check.warning != null) bridgeT(l, check.warning!, check.warningParams),
            if (_externalWallet) bridgeT(l, 'bridge.externalWalletNote', {'chain': 'TPIX Chain'}),
          ],
          confirmLabel: bridgeT(l, 'bridge.burnUsdt', {'chain': chain.name}),
          danger: true,
        );
        if (!ok || !mounted) return;

        final hash = await _send(
          plan,
          owner: owner,
          summary: 'TPIX Bridge: withdraw $amountText USDT TPIX Chain → ${chain.name}',
        );
        if (hash == null) return;
        await _tracker.add(TrackedTransfer(
          kind: usdtWithdraw,
          chainId: bridgeTpixChainId,
          txHash: hash,
          targetChainId: chain.chainId,
          owner: owner,
          amount: amountText,
        ));
        if (!mounted) return;
        setState(() => _withdrawCtrl.clear());
        bridgeSnack(context, l.t('bridge.sentNotice'));
        unawaited(_refreshTpixBalance());
        unawaited(_loadHistory());
      });

  Future<void> _addManual() async {
    final hash = _manualCtrl.text.trim();
    final owner = _owner;
    if (!isTxHash(hash) || owner == null) return;
    final chainId = _manualKind == usdtDeposit ? _manualChainId : bridgeTpixChainId;
    if (chainId == null) return;
    await _tracker.add(TrackedTransfer(kind: _manualKind, chainId: chainId, txHash: hash, owner: owner));
    if (!mounted) return;
    setState(() => _manualCtrl.clear());
  }

  Future<void> _remove(TrackedTransfer entry) async {
    if (!await confirmRemoveTracked(context)) return;
    await _tracker.remove(entry);
  }

  /// ยอดสูงสุดที่กรอกได้ (ตัดเหลือ 6 ทศนิยม — เศษที่เกินอยู่ในกระเป๋าต่อ ไม่กลายเป็นฝุ่นใน vault)
  void _fillMaxDeposit() {
    final b = _sourceBalance;
    final s = _source;
    if (b == null || s == null) return;
    setState(() => _depositCtrl.text = formatUnitsPlain(toUsdt6(b, s.usdtDecimals), usdtTpixDecimals));
  }

  void _openWalletSheet() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      builder: (_) => const WalletConnectSheet(),
    );
  }

  // ================================================================
  // UI
  // ================================================================

  @override
  Widget build(BuildContext context) {
    final l = context.watch<LocaleProvider>();
    final wallet = context.watch<WalletProvider>();
    // สลับกระเป๋าขณะเปิดหน้านี้อยู่ → ล้างของใบเก่า โหลดของใบใหม่ (ทำหลังวาดเสร็จ ห้าม setState ใน build)
    if (_started && wallet.address?.toLowerCase() != _owner?.toLowerCase()) {
      final next = wallet.address;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted || context.read<WalletProvider>().address != next) return;
        if (next?.toLowerCase() != _owner?.toLowerCase()) _onOwnerChanged(next);
      });
    }

    return Scaffold(
      appBar: AppBar(
        title: Text(l.t('bridge.title')),
        backgroundColor: Colors.transparent,
        elevation: 0,
        actions: [
          IconButton(
            tooltip: l.t('bridge.retry'),
            icon: const Icon(Icons.refresh_rounded),
            onPressed: _loading || _refreshing ? null : _refreshAll,
          ),
        ],
      ),
      body: Container(
        decoration: BoxDecoration(gradient: AppGradients.darkBg),
        child: SafeArea(bottom: false, child: _body(l)),
      ),
    );
  }

  Widget _body(LocaleProvider l) {
    final accent = context.watch<AccentProvider>();
    if (_loading && _info == null) {
      return Center(child: CircularProgressIndicator(color: accent.g2));
    }
    final info = _info;
    if (_loadFailed || info == null) {
      return ListView(
        padding: const EdgeInsets.all(24),
        children: [
          const SizedBox(height: 40),
          BridgeCard(
            child: Column(
              children: [
                Icon(Icons.cloud_off_rounded, color: AppColors.tradingRed, size: 44),
                const SizedBox(height: 14),
                Text(l.t('bridge.loadFailed'),
                    textAlign: TextAlign.center,
                    style: AppFonts.ui(fontSize: 14.5, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                const SizedBox(height: 18),
                GradientButton(key: const ValueKey('bridge-retry'), text: l.t('bridge.retry'), onPressed: _load),
              ],
            ),
          ),
        ],
      );
    }

    return RefreshIndicator(
      onRefresh: _refreshAll,
      color: accent.g2,
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 40),
        children: [
          if (_refreshing)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: LinearProgressIndicator(minHeight: 2, color: accent.g2),
            ),
          if (!info.open) ...[
            // ยังไม่เปิด / หยุดชั่วคราว / ตั้งค่าไม่ครบ — บอกตรงๆ ดีกว่าโชว์ปุ่มที่กดแล้วเงินไปค้าง
            BridgeBanner(
              key: const ValueKey('bridge-closed'),
              title: l.t('bridge.notReady'),
              text: !info.enabled
                  ? (info.installed ? l.t('bridge.pausedBody') : l.t('bridge.notReadyBody'))
                  : l.t('bridge.misconfiguredBody'),
              color: AppColors.brandWarm,
              icon: Icons.construction_rounded,
            ),
            const SizedBox(height: 16),
          ] else if (_owner == null) ...[
            BridgeBanner(
              key: const ValueKey('bridge-connect'),
              text: l.t('bridge.connectFirst'),
              color: AppColors.brandCyan,
              icon: Icons.account_balance_wallet_rounded,
            ),
            const SizedBox(height: 12),
            GradientButton(text: l.t('bridge.connectButton'), icon: Icons.link_rounded, onPressed: _openWalletSheet),
            const SizedBox(height: 16),
          ] else ...[
            if (info.minterPaused == true) ...[
              BridgeBanner(text: l.t('bridge.minterPaused'), color: AppColors.brandWarm, icon: Icons.pause_circle_rounded),
              const SizedBox(height: 12),
            ],
            _balancesCard(info, l),
            const SizedBox(height: 12),
            BridgeSegmented<_Tab>(
              key: const ValueKey('bridge-tabs'),
              options: [
                (_Tab.deposit, l.t('bridge.tabDeposit')),
                (_Tab.withdraw, l.t('bridge.tabWithdraw')),
                (_Tab.track, l.t('bridge.tabTrack')),
              ],
              value: _tab,
              onChanged: (t) => setState(() => _tab = t),
            ),
            const SizedBox(height: 12),
            switch (_tab) {
              _Tab.deposit => _depositSection(info, l),
              _Tab.withdraw => _withdrawSection(info, l),
              _Tab.track => _trackSection(info, l),
            },
            const SizedBox(height: 16),
          ],
          if (_owner != null) ...[
            _trackedSection(info, l),
            const SizedBox(height: 16),
            _historySection(info, l),
            const SizedBox(height: 12),
          ],
          Center(child: BridgeLink(label: l.t('bridge.reservesLink'), url: BridgeApi.reservesUrl)),
        ],
      ),
    );
  }

  Widget _balancesCard(UsdtBridgeInfo info, LocaleProvider l) {
    final source = _source;
    String fmt(BigInt? v, int decimals, bool failed) =>
        v != null ? formatUnitsPlain(v, decimals) : (failed ? l.t('bridge.balanceUnknown') : '…');
    return BridgeCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          BridgeSectionTitle(l.t('bridge.balances')),
          if (source != null)
            BridgeKv(
              bridgeT(l, 'bridge.balanceOn', {'chain': source.name}),
              '${fmt(_sourceBalance, source.usdtDecimals, _sourceBalanceFailed)} ${source.usdtSymbol}',
            ),
          BridgeKv(l.t('bridge.tpixBalance'), '${fmt(_tpixUsdtBalance, usdtTpixDecimals, _tpixBalanceFailed)} USDT'),
        ],
      ),
    );
  }

  Widget _depositSection(UsdtBridgeInfo info, LocaleProvider l) {
    final source = _source;
    final check = _depositCheck;
    final units6 = _depositUnits6;
    final limits = info.limits;
    final chains = info.depositChains;
    final hasFee = usdtToUnits(info.depositFee) > BigInt.zero;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        BridgeWarningBox(
          title: l.t('bridge.warnTitle'),
          items: [l.t('bridge.warnNoExchange'), l.t('bridge.warnOwnWallet'), l.t('bridge.warnSameAddress'), l.t('bridge.warnUsdtOnly')],
        ),
        const SizedBox(height: 12),
        BridgeCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              BridgeLabel(l.t('bridge.sourceChain')),
              const SizedBox(height: 6),
              if (chains.isEmpty)
                Text(l.t('bridge.noDepositChain'),
                    key: const ValueKey('bridge-no-deposit-chain'),
                    style: AppFonts.ui(fontSize: 12.5, color: AppColors.textSecondary))
              else
                BridgeChainPicker(
                  key: const ValueKey('bridge-source-picker'),
                  chains: [for (final ch in chains) (id: ch.chainId, title: ch.name, subtitle: ch.usdtSymbol)],
                  selected: _sourceChainId,
                  onSelect: _busy
                      ? null
                      : (id) {
                          if (id == _sourceChainId) return;
                          setState(() {
                            _sourceChainId = id;
                            _depositCtrl.clear();
                          });
                          unawaited(_refreshSourceBalance(reset: true));
                        },
                ),
              if (source != null) ...[
                const SizedBox(height: 14),
                BridgeAddressLine(label: '${l.t('bridge.vaultAddress')} (${source.name})', address: info.vaultAddress!),
                const SizedBox(height: 6),
                BridgeAddressLine(
                  label: bridgeT(l, 'bridge.usdtContractDecimals', {'decimals': '${source.usdtDecimals}'}),
                  address: source.usdtAddress!,
                ),
                BridgeLink(
                  label: l.t('bridge.viewContract'),
                  url: source.explorer == null ? null : '${source.explorer}/token/${source.usdtAddress}',
                ),
                const SizedBox(height: 14),
                Row(
                  children: [
                    Expanded(child: BridgeLabel(l.t('bridge.amount'))),
                    if (_sourceBalance != null)
                      InkWell(
                        onTap: _busy ? null : _fillMaxDeposit,
                        child: Text(
                          '${l.t('bridge.balance')}: ${formatUnitsPlain(_sourceBalance!, source.usdtDecimals)} · ${l.t('bridge.max')}',
                          style: AppFonts.ui(fontSize: 11.5, color: AppColors.brandCyan),
                        ),
                      ),
                  ],
                ),
                const SizedBox(height: 4),
                BridgeInput(
                  key: const ValueKey('bridge-deposit-amount'),
                  controller: _depositCtrl,
                  hint: '0.00',
                  amount: true,
                  enabled: !_busy,
                  onChanged: (_) => setState(() {}),
                  errorText: check.error != null
                      ? bridgeT(l, check.error!, check.params)
                      : _depositInsufficient
                          ? l.t('bridge.errInsufficient')
                          : null,
                  warningText: check.warning != null ? bridgeT(l, check.warning!, check.warningParams) : null,
                ),
                const SizedBox(height: 10),
                BridgeKv(l.t('bridge.depositFee'), hasFee ? '${info.depositFee} USDT' : l.t('bridge.free')),
                BridgeKv(
                  l.t('bridge.youReceive'),
                  units6 != null && !check.blocked
                      ? '${formatUnitsPlain(depositReceiveUnits(units6, info.depositFee), usdtTpixDecimals)} ${l.t('bridge.receiveOnTpix')}'
                      : '—',
                  valueColor: AppColors.tradingGreen,
                ),
                BridgeKv(l.t('bridge.eta'), bridgeT(l, 'bridge.etaMinutes', {'minutes': '${source.etaMinutes}'})),
                BridgeKv('${l.t('bridge.minDeposit')} / ${l.t('bridge.maxPerTx')}',
                    '${limits['min_deposit'] ?? '—'} / ${info.depositMaxPerTx ?? limits['max_deposit_per_tx'] ?? '—'} USDT'),
                BridgeKv(l.t('bridge.dailyPerAddress'), '${limits['max_deposit_per_address_daily'] ?? '—'} USDT'),
                if (info.minterRemainingToday != null)
                  BridgeKv(l.t('bridge.remainingToday'), '${info.minterRemainingToday} USDT'),
                const SizedBox(height: 6),
                BridgeAckTile(
                  key: const ValueKey('bridge-ack'),
                  value: _ackOwnWallet,
                  onChanged: _busy ? null : (v) => setState(() => _ackOwnWallet = v),
                  text: l.t('bridge.ackOwnWallet'),
                ),
                const SizedBox(height: 10),
                BridgePrimaryButton(
                  key: const ValueKey('bridge-deposit-button'),
                  label: l.t('bridge.sendUsdt'),
                  onPressed: _canDeposit ? _submitDeposit : null,
                  loading: _busy,
                  loadingLabel: _busyLabel,
                ),
              ],
            ],
          ),
        ),
        const SizedBox(height: 12),
        BridgeHowItWorks(title: l.t('bridge.howTitle'), steps: [
          l.t('bridge.howDeposit1'),
          l.t('bridge.howDeposit2'),
          l.t('bridge.howDeposit3'),
          l.t('bridge.howDeposit4'),
        ]),
      ],
    );
  }

  Widget _withdrawSection(UsdtBridgeInfo info, LocaleProvider l) {
    final target = _target;
    final check = _withdrawCheck;
    final limits = info.limits;
    final chains = info.withdrawChains;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        BridgeCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              BridgeLabel(l.t('bridge.targetChain')),
              const SizedBox(height: 6),
              if (chains.isEmpty)
                Text(l.t('bridge.noWithdrawChain'), style: AppFonts.ui(fontSize: 12.5, color: AppColors.textSecondary))
              else
                BridgeChainPicker(
                  key: const ValueKey('bridge-target-picker'),
                  chains: [
                    for (final ch in chains)
                      (id: ch.chainId, title: ch.name, subtitle: '${l.t('bridge.feeShort')}: ${ch.withdrawFee} USDT'),
                  ],
                  selected: _targetChainId,
                  onSelect: _busy ? null : (id) => setState(() => _targetChainId = id),
                ),
              if (target != null) ...[
                const SizedBox(height: 14),
                Row(
                  children: [
                    Expanded(child: BridgeLabel(l.t('bridge.amount'))),
                    if (_tpixUsdtBalance != null)
                      InkWell(
                        onTap: _busy
                            ? null
                            : () => setState(() => _withdrawCtrl.text = formatUnitsPlain(_tpixUsdtBalance!, usdtTpixDecimals)),
                        child: Text(
                          '${l.t('bridge.tpixBalance')}: ${formatUnitsPlain(_tpixUsdtBalance!, usdtTpixDecimals)} · ${l.t('bridge.max')}',
                          style: AppFonts.ui(fontSize: 11.5, color: AppColors.brandCyan),
                        ),
                      ),
                  ],
                ),
                const SizedBox(height: 4),
                BridgeInput(
                  key: const ValueKey('bridge-withdraw-amount'),
                  controller: _withdrawCtrl,
                  hint: '0.00',
                  amount: true,
                  enabled: !_busy,
                  onChanged: (_) => setState(() {}),
                  errorText: check.error != null ? bridgeT(l, check.error!, check.params) : null,
                  warningText: check.warning != null ? bridgeT(l, check.warning!, check.warningParams) : null,
                ),
                const SizedBox(height: 10),
                BridgeLabel(l.t('bridge.targetAddress')),
                const SizedBox(height: 4),
                BridgeInput(
                  key: const ValueKey('bridge-target-address'),
                  controller: _targetCtrl,
                  hint: '0x…',
                  enabled: !_busy,
                  onChanged: (_) => setState(() {}),
                  errorText: _addressError != null ? l.t(_addressError!) : null,
                  suffix: _owner == null
                      ? null
                      : TextButton(
                          onPressed: _busy ? null : () => setState(() => _targetCtrl.text = _owner!),
                          child: Text(l.t('bridge.useMyAddress'),
                              style: AppFonts.ui(fontSize: 11.5, color: AppColors.brandCyan)),
                        ),
                ),
                const SizedBox(height: 10),
                BridgeKv(l.t('bridge.withdrawFee'), '${target.withdrawFee} USDT'),
                BridgeKv(
                  l.t('bridge.youReceive'),
                  '${formatUnitsPlain(_withdrawReceive, usdtTpixDecimals)} ${target.usdtSymbol} · ${target.name}',
                  valueColor: AppColors.tradingGreen,
                ),
                BridgeKv(l.t('bridge.eta'), bridgeT(l, 'bridge.etaMinutes', {'minutes': '${target.etaMinutes}'})),
                BridgeKv('${l.t('bridge.minDeposit')} / ${l.t('bridge.maxPerTx')}',
                    '${limits['min_withdraw'] ?? '—'} / ${limits['max_withdraw_per_tx'] ?? '—'} USDT'),
                BridgeKv(l.t('bridge.networkFee'), l.t('bridge.gasFree')),
                const SizedBox(height: 10),
                BridgePrimaryButton(
                  key: const ValueKey('bridge-withdraw-button'),
                  label: bridgeT(l, 'bridge.burnUsdt', {'chain': target.name}),
                  onPressed: _canWithdraw ? _submitWithdraw : null,
                  loading: _busy,
                  loadingLabel: _busyLabel,
                ),
              ],
            ],
          ),
        ),
        const SizedBox(height: 12),
        BridgeHowItWorks(title: l.t('bridge.howTitle'), steps: [
          l.t('bridge.howWithdraw1'),
          l.t('bridge.howWithdraw2'),
          l.t('bridge.howWithdraw3'),
        ]),
      ],
    );
  }

  Widget _trackSection(UsdtBridgeInfo info, LocaleProvider l) {
    final valid = isTxHash(_manualCtrl.text);
    final chains = info.chains.where((c) => c.serverEnabled && c.chainId != bridgeTpixChainId).toList();
    return BridgeCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          BridgeSectionTitle(l.t('bridge.trackTitle')),
          Text(l.t('bridge.trackHint'), style: AppFonts.ui(fontSize: 12, color: AppColors.textSecondary)),
          const SizedBox(height: 10),
          BridgeSegmented<String>(
            options: [(usdtDeposit, l.t('bridge.tabDeposit')), (usdtWithdraw, l.t('bridge.trackWithdrawal'))],
            value: _manualKind,
            onChanged: (k) => setState(() => _manualKind = k),
          ),
          if (_manualKind == usdtDeposit) ...[
            const SizedBox(height: 10),
            BridgeChainPicker(
              chains: [for (final ch in chains) (id: ch.chainId, title: ch.name, subtitle: ch.usdtSymbol)],
              selected: _manualChainId,
              onSelect: (id) => setState(() => _manualChainId = id),
            ),
          ],
          const SizedBox(height: 10),
          BridgeInput(
            key: const ValueKey('bridge-manual-hash'),
            controller: _manualCtrl,
            hint: l.t('bridge.trackTxHash'),
            onChanged: (_) => setState(() {}),
            errorText: _manualCtrl.text.trim().isNotEmpty && !valid ? l.t('bridge.errHashInvalid') : null,
          ),
          const SizedBox(height: 10),
          BridgePrimaryButton(
            label: l.t('bridge.trackButton'),
            onPressed: valid && (_manualKind == usdtWithdraw || _manualChainId != null) ? _addManual : null,
          ),
        ],
      ),
    );
  }

  Widget _trackedSection(UsdtBridgeInfo info, LocaleProvider l) {
    final entries = _tracker.entries;
    return BridgeCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          BridgeSectionTitle(l.t('bridge.tracked')),
          if (entries.isEmpty)
            Text(l.t('bridge.noTracked'), style: AppFonts.ui(fontSize: 12, color: AppColors.textTertiary))
          else
            for (final e in entries) _trackedTile(e, info, l),
        ],
      ),
    );
  }

  Widget _trackedTile(TrackedTransfer e, UsdtBridgeInfo info, LocaleProvider l) {
    final s = e.status;
    final badge = s == null ? null : stateBadge(s.state);
    final deposit = e.kind == usdtDeposit;
    int? targetChain = e.targetChainId;
    for (final r in s?.rows ?? const <BridgeStatusRow>[]) {
      targetChain ??= r.targetChainId;
    }
    final explorer = explorerTx(info.explorerFor(e.chainId), e.txHash);
    return Container(
      key: ValueKey('tracked-${e.key}'),
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(12),
        color: AppColors.bgTertiary,
        border: Border.all(color: AppColors.bgCardBorder),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${deposit ? '${_chainName(e.chainId)} → TPIX' : 'TPIX → ${_chainName(targetChain)}'}'
                      '${e.amount != null ? ' · ${e.amount} USDT' : ''}',
                      style: AppFonts.ui(fontSize: 12.5, fontWeight: FontWeight.w700, color: AppColors.textPrimary),
                    ),
                    const SizedBox(height: 2),
                    InkWell(
                      onTap: explorer == null ? null : () => openBridgeUrl(context, explorer),
                      child: Text(
                        bridgeShort(e.txHash),
                        style: AppTheme.mono(
                          fontSize: 11,
                          color: explorer == null ? AppColors.textTertiary : AppColors.brandCyan,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 6),
              Flexible(
                child: BridgeStateChip(
                  label: badge == null
                      ? l.t('bridge.state.waiting')
                      : bridgeT(l, badge.labelKey, const {}, 'bridge.state.other'),
                  tone: badge?.tone ?? BridgeTone.info,
                ),
              ),
              IconButton(
                tooltip: l.t('bridge.remove'),
                visualDensity: VisualDensity.compact,
                icon: Icon(Icons.close_rounded, size: 18, color: AppColors.textTertiary),
                onPressed: () => _remove(e),
              ),
            ],
          ),
          if (s != null && s.state == 'confirming')
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                '${s.isFinal ? l.t('bridge.finalSoon') : bridgeT(l, 'bridge.confirmations', {'n': '${s.confirmations ?? 0}'})}'
                '${s.amount != null ? ' · ${s.amount} USDT' : ''}',
                style: AppFonts.ui(fontSize: 11.5, color: AppColors.textSecondary),
              ),
            ),
          if (s != null && s.needsRefund)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(l.t('bridge.refundHint'), style: AppFonts.ui(fontSize: 11.5, color: AppColors.brandWarm)),
            ),
          for (final r in s?.rows ?? const <BridgeStatusRow>[])
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Wrap(
                spacing: 10,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  Text(
                    deposit ? '${r.amount ?? '—'} USDT' : '${r.amount ?? '—'} USDT → ${bridgeShort(r.targetAddress, head: 8)}',
                    style: AppTheme.mono(fontSize: 11.5, color: AppColors.textPrimary),
                  ),
                  BridgeLink(label: deposit ? l.t('bridge.viewMint') : l.t('bridge.viewPayout'), url: r.url),
                ],
              ),
            ),
        ],
      ),
    );
  }

  Widget _historySection(UsdtBridgeInfo info, LocaleProvider l) => BridgeCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            BridgeSectionTitle(l.t('bridge.history')),
            if (_history.isEmpty)
              Text(l.t('bridge.historyEmpty'), style: AppFonts.ui(fontSize: 12, color: AppColors.textTertiary))
            else
              for (final r in _history)
                InkWell(
                  onTap: r.txHash == null
                      ? null
                      : () => openBridgeUrl(
                            context,
                            explorerTx(info.explorerFor(r.kind == usdtDeposit ? (r.chainId ?? -1) : bridgeTpixChainId), r.txHash),
                          ),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(vertical: 5),
                    child: Row(
                      children: [
                        Expanded(
                          child: Text(
                            '${r.kind == usdtDeposit ? l.t('bridge.deposits') : l.t('bridge.withdrawals')}'
                            ' · ${_chainName(r.chainId)} · ${r.amount ?? '—'} USDT',
                            style: AppFonts.ui(fontSize: 12, color: AppColors.textPrimary),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Flexible(
                          child: BridgeStateChip(
                            label: bridgeT(l, stateBadge(r.state).labelKey, const {}, 'bridge.state.other'),
                            tone: stateBadge(r.state).tone,
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
