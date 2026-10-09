/// TPIX TRADE — ไคลเอนต์ของหน้า "ยืนยันตัวตน" (`/api/v1/kyc/*`)
///
/// ยืนยันตัวตนในแอปโดยใช้ผล KYC ของบัญชี Thaiprompt (เหมือนหน้าเว็บ /kyc)
///   GET  /kyc/status               สถานะ + ด่านทุกฟีเจอร์ + การเชื่อม Thaiprompt
///   POST /kyc/thaiprompt/start     ขอ URL หน้าอนุญาตของ Thaiprompt (ต้องติ๊กยินยอม)
///   POST /kyc/thaiprompt/complete  แลก "รหัสรับผล" ที่กลับมาทาง tpixtrade://kyc
///   POST /kyc/thaiprompt/refresh   ถามผลซ้ำระหว่างผู้ใช้ทำ eKYC ในแอป Thaiprompt
///
/// กฎเดียวกับ `ai_bot_api.dart`: **ห้ามกลืนเหตุผล** ทุกคำขอคืน [ApiResult] ที่พก
/// code + message + status เสมอ — หน้าจอต้องบอกทางออกได้ ไม่ใช่ "ไม่สำเร็จ" เปล่าๆ
///
/// ทุกเส้นอยู่หลัง VerifyWalletOwnership ฝั่งเซิร์ฟเวอร์ → แนบหัว `X-Wallet-Session`
/// ทุกคำขอ และส่ง `wallet_address` ไปด้วยเสมอ (ตัวนับ throttle:trading นับต่อกระเป๋า)
///
/// ⚠️ รหัสรับผล (completion) แลกผล KYC ได้ภายใน 8 นาที — ห้าม log เด็ดขาด
///    ไฟล์นี้พิมพ์แค่ method + path + สถานะ HTTP ไม่พิมพ์ body/query ใดๆ
///
/// Developed by Xman Studio
library;

import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../core/constants/api_constants.dart';
import '../models/kyc_models.dart';
import 'api_result.dart';
import 'wallet_session.dart';

/// เส้นทางของกลุ่ม kyc (ไม่เขียน path ดิบตามที่เรียกใช้)
class KycEndpoints {
  KycEndpoints._();

  static const String status = '/kyc/status';
  static const String thaipromptStart = '/kyc/thaiprompt/start';
  static const String thaipromptComplete = '/kyc/thaiprompt/complete';
  static const String thaipromptRefresh = '/kyc/thaiprompt/refresh';
}

/// รหัสข้อผิดพลาดของหน้า KYC (ของเซิร์ฟเวอร์ + ที่แอปตั้งเอง)
///
/// ข้อความ 2 ภาษาอยู่ที่ `kyc.err.<CODE>` ใน locale_provider.dart
class KycErrorCodes {
  KycErrorCodes._();

  // ── เซิร์ฟเวอร์ ──
  /// เซิร์ฟเวอร์ยังไม่ได้ตั้งค่า Thaiprompt หรือติดต่อ Thaiprompt ไม่ได้ชั่วคราว
  static const String thaipromptUnavailable = 'THAIPROMPT_UNAVAILABLE';

  /// บัญชี Thaiprompt นี้ใช้ยืนยันกับบัญชี TPIX TRADE อื่นไปแล้ว
  static const String thaipromptTaken = 'THAIPROMPT_TAKEN';

  /// รหัสรับผลหมดอายุ/ไม่ถูกต้อง — ต้องกดยืนยันด้วย Thaiprompt ใหม่
  static const String thaipromptLinkFailed = 'THAIPROMPT_LINK_FAILED';

  /// สิทธิ์ที่ได้จาก Thaiprompt หมดอายุ — ต้องเชื่อมใหม่ ถามผลซ้ำไม่ช่วย
  static const String thaipromptReconnect = 'THAIPROMPT_RECONNECT';

  /// ยังไม่เคยเชื่อมบัญชี Thaiprompt (404 ของ refresh)
  static const String thaipromptNotLinked = 'THAIPROMPT_NOT_LINKED';

  static const String walletNotVerified = 'WALLET_NOT_VERIFIED';
  static const String walletIpMismatch = 'WALLET_IP_MISMATCH';

  // ── ฝั่งแอป ──
  static const String noWallet = 'NO_WALLET';
  static const String invalidWallet = 'INVALID_WALLET';

  /// รหัสรับผลจาก deep link ผิดรูป — ไม่ยิงเน็ตเลย
  static const String invalidCompletion = 'INVALID_COMPLETION';
  static const String network = 'NETWORK';
  static const String timeout = 'TIMEOUT';
  static const String cancelled = 'CANCELLED';
  static const String badPayload = 'BAD_PAYLOAD';
  static const String validation = 'VALIDATION_ERROR';
  static const String rateLimited = 'RATE_LIMITED';
  static const String unknown = 'UNKNOWN';
}

/// สถานะ KYC ที่เซิร์ฟเวอร์แนบมากับซองล้มเหลว — null เมื่อไม่มี
///
/// มีจริงเฉพาะ 422 ของ refresh (THAIPROMPT_RECONNECT / THAIPROMPT_UNAVAILABLE)
KycStatus? kycStatusAttachedTo(ApiErr err) {
  final payload = err.payload;
  return payload is KycStatus ? payload : null;
}

/// รูปแบบที่อยู่กระเป๋าที่เซิร์ฟเวอร์ยอมรับ
final RegExp _walletPattern = RegExp(r'^0x[a-fA-F0-9]{40}$');

class KycApi {
  static final KycApi _instance = KycApi._(null);

  /// ตัวเดียวทั้งแอป — ตัวนับถอยหลังเมื่อโดน 429 ต้องเป็นของจริงตัวเดียว
  factory KycApi() => _instance;

  /// สำหรับเทสต์ — ส่ง Dio ที่ต่อ adapter ปลอมไว้เข้ามาแทนการยิงเน็ตจริง
  @visibleForTesting
  KycApi.withDio(Dio dio) : this._(dio);

  KycApi._(Dio? dio) {
    _dio = dio ??
        Dio(BaseOptions(
          baseUrl: ApiConstants.baseUrl,
          connectTimeout: ApiConstants.timeout,
          receiveTimeout: ApiConstants.timeout,
          sendTimeout: ApiConstants.timeout,
          headers: const {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
          },
        ));

    // ไม่แนบหัวนี้ = 403 ทันทีที่แคชลายเซ็น 4 ชั่วโมงหมด ทั้งที่ผู้ใช้ถือโทเคน 30 วันอยู่
    _dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) {
        options.headers.addAll(WalletSession.headers());
        return handler.next(options);
      },
    ));
  }

  late final Dio _dio;

  /// เวลาที่ห้ามยิงจนกว่าจะถึง แยกรายเส้น
  ///
  /// แยกเพราะเซิร์ฟเวอร์ตั้งเพดานคนละตัวต่อเส้น (start 10 ครั้ง/10 นาที ·
  /// refresh 20 ครั้ง/นาที) — โดนที่ start แล้วไปปิด status ด้วยคือหน้าจอโหลดไม่ขึ้น
  /// ทั้งที่เส้นนั้นยังมีโควตาเหลือ
  final Map<String, DateTime> _cooldownUntil = {};

  // ══════════════════════════════════════════════════════════════
  //  คำขอ
  // ══════════════════════════════════════════════════════════════

  /// สถานะ KYC + ด่านทุกฟีเจอร์ + การเชื่อม Thaiprompt
  Future<ApiResult<KycStatus>> fetchStatus(String wallet) async {
    final guard = _walletGuard<KycStatus>(wallet);
    if (guard != null) return guard;
    final res = await _execute(
      'GET',
      KycEndpoints.status,
      query: {'wallet_address': _normalize(wallet)},
    );
    return _asStatus(res);
  }

  /// ขอ URL หน้าอนุญาตของ Thaiprompt — ต้องติ๊กยินยอมแล้วเท่านั้น
  ///
  /// คืน [Uri] ที่ผ่านการตรวจว่าเป็น https แล้ว (ปลายทางคือการเปิดเบราว์เซอร์
  /// ของระบบ ลิงก์ผิดรูปจากเซิร์ฟเวอร์ไม่ควรถูกส่งต่อให้ระบบเปิด)
  Future<ApiResult<Uri>> startThaiprompt(String wallet) async {
    final guard = _walletGuard<Uri>(wallet);
    if (guard != null) return guard;
    final res = await _execute(
      'POST',
      KycEndpoints.thaipromptStart,
      // ส่ง consent ทุกครั้ง — ปุ่มกดไม่ได้จนกว่าผู้ใช้จะติ๊กเอง
      body: {'wallet_address': _normalize(wallet), 'consent': true},
    );
    switch (res) {
      case ApiErr<Object?>():
        return _reshape<Uri>(res);
      case ApiOk<Object?>(:final data):
        final url = data is Map ? data['authorize_url']?.toString() : null;
        final uri = httpsUri(url);
        if (uri == null) {
          return const ApiErr<Uri>(
            KycErrorCodes.badPayload,
            'เซิร์ฟเวอร์ส่งลิงก์ยืนยันตัวตนที่เปิดไม่ได้',
          );
        }
        return ApiOk<Uri>(uri);
    }
  }

  /// แลกรหัสรับผลที่กลับมาทาง deep link — คืนสถานะ KYC ล่าสุด
  ///
  /// ตรวจรูปแบบก่อนยิง: รหัสผิดรูปยิงไปก็ได้ 422 กลับมา เสียโควตาฟรีๆ
  /// (เส้นนี้จำกัด 20 ครั้ง/10 นาที)
  Future<ApiResult<KycStatus>> completeThaiprompt({
    required String wallet,
    required String completion,
  }) async {
    final guard = _walletGuard<KycStatus>(wallet);
    if (guard != null) return guard;
    if (!KycReturnLink.completionPattern.hasMatch(completion)) {
      return const ApiErr<KycStatus>(
        KycErrorCodes.invalidCompletion,
        'ลิงก์ยืนยันตัวตนไม่ถูกต้อง',
      );
    }
    final res = await _execute(
      'POST',
      KycEndpoints.thaipromptComplete,
      body: {'wallet_address': _normalize(wallet), 'completion': completion},
    );
    return _asStatus(res);
  }

  /// ถามผลจาก Thaiprompt ซ้ำ — ใช้ตอนรอผู้ใช้ทำ eKYC ในแอป Thaiprompt
  ///
  /// เซิร์ฟเวอร์ไม่ถาม Thaiprompt ซ้ำถ้าเพิ่งถามไปไม่ถึง 10 วินาที (คืนค่าที่จำไว้)
  /// 422 THAIPROMPT_RECONNECT/UNAVAILABLE แนบสถานะล่าสุดมาด้วย → อ่านได้จาก
  /// [kycStatusAttachedTo]
  Future<ApiResult<KycStatus>> refreshThaiprompt(String wallet) async {
    final guard = _walletGuard<KycStatus>(wallet);
    if (guard != null) return guard;
    final res = await _execute(
      'POST',
      KycEndpoints.thaipromptRefresh,
      body: {'wallet_address': _normalize(wallet)},
    );
    return _asStatus(res);
  }

  // ══════════════════════════════════════════════════════════════
  //  ชั้นส่งคำขอ
  // ══════════════════════════════════════════════════════════════

  String _normalize(String wallet) => wallet.trim().toLowerCase();

  ApiErr<T>? _walletGuard<T>(String? wallet) {
    final w = wallet?.trim() ?? '';
    if (w.isEmpty) {
      return ApiErr<T>(KycErrorCodes.noWallet, 'กรุณาเชื่อมกระเป๋าก่อน');
    }
    if (!_walletPattern.hasMatch(w)) {
      return ApiErr<T>(
        KycErrorCodes.invalidWallet,
        'รูปแบบที่อยู่กระเป๋าไม่ถูกต้อง',
      );
    }
    return null;
  }

  Future<ApiResult<Object?>> _execute(
    String method,
    String path, {
    Map<String, dynamic>? query,
    Map<String, dynamic>? body,
  }) async {
    // ระหว่างที่เซิร์ฟเวอร์ยังสั่งให้รอ ยิงไปก็เสียโควตาเปล่า
    final until = _cooldownUntil[path];
    if (until != null) {
      final left = until.difference(DateTime.now());
      if (left > Duration.zero) {
        final secs = left.inSeconds + (left.inMilliseconds % 1000 > 0 ? 1 : 0);
        return ApiErr<Object?>(
          KycErrorCodes.rateLimited,
          'ส่งคำขอถี่เกินไป กรุณารออีก $secs วินาที',
          status: 429,
          retryAfterSeconds: secs,
        );
      }
      _cooldownUntil.remove(path);
    }

    try {
      final res = await _dio.request<dynamic>(
        path,
        queryParameters: query,
        data: body,
        options: Options(method: method),
      );

      final payload = res.data;
      if (payload is! Map) {
        return ApiErr<Object?>(
          KycErrorCodes.badPayload,
          'เซิร์ฟเวอร์ตอบรูปแบบที่อ่านไม่ได้',
          status: res.statusCode,
        );
      }

      final map = payload.map((k, v) => MapEntry(k.toString(), v));
      if (map['success'] != true) {
        return _errorFrom(map, res.statusCode, res.headers, path);
      }
      return ApiOk<Object?>(map['data']);
    } on DioException catch (e) {
      return _fromDioException(e, method, path);
    } catch (e) {
      debugPrint('[KycApi] $method $path: ${e.runtimeType}');
      return ApiErr<Object?>(KycErrorCodes.unknown, 'เกิดข้อผิดพลาดที่ไม่คาดคิด');
    }
  }

  ApiResult<Object?> _fromDioException(
    DioException e,
    String method,
    String path,
  ) {
    final status = e.response?.statusCode;
    // log เฉพาะสถานะ — body มีที่อยู่กระเป๋าและรหัสรับผลอยู่ในนั้น
    debugPrint('[KycApi] $method $path -> ${status ?? e.type.name}');

    if (status == 429) return _throttled(e.response?.headers, path);

    final data = e.response?.data;
    if (data is Map) {
      return _errorFrom(
        data.map((k, v) => MapEntry(k.toString(), v)),
        status,
        e.response?.headers,
        path,
      );
    }

    if (status == null) {
      if (e.type == DioExceptionType.cancel) {
        return ApiErr<Object?>(KycErrorCodes.cancelled, 'ยกเลิกคำขอแล้ว');
      }
      final timedOut = e.type == DioExceptionType.connectionTimeout ||
          e.type == DioExceptionType.sendTimeout ||
          e.type == DioExceptionType.receiveTimeout;
      return ApiErr<Object?>(
        timedOut ? KycErrorCodes.timeout : KycErrorCodes.network,
        timedOut ? 'เซิร์ฟเวอร์ตอบช้าเกินไป' : 'ต่ออินเทอร์เน็ตไม่ได้',
      );
    }

    return ApiErr<Object?>('HTTP_$status', 'เซิร์ฟเวอร์ตอบผิดพลาด', status: status);
  }

  /// อ่านซองความล้มเหลว 4 แบบที่เซิร์ฟเวอร์ส่งได้จริง (ชุดเดียวกับ AiBotApi)
  ApiResult<Object?> _errorFrom(
    Map<String, dynamic> map,
    int? status,
    Headers? headers,
    String path,
  ) {
    // แบบที่ 1 — ซองปกติ: {success:false, error:{code,message}, data?}
    final err = map['error'];
    if (err is Map) {
      final e = err.map((k, v) => MapEntry(k.toString(), v));
      return ApiErr<Object?>(
        _str(e['code']) ?? KycErrorCodes.unknown,
        _str(e['message']) ?? 'ทำรายการไม่สำเร็จ',
        status: status,
        // ของแถมของ refresh — พังก็แค่ไม่มี (tryParse ไม่ throw)
        payload: KycStatus.tryParse(map['data']),
      );
    }

    // แบบที่ 2 — validator ของ Laravel: {message, errors:{field:[...]}}
    final errors = map['errors'];
    if (errors is Map) {
      final fields = <String, List<String>>{};
      errors.forEach((key, value) {
        final list = <String>[];
        if (value is List) {
          for (final item in value) {
            final s = _str(item);
            if (s != null) list.add(s);
          }
        } else {
          final s = _str(value);
          if (s != null) list.add(s);
        }
        if (list.isNotEmpty) fields[key.toString()] = list;
      });
      return ApiErr<Object?>(
        KycErrorCodes.validation,
        _str(map['message']) ?? 'ข้อมูลที่ส่งไม่ถูกต้อง',
        status: status ?? 422,
        fieldErrors: fields,
      );
    }

    // แบบที่ 3 — throttle: {message: "Too Many Attempts."}
    if (status == 429) return _throttled(headers, path);

    // แบบที่ 4 — 404/500 ดิบของ Laravel: {message: "..."}
    return ApiErr<Object?>(
      status == null ? KycErrorCodes.unknown : 'HTTP_$status',
      _str(map['message']) ?? 'ทำรายการไม่สำเร็จ',
      status: status,
    );
  }

  ApiErr<Object?> _throttled(Headers? headers, String path) {
    final raw = headers?.value('retry-after');
    var secs = int.tryParse(raw?.trim() ?? '') ?? 30;
    if (secs <= 0) secs = 30;
    // กันค่าประหลาด — รอเกิน 5 นาทีไม่สมเหตุสมผลกับหน้าจอที่ผู้ใช้เปิดค้างอยู่
    if (secs > 300) secs = 300;
    _cooldownUntil[path] = DateTime.now().add(Duration(seconds: secs));
    return ApiErr<Object?>(
      KycErrorCodes.rateLimited,
      'ส่งคำขอถี่เกินไป กรุณารออีก $secs วินาที',
      status: 429,
      retryAfterSeconds: secs,
    );
  }

  ApiResult<KycStatus> _asStatus(ApiResult<Object?> res) {
    switch (res) {
      case ApiErr<Object?>():
        return _reshape<KycStatus>(res);
      case ApiOk<Object?>(:final data):
        if (data is! Map) {
          return const ApiErr<KycStatus>(
            KycErrorCodes.badPayload,
            'เซิร์ฟเวอร์ตอบรูปแบบที่อ่านไม่ได้',
          );
        }
        try {
          return ApiOk<KycStatus>(
            KycStatus.fromJson(data.map((k, v) => MapEntry(k.toString(), v))),
          );
        } catch (e) {
          debugPrint('[KycApi] parse KycStatus: ${e.runtimeType}');
          return const ApiErr<KycStatus>(
            KycErrorCodes.badPayload,
            'อ่านข้อมูลจากเซิร์ฟเวอร์ไม่สำเร็จ',
          );
        }
    }
  }

  /// ส่งต่อ error ข้ามชนิดผลลัพธ์โดยไม่ทำเหตุผลหายแม้แต่ฟิลด์เดียว
  ApiErr<T> _reshape<T>(ApiErr<Object?> err) => ApiErr<T>(
        err.code,
        err.message,
        status: err.status,
        fieldErrors: err.fieldErrors,
        retryAfterSeconds: err.retryAfterSeconds,
        kycFeature: err.kycFeature,
        kycLevel: err.kycLevel,
        payload: err.payload,
      );

  static String? _str(dynamic value) {
    if (value == null) return null;
    final s = value.toString().trim();
    return s.isEmpty ? null : s;
  }
}

/// Developed by Xman Studio
