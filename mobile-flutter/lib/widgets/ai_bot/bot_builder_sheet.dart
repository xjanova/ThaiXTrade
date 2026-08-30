/// TPIX TRADE — ฟอร์มสร้าง / แก้ไขบอท AI TRADE
/// ฟอร์มยาวบนมือถือต้องไม่น่ากลัว: แบ่งเป็นกลุ่ม อธิบายทุกช่องสั้นๆ ให้ค่าปริยาย
/// ที่ใช้ได้จริง ตรวจค่าทันทีที่พิมพ์ และบอกตรงๆ ว่ากลยุทธ์ที่ล็อกต้องแพลนไหน
///
/// พารามิเตอร์ทั้งหมดวาดจากสิ่งที่เซิร์ฟเวอร์ส่งมาเท่านั้น:
///   `catalog.common_params` ก่อน แล้วให้ `catalog.strategies[x].params` เขียนทับ
///   คีย์ที่ชื่อซ้ำ — ถ้าวาดจากกลยุทธ์อย่างเดียว สวิตช์ร่วม (ด่านข่าว / ให้ AI
///   เลือกเหรียญ) จะไม่มีวันโผล่บนจอ
///
/// กับดักที่ฟอร์มนี้แก้ไว้แล้ว:
///   • `max_position_usd_requested` มีลำดับสูงกว่า `max_position_usd` ฝั่ง
///     เซิร์ฟเวอร์ — ถ้าคัด risk เดิมมาทั้งก้อนแล้วแก้แค่ตัวหลัง ค่าที่ผู้ใช้พิมพ์
///     จะถูกทิ้งเงียบๆ โดยตอบ 200 เหมือนสำเร็จ ฟอร์มนี้จึงส่งค่าเดียวกันทั้งสองคีย์
///   • กรอบเวลาต้องมาจาก `strategies[x].timeframes` ไม่ใช่รายการรวม
///   • กลยุทธ์ที่ `available: false` อัปเกรดแพลนก็ยังใช้ไม่ได้ — ป้าย "ยังไม่เปิด"
///     จึงสำคัญกว่าป้ายระดับแพลน
///   • เปลี่ยนคู่เทรดขณะถือของอยู่ เซิร์ฟเวอร์ไม่ปิดไม้ให้ ฟอร์มจึงล็อกช่องไว้
///
/// Developed by Xman Studio
library;

import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';

import '../../core/locale/locale_provider.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_theme.dart';
import '../../providers/accent_provider.dart';
import '../common/glass_card.dart';
import '../common/gradient_button.dart';
import 'bot_json.dart';

// ─────────────────────────────────────────────────────────
// สัญญาระหว่างฟอร์มกับหน้าจอที่เรียกใช้
// ─────────────────────────────────────────────────────────

/// ค่าที่ฟอร์มส่งกลับ — พร้อมยิงเข้า `POST /ai-bot/bots` หรือ `PUT /ai-bot/bots/{id}`
class BotFormPayload {
  /// null = สร้างใหม่ · มีค่า = แก้ไขบอทตัวนี้
  final int? id;
  final String name;
  final String pair;
  final String strategy;
  final String timeframe;
  final Map<String, dynamic> params;
  final Map<String, dynamic> risk;

  const BotFormPayload({
    required this.id,
    required this.name,
    required this.pair,
    required this.strategy,
    required this.timeframe,
    required this.params,
    required this.risk,
  });

  bool get isEditing => id != null;

  Map<String, dynamic> toJson() => {
        'name': name,
        'pair': pair,
        'strategy': strategy,
        'timeframe': timeframe,
        'params': params,
        'risk': risk,
      };
}

/// ผลลัพธ์การบันทึกที่หน้าจอส่งกลับมาให้ฟอร์ม
/// `fieldErrors` คือ `errors` รูป Laravel (422) แปลงเป็น field → ข้อความ
/// คีย์ที่ฟอร์มรู้จัก: name · pair · strategy · timeframe · params · risk
class BotFormResult {
  final bool ok;
  final String? message;
  final Map<String, String> fieldErrors;

  const BotFormResult.success()
      : ok = true,
        message = null,
        fieldErrors = const {};

  const BotFormResult.failure(
    this.message, {
    this.fieldErrors = const {},
  }) : ok = false;
}

typedef BotFormSubmit = Future<BotFormResult> Function(BotFormPayload payload);

/// เปิดฟอร์ม — คืน true เมื่อบันทึกสำเร็จ
Future<bool> showBotBuilderSheet(
  BuildContext context, {
  required Map<String, dynamic> catalog,
  required List<String> unlockedStrategies,
  required List<String> pairs,
  required BotFormSubmit onSubmit,
  Map<String, dynamic>? existing,
  bool salesOpen = false,
  double? planCapitalCap,
  int botCount = 0,
}) async {
  final saved = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    backgroundColor: Colors.transparent,
    builder: (_) => BotBuilderSheet(
      catalog: catalog,
      unlockedStrategies: unlockedStrategies,
      pairs: pairs,
      onSubmit: onSubmit,
      existing: existing,
      salesOpen: salesOpen,
      planCapitalCap: planCapitalCap,
      botCount: botCount,
    ),
  );
  return saved == true;
}

// ─────────────────────────────────────────────────────────
// ตัวฟอร์ม
// ─────────────────────────────────────────────────────────

class BotBuilderSheet extends StatefulWidget {
  /// payload ดิบของ `GET /ai-bot/catalog`
  final Map<String, dynamic> catalog;

  /// `status.unlocked_strategies`
  final List<String> unlockedStrategies;

  /// รายการคู่เทรดที่เลือกได้ (จาก `/market/pairs`)
  final List<String> pairs;

  /// payload ของบอทที่กำลังแก้ (โครง `presentBot()`) — null = สร้างใหม่
  final Map<String, dynamic>? existing;

  /// `catalog.features.sales_open` — ใช้เลือกถ้อยคำตอนกลยุทธ์ถูกล็อก
  final bool salesOpen;

  /// `plan.max_capital_usd` ของแพลนปัจจุบัน (null หรือ <= 0 = ไม่จำกัด)
  final double? planCapitalCap;

  /// จำนวนบอทที่มีอยู่ — ใช้ตั้งชื่อปริยาย
  final int botCount;

  final BotFormSubmit onSubmit;

  const BotBuilderSheet({
    super.key,
    required this.catalog,
    required this.unlockedStrategies,
    required this.pairs,
    required this.onSubmit,
    this.existing,
    this.salesOpen = false,
    this.planCapitalCap,
    this.botCount = 0,
  });

  @override
  State<BotBuilderSheet> createState() => _BotBuilderSheetState();
}

class _BotBuilderSheetState extends State<BotBuilderSheet> {
  // ── ข้อมูลจากแคตตาล็อก ────────────────────────────────
  late final List<Map<String, dynamic>> _strategies;
  late final List<Map<String, dynamic>> _commonParams;
  late final Map<String, dynamic> _limits;
  late final List<String> _allTimeframes;
  late final int _maxNameLength;

  // ── สถานะฟอร์ม ────────────────────────────────────────
  final TextEditingController _nameCtrl = TextEditingController();
  final ScrollController _scrollCtrl = ScrollController();
  final Map<String, TextEditingController> _paramCtrls = {};
  final Map<String, TextEditingController> _riskCtrls = {};

  /// เก็บเฉพาะพารามิเตอร์ที่ไม่ใช่ตัวเลข (bool / select)
  Map<String, dynamic> _params = {};

  String _pair = '';
  String _strategy = '';
  String _timeframe = '';

  bool _submitting = false;
  String? _formError;
  Map<String, String> _fieldErrors = const {};

  /// ข้อความอธิบายตอนผู้ใช้แตะกลยุทธ์ที่กดไม่ได้ (ไม่ปล่อยให้ปุ่มตายเฉยๆ)
  String? _lockNote;

  // ── ค่าจากบอทเดิม ─────────────────────────────────────
  int? get _editingId => jsonInt(widget.existing?['id']);
  bool get _isEditing => _editingId != null;
  bool get _hasOpenPosition => widget.existing?['position'] is Map;
  bool get _editingRunning =>
      jsonStr(widget.existing?['status']) == 'running';

  @override
  void initState() {
    super.initState();

    _strategies = jsonMapList(widget.catalog['strategies']);
    _commonParams = jsonMapList(widget.catalog['common_params']);
    _limits = jsonMap(widget.catalog['limits']);
    _allTimeframes = jsonStrList(widget.catalog['timeframes']);
    _maxNameLength = jsonInt(_limits['max_name_length']) ?? 60;

    final existing = widget.existing;

    // ชื่อ
    _nameCtrl.text = jsonStr(existing?['name']) ??
        'บอท ${widget.botCount + 1}';

    // คู่เทรด
    final existingPair = jsonStr(existing?['pair'])?.toUpperCase();
    _pair = existingPair ??
        (widget.pairs.isNotEmpty ? widget.pairs.first : 'BTC/USDT');

    // กลยุทธ์ — สร้างใหม่ให้เลือกตัวแรกที่กดได้จริง
    final existingStrategy = jsonStr(existing?['strategy']);
    _strategy = existingStrategy ?? _firstSelectableStrategy();

    // กรอบเวลา
    final tfs = _timeframesFor(_strategy);
    final existingTf = jsonStr(existing?['timeframe']);
    _timeframe = (existingTf != null && tfs.contains(existingTf))
        ? existingTf
        : (tfs.isNotEmpty ? tfs.first : '1h');

    // พารามิเตอร์
    _applyStrategyParams(_strategy, seed: jsonMap(existing?['params']));

    // กรอบความเสี่ยง
    _buildRiskControllers(jsonMap(existing?['risk']));
  }

  @override
  void dispose() {
    _nameCtrl.dispose();
    _scrollCtrl.dispose();
    for (final c in _paramCtrls.values) {
      c.dispose();
    }
    for (final c in _riskCtrls.values) {
      c.dispose();
    }
    super.dispose();
  }

  // ─────────────────────────────────────────────────────
  // ตรรกะแคตตาล็อก
  // ─────────────────────────────────────────────────────

  Map<String, dynamic>? _strategyByCode(String code) {
    for (final s in _strategies) {
      if (jsonStr(s['code']) == code) return s;
    }
    return null;
  }

  /// กลยุทธ์นี้ปลดล็อกตามแพลนแล้วไหม
  bool _isUnlocked(String code) => widget.unlockedStrategies.contains(code);

  /// กลยุทธ์นี้ลงมือได้จริงไหม (พูล DEX ยังไม่ deploy = arbitrage ใช้ไม่ได้)
  bool _isRunnable(Map<String, dynamic> s) => s['available'] != false;

  /// กดเลือกได้ไหม — บอทที่กำลังแก้อยู่ใช้กลยุทธ์เดิมได้เสมอ
  bool _isSelectable(Map<String, dynamic> s) {
    final code = jsonStr(s['code']) ?? '';
    if (_isEditing && code == jsonStr(widget.existing?['strategy'])) {
      return true;
    }
    return _isUnlocked(code) && _isRunnable(s);
  }

  String _firstSelectableStrategy() {
    for (final s in _strategies) {
      if (_isSelectable(s)) return jsonStr(s['code']) ?? '';
    }
    return _strategies.isEmpty ? 'grid' : (jsonStr(_strategies.first['code']) ?? 'grid');
  }

  List<String> _timeframesFor(String code) {
    final s = _strategyByCode(code);
    final own = jsonStrList(s?['timeframes']);
    return own.isNotEmpty ? own : _allTimeframes;
  }

  /// รวมสเปกพารามิเตอร์: สวิตช์ร่วมก่อน แล้วให้ของกลยุทธ์เขียนทับคีย์ซ้ำ
  List<_ParamSpec> _specsFor(String code) {
    final merged = <String, _ParamSpec>{};
    for (final raw in _commonParams) {
      final spec = _ParamSpec.from(raw, fromStrategy: false);
      if (spec != null) merged[spec.key] = spec;
    }
    final s = _strategyByCode(code);
    for (final raw in jsonMapList(s?['params'])) {
      final spec = _ParamSpec.from(raw, fromStrategy: true);
      if (spec != null) merged[spec.key] = spec;
    }
    return merged.values.toList();
  }

  /// เตรียมค่า/คอนโทรลเลอร์ให้ตรงกับกลยุทธ์ที่เลือก
  /// `seed` = ค่าจากบอทเดิม (ใส่เฉพาะตอนเปิดฟอร์มครั้งแรก)
  void _applyStrategyParams(String code, {Map<String, dynamic>? seed}) {
    final specs = _specsFor(code);
    final keys = specs.map((s) => s.key).toSet();

    // ทิ้งคอนโทรลเลอร์ของคีย์ที่กลยุทธ์ใหม่ไม่มีแล้ว
    for (final k in _paramCtrls.keys.toList()) {
      if (!keys.contains(k)) {
        _paramCtrls.remove(k)?.dispose();
      }
    }

    final nextValues = <String, dynamic>{};
    for (final spec in specs) {
      final seeded = seed != null && seed.containsKey(spec.key)
          ? seed[spec.key]
          : null;

      switch (spec.type) {
        case 'number':
          final ctrl = _paramCtrls[spec.key];
          if (ctrl == null) {
            final v = jsonDouble(seeded) ?? spec.numDefault;
            _paramCtrls[spec.key] =
                TextEditingController(text: formatNumberText(v));
          } else if (seeded != null) {
            ctrl.text = formatNumberText(jsonDouble(seeded) ?? spec.numDefault);
          }
          break;
        case 'select':
          final current = jsonStr(seeded) ?? jsonStr(_params[spec.key]);
          nextValues[spec.key] =
              (current != null && spec.options.contains(current))
                  ? current
                  : spec.stringDefault;
          break;
        default: // bool
          if (seeded is bool) {
            nextValues[spec.key] = seeded;
          } else if (_params[spec.key] is bool) {
            nextValues[spec.key] = _params[spec.key];
          } else {
            nextValues[spec.key] = spec.boolDefault;
          }
      }
    }
    _params = nextValues;
  }

  void _buildRiskControllers(Map<String, dynamic> seed) {
    for (final f in _riskFields) {
      final v = jsonDouble(
            f.key == 'max_position_usd'
                // ค่าที่ผู้ใช้ "ตั้งใจ" ไว้ ไม่ใช่ค่าที่ถูกแพลนบีบแล้ว
                ? (seed['max_position_usd_requested'] ??
                    seed['max_position_usd'])
                : seed[f.key],
          ) ??
          _limitDefault(f.key, f.fallbackDefault);
      _riskCtrls[f.key] = TextEditingController(text: formatNumberText(v));
    }
  }

  double _limitMin(String key, double fallback) =>
      jsonDouble(jsonMap(_limits[key])['min']) ?? fallback;

  double _limitMax(String key, double fallback) =>
      jsonDouble(jsonMap(_limits[key])['max']) ?? fallback;

  double _limitDefault(String key, double fallback) =>
      jsonDouble(jsonMap(_limits[key])['default']) ?? fallback;

  // ─────────────────────────────────────────────────────
  // ตรวจค่า (ทันทีที่พิมพ์)
  // ─────────────────────────────────────────────────────

  String? _nameError(bool th) {
    final v = _nameCtrl.text.trim();
    if (v.isEmpty) {
      return th ? 'ตั้งชื่อบอทก่อนบันทึก' : 'Name the bot before saving';
    }
    if (v.length > _maxNameLength) {
      return th
          ? 'ชื่อยาวเกิน $_maxNameLength ตัวอักษร'
          : 'Name is longer than $_maxNameLength characters';
    }
    return _fieldErrors['name'];
  }

  String? _pairError(bool th) {
    final ok = RegExp(r'^[A-Za-z0-9]{2,15}/[A-Za-z0-9]{2,15}$').hasMatch(_pair);
    if (!ok) {
      return th
          ? 'เลือกคู่เทรดที่ถูกต้อง เช่น BTC/USDT'
          : 'Pick a valid pair, e.g. BTC/USDT';
    }
    return _fieldErrors['pair'];
  }

  /// ตรวจช่องตัวเลข — คืนข้อความไทย/อังกฤษเมื่อผิด
  String? _numberError(
    TextEditingController ctrl,
    double? min,
    double? max,
    bool th,
  ) {
    final raw = ctrl.text.trim();
    if (raw.isEmpty) {
      return th ? 'กรอกตัวเลข' : 'Enter a number';
    }
    final v = double.tryParse(raw);
    if (v == null) {
      return th ? 'ต้องเป็นตัวเลขเท่านั้น' : 'Numbers only';
    }
    if (min != null && v < min) {
      return th
          ? 'ต่ำกว่าขั้นต่ำ ${formatNumberText(min)}'
          : 'Below the minimum ${formatNumberText(min)}';
    }
    if (max != null && v > max) {
      return th
          ? 'เกินเพดาน ${formatNumberText(max)}'
          : 'Above the maximum ${formatNumberText(max)}';
    }
    return null;
  }

  bool get _formIsValid {
    if (_nameError(true) != null) return false;
    if (_pairError(true) != null) return false;
    if (_strategy.isEmpty) return false;
    if (_timeframe.isEmpty) return false;

    for (final spec in _specsFor(_strategy)) {
      if (spec.type != 'number') continue;
      final ctrl = _paramCtrls[spec.key];
      if (ctrl == null) return false;
      if (_numberError(ctrl, spec.min, spec.max, true) != null) return false;
    }
    for (final f in _riskFields) {
      final ctrl = _riskCtrls[f.key];
      if (ctrl == null) return false;
      final err = _numberError(
        ctrl,
        _limitMin(f.key, f.fallbackMin),
        _limitMax(f.key, f.fallbackMax),
        true,
      );
      if (err != null) return false;
    }
    return true;
  }

  // ─────────────────────────────────────────────────────
  // ส่งฟอร์ม
  // ─────────────────────────────────────────────────────

  BotFormPayload _buildPayload() {
    final params = <String, dynamic>{};
    for (final spec in _specsFor(_strategy)) {
      switch (spec.type) {
        case 'number':
          params[spec.key] =
              double.tryParse(_paramCtrls[spec.key]?.text.trim() ?? '') ??
                  spec.numDefault;
          break;
        case 'select':
          params[spec.key] = _params[spec.key] ?? spec.stringDefault;
          break;
        default:
          params[spec.key] = _params[spec.key] == true;
      }
    }

    final cap = double.tryParse(
          _riskCtrls['max_position_usd']?.text.trim() ?? '',
        ) ??
        _limitDefault('max_position_usd', 100);

    final risk = <String, dynamic>{
      // ส่งค่าเดียวกันทั้งสองคีย์ — เซิร์ฟเวอร์อ่าน `_requested` ก่อนเสมอ
      // ถ้าส่งแต่คีย์แรก ค่าที่ผู้ใช้พิมพ์จะถูกทิ้งโดยตอบ 200 เหมือนสำเร็จ
      'max_position_usd': cap,
      'max_position_usd_requested': cap,
    };
    for (final f in _riskFields) {
      if (f.key == 'max_position_usd') continue;
      risk[f.key] = double.tryParse(_riskCtrls[f.key]?.text.trim() ?? '') ??
          _limitDefault(f.key, f.fallbackDefault);
    }

    return BotFormPayload(
      id: _editingId,
      name: _nameCtrl.text.trim(),
      pair: _pair.toUpperCase(),
      strategy: _strategy,
      timeframe: _timeframe,
      params: params,
      risk: risk,
    );
  }

  Future<void> _submit() async {
    if (_submitting) return; // กันกดรัว
    FocusScope.of(context).unfocus();

    final th = context.read<LocaleProvider>().isThai;
    if (!_formIsValid) {
      setState(() {
        _formError = th
            ? 'ยังมีช่องที่กรอกไม่ครบหรือค่าไม่อยู่ในกรอบ — เลื่อนดูช่องที่ขึ้นสีแดง'
            : 'Some fields are missing or out of range — check the ones marked red';
      });
      return;
    }

    setState(() {
      _submitting = true;
      _formError = null;
      _fieldErrors = const {};
    });

    BotFormResult result;
    try {
      result = await widget.onSubmit(_buildPayload());
    } catch (_) {
      // ไม่ให้ล้มเงียบ — แม้ผู้เรียกจะโยน exception ออกมาก็ต้องมีเหตุผลบนจอ
      result = BotFormResult.failure(
        th
            ? 'บันทึกไม่สำเร็จ ลองใหม่อีกครั้ง'
            : 'Could not save — please try again',
      );
    }

    if (!mounted) return;

    if (result.ok) {
      Navigator.pop(context, true);
      return;
    }

    setState(() {
      _submitting = false;
      _fieldErrors = result.fieldErrors;
      _formError = result.message ??
          (th ? 'บันทึกไม่สำเร็จ' : 'Could not save');
    });

    // เลื่อนกลับไปบนสุดให้เห็นช่องที่เซิร์ฟเวอร์ตีกลับ
    if (_fieldErrors.isNotEmpty && _scrollCtrl.hasClients) {
      _scrollCtrl.animateTo(
        0,
        duration: const Duration(milliseconds: 260),
        curve: Curves.easeOut,
      );
    }
  }

  // ─────────────────────────────────────────────────────
  // UI
  // ─────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final locale = context.watch<LocaleProvider>();
    final accent = context.watch<AccentProvider>();
    final th = locale.isThai;
    final maxHeight = MediaQuery.of(context).size.height * 0.94;

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: ClipRRect(
        borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
        child: BackdropFilter(
          filter: ImageFilter.blur(sigmaX: 22, sigmaY: 22),
          child: Container(
            constraints: BoxConstraints(maxHeight: maxHeight),
            decoration: BoxDecoration(
              gradient: const LinearGradient(
                colors: [Color(0xF21A1C24), Color(0xF20E0F14)],
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
              ),
              borderRadius:
                  const BorderRadius.vertical(top: Radius.circular(24)),
              border: Border(
                top: BorderSide(
                  color: accent.goldBorder,
                  width: kGoldEdgeWidth,
                ),
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
                _buildHeader(th, accent),
                Flexible(
                  child: SingleChildScrollView(
                    controller: _scrollCtrl,
                    padding: const EdgeInsets.fromLTRB(18, 4, 18, 18),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        _buildBasicsGroup(th, accent),
                        const SizedBox(height: 12),
                        _buildStrategyGroup(th, accent),
                        const SizedBox(height: 12),
                        ..._buildParamGroups(th, accent),
                        _buildRiskGroup(th, accent),
                      ],
                    ),
                  ),
                ),
                _buildFooter(th, accent),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildHeader(bool th, AccentProvider accent) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 14, 12, 10),
      child: Row(
        children: [
          Container(
            width: 34,
            height: 34,
            decoration: BoxDecoration(
              color: accent.goldTint,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: accent.goldBorder, width: 1),
            ),
            child: Icon(
              _isEditing ? Icons.tune_rounded : Icons.add_circle_outline_rounded,
              size: 17,
              color: accent.g2,
            ),
          ),
          const SizedBox(width: 11),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  _isEditing
                      ? (th ? 'แก้ไขบอท' : 'Edit bot')
                      : (th ? 'สร้างบอทใหม่' : 'New bot'),
                  style: GoogleFonts.inter(
                    fontSize: 17,
                    fontWeight: FontWeight.w800,
                    color: AppColors.textPrimary,
                    letterSpacing: -0.3,
                  ),
                ),
                const SizedBox(height: 1),
                Text(
                  th
                      ? 'ตั้งค่าเสร็จแล้วปรับเปลี่ยนทีหลังได้ตลอด'
                      : 'Everything here can be changed later',
                  style: GoogleFonts.inter(
                    fontSize: 11,
                    color: AppColors.textTertiary,
                  ),
                ),
              ],
            ),
          ),
          IconButton(
            onPressed: _submitting ? null : () => Navigator.pop(context, false),
            icon: const Icon(Icons.close_rounded, size: 20),
            color: AppColors.textTertiary,
            tooltip: th ? 'ปิด' : 'Close',
          ),
        ],
      ),
    );
  }

  // ── กลุ่ม 1: พื้นฐาน ──────────────────────────────────
  Widget _buildBasicsGroup(bool th, AccentProvider accent) {
    final nameErr = _nameError(th);
    final pairErr = _pairError(th);
    final pairLocked = _isEditing && _hasOpenPosition;

    return _Group(
      icon: Icons.badge_outlined,
      title: th ? 'ข้อมูลพื้นฐาน' : 'Basics',
      subtitle: th
          ? 'ชื่อไว้แยกบอทของคุณ และคู่ที่จะให้บอทเฝ้า'
          : 'A name to tell your bots apart, and the pair it watches',
      children: [
        _FieldLabel(
          label: th ? 'ชื่อบอท' : 'Bot name',
          help: th
              ? 'ตั้งให้จำได้ เช่น "กริด BTC กลางคืน"'
              : 'Something memorable, e.g. "BTC grid overnight"',
        ),
        const SizedBox(height: 7),
        TextField(
          controller: _nameCtrl,
          maxLength: _maxNameLength,
          textInputAction: TextInputAction.next,
          onChanged: (_) => setState(() {}),
          style: GoogleFonts.inter(
            color: AppColors.textPrimary,
            fontSize: 14,
            fontWeight: FontWeight.w600,
          ),
          cursorColor: accent.g2,
          decoration: _inputDecoration(
            accent,
            hint: th ? 'เช่น กริด BTC กลางคืน' : 'e.g. BTC grid overnight',
            error: nameErr,
            counter: true,
          ),
        ),
        const SizedBox(height: 14),
        _FieldLabel(
          label: th ? 'คู่เทรด' : 'Trading pair',
          help: pairLocked
              ? (th
                  ? 'เปลี่ยนไม่ได้ตอนนี้ — บอทยังถือของอยู่ ถ้าเปลี่ยนคู่ ไม้เดิมจะค้างโดยไม่มีใครดูแล'
                  : 'Locked — the bot still holds a position; changing the pair would abandon it')
              : (th
                  ? 'บอทจะดูแท่งเทียนของคู่นี้เป็นหลัก'
                  : 'The bot reads this pair\'s candles'),
        ),
        const SizedBox(height: 7),
        _PairField(
          pair: _pair,
          locked: pairLocked || _submitting,
          error: pairErr,
          onTap: () => _openPairPicker(th),
        ),
        if (_params['auto_pair'] == true) ...[
          const SizedBox(height: 8),
          _NoteLine(
            icon: Icons.auto_awesome_rounded,
            text: th
                ? 'คุณเปิด "ให้ AI เลือกเหรียญ" ไว้ — บอทอาจเลือกคู่อื่นให้ในบางรอบ คู่นี้ใช้เป็นค่าตั้งต้น'
                : 'You turned on "let AI pick the coin" — the bot may choose another pair each round; this one is the starting point',
          ),
        ],
      ],
    );
  }

  Future<void> _openPairPicker(bool th) async {
    if (_submitting) return;
    final picked = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      builder: (_) => _PairPickerSheet(
        pairs: widget.pairs,
        selected: _pair,
      ),
    );
    if (!mounted || picked == null) return;
    setState(() {
      _pair = picked.toUpperCase();
      _fieldErrors = Map.of(_fieldErrors)..remove('pair');
    });
  }

  // ── กลุ่ม 2: กลยุทธ์ + กรอบเวลา ────────────────────────
  Widget _buildStrategyGroup(bool th, AccentProvider accent) {
    final timeframes = _timeframesFor(_strategy);
    final strategyErr = _fieldErrors['strategy'];
    final tfErr = _fieldErrors['timeframe'];

    return _Group(
      icon: Icons.insights_rounded,
      title: th ? 'กลยุทธ์' : 'Strategy',
      subtitle: th
          ? 'วิธีที่บอทใช้ตัดสินใจ — เปลี่ยนได้ทีหลัง'
          : 'How the bot decides — you can change it later',
      children: [
        LayoutBuilder(
          builder: (context, c) {
            const gap = 8.0;
            final w = (c.maxWidth - gap) / 2;
            return Wrap(
              spacing: gap,
              runSpacing: gap,
              children: [
                for (final s in _strategies)
                  SizedBox(
                    width: w,
                    child: _StrategyTile(
                      data: s,
                      th: th,
                      selected: jsonStr(s['code']) == _strategy,
                      selectable: _isSelectable(s) && !_submitting,
                      onTap: () => _selectStrategy(s),
                      onBlocked: () => _explainLocked(s, th),
                    ),
                  ),
              ],
            );
          },
        ),
        if (_lockNote != null) ...[
          const SizedBox(height: 10),
          _NoteLine(icon: Icons.lock_outline_rounded, text: _lockNote!),
        ],
        if (strategyErr != null) ...[
          const SizedBox(height: 8),
          _ErrorLine(text: strategyErr),
        ],
        const SizedBox(height: 16),
        _FieldLabel(
          label: th ? 'กรอบเวลา' : 'Timeframe',
          help: th
              ? 'ความยาวของแท่งเทียนที่บอทอ่าน — กลยุทธ์นี้ใช้ได้เฉพาะที่แสดงไว้'
              : 'Candle size the bot reads — only these work with this strategy',
        ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 7,
          runSpacing: 7,
          children: [
            for (final tf in timeframes)
              _ChoiceChip(
                label: tf,
                selected: tf == _timeframe,
                mono: true,
                onTap: _submitting
                    ? null
                    : () => setState(() {
                          _timeframe = tf;
                          _fieldErrors =
                              Map.of(_fieldErrors)..remove('timeframe');
                        }),
              ),
          ],
        ),
        if (tfErr != null) ...[
          const SizedBox(height: 8),
          _ErrorLine(text: tfErr),
        ],
        if (_isEditing && _editingRunning) ...[
          const SizedBox(height: 10),
          _NoteLine(
            icon: Icons.info_outline_rounded,
            text: th
                ? 'บอทตัวนี้กำลังทำงานอยู่ — บันทึกแล้วรอบถัดไปจะใช้ค่าใหม่ทันที'
                : 'This bot is running — the next cycle uses the new settings right away',
          ),
        ],
      ],
    );
  }

  void _selectStrategy(Map<String, dynamic> s) {
    final code = jsonStr(s['code']) ?? '';
    if (code.isEmpty || code == _strategy) return;
    setState(() {
      _strategy = code;
      _lockNote = null;
      _fieldErrors = Map.of(_fieldErrors)
        ..remove('strategy')
        ..remove('timeframe');

      final tfs = _timeframesFor(code);
      if (!tfs.contains(_timeframe)) {
        _timeframe = tfs.isNotEmpty ? tfs.first : _timeframe;
      }
      _applyStrategyParams(code);
    });
  }

  void _explainLocked(Map<String, dynamic> s, bool th) {
    final runnable = _isRunnable(s);
    String note;
    if (!runnable) {
      note = (th
              ? jsonStr(s['unavailable_reason'])
              : jsonStr(s['unavailable_reason_en'])) ??
          (th
              ? 'กลยุทธ์นี้ยังเปิดใช้งานไม่ได้ในตอนนี้'
              : 'This strategy is not available yet');
    } else {
      final tier = _tierText(jsonStr(s['tier']), th);
      note = tier.isEmpty
          ? (th
              ? 'กลยุทธ์นี้ต้องใช้แพลนระดับสูงกว่า'
              : 'This strategy needs a higher plan')
          : (th ? 'ต้องใช้แพลน $tier' : 'Needs the $tier plan');
      if (!widget.salesOpen) {
        note += th
            ? ' — ตอนนี้ยังไม่เปิดให้เช่า ระหว่างนี้ใช้กลยุทธ์ที่ปลดแล้วได้เต็มที่'
            : ' — renting is not open yet; the unlocked strategies are free to use meanwhile';
      }
    }
    setState(() => _lockNote = note);
  }

  // ── กลุ่ม 3-4: พารามิเตอร์ ────────────────────────────
  List<Widget> _buildParamGroups(bool th, AccentProvider accent) {
    final specs = _specsFor(_strategy);
    final strategySpecs = specs.where((s) => s.fromStrategy).toList();
    final commonSpecs = specs.where((s) => !s.fromStrategy).toList();
    final paramErr = _fieldErrors['params'];

    final out = <Widget>[];

    if (strategySpecs.isNotEmpty) {
      out.add(_Group(
        icon: Icons.settings_suggest_rounded,
        title: th ? 'ค่าปรับแต่งกลยุทธ์' : 'Strategy settings',
        subtitle: th
            ? 'ค่าปริยายใช้ได้ทันที ปรับเมื่อรู้ว่าต้องการอะไร'
            : 'The defaults work as-is — tweak them when you know what you want',
        children: [
          for (int i = 0; i < strategySpecs.length; i++) ...[
            if (i > 0) const SizedBox(height: 14),
            _buildParamField(strategySpecs[i], th, accent),
          ],
          if (paramErr != null) ...[
            const SizedBox(height: 10),
            _ErrorLine(text: paramErr),
          ],
        ],
      ));
      out.add(const SizedBox(height: 12));
    }

    if (commonSpecs.isNotEmpty) {
      out.add(_Group(
        icon: Icons.shield_moon_outlined,
        title: th ? 'ตัวช่วยอัตโนมัติ' : 'Automatic helpers',
        subtitle: th
            ? 'สวิตช์ที่ใช้ได้กับทุกกลยุทธ์'
            : 'Switches that work with every strategy',
        children: [
          for (int i = 0; i < commonSpecs.length; i++) ...[
            if (i > 0) const SizedBox(height: 10),
            _buildParamField(commonSpecs[i], th, accent),
          ],
        ],
      ));
      out.add(const SizedBox(height: 12));
    }

    return out;
  }

  Widget _buildParamField(_ParamSpec spec, bool th, AccentProvider accent) {
    final label = th ? spec.label : (spec.labelEn ?? spec.label);
    final help = _paramHelp(spec.key, th);

    switch (spec.type) {
      case 'number':
        final ctrl = _paramCtrls[spec.key];
        if (ctrl == null) return const SizedBox.shrink();
        final err = _numberError(ctrl, spec.min, spec.max, th);
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _FieldLabel(
              label: label,
              help: help ?? _rangeHint(spec.min, spec.max, th),
            ),
            const SizedBox(height: 7),
            _NumberField(
              controller: ctrl,
              enabled: !_submitting,
              error: err,
              accent: accent,
              onChanged: () => setState(() {}),
            ),
          ],
        );

      case 'select':
        final current = jsonStr(_params[spec.key]) ?? spec.stringDefault;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _FieldLabel(label: label, help: help),
            const SizedBox(height: 8),
            Wrap(
              spacing: 7,
              runSpacing: 7,
              children: [
                for (final opt in spec.options)
                  _ChoiceChip(
                    label: opt,
                    selected: opt == current,
                    mono: false,
                    onTap: _submitting
                        ? null
                        : () => setState(() => _params[spec.key] = opt),
                  ),
              ],
            ),
          ],
        );

      default: // bool
        return _SwitchRow(
          label: label,
          help: help,
          value: _params[spec.key] == true,
          enabled: !_submitting,
          onChanged: (v) => setState(() => _params[spec.key] = v),
        );
    }
  }

  /// คำอธิบายสั้นๆ ของสวิตช์ร่วม (เซิร์ฟเวอร์ส่งมาแค่ป้ายชื่อ)
  String? _paramHelp(String key, bool th) {
    switch (key) {
      case 'news_filter':
        return th
            ? 'มีข่าวแรงเมื่อไหร่ บอทจะพักมือชั่วคราว แทนที่จะวิ่งเข้าใส่'
            : 'When heavy news lands, the bot sits out instead of charging in';
      case 'auto_pair':
        return th
            ? 'ให้ AI คัดเหรียญที่น่าเข้าที่สุดในรอบนั้นแทนคู่ที่เลือกไว้'
            : 'Let the AI pick the most promising coin each round instead of your pair';
      default:
        return null;
    }
  }

  String? _rangeHint(double? min, double? max, bool th) {
    if (min == null && max == null) return null;
    final lo = min == null ? '' : formatNumberText(min);
    final hi = max == null ? '' : formatNumberText(max);
    if (min != null && max != null) {
      return th ? 'ใส่ได้ระหว่าง $lo – $hi' : 'Allowed range $lo – $hi';
    }
    return th
        ? (min != null ? 'อย่างน้อย $lo' : 'ไม่เกิน $hi')
        : (min != null ? 'At least $lo' : 'At most $hi');
  }

  // ── กลุ่ม 5: กรอบความเสี่ยง ───────────────────────────
  Widget _buildRiskGroup(bool th, AccentProvider accent) {
    final riskErr = _fieldErrors['risk'];
    final cap = widget.planCapitalCap;
    final capActive = cap != null && cap > 0;
    final typedCap =
        double.tryParse(_riskCtrls['max_position_usd']?.text.trim() ?? '');

    // ค่าที่ผู้ใช้เคยขอ vs ค่าที่แพลนบีบไว้จริง (มาจากบอทเดิม)
    final existingRisk = jsonMap(widget.existing?['risk']);
    final requested = jsonDouble(existingRisk['max_position_usd_requested']);
    final applied = jsonDouble(existingRisk['max_position_usd']);
    final wasClamped = requested != null &&
        applied != null &&
        (requested - applied).abs() > 0.0001;

    return _Group(
      icon: Icons.security_rounded,
      title: th ? 'กรอบความเสี่ยง' : 'Risk limits',
      subtitle: th
          ? 'เพดานที่บอทห้ามข้าม — เซิร์ฟเวอร์จะบีบให้อยู่ในกรอบของแพลนเสมอ'
          : 'Ceilings the bot may not cross — the server always clamps them to your plan',
      children: [
        for (int i = 0; i < _riskFields.length; i++) ...[
          if (i > 0) const SizedBox(height: 14),
          _buildRiskField(_riskFields[i], th, accent),
        ],
        if (capActive && typedCap != null && typedCap > cap) ...[
          const SizedBox(height: 10),
          _NoteLine(
            icon: Icons.info_outline_rounded,
            text: th
                ? 'แพลนของคุณจำกัดทุนต่อไม้ไว้ที่ \$${formatNumberText(cap)} — ค่าที่เกินจะถูกบีบลงอัตโนมัติ'
                : 'Your plan caps capital per trade at \$${formatNumberText(cap)} — anything above is clamped',
          ),
        ],
        if (wasClamped) ...[
          const SizedBox(height: 10),
          _NoteLine(
            icon: Icons.compress_rounded,
            text: th
                ? 'ครั้งก่อนคุณตั้งไว้ \$${formatNumberText(requested)} แต่แพลนอนุญาตจริง \$${formatNumberText(applied)}'
                : 'You asked for \$${formatNumberText(requested)} last time; your plan allowed \$${formatNumberText(applied)}',
          ),
        ],
        if (riskErr != null) ...[
          const SizedBox(height: 10),
          _ErrorLine(text: riskErr),
        ],
      ],
    );
  }

  Widget _buildRiskField(_RiskField f, bool th, AccentProvider accent) {
    final ctrl = _riskCtrls[f.key];
    if (ctrl == null) return const SizedBox.shrink();
    final min = _limitMin(f.key, f.fallbackMin);
    final max = _limitMax(f.key, f.fallbackMax);
    final err = _numberError(ctrl, min, max, th);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _FieldLabel(
          label: th ? f.labelTh : f.labelEn,
          help: th ? f.helpTh : f.helpEn,
        ),
        const SizedBox(height: 7),
        _NumberField(
          controller: ctrl,
          enabled: !_submitting,
          error: err,
          suffix: f.suffix,
          accent: accent,
          onChanged: () => setState(() {}),
        ),
        const SizedBox(height: 4),
        Text(
          th
              ? 'ใส่ได้ระหว่าง ${formatNumberText(min)} – ${formatNumberText(max)}'
              : 'Allowed range ${formatNumberText(min)} – ${formatNumberText(max)}',
          style: AppTheme.mono(
            fontSize: 10,
            fontWeight: FontWeight.w500,
            color: AppColors.textTertiary,
          ),
        ),
      ],
    );
  }

  // ── ท้ายฟอร์ม ─────────────────────────────────────────
  Widget _buildFooter(bool th, AccentProvider accent) {
    return Container(
      padding: const EdgeInsets.fromLTRB(18, 12, 18, 16),
      decoration: const BoxDecoration(
        border: Border(
          top: BorderSide(color: AppColors.bgCardBorder, width: 1),
        ),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (_formError != null) ...[
            _ErrorBanner(text: _formError!),
            const SizedBox(height: 10),
          ],
          if (!_isEditing) ...[
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(Icons.play_circle_outline_rounded,
                    size: 13, color: accent.g2),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    th
                        ? 'บอทที่สร้างใหม่จะอยู่ในสถานะ "พัก" — กดเริ่มอีกครั้งถึงจะเดิน'
                        : 'A new bot starts paused — hit Start to run it',
                    style: GoogleFonts.inter(
                      fontSize: 10.5,
                      height: 1.3,
                      color: AppColors.textTertiary,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
          ],
          GradientButton(
            text: _isEditing
                ? (th ? 'บันทึกการแก้ไข' : 'Save changes')
                : (th ? 'สร้างบอท' : 'Create bot'),
            icon: _isEditing ? Icons.check_rounded : Icons.rocket_launch_rounded,
            variant: ButtonVariant.gold,
            isLoading: _submitting,
            onPressed: _submitting || !_formIsValid ? null : _submit,
          ),
          const SizedBox(height: 6),
          Center(
            child: GestureDetector(
              onTap: _submitting ? null : () => Navigator.pop(context, false),
              behavior: HitTestBehavior.opaque,
              child: Padding(
                padding: const EdgeInsets.all(8),
                child: Text(
                  th ? 'ยกเลิก' : 'Cancel',
                  style: GoogleFonts.inter(
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textTertiary,
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  InputDecoration _inputDecoration(
    AccentProvider accent, {
    String? hint,
    String? error,
    bool counter = false,
  }) {
    OutlineInputBorder border(Color c, double w) => OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: c, width: w),
        );

    return InputDecoration(
      isDense: true,
      filled: true,
      fillColor: AppColors.bgInput,
      hintText: hint,
      hintStyle: GoogleFonts.inter(
        color: AppColors.textDisabled,
        fontSize: 13.5,
      ),
      errorText: error,
      errorStyle: GoogleFonts.inter(
        fontSize: 10.5,
        color: AppColors.tradingRed,
      ),
      counterText: counter ? null : '',
      counterStyle: GoogleFonts.inter(
        fontSize: 10,
        color: AppColors.textTertiary,
      ),
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
      border: border(AppColors.bgCardBorder, 1),
      enabledBorder: border(AppColors.bgCardBorder, 1),
      focusedBorder: border(accent.g2, 1.5),
      errorBorder: border(AppColors.tradingRed, 1),
      focusedErrorBorder: border(AppColors.tradingRed, 1.5),
    );
  }
}

// ─────────────────────────────────────────────────────────
// สเปกพารามิเตอร์ / กรอบความเสี่ยง
// ─────────────────────────────────────────────────────────

class _ParamSpec {
  final String key;
  final String label;
  final String? labelEn;
  final String type; // number | bool | select
  final dynamic rawDefault;
  final double? min;
  final double? max;
  final List<String> options;
  final bool fromStrategy;

  const _ParamSpec({
    required this.key,
    required this.label,
    required this.labelEn,
    required this.type,
    required this.rawDefault,
    required this.min,
    required this.max,
    required this.options,
    required this.fromStrategy,
  });

  static _ParamSpec? from(Map<String, dynamic> raw, {required bool fromStrategy}) {
    final key = jsonStr(raw['key']);
    if (key == null) return null;
    final type = jsonStr(raw['type']) ?? 'number';
    return _ParamSpec(
      key: key,
      label: jsonStr(raw['label']) ?? key,
      labelEn: jsonStr(raw['label_en']),
      type: type,
      rawDefault: raw['default'],
      min: jsonDouble(raw['min']),
      max: jsonDouble(raw['max']),
      options: jsonStrList(raw['options']),
      fromStrategy: fromStrategy,
    );
  }

  double get numDefault => jsonDouble(rawDefault) ?? min ?? 0;
  bool get boolDefault => jsonBool(rawDefault);
  String get stringDefault =>
      jsonStr(rawDefault) ?? (options.isNotEmpty ? options.first : '');
}

class _RiskField {
  final String key;
  final String labelTh;
  final String labelEn;
  final String helpTh;
  final String helpEn;
  final String suffix;
  final double fallbackMin;
  final double fallbackMax;
  final double fallbackDefault;

  const _RiskField({
    required this.key,
    required this.labelTh,
    required this.labelEn,
    required this.helpTh,
    required this.helpEn,
    required this.suffix,
    required this.fallbackMin,
    required this.fallbackMax,
    required this.fallbackDefault,
  });
}

const List<_RiskField> _riskFields = [
  _RiskField(
    key: 'max_position_usd',
    labelTh: 'ทุนสูงสุดต่อไม้',
    labelEn: 'Max capital per trade',
    helpTh: 'เงินมากสุดที่บอทใช้เปิดหนึ่งไม้',
    helpEn: 'The most the bot spends opening one position',
    suffix: 'USD',
    fallbackMin: 10,
    fallbackMax: 1000000,
    fallbackDefault: 100,
  ),
  _RiskField(
    key: 'stop_loss_pct',
    labelTh: 'ตัดขาดทุน',
    labelEn: 'Stop loss',
    helpTh: 'ขาดทุนถึงกี่เปอร์เซ็นต์ให้ตัดทิ้ง',
    helpEn: 'Cut the trade once it is down this much',
    suffix: '%',
    fallbackMin: 0.5,
    fallbackMax: 50,
    fallbackDefault: 5,
  ),
  _RiskField(
    key: 'take_profit_pct',
    labelTh: 'ทำกำไร',
    labelEn: 'Take profit',
    helpTh: 'กำไรถึงกี่เปอร์เซ็นต์ให้ปิดไม้',
    helpEn: 'Close the trade once it is up this much',
    suffix: '%',
    fallbackMin: 0.5,
    fallbackMax: 200,
    fallbackDefault: 10,
  ),
  _RiskField(
    key: 'max_daily_loss_usd',
    labelTh: 'ขาดทุนสูงสุดต่อวัน',
    labelEn: 'Max daily loss',
    helpTh: 'ถึงเพดานนี้แล้วบอทหยุดเทรดของวันนั้น',
    helpEn: 'The bot stops trading for the day once it hits this',
    suffix: 'USD',
    fallbackMin: 5,
    fallbackMax: 1000000,
    fallbackDefault: 50,
  ),
];

/// ข้อความระดับแพลน — คืนสตริงว่างเมื่อไม่รู้จัก (เว็บเคยส่ง undefined เข้าตัวแปล
/// แล้วทั้งหน้ากลายเป็นจอว่าง)
String _tierText(String? tier, bool th) {
  switch (tier) {
    case 'basic':
      return th ? 'ทุกแพลน' : 'All plans';
    case 'pro':
      return th ? 'Pro ขึ้นไป' : 'Pro and up';
    case 'vip':
      return th ? 'VIP เท่านั้น' : 'VIP only';
    default:
      return '';
  }
}

/// ตัดศูนย์ท้ายทศนิยมทิ้ง — 5.00 → 5, 1.50 → 1.5
String formatNumberText(double v) {
  if (v == v.roundToDouble() && v.abs() < 1e15) {
    return v.toStringAsFixed(0);
  }
  var s = v.toStringAsFixed(6);
  s = s.replaceFirst(RegExp(r'0+$'), '');
  if (s.endsWith('.')) s = s.substring(0, s.length - 1);
  return s;
}

// ─────────────────────────────────────────────────────────
// ชิ้นส่วน UI
// ─────────────────────────────────────────────────────────

/// กล่องกลุ่มหนึ่งกลุ่มพร้อมหัวข้อ
class _Group extends StatelessWidget {
  final IconData icon;
  final String title;
  final String subtitle;
  final List<Widget> children;

  const _Group({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.children,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return GlassCard(
      variant: GlassVariant.standard,
      borderRadius: 16,
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
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
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: GoogleFonts.inter(
                        fontSize: 14.5,
                        fontWeight: FontWeight.w700,
                        color: AppColors.textPrimary,
                        letterSpacing: -0.2,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      subtitle,
                      style: GoogleFonts.inter(
                        fontSize: 10.5,
                        height: 1.3,
                        color: AppColors.textTertiary,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          ...children,
        ],
      ),
    );
  }
}

/// ป้ายกำกับช่อง + คำอธิบายสั้นๆ
class _FieldLabel extends StatelessWidget {
  final String label;
  final String? help;

  const _FieldLabel({required this.label, this.help});

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: GoogleFonts.inter(
            fontSize: 12.5,
            fontWeight: FontWeight.w700,
            color: AppColors.textPrimary,
          ),
        ),
        if (help != null) ...[
          const SizedBox(height: 2),
          Text(
            help!,
            style: GoogleFonts.inter(
              fontSize: 10.5,
              height: 1.3,
              color: AppColors.textTertiary,
            ),
          ),
        ],
      ],
    );
  }
}

/// ช่องกรอกตัวเลขพร้อมหน่วยท้ายช่อง
class _NumberField extends StatelessWidget {
  final TextEditingController controller;
  final bool enabled;
  final String? error;
  final String? suffix;
  final AccentProvider accent;
  final VoidCallback onChanged;

  const _NumberField({
    required this.controller,
    required this.enabled,
    required this.error,
    required this.accent,
    required this.onChanged,
    this.suffix,
  });

  @override
  Widget build(BuildContext context) {
    final hasError = error != null;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          decoration: BoxDecoration(
            color: AppColors.bgInput,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(
              color: hasError ? AppColors.tradingRed : AppColors.bgCardBorder,
              width: hasError ? 1.4 : 1,
            ),
          ),
          padding: const EdgeInsets.symmetric(horizontal: 14),
          child: Row(
            children: [
              Expanded(
                child: TextField(
                  controller: controller,
                  enabled: enabled,
                  keyboardType:
                      const TextInputType.numberWithOptions(decimal: true),
                  inputFormatters: [
                    FilteringTextInputFormatter.allow(RegExp(r'[0-9.]')),
                  ],
                  onChanged: (_) => onChanged(),
                  style: AppTheme.mono(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: enabled
                        ? AppColors.textPrimary
                        : AppColors.textDisabled,
                  ),
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
              if (suffix != null) ...[
                const SizedBox(width: 8),
                Text(
                  suffix!,
                  style: GoogleFonts.inter(
                    fontSize: 11.5,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textSecondary,
                  ),
                ),
              ],
            ],
          ),
        ),
        if (hasError) ...[
          const SizedBox(height: 5),
          _ErrorLine(text: error!),
        ],
      ],
    );
  }
}

/// ช่องเลือกคู่เทรด (กดเปิด picker)
class _PairField extends StatelessWidget {
  final String pair;
  final bool locked;
  final String? error;
  final VoidCallback onTap;

  const _PairField({
    required this.pair,
    required this.locked,
    required this.error,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final hasError = error != null;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Material(
          color: Colors.transparent,
          child: InkWell(
            onTap: locked ? null : onTap,
            borderRadius: BorderRadius.circular(12),
            child: Ink(
              padding:
                  const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
              decoration: BoxDecoration(
                color: AppColors.bgInput,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(
                  color:
                      hasError ? AppColors.tradingRed : AppColors.bgCardBorder,
                  width: hasError ? 1.4 : 1,
                ),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      pair,
                      style: AppTheme.mono(
                        fontSize: 14,
                        fontWeight: FontWeight.w700,
                        color: locked
                            ? AppColors.textSecondary
                            : AppColors.textPrimary,
                      ),
                    ),
                  ),
                  Icon(
                    locked
                        ? Icons.lock_outline_rounded
                        : Icons.expand_more_rounded,
                    size: 18,
                    color: AppColors.textTertiary,
                  ),
                ],
              ),
            ),
          ),
        ),
        if (hasError) ...[
          const SizedBox(height: 5),
          _ErrorLine(text: error!),
        ],
      ],
    );
  }
}

/// ชิปเลือกค่าเดียว (กรอบเวลา / ตัวเลือกของกลยุทธ์)
class _ChoiceChip extends StatelessWidget {
  final String label;
  final bool selected;
  final bool mono;
  final VoidCallback? onTap;

  const _ChoiceChip({
    required this.label,
    required this.selected,
    required this.mono,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(10),
        child: Ink(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
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
            style: mono
                ? AppTheme.mono(
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: selected
                        ? AppColors.goldTextOn
                        : AppColors.textSecondary,
                  )
                : GoogleFonts.inter(
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: selected
                        ? AppColors.goldTextOn
                        : AppColors.textSecondary,
                  ),
          ),
        ),
      ),
    );
  }
}

/// แถวสวิตช์เปิด/ปิดพร้อมคำอธิบาย
class _SwitchRow extends StatelessWidget {
  final String label;
  final String? help;
  final bool value;
  final bool enabled;
  final ValueChanged<bool> onChanged;

  const _SwitchRow({
    required this.label,
    required this.help,
    required this.value,
    required this.enabled,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 8, 6, 8),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(12),
        color: AppColors.bgInputStrong,
        border: Border.all(color: AppColors.bgCardBorder, width: 1),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: GoogleFonts.inter(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
                if (help != null) ...[
                  const SizedBox(height: 2),
                  Text(
                    help!,
                    style: GoogleFonts.inter(
                      fontSize: 10.5,
                      height: 1.3,
                      color: AppColors.textTertiary,
                    ),
                  ),
                ],
              ],
            ),
          ),
          Switch(
            value: value,
            activeThumbColor: AppColors.goldTextOn,
            activeTrackColor: accent.g2,
            inactiveThumbColor: AppColors.textSecondary,
            inactiveTrackColor: AppColors.bgTertiary,
            onChanged: enabled ? onChanged : null,
          ),
        ],
      ),
    );
  }
}

/// การ์ดกลยุทธ์หนึ่งใบ
class _StrategyTile extends StatelessWidget {
  final Map<String, dynamic> data;
  final bool th;
  final bool selected;
  final bool selectable;
  final VoidCallback onTap;
  final VoidCallback onBlocked;

  const _StrategyTile({
    required this.data,
    required this.th,
    required this.selected,
    required this.selectable,
    required this.onTap,
    required this.onBlocked,
  });

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();

    final name = (th ? jsonStr(data['name_th']) : jsonStr(data['name'])) ??
        jsonStr(data['name']) ??
        jsonStr(data['code']) ??
        '—';
    final desc = (th
            ? jsonStr(data['description_th'])
            : jsonStr(data['description'])) ??
        jsonStr(data['description']) ??
        '';
    final runnable = data['available'] != false;
    final tier = _tierText(jsonStr(data['tier']), th);
    final riskLevel = jsonStr(data['risk']) ?? 'medium';

    return Opacity(
      opacity: selectable ? 1 : 0.5,
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: selectable ? onTap : onBlocked,
          borderRadius: BorderRadius.circular(14),
          child: Ink(
            height: 128,
            padding: const EdgeInsets.fromLTRB(11, 10, 11, 10),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              color: selected ? accent.goldTint : AppColors.bgInputStrong,
              border: Border.all(
                color: selected ? accent.goldBorder : AppColors.bgCardBorder,
                width: selected ? 1.4 : 1,
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: GoogleFonts.inter(
                          fontSize: 12.5,
                          fontWeight: FontWeight.w700,
                          color: AppColors.textPrimary,
                        ),
                      ),
                    ),
                    if (selected)
                      Icon(Icons.check_circle_rounded,
                          size: 14, color: accent.g2)
                    else if (!selectable)
                      const Icon(Icons.lock_outline_rounded,
                          size: 13, color: AppColors.textTertiary),
                  ],
                ),
                const SizedBox(height: 5),
                // ป้าย "ยังไม่เปิด" สำคัญกว่าป้ายระดับแพลน — อัปเกรดก็ยังใช้ไม่ได้
                if (!runnable)
                  _TinyTag(
                    text: th ? 'ยังไม่เปิด' : 'Not live',
                    color: AppColors.tradingRed,
                  )
                else if (tier.isNotEmpty)
                  _TinyTag(text: tier, color: accent.g2),
                const SizedBox(height: 6),
                Expanded(
                  child: Text(
                    desc,
                    maxLines: 3,
                    overflow: TextOverflow.ellipsis,
                    style: GoogleFonts.inter(
                      fontSize: 10,
                      height: 1.32,
                      color: AppColors.textSecondary,
                    ),
                  ),
                ),
                Text(
                  _riskLabel(riskLevel, th),
                  style: GoogleFonts.inter(
                    fontSize: 9.5,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textTertiary,
                    letterSpacing: 0.4,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  String _riskLabel(String level, bool th) {
    switch (level) {
      case 'low':
        return th ? 'เสี่ยงต่ำ' : 'Low risk';
      case 'high':
        return th ? 'เสี่ยงสูง' : 'High risk';
      default:
        return th ? 'เสี่ยงกลาง' : 'Medium risk';
    }
  }
}

class _TinyTag extends StatelessWidget {
  final String text;
  final Color color;

  const _TinyTag({required this.text, required this.color});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: color.withValues(alpha: 0.14),
        border: Border.all(color: color.withValues(alpha: 0.32), width: 1),
      ),
      child: Text(
        text,
        style: GoogleFonts.inter(
          fontSize: 8.5,
          fontWeight: FontWeight.w700,
          color: color,
          letterSpacing: 0.5,
        ),
      ),
    );
  }
}

/// บรรทัดอธิบายเพิ่มเติม (โทนทอง)
class _NoteLine extends StatelessWidget {
  final IconData icon;
  final String text;

  const _NoteLine({required this.icon, required this.text});

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        color: accent.goldTint,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: accent.goldBorder, width: 1),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 13, color: accent.g2),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              text,
              style: GoogleFonts.inter(
                fontSize: 10.5,
                height: 1.35,
                color: AppColors.textSecondary,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// บรรทัดข้อผิดพลาดใต้ช่อง
class _ErrorLine extends StatelessWidget {
  final String text;

  const _ErrorLine({required this.text});

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Icon(Icons.error_outline_rounded,
            size: 12, color: AppColors.tradingRed),
        const SizedBox(width: 5),
        Expanded(
          child: Text(
            text,
            style: GoogleFonts.inter(
              fontSize: 10.5,
              height: 1.3,
              color: AppColors.tradingRed,
            ),
          ),
        ),
      ],
    );
  }
}

/// แถบข้อผิดพลาดรวมเหนือปุ่มบันทึก
class _ErrorBanner extends StatelessWidget {
  final String text;

  const _ErrorBanner({required this.text});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: AppColors.tradingRedBg,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: AppColors.tradingRed.withValues(alpha: 0.3),
          width: 1,
        ),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.error_outline_rounded,
              size: 15, color: AppColors.tradingRed),
          const SizedBox(width: 9),
          Expanded(
            child: Text(
              text,
              style: GoogleFonts.inter(
                fontSize: 11.5,
                height: 1.35,
                color: AppColors.textPrimary,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────
// ตัวเลือกคู่เทรด
// ─────────────────────────────────────────────────────────

class _PairPickerSheet extends StatefulWidget {
  final List<String> pairs;
  final String selected;

  const _PairPickerSheet({required this.pairs, required this.selected});

  @override
  State<_PairPickerSheet> createState() => _PairPickerSheetState();
}

class _PairPickerSheetState extends State<_PairPickerSheet> {
  final TextEditingController _searchCtrl = TextEditingController();
  String _query = '';

  @override
  void dispose() {
    _searchCtrl.dispose();
    super.dispose();
  }

  List<String> get _filtered {
    if (_query.isEmpty) return widget.pairs;
    final q = _query.toUpperCase();
    return widget.pairs.where((p) => p.toUpperCase().contains(q)).toList();
  }

  @override
  Widget build(BuildContext context) {
    final accent = context.watch<AccentProvider>();
    final th = context.watch<LocaleProvider>().isThai;
    final items = _filtered;

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: ClipRRect(
        borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
        child: BackdropFilter(
          filter: ImageFilter.blur(sigmaX: 22, sigmaY: 22),
          child: Container(
            constraints: BoxConstraints(
              maxHeight: MediaQuery.of(context).size.height * 0.7,
            ),
            decoration: BoxDecoration(
              gradient: const LinearGradient(
                colors: [Color(0xF21A1C24), Color(0xF20E0F14)],
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
              ),
              borderRadius:
                  const BorderRadius.vertical(top: Radius.circular(24)),
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
                  margin: const EdgeInsets.only(top: 12, bottom: 14),
                  decoration: BoxDecoration(
                    gradient: accent.goldGradient,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(18, 0, 18, 12),
                  child: TextField(
                    controller: _searchCtrl,
                    autofocus: false,
                    onChanged: (v) => setState(() => _query = v.trim()),
                    style: GoogleFonts.inter(
                      color: AppColors.textPrimary,
                      fontSize: 14,
                    ),
                    cursorColor: accent.g2,
                    decoration: InputDecoration(
                      isDense: true,
                      filled: true,
                      fillColor: AppColors.bgInput,
                      hintText: th ? 'ค้นหาคู่เทรด' : 'Search pairs',
                      hintStyle: GoogleFonts.inter(
                        color: AppColors.textTertiary,
                        fontSize: 14,
                      ),
                      prefixIcon:
                          Icon(Icons.search_rounded, color: accent.g2, size: 20),
                      contentPadding: const EdgeInsets.symmetric(
                          horizontal: 16, vertical: 14),
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(14),
                        borderSide:
                            const BorderSide(color: AppColors.bgCardBorder),
                      ),
                      enabledBorder: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(14),
                        borderSide:
                            const BorderSide(color: AppColors.bgCardBorder),
                      ),
                      focusedBorder: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(14),
                        borderSide: BorderSide(color: accent.g2, width: 1.5),
                      ),
                    ),
                  ),
                ),
                Flexible(
                  child: items.isEmpty
                      ? Padding(
                          padding: const EdgeInsets.fromLTRB(18, 24, 18, 40),
                          child: Column(
                            children: [
                              const Icon(Icons.search_off_rounded,
                                  color: AppColors.textTertiary, size: 40),
                              const SizedBox(height: 10),
                              Text(
                                th
                                    ? 'ไม่พบคู่เทรดที่ค้นหา'
                                    : 'No pair matches that search',
                                style: GoogleFonts.inter(
                                  fontSize: 13,
                                  color: AppColors.textTertiary,
                                ),
                              ),
                            ],
                          ),
                        )
                      : ListView.builder(
                          padding: const EdgeInsets.fromLTRB(18, 0, 18, 24),
                          itemCount: items.length,
                          itemBuilder: (_, i) {
                            final p = items[i].toUpperCase();
                            final isSel =
                                p == widget.selected.toUpperCase();
                            return Padding(
                              padding: const EdgeInsets.only(bottom: 8),
                              child: Material(
                                color: Colors.transparent,
                                child: InkWell(
                                  onTap: () => Navigator.pop(context, p),
                                  borderRadius: BorderRadius.circular(14),
                                  child: Ink(
                                    padding: const EdgeInsets.symmetric(
                                        horizontal: 14, vertical: 13),
                                    decoration: BoxDecoration(
                                      borderRadius: BorderRadius.circular(14),
                                      color: isSel
                                          ? accent.goldTint
                                          : AppColors.bgInputStrong,
                                      border: Border.all(
                                        color: isSel
                                            ? accent.goldBorder
                                            : AppColors.bgCardBorder,
                                        width: isSel ? 1.2 : 1,
                                      ),
                                    ),
                                    child: Row(
                                      children: [
                                        Expanded(
                                          child: Text(
                                            p,
                                            style: AppTheme.mono(
                                              fontSize: 13.5,
                                              fontWeight: FontWeight.w700,
                                              color: AppColors.textPrimary,
                                            ),
                                          ),
                                        ),
                                        if (isSel)
                                          Icon(Icons.check_rounded,
                                              size: 17, color: accent.g2),
                                      ],
                                    ),
                                  ),
                                ),
                              ),
                            );
                          },
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
