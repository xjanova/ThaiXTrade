/// TPIX TRADE — Accent / Metal-Finish Provider
/// Runtime-switchable gold tone (Champagne / Platinum / Rose Gold) plus
/// ambient "gold fireflies" preferences. This is the single source of truth
/// for the gilded-metal accent — swapping the tone re-skins every gold
/// surface that reads from here (mirrors the design's "swap 6 CSS vars").
///
/// Persisted with shared_preferences (same pattern as LocaleProvider).
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// The three "Metal Finish" tones from the design handoff.
enum MetalTone { champagneGold, platinum, roseGold }

/// Immutable palette for one metal tone (the 6 gold "vars" + label).
class MetalPalette {
  final String id; // stable key for persistence
  final String label; // English display label
  final String labelTh; // Thai display label
  final Color g1; // highlight
  final Color g2; // mid / icon stroke
  final Color g3; // deep
  final Color border; // gline
  final Color glow; // gglow

  const MetalPalette({
    required this.id,
    required this.label,
    required this.labelTh,
    required this.g1,
    required this.g2,
    required this.g3,
    required this.border,
    required this.glow,
  });

  /// 135deg highlight → mid → deep gradient (the gilded surface fill).
  LinearGradient get gradient => LinearGradient(
        colors: [g1, g2, g3],
        stops: const [0.0, 0.42, 1.0],
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
      );

  /// 12% wash of the mid tone — used for tinted icon tiles / pills.
  Color get tint => g2.withValues(alpha: 0.12);
}

/// Tone definitions — values taken verbatim from the design handoff.
const Map<MetalTone, MetalPalette> kMetalPalettes = {
  MetalTone.champagneGold: MetalPalette(
    id: 'champagne_gold',
    label: 'Champagne Gold',
    labelTh: 'แชมเปญโกลด์',
    g1: Color(0xFFFCEBB8),
    g2: Color(0xFFD4AF37),
    g3: Color(0xFF9C7A1E),
    border: Color(0x57D4AF37), // rgba(212,175,55,0.34)
    glow: Color(0x80F0D278), // rgba(240,210,120,0.5)
  ),
  MetalTone.platinum: MetalPalette(
    id: 'platinum',
    label: 'Platinum',
    labelTh: 'แพลทินัม',
    g1: Color(0xFFF2F5F9),
    g2: Color(0xFFB9C2CE),
    g3: Color(0xFF717A87),
    border: Color(0x66BEC8D6), // rgba(190,200,214,0.40)
    glow: Color(0x73DCE4EE), // rgba(220,228,238,0.45)
  ),
  MetalTone.roseGold: MetalPalette(
    id: 'rose_gold',
    label: 'Rose Gold',
    labelTh: 'โรสโกลด์',
    g1: Color(0xFFF8DBC6),
    g2: Color(0xFFD99E78),
    g3: Color(0xFFA4663F),
    border: Color(0x66D99E78), // rgba(217,158,120,0.40)
    glow: Color(0x80F3C8AA), // rgba(243,200,170,0.5)
  ),
};

class AccentProvider extends ChangeNotifier {
  static const String _kTone = 'accent_tone';
  static const String _kFireflies = 'accent_fireflies';
  static const String _kReduceMotion = 'accent_reduce_motion';

  MetalTone _tone = MetalTone.champagneGold;
  bool _showFireflies = true;
  bool _reduceMotion = false;
  int _fireflyDensity = 16; // dots per screen

  /// Completes once persisted prefs are loaded. Await in main() before the
  /// first frame so the saved tone is applied without a startup flash.
  late final Future<void> ready;

  AccentProvider() {
    ready = _load();
  }

  // ── Getters ────────────────────────────────────
  MetalTone get tone => _tone;
  MetalPalette get palette => kMetalPalettes[_tone]!;
  bool get showFireflies => _showFireflies;
  bool get reduceMotion => _reduceMotion;
  int get fireflyDensity => _fireflyDensity;

  /// Whether fireflies should actually animate (off if reduce-motion).
  bool get firefliesActive => _showFireflies;
  bool get animateFireflies => _showFireflies && !_reduceMotion;

  // Convenience pass-throughs to the active palette.
  Color get g1 => palette.g1;
  Color get g2 => palette.g2;
  Color get g3 => palette.g3;
  Color get goldBorder => palette.border;
  Color get goldGlow => palette.glow;
  Color get goldTint => palette.tint;
  LinearGradient get goldGradient => palette.gradient;

  // ── Mutations (persist + notify) ───────────────
  Future<void> setTone(MetalTone tone) async {
    if (_tone == tone) return;
    _tone = tone;
    notifyListeners();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kTone, kMetalPalettes[tone]!.id);
  }

  Future<void> setShowFireflies(bool value) async {
    if (_showFireflies == value) return;
    _showFireflies = value;
    notifyListeners();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kFireflies, value);
  }

  Future<void> setReduceMotion(bool value) async {
    if (_reduceMotion == value) return;
    _reduceMotion = value;
    notifyListeners();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kReduceMotion, value);
  }

  void setFireflyDensity(int value) {
    final clamped = value.clamp(0, 40).toInt();
    if (_fireflyDensity == clamped) return;
    _fireflyDensity = clamped;
    notifyListeners();
  }

  // ── Persistence ────────────────────────────────
  Future<void> _load() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final toneId = prefs.getString(_kTone);
      if (toneId != null) {
        _tone = kMetalPalettes.entries
            .firstWhere(
              (e) => e.value.id == toneId,
              orElse: () => kMetalPalettes.entries.first,
            )
            .key;
      }
      _showFireflies = prefs.getBool(_kFireflies) ?? true;
      _reduceMotion = prefs.getBool(_kReduceMotion) ?? false;
      notifyListeners();
    } catch (_) {
      // Defaults are valid — ignore load failures (first launch / no storage).
    }
  }
}
