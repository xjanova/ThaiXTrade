/// TPIX TRADE — คลิปพื้นหลังเคลื่อนไหวของธีม Obsidian
///
/// คลิปเฉพาะของแอปเทรด: พื้นหกเหลี่ยมนีออนแบบ Wallet แต่เส้นขอบฟ้าเป็นกราฟแท่งเทียน
/// (ภาพจาก ChatGPT → digen Real Motion 3.1 10 วิ → ตัดเป็นลูป 8.5 วิด้วย crossfade 1.5 วิ
///  ต้นฉบับ D:/Code/TPIX/design/tpix-trade-v2/video/) — ภาพนิ่ง = เฟรมแรกของคลิป
///
/// ต่างจาก Wallet ตรงที่ "ตัวเล่นเดียวใช้ร่วมกันทุกหน้า":
///   แอปเทรดเก็บหน้าในแถบเมนูค้างไว้พร้อมกัน (IndexedStack) และทุกหน้ามี
///   AppBackground ของตัวเอง ถ้าหน้าละตัวเล่น เครื่องจะถอดรหัสวิดีโอซ้อน 5–6 ชุด
///   → [ObsidianBackdropVideo] ถือ VideoPlayerController ตัวเดียว ทุกหน้าวาด
///     texture เดียวกัน (VideoPlayer หลายวิดเจ็ตชี้ตัวควบคุมเดียวกันได้)
///
/// กฎที่ต้องรักษา:
///   - เล่นเฉพาะตอนมีหน้าที่ "มองเห็นอยู่" ขอเล่น (TickerMode = หน้าที่อยู่หลังแท็บอื่นไม่นับ)
///   - แอปลงพื้นหลัง → หยุดทันที · กลับมาค่อยเล่นต่อ
///   - ลดภาพเคลื่อนไหว (ของเครื่องหรือในตั้งค่าแอป) → ไม่สร้างตัวเล่น ใช้ภาพนิ่ง
///   - ไม่มีหน้าไหนใช้แล้ว (สลับไปธีมอื่น) → dispose ตัวเล่น
///   - เล่นไม่ได้ (เครื่องไม่รองรับ / เทสต์) → ภาพนิ่งเงียบ ๆ ไม่ลองซ้ำ
///
/// Developed by Xman Studio
library;

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:video_player/video_player.dart';

import '../../providers/accent_provider.dart';

class ObsidianBackdropVideo extends ChangeNotifier with WidgetsBindingObserver {
  ObsidianBackdropVideo._();
  static final ObsidianBackdropVideo instance = ObsidianBackdropVideo._();

  static const videoAsset = 'assets/images/obsidian/bg_loop.mp4';
  static const posterAsset = 'assets/images/obsidian/bg_tech.jpg';

  /// เทสต์ปิดได้ (flutter test ไม่มีปลั๊กอินเล่นวิดีโอ)
  @visibleForTesting
  static bool enableVideo = true;

  VideoPlayerController? _controller;
  int _generation = 0;
  bool _failed = false;
  bool _firstFrame = false;
  bool _foreground = true;
  bool _observing = false;

  /// หน้าที่ติดตั้งอยู่ทั้งหมด / หน้าที่มองเห็นและขอเล่นอยู่
  final Set<Object> _mounted = {};
  final Set<Object> _playing = {};

  VideoPlayerController? get controller => _controller;

  /// พร้อมโชว์วิดีโอแทนภาพนิ่ง (เฟรมแรกขึ้นแล้ว)
  bool get showVideo =>
      _controller != null && _controller!.value.isInitialized && _firstFrame;

  void attach(Object owner) {
    _mounted.add(owner);
    if (!_observing) {
      WidgetsBinding.instance.addObserver(this);
      _observing = true;
      final s = WidgetsBinding.instance.lifecycleState;
      _foreground = s == null || s == AppLifecycleState.resumed;
    }
  }

  void detach(Object owner) {
    _mounted.remove(owner);
    _playing.remove(owner);
    if (_mounted.isEmpty) {
      _disposeController();
      if (_observing) {
        WidgetsBinding.instance.removeObserver(this);
        _observing = false;
      }
    } else {
      _sync();
    }
  }

  /// หน้า [owner] อยากให้คลิปเล่นไหม (มองเห็น + ไม่ได้ลดภาพเคลื่อนไหว)
  void setWantsPlay(Object owner, bool wants) {
    final changed = wants ? _playing.add(owner) : _playing.remove(owner);
    if (changed) _sync();
  }

  void _sync() {
    if (_playing.isEmpty) {
      _controller?.pause();
      return;
    }
    if (_controller == null) {
      _start();
    } else if (_controller!.value.isInitialized && _foreground) {
      _controller!.play();
    }
  }

  void _start() {
    if (!enableVideo || _failed || _controller != null) return;
    final gen = ++_generation;
    final v = VideoPlayerController.asset(
      videoAsset,
      // ไม่แย่งเสียงแอปเพลงของผู้ใช้ — คลิปนี้ไม่มีเสียงอยู่แล้ว
      videoPlayerOptions: VideoPlayerOptions(mixWithOthers: true),
    );
    _controller = v;
    v.addListener(_onTick);
    unawaited(_init(v, gen));
  }

  Future<void> _init(VideoPlayerController v, int gen) async {
    try {
      await v.initialize();
      if (gen != _generation) return;
      await v.setVolume(0);
      await v.setLooping(true);
      if (gen != _generation) return;
      if (_foreground && _playing.isNotEmpty) await v.play();
      notifyListeners();
    } catch (e) {
      // พื้นหลังเป็นของตกแต่ง ไม่ใช่ฟีเจอร์ — เล่นไม่ได้ก็ใช้ภาพนิ่งเงียบ ๆ
      debugPrint('ObsidianBackdropVideo unavailable: ${e.runtimeType}');
      if (gen == _generation) {
        _failed = true;
        _disposeController();
        notifyListeners();
      }
    }
  }

  void _onTick() {
    final v = _controller;
    if (v == null || _firstFrame) return;
    // ตำแหน่ง > 0 = เฟรมจริงขึ้นแล้ว (isInitialized อย่างเดียว แอนดรอยด์บางรุ่นยังจอดำ)
    if (v.value.isInitialized && v.value.isPlaying && v.value.position > Duration.zero) {
      _firstFrame = true;
      notifyListeners();
    }
  }

  void _disposeController() {
    _generation++;
    final v = _controller;
    _controller = null;
    _firstFrame = false;
    if (v != null) {
      v.removeListener(_onTick);
      unawaited(v.dispose());
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _foreground = state == AppLifecycleState.resumed;
    final v = _controller;
    if (v == null || !v.value.isInitialized) return;
    if (_foreground && _playing.isNotEmpty) {
      v.play();
    } else {
      v.pause(); // ลงพื้นหลัง → หยุดถอดรหัสทันที (ประหยัดแบต)
    }
  }
}

/// ชั้นภาพพื้นหลัง: ภาพนิ่งเสมอ + คลิปจางเข้ามาทับเมื่อเฟรมแรกขึ้น
class ObsidianBackdropLayer extends StatefulWidget {
  /// false = ภาพนิ่งตลอด (หน้าที่มีกราฟสด เช่นหน้าเทรด ไม่ควรแย่งเครื่องถอดรหัส)
  final bool animated;

  const ObsidianBackdropLayer({super.key, this.animated = true});

  @override
  State<ObsidianBackdropLayer> createState() => _ObsidianBackdropLayerState();
}

class _ObsidianBackdropLayerState extends State<ObsidianBackdropLayer> {
  final _video = ObsidianBackdropVideo.instance;

  @override
  void initState() {
    super.initState();
    _video.attach(this);
    _video.addListener(_onVideo);
  }

  void _onVideo() {
    if (mounted) setState(() {});
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _syncWants();
  }

  @override
  void didUpdateWidget(ObsidianBackdropLayer oldWidget) {
    super.didUpdateWidget(oldWidget);
    _syncWants();
  }

  void _syncWants() {
    final reduce = (MediaQuery.maybeDisableAnimationsOf(context) ?? false) ||
        (context.read<AccentProvider?>()?.reduceMotion ?? false);
    // TickerMode = false เมื่อหน้านี้อยู่หลังแท็บอื่นของ IndexedStack
    // TickerMode.of ใช้ได้ทั้ง Flutter ของ CI (3.38 ยังไม่มี valuesOf) และรุ่นใหม่
    // ที่ประกาศ deprecated ไว้ — เปลี่ยนเป็น valuesOf เมื่อ CI ขยับรุ่นแล้ว
    // ignore: deprecated_member_use
    final visible = TickerMode.of(context);
    _video.setWantsPlay(this, widget.animated && visible && !reduce);
  }

  @override
  void dispose() {
    _video.removeListener(_onVideo);
    _video.detach(this);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // ผูกค่า "ลดภาพเคลื่อนไหว" ของแอป — เปลี่ยนแล้วต้อง sync ใหม่
    context.select<AccentProvider?, bool>((a) => a?.reduceMotion ?? false);
    final c = _video.controller;
    final show = widget.animated && _video.showVideo && c != null;
    return Stack(
      fit: StackFit.expand,
      children: [
        Image.asset(
          ObsidianBackdropVideo.posterAsset,
          fit: BoxFit.cover,
          // ถอดรหัสแค่ขนาดไฟล์จริง (544 กว้าง) ไม่ขยายเต็มจอในแรม
          cacheWidth: 544,
          gaplessPlayback: true,
          errorBuilder: (_, _, _) => const SizedBox.shrink(),
        ),
        if (show)
          FittedBox(
            fit: BoxFit.cover,
            clipBehavior: Clip.hardEdge,
            child: SizedBox(
              width: c.value.size.width,
              height: c.value.size.height,
              child: VideoPlayer(c),
            ),
          ),
      ],
    );
  }
}
