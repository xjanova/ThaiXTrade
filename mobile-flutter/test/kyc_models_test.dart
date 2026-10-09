/// TPIX TRADE — เทสต์ของหน้า "ยืนยันตัวตน" ส่วนที่ไม่ต้องมี widget
///
/// เฝ้าสิ่งที่พังแล้วผู้ใช้เดือดร้อนจริง:
///   • payload จาก PHP รูปแปลก (bool เป็น 1/0, สตริงว่าง, ลิงก์ผิด scheme) ต้องอ่านได้
///   • ซองความล้มเหลวทุกแบบต้องกลายเป็นรหัสที่หน้าจอรู้จัก ไม่ใช่ "ไม่สำเร็จ" เปล่าๆ
///   • deep link กลับจาก Thaiprompt ต้องแยกผลถูก และรหัสรับผลต้องไม่หลุดไปที่ไหน
///   • การแลกรหัสต้องไม่ทิ้งรหัสที่ยังใช้ได้ และไม่แลกซ้ำรหัสที่ใช้ไปแล้ว
///
/// Developed by Xman Studio
library;

import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tpix_trade/core/locale/locale_provider.dart';
import 'package:tpix_trade/models/kyc_models.dart';
import 'package:tpix_trade/providers/kyc_store.dart';
import 'package:tpix_trade/services/api_result.dart';
import 'package:tpix_trade/services/bug_reporter.dart';
import 'package:tpix_trade/services/deep_link_service.dart';
import 'package:tpix_trade/services/kyc_api.dart';

const _wallet = '0xAbCdEf0123456789abcdef0123456789ABCDEF01';
final _completion = 'A1b2C3d4' * 6; // 48 ตัว

Map<String, dynamic> _payload({
  bool gateEnabled = true,
  Object? approvedLevel,
  Map<String, dynamic>? submission,
  bool available = true,
  Map<String, dynamic>? link,
  String appLink = 'thaiprompt://ekyc?from=profile',
  String downloadUrl = 'https://main.thaiprompt.online/app/download',
  String webKycUrl = 'https://tpix.online/kyc',
}) =>
    {
      'gate': {
        'enabled': gateEnabled,
        'approved_level': approvedLevel,
        'features': {
          // ลำดับคีย์จงใจสลับ — แอปต้องเรียงเองตามลำดับที่ตั้งไว้
          'bridge': {'required': 0, 'level': 'basic', 'passed': 1},
          'masternode': {'required': true, 'level': 'enhanced', 'passed': false},
          'ai_bot': {'required': '1', 'level': 'basic', 'passed': false},
          'brand_new_feature': {'required': true, 'level': 'basic', 'passed': false},
        },
      },
      'submission': submission,
      'thaiprompt': {
        'available': available,
        'link': link,
        'app_link': appLink,
        'download_url': downloadUrl,
      },
      'web_kyc_url': webKycUrl,
    };

// ══════════════════════════════════════════════════════════════════
// adapter ปลอมของ Dio — ตอบตามที่เทสต์กำหนด ไม่ยิงเน็ตจริง
// ══════════════════════════════════════════════════════════════════

class _Reply {
  final int status;
  final Object? body;
  final Map<String, List<String>> headers;
  final DioExceptionType? failure;

  const _Reply(this.status, this.body, {this.headers = const {}})
      : failure = null;
  const _Reply.fail(this.failure)
      : status = 0,
        body = null,
        headers = const {};
}

class _FakeAdapter implements HttpClientAdapter {
  final List<_Reply> replies = [];
  final List<RequestOptions> requests = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    final reply = replies.removeAt(0);
    if (reply.failure != null) {
      throw DioException(requestOptions: options, type: reply.failure!);
    }
    return ResponseBody.fromString(
      reply.body is String ? reply.body as String : jsonEncode(reply.body),
      reply.status,
      headers: {
        Headers.contentTypeHeader: [
          reply.body is String ? 'text/html' : Headers.jsonContentType,
        ],
        ...reply.headers,
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

(KycApi, _FakeAdapter) _api() {
  final adapter = _FakeAdapter();
  final dio = Dio(BaseOptions(baseUrl: 'https://test.local/api/v1'))
    ..httpClientAdapter = adapter;
  return (KycApi.withDio(dio), adapter);
}

/// KycApi ปลอมสำหรับเทสต์ของ KycStore — ตอบผลที่ตั้งไว้ทีละครั้ง
class _ScriptedApi implements KycApi {
  final List<ApiResult<KycStatus>> completes = [];
  int completeCalls = 0;

  @override
  Future<ApiResult<KycStatus>> completeThaiprompt({
    required String wallet,
    required String completion,
  }) async {
    completeCalls++;
    return completes.removeAt(0);
  }

  @override
  Future<ApiResult<KycStatus>> fetchStatus(String wallet) =>
      throw UnimplementedError();

  @override
  Future<ApiResult<KycStatus>> refreshThaiprompt(String wallet) =>
      throw UnimplementedError();

  @override
  Future<ApiResult<Uri>> startThaiprompt(String wallet) =>
      throw UnimplementedError();
}

void main() {
  group('KycStatus — อ่าน payload', () {
    test('อ่านครบทุกส่วน ทนค่าแปลกจาก PHP และเรียงฟีเจอร์เอง', () {
      final s = KycStatus.fromJson(_payload(
        approvedLevel: 'basic',
        submission: {
          'status': 'approved',
          'level': 'basic',
          'source': 'thaiprompt',
          'submitted_at': '2026-10-01T03:00:00+00:00',
          'reviewed_at': '2026-10-02T04:30:00+00:00',
          'reject_reason': '',
        },
        link: {
          'status': 'approved',
          'verified_at': '2026-09-30T00:00:00+00:00',
          'method': 'ekyc',
          'linked_at': '2026-10-02T04:29:00+00:00',
          'last_checked_at': null,
          'needs_reconnect': 0,
        },
      ));

      expect(s.isApproved, isTrue);
      expect(s.submission!.viaThaiprompt, isTrue);
      expect(s.submission!.rejectReason, isNull, reason: 'สตริงว่าง = ไม่มี');
      expect(s.submission!.effectiveDate, DateTime.utc(2026, 10, 2, 4, 30));
      expect(s.gate.enabled, isTrue);
      expect(s.gate.approvedLevel, 'basic');

      // bool รูป 1/"1"/0 ต้องอ่านถูก
      final keys = s.gate.features.map((f) => f.key).toList();
      expect(keys, ['ai_bot', 'bridge', 'masternode', 'brand_new_feature']);
      final required = s.gate.requiredFeatures.map((f) => f.key).toList();
      expect(required, ['ai_bot', 'masternode', 'brand_new_feature'],
          reason: 'โชว์เฉพาะฟีเจอร์ที่บังคับจริง');
      expect(s.gate.features.firstWhere((f) => f.key == 'bridge').passed, isTrue);
      expect(s.gate.features.firstWhere((f) => f.key == 'masternode').needsEnhanced,
          isTrue);
      expect(s.gate.features.last.fallbackLabel, 'brand new feature');

      expect(s.thaiprompt.available, isTrue);
      expect(s.thaiprompt.link!.isApproved, isTrue);
      expect(s.thaiprompt.link!.needsReconnect, isFalse);
      expect(s.thaiprompt.appLinkUri.toString(), 'thaiprompt://ekyc?from=profile');
      expect(s.thaiprompt.downloadUri!.host, 'main.thaiprompt.online');
      expect(s.webKycUri.toString(), 'https://tpix.online/kyc');
    });

    test('ลิงก์ที่จะส่งให้ระบบเปิดต้องผ่านการตรวจ scheme', () {
      final s = KycStatus.fromJson(_payload(
        appLink: 'intent://evil#Intent;end',
        downloadUrl: 'http://not-secure.example/app',
        webKycUrl: 'javascript:alert(1)',
      ));
      expect(s.thaiprompt.appLinkUri, isNull);
      expect(s.thaiprompt.downloadUri, isNull);
      expect(s.webKycUrl, KycStatus.defaultWebKycUrl,
          reason: 'ลิงก์เว็บผิดรูป → ถอยไปใช้ค่าปริยาย ไม่ใช่เปิดของแปลก');
    });

    test('config ว่างจากเซิร์ฟเวอร์ = ไม่มีลิงก์ ไม่ใช่ Uri ว่าง', () {
      final s = KycStatus.fromJson(_payload(appLink: '', downloadUrl: ''));
      expect(s.thaiprompt.appLink, isNull);
      expect(s.thaiprompt.appLinkUri, isNull);
      expect(s.thaiprompt.downloadUri, isNull);
    });

    test('tryParse ไม่ throw กับของเสีย', () {
      expect(KycStatus.tryParse(null), isNull);
      expect(KycStatus.tryParse('oops'), isNull);
      expect(KycStatus.tryParse(<String, dynamic>{}), isNotNull,
          reason: 'ก้อนว่างอ่านได้ = ยังไม่ผ่าน + ไม่มี Thaiprompt');
      final empty = KycStatus.tryParse(<String, dynamic>{})!;
      expect(empty.isApproved, isFalse);
      expect(empty.thaiprompt.available, isFalse);
    });
  });

  group('resolveKycView — สถานะหน้าจอ', () {
    KycStatus status({
      Map<String, dynamic>? submission,
      bool available = true,
      Map<String, dynamic>? link,
    }) =>
        KycStatus.fromJson(
            _payload(submission: submission, available: available, link: link));

    KycView view(KycStatus? s,
            {bool connected = true, bool sign = false, bool failed = false}) =>
        resolveKycView(
          walletConnected: connected,
          needsWalletSign: sign,
          status: s,
          loadFailed: failed,
        );

    test('ลำดับ: กระเป๋า → ลายเซ็น → ข้อมูล', () {
      expect(view(status(), connected: false, sign: true), KycView.noWallet);
      expect(view(status(), sign: true), KycView.needsWalletSign);
      expect(view(null), KycView.loading);
      expect(view(null, failed: true), KycView.loadFailed);
    });

    test('ผ่านแล้วมาก่อนเรื่อง Thaiprompt — คนผ่านด้วยเอกสารไม่ต้องเห็นการ์ดชวนเชื่อม', () {
      expect(
        view(status(
          submission: {'status': 'approved', 'source': 'manual'},
          available: true,
        )),
        KycView.approved,
      );
    });

    test('Thaiprompt: ยังไม่เชื่อม / ต้องเชื่อมใหม่ / รอผล / ปิดอยู่', () {
      expect(view(status()), KycView.connectThaiprompt);
      expect(
        view(status(link: {'status': 'approved', 'needs_reconnect': true})),
        KycView.connectThaiprompt,
      );
      for (final st in ['none', 'pending', 'rejected']) {
        expect(view(status(link: {'status': st})), KycView.waitingThaiprompt,
            reason: 'link.status=$st');
      }
      expect(view(status(available: false)), KycView.webOnly);
    });
  });

  group('KycReturnLink — deep link กลับจาก Thaiprompt', () {
    Uri link(String query) => Uri.parse('tpixtrade://kyc?$query');

    test('ok + รหัสถูกรูป = เอาไปแลกได้', () {
      final r = KycReturnLink.parse(link('result=ok&completion=$_completion'))!;
      expect(r.result, KycReturnResult.ok);
      expect(r.completion, _completion);
    });

    test('รหัสผิดรูปทุกแบบ = invalid (ไม่ยิงเน็ต)', () {
      for (final bad in [
        _completion.substring(1), // 47 ตัว
        '${_completion}x', // 49 ตัว
        '${_completion.substring(1)}-', // อักขระพิเศษ
        '',
      ]) {
        final r = KycReturnLink.parse(
            link('result=ok&completion=${Uri.encodeQueryComponent(bad)}'))!;
        expect(r.result, KycReturnResult.invalid, reason: 'completion="$bad"');
        expect(r.completion, isNull);
      }
    });

    test('denied / expired / error / ค่าแปลก', () {
      expect(KycReturnLink.parse(link('result=denied'))!.result,
          KycReturnResult.denied);
      expect(KycReturnLink.parse(link('result=expired'))!.result,
          KycReturnResult.expired);
      expect(KycReturnLink.parse(link('result=error'))!.result,
          KycReturnResult.error);
      expect(KycReturnLink.parse(link('result=whatever'))!.result,
          KycReturnResult.invalid);
      expect(KycReturnLink.parse(link('foo=bar'))!.result, KycReturnResult.invalid);
    });

    test('ไม่มี result แต่รหัสถูกรูป = ยังแลกได้ (ไม่ทิ้งสิ่งที่ผู้ใช้อนุญาตมาแล้ว)', () {
      final r = KycReturnLink.parse(link('completion=$_completion'))!;
      expect(r.result, KycReturnResult.ok);
    });

    test('คนละ scheme/host = ไม่ใช่ลิงก์ของหน้านี้', () {
      expect(KycReturnLink.parse(Uri.parse('tpixtrade://trade?pair=BTC-USDT')),
          isNull);
      expect(KycReturnLink.parse(Uri.parse('https://kyc?result=ok')), isNull);
    });

    test('toString ไม่พิมพ์รหัสรับผล', () {
      final r = KycReturnLink.parse(link('result=ok&completion=$_completion'))!;
      expect(r.toString(), isNot(contains(_completion)));
    });

    test('router-fallback เดา host kyc ได้ โดยไม่แย่งลิงก์เดิม', () {
      String? infer(Map<String, String> qp) =>
          DeepLinkService.inferHostFromQuery(qp);
      expect(infer({'result': 'ok', 'completion': _completion}), 'kyc');
      expect(infer({'result': 'denied'}), 'kyc');
      // ลิงก์เดิมต้องเดาเหมือนเดิมทุกตัว
      expect(infer({'address': _wallet, 'chain': '4289'}), 'connect');
      expect(infer({'kind': 'tx', 'nonce': 'n', 'error': 'x'}), 'tx-result');
      expect(infer({'nonce': 'n', 'signature': '0x1'}), 'sign-result');
      expect(infer({'nonce': 'n', 'error': 'user_rejected'}), 'sign-result');
      expect(infer({'pair': 'BTC-USDT'}), 'trade');
      expect(infer({'foo': 'bar'}), isNull);
    });
  });

  group('KycApi — ซองตอบกลับทุกแบบกลายเป็นรหัสที่หน้าจอรู้จัก', () {
    test('status สำเร็จ + ส่งที่อยู่ตัวเล็กไปทาง query', () async {
      final (api, http) = _api();
      http.replies.add(_Reply(200, {'success': true, 'data': _payload()}));

      final res = await api.fetchStatus(_wallet);

      expect(res.isOk, isTrue);
      expect(res.valueOrNull!.thaiprompt.available, isTrue);
      expect(http.requests.single.path, KycEndpoints.status);
      expect(http.requests.single.queryParameters['wallet_address'],
          _wallet.toLowerCase());
    });

    test('กระเป๋าผิดรูป/ไม่มี = ไม่ยิงเน็ต', () async {
      final (api, http) = _api();
      expect((await api.fetchStatus('')).errorOrNull!.code, KycErrorCodes.noWallet);
      expect((await api.fetchStatus('0x123')).errorOrNull!.code,
          KycErrorCodes.invalidWallet);
      expect(http.requests, isEmpty);
    });

    test('start ส่ง consent=true และรับเฉพาะลิงก์ https', () async {
      final (api, http) = _api();
      http.replies
        ..add(_Reply(200, {
          'success': true,
          'data': {'authorize_url': 'https://main.thaiprompt.online/oauth/authorize?x=1'},
        }))
        ..add(_Reply(200, {
          'success': true,
          'data': {'authorize_url': 'http://main.thaiprompt.online/oauth'},
        }));

      final ok = await api.startThaiprompt(_wallet);
      expect(ok.valueOrNull!.host, 'main.thaiprompt.online');
      final body = http.requests.first.data as Map;
      expect(body['consent'], isTrue);
      expect(body['wallet_address'], _wallet.toLowerCase());

      final insecure = await api.startThaiprompt(_wallet);
      expect(insecure.errorOrNull!.code, KycErrorCodes.badPayload);
    });

    test('start: 422 THAIPROMPT_UNAVAILABLE และ 422 validator ของ Laravel', () async {
      final (api, http) = _api();
      http.replies
        ..add(_Reply(422, {
          'success': false,
          'error': {'code': 'THAIPROMPT_UNAVAILABLE', 'message': 'ยังไม่เปิด'},
        }))
        ..add(_Reply(422, {
          'message': 'The consent field must be accepted.',
          'errors': {
            'consent': ['The consent field must be accepted.'],
          },
        }));

      final unavailable = (await api.startThaiprompt(_wallet)).errorOrNull!;
      expect(unavailable.code, KycErrorCodes.thaipromptUnavailable);
      expect(unavailable.status, 422);

      final validation = (await api.startThaiprompt(_wallet)).errorOrNull!;
      expect(validation.code, KycErrorCodes.validation);
      expect(validation.isValidation, isTrue);
      expect(validation.fieldError('consent'), isNotNull);
    });

    test('complete: รหัสผิดรูปไม่ยิงเน็ต · TAKEN · LINK_FAILED · สำเร็จ', () async {
      final (api, http) = _api();
      final bad = await api.completeThaiprompt(wallet: _wallet, completion: 'short');
      expect(bad.errorOrNull!.code, KycErrorCodes.invalidCompletion);
      expect(http.requests, isEmpty);

      http.replies
        ..add(_Reply(422, {
          'success': false,
          'error': {'code': 'THAIPROMPT_TAKEN', 'message': '...'},
        }))
        ..add(_Reply(422, {
          'success': false,
          'error': {'code': 'THAIPROMPT_LINK_FAILED', 'message': '...'},
        }))
        ..add(_Reply(200, {
          'success': true,
          'data': _payload(link: {'status': 'none'}),
        }));

      expect(
        (await api.completeThaiprompt(wallet: _wallet, completion: _completion))
            .errorOrNull!
            .code,
        KycErrorCodes.thaipromptTaken,
      );
      expect(
        (await api.completeThaiprompt(wallet: _wallet, completion: _completion))
            .errorOrNull!
            .code,
        KycErrorCodes.thaipromptLinkFailed,
      );
      final ok =
          await api.completeThaiprompt(wallet: _wallet, completion: _completion);
      expect(ok.valueOrNull!.thaipromptLink!.status, 'none');
      expect((http.requests.last.data as Map)['completion'], _completion);
    });

    test('refresh: 422 RECONNECT แนบสถานะมาด้วย · 404 NOT_LINKED', () async {
      final (api, http) = _api();
      http.replies
        ..add(_Reply(422, {
          'success': false,
          'error': {'code': 'THAIPROMPT_RECONNECT', 'message': '...'},
          'data': _payload(link: {'status': 'none', 'needs_reconnect': true}),
        }))
        ..add(_Reply(404, {
          'success': false,
          'error': {'code': 'THAIPROMPT_NOT_LINKED', 'message': '...'},
        }));

      final reconnect = (await api.refreshThaiprompt(_wallet)).errorOrNull!;
      expect(reconnect.code, KycErrorCodes.thaipromptReconnect);
      final attached = kycStatusAttachedTo(reconnect);
      expect(attached, isNotNull);
      expect(attached!.thaipromptLink!.needsReconnect, isTrue);

      final notLinked = (await api.refreshThaiprompt(_wallet)).errorOrNull!;
      expect(notLinked.code, KycErrorCodes.thaipromptNotLinked);
      expect(notLinked.status, 404);
      expect(kycStatusAttachedTo(notLinked), isNull);
    });

    test('403 ของกระเป๋า = ต้องเซ็นใหม่ (แยกเหตุ IP เปลี่ยนได้)', () async {
      final (api, http) = _api();
      http.replies
        ..add(_Reply(403, {
          'success': false,
          'error': {'code': 'WALLET_NOT_VERIFIED', 'message': '...'},
        }))
        ..add(_Reply(403, {
          'success': false,
          'error': {'code': 'WALLET_IP_MISMATCH', 'message': '...'},
        }));

      final expired = (await api.fetchStatus(_wallet)).errorOrNull!;
      expect(expired.needsWalletSign, isTrue);
      expect(expired.isIpMismatch, isFalse);
      final ip = (await api.fetchStatus(_wallet)).errorOrNull!;
      expect(ip.needsWalletSign, isTrue);
      expect(ip.isIpMismatch, isTrue);
    });

    test('429 = ถอยเฉพาะเส้นที่โดน ตามเวลาใน Retry-After', () async {
      final (api, http) = _api();
      http.replies
        ..add(const _Reply(
          429,
          {'message': 'Too Many Attempts.'},
          headers: {
            'retry-after': ['12'],
          },
        ))
        ..add(_Reply(200, {'success': true, 'data': _payload()}));

      final throttled = (await api.refreshThaiprompt(_wallet)).errorOrNull!;
      expect(throttled.isThrottled, isTrue);
      expect(throttled.retryAfterSeconds, 12);

      // ยิงซ้ำระหว่างถอย = ไม่ถึงเน็ตเลย
      final again = (await api.refreshThaiprompt(_wallet)).errorOrNull!;
      expect(again.code, KycErrorCodes.rateLimited);
      expect(http.requests.length, 1);

      // เส้นอื่นยังใช้ได้ (คนละตัวนับฝั่งเซิร์ฟเวอร์)
      expect((await api.fetchStatus(_wallet)).isOk, isTrue);
      expect(http.requests.length, 2);
    });

    test('เน็ตหลุด / หมดเวลา / 500 ดิบ', () async {
      final (api, http) = _api();
      http.replies
        ..add(const _Reply.fail(DioExceptionType.connectionError))
        ..add(const _Reply.fail(DioExceptionType.receiveTimeout))
        ..add(const _Reply(500, '<html>Server Error</html>'));

      final offline = (await api.fetchStatus(_wallet)).errorOrNull!;
      expect(offline.code, KycErrorCodes.network);
      expect(offline.isOffline, isTrue);
      expect((await api.fetchStatus(_wallet)).errorOrNull!.code,
          KycErrorCodes.timeout);
      final server = (await api.fetchStatus(_wallet)).errorOrNull!;
      expect(server.code, 'HTTP_500');
      expect(server.status, 500);
    });
  });

  group('KycStore — แลกรหัสรับผล', () {
    KycStatus status({bool approved = false}) => KycStatus.fromJson(_payload(
          submission: approved
              ? {'status': 'approved', 'source': 'thaiprompt'}
              : null,
          link: {'status': approved ? 'approved' : 'none'},
        ));

    test('สำเร็จ: ผ่านทันที / เชื่อมแล้วรอ eKYC — และแจ้งผู้ฟัง', () async {
      final store = KycStore();
      final api = _ScriptedApi()
        ..completes.add(ApiOk(status(approved: true)))
        ..completes.add(ApiOk(status()));
      var notified = 0;
      store.addListener(() => notified++);
      final rev = store.revision;

      final first =
          await store.complete(api: api, wallet: _wallet, completion: _completion);
      expect(first, KycCompleteOutcome.approved);
      expect(store.statusFor(_wallet.toLowerCase())!.isApproved, isTrue);
      expect(store.statusFor('0x0000000000000000000000000000000000000000'), isNull,
          reason: 'สถานะของกระเป๋าอื่นต้องไม่โผล่');
      expect(store.revision, greaterThan(rev));
      expect(store.isCompleting, isFalse);
      expect(notified, greaterThan(0));

      final second = await store.complete(
          api: api, wallet: _wallet, completion: 'Z9' * 24);
      expect(second, KycCompleteOutcome.linked);
    });

    test('deep link มาซ้ำ = ไม่แลกซ้ำ', () async {
      final store = KycStore();
      final api = _ScriptedApi()..completes.add(ApiOk(status()));
      await store.complete(api: api, wallet: _wallet, completion: _completion);
      final again =
          await store.complete(api: api, wallet: _wallet, completion: _completion);
      expect(again, KycCompleteOutcome.duplicate);
      expect(api.completeCalls, 1);
    });

    test('ต้องเซ็นกระเป๋าก่อน = เก็บรหัสไว้ แล้วแลกต่อให้เองหลังพร้อม', () async {
      final store = KycStore();
      final api = _ScriptedApi()
        ..completes.add(const ApiErr('WALLET_NOT_VERIFIED', '...', status: 403))
        ..completes.add(ApiOk(status()));

      final first =
          await store.complete(api: api, wallet: _wallet, completion: _completion);
      expect(first, KycCompleteOutcome.needsWalletSign);
      expect(store.hasPendingFor(_wallet), isTrue);
      expect(store.hasPendingFor('0x1111111111111111111111111111111111111111'),
          isFalse, reason: 'รหัสของกระเป๋านี้ต้องไม่ถูกใช้กับกระเป๋าอื่น');

      final retried = await store.completePending(api: api, wallet: _wallet);
      expect(retried, KycCompleteOutcome.linked);
      expect(store.hasPendingFor(_wallet), isFalse);
      expect(await store.completePending(api: api, wallet: _wallet), isNull);
    });

    test('ยังไม่เชื่อมกระเป๋า = เก็บไว้ให้กระเป๋าแรกที่พร้อม', () async {
      final store = KycStore();
      final api = _ScriptedApi();
      final outcome =
          await store.complete(api: api, wallet: null, completion: _completion);
      expect(outcome, KycCompleteOutcome.noWallet);
      expect(api.completeCalls, 0);
      expect(store.hasPendingFor(_wallet), isTrue);
    });

    test('บัญชีถูกใช้แล้ว / หมดอายุ = เลิกเก็บรหัส (แลกซ้ำไม่มีวันผ่าน)', () async {
      final store = KycStore();
      final api = _ScriptedApi()
        ..completes.add(const ApiErr('THAIPROMPT_TAKEN', '...', status: 422))
        ..completes.add(const ApiErr('THAIPROMPT_LINK_FAILED', '...', status: 422));

      expect(
        await store.complete(api: api, wallet: _wallet, completion: _completion),
        KycCompleteOutcome.taken,
      );
      expect(store.hasPendingFor(_wallet), isFalse);
      expect(
        await store.complete(api: api, wallet: _wallet, completion: 'Q7' * 24),
        KycCompleteOutcome.expired,
      );
      expect(store.hasPendingFor(_wallet), isFalse);
    });

    test('เน็ตหลุด = เก็บรหัสไว้ลองใหม่', () async {
      final store = KycStore();
      final api = _ScriptedApi()
        ..completes.add(const ApiErr('NETWORK', 'ต่ออินเทอร์เน็ตไม่ได้'));
      expect(
        await store.complete(api: api, wallet: _wallet, completion: _completion),
        KycCompleteOutcome.failed,
      );
      expect(store.hasPendingFor(_wallet), isTrue);
      expect(store.lastCompleteError!.isOffline, isTrue);
    });
  });

  group('ข้อความ 2 ภาษา + ความลับ', () {
    test('ทุกคีย์ที่ใช้มีทั้งไทยและอังกฤษ (ไทยไม่ตกไปใช้อังกฤษ)', () async {
      SharedPreferences.setMockInitialValues({});
      final th = LocaleProvider();
      await th.setLocale('th');
      final en = LocaleProvider();
      await en.setLocale('en');

      final keys = <String>{
        for (final o in KycCompleteOutcome.values)
          if (o.messageKey != null) o.messageKey!,
        for (final f in kKycFeatureOrder) 'kyc.feature.$f',
        'kyc.link.denied',
        'kyc.err.THAIPROMPT_UNAVAILABLE',
        'kyc.err.THAIPROMPT_RECONNECT',
        'kyc.err.NETWORK',
        'kyc.tp.consent',
        'kyc.wait.status.none',
        'kyc.wait.status.pending',
        'kyc.wait.status.rejected',
        'kyc.required.title',
      };
      for (final key in keys) {
        expect(en.t(key), isNot(key), reason: 'ไม่มีคีย์ $key ภาษาอังกฤษ');
        expect(th.t(key), isNot(en.t(key)), reason: 'ไม่มีคีย์ $key ภาษาไทย');
      }
    });

    test('รายงานบั๊กล้างรหัสรับผลออกก่อนเก็บ', () {
      BugReporter.I.breadcrumb('deeplink tpixtrade://kyc?result=ok&completion=$_completion');
      BugReporter.I.breadcrumb('{"completion":"$_completion"}');
      final crumbs = BugReporter.I.breadcrumbs.join('\n');
      expect(crumbs, isNot(contains(_completion)));
      expect(crumbs, contains('[redacted]'));
    });
  });
}

/// Developed by Xman Studio
