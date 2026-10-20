/// TPIX TRADE — RPC สำรองอัตโนมัติ
///
/// สิ่งที่กันไว้:
///   - rpc.tpix.online โดน Cloudflare บล็อก (403) แล้วแอปต้องสลับไป rpc1 เอง
///     ไม่ใช่โชว์ยอด 0 หรือบอกว่า "ธุรกรรมจะล้มเหลว"
///   - revert/nonce ต่ำจากโหนด = ไม่สลับ (โหนดอื่นก็ตอบแบบเดียวกัน ถามซ้ำแค่ซ่อนสาเหตุ)
///   - ส่งธุรกรรมซ้ำไปโหนดที่สองต้องเป็นไบต์เดิม — ได้ "already known" = สำเร็จ ไม่ใช่ซื้อซ้ำ
///
/// Developed by Xman Studio
library;

import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:tpix_trade/models/chain_config.dart';
import 'package:tpix_trade/services/rpc_failover.dart';
import 'package:web3dart/json_rpc.dart' show RPCError;
import 'package:web3dart/web3dart.dart';

const _a = 'https://rpc.tpix.online';
const _b = 'https://rpc1.tpix.online';

/// โหนดจำลอง — ตอบตาม host ที่ถูกเรียก และนับจำนวนครั้ง
class _FakeNodes {
  final Map<String, http.Response Function(Map<String, dynamic> body)> byHost;
  final Map<String, int> calls = {};

  _FakeNodes(this.byHost);

  http.Client client() => MockClient((req) async {
        final host = req.url.host;
        calls[host] = (calls[host] ?? 0) + 1;
        final body = jsonDecode(req.body) as Map<String, dynamic>;
        final handler = byHost[host];
        if (handler == null) return http.Response('not found', 404);
        return handler(body);
      });
}

http.Response _ok(Map<String, dynamic> body, Object? result) => http.Response(
      jsonEncode({'jsonrpc': '2.0', 'id': body['id'], 'result': result}),
      200,
      headers: {'content-type': 'application/json'},
    );

http.Response _rpcError(Map<String, dynamic> body, int code, String msg) =>
    http.Response(
      jsonEncode({
        'jsonrpc': '2.0',
        'id': body['id'],
        'error': {'code': code, 'message': msg},
      }),
      200,
      headers: {'content-type': 'application/json'},
    );

http.Response _cloudflare403(Map<String, dynamic> _) =>
    http.Response('<html>Attention Required! | Cloudflare</html>', 403);

void main() {
  group('RpcFailover.shouldFailover — สลับเฉพาะปัญหาการส่ง', () {
    test('HTTP 403/5xx, หมดเวลา, เน็ตหลุด, ได้ HTML แทน JSON = สลับ', () {
      expect(RpcFailover.shouldFailover(const RpcHttpException(403, 'x')), isTrue);
      expect(RpcFailover.shouldFailover(const RpcHttpException(502, 'x')), isTrue);
      expect(RpcFailover.shouldFailover(TimeoutException('slow')), isTrue);
      expect(RpcFailover.shouldFailover(http.ClientException('reset')), isTrue);
      expect(RpcFailover.shouldFailover(const SocketException('down')), isTrue);
      expect(RpcFailover.shouldFailover(const FormatException('<html>')), isTrue);
    });

    test('JSON-RPC error (revert, nonce ต่ำ) = ไม่สลับ', () {
      expect(
          RpcFailover.shouldFailover(
              const RPCError(3, 'execution reverted', null)),
          isFalse);
      expect(
          RpcFailover.shouldFailover(
              const RPCError(-32000, 'nonce too low', null)),
          isFalse);
    });

    test('บั๊กในโค้ดเราเองไม่ถูกกลบด้วยการสลับโหนด', () {
      expect(RpcFailover.shouldFailover(StateError('bug')), isFalse);
      expect(RpcFailover.shouldFailover(ArgumentError('bug')), isFalse);
    });
  });

  group('RpcFailover.order', () {
    test('ตัดค่าว่าง/ซ้ำ คงลำดับเดิม', () {
      expect(RpcFailover.order([_a, null, '', _a, _b]), [_a, _b]);
    });

    test('ตัวที่ใช้ได้ล่าสุดขึ้นก่อน', () {
      expect(RpcFailover.order([_a, _b], lastGood: _b), [_b, _a]);
      // lastGood ที่ไม่อยู่ในรายการไม่ถูกเพิ่มเข้ามาเอง
      expect(RpcFailover.order([_a, _b], lastGood: 'https://evil.example'),
          [_a, _b]);
    });
  });

  group('RpcFailover.run', () {
    test('ตัวแรก 403 → ใช้ตัวที่สอง และจำตัวที่สองไว้', () async {
      final tried = <String>[];
      String? good;
      final out = await RpcFailover.run<int>(
        [_a, _b],
        (url) async {
          tried.add(url);
          if (url == _a) throw const RpcHttpException(403, 'rpc.tpix.online');
          return 42;
        },
        onSuccess: (u) => good = u,
      );
      expect(out, 42);
      expect(tried, [_a, _b]);
      expect(good, _b);
    });

    test('revert จากตัวแรกโยนออกทันที ไม่ไปถามตัวที่สอง', () async {
      final tried = <String>[];
      await expectLater(
        RpcFailover.run<int>([_a, _b], (url) async {
          tried.add(url);
          throw const RPCError(3, 'execution reverted', null);
        }),
        throwsA(isA<RPCError>()),
      );
      expect(tried, [_a]);
    });

    test('ล้มครบทุกตัว = โยน error ของตัวสุดท้าย', () async {
      await expectLater(
        RpcFailover.run<int>([_a, _b], (url) async {
          throw RpcHttpException(url == _a ? 403 : 503, url);
        }),
        throwsA(isA<RpcHttpException>()
            .having((e) => e.statusCode, 'statusCode', 503)),
      );
    });

    test('หมดเวลาถือเป็นปัญหาการส่ง — ไปตัวถัดไป', () async {
      final out = await RpcFailover.run<String>(
        [_a, _b],
        (url) async {
          if (url == _a) {
            await Future<void>.delayed(const Duration(milliseconds: 200));
          }
          return url;
        },
        timeout: const Duration(milliseconds: 20),
      );
      expect(out, _b);
    });
  });

  group('RpcPool กับโหนดจำลอง (web3dart จริง)', () {
    test('Cloudflare 403 ที่ rpc หลัก → อ่านผ่าน rpc1 ได้ และรอบถัดไปเริ่มที่ rpc1', () async {
      final nodes = _FakeNodes({
        'rpc.tpix.online': _cloudflare403,
        'rpc1.tpix.online': (b) => _ok(b, '0x10'),
      });
      final pool = RpcPool([_a, _b], httpClientFactory: nodes.client);

      final block = await pool.withClient((c) => c.getBlockNumber());
      expect(block, 16);
      expect(pool.lastGood, _b);
      expect(nodes.calls['rpc.tpix.online'], 1);

      await pool.withClient((c) => c.getBlockNumber());
      // ไม่ไปชน 403 ซ้ำทุกคำขอ
      expect(nodes.calls['rpc.tpix.online'], 1);
      expect(nodes.calls['rpc1.tpix.online'], 2);
    });

    test('revert จากสัญญาไม่สลับโหนด — ผู้เรียกได้ RPCError ไปตัดสินเอง', () async {
      final nodes = _FakeNodes({
        'rpc.tpix.online': (b) => _rpcError(b, 3, 'execution reverted'),
        'rpc1.tpix.online': (b) => _ok(b, '0x10'),
      });
      final pool = RpcPool([_a, _b], httpClientFactory: nodes.client);

      await expectLater(
        pool.withClient((c) => c.getBlockNumber()),
        throwsA(isA<RPCError>()),
      );
      expect(nodes.calls['rpc1.tpix.online'], isNull);
    });

    test('ทุกโหนดล่ม = error การส่ง (ไม่ใช่คืนค่า 0 เงียบ ๆ)', () async {
      final nodes = _FakeNodes({
        'rpc.tpix.online': _cloudflare403,
        'rpc1.tpix.online': (_) => http.Response('bad gateway', 502),
      });
      final pool = RpcPool([_a, _b], httpClientFactory: nodes.client);

      await expectLater(
        pool.withClient((c) => c.getBalance(
            EthereumAddress.fromHex('0x${'1' * 40}'))),
        throwsA(isA<RpcHttpException>()),
      );
    });

    test('broadcast: โหนดแรก 502 โหนดสองตอบ "already known" = สำเร็จด้วย hash เดิม', () async {
      final signed = Uint8List.fromList(List<int>.generate(110, (i) => i));
      final expected = RpcPool.signedTxHash(signed);
      final nodes = _FakeNodes({
        'rpc.tpix.online': (_) => http.Response('bad gateway', 502),
        'rpc1.tpix.online': (b) => _rpcError(b, -32000, 'already known'),
      });
      final pool = RpcPool([_a, _b], httpClientFactory: nodes.client);

      expect(await pool.broadcastRaw(signed), expected);
    });

    test('broadcast: ส่งไบต์เดียวกันทุกโหนด (เซ็นครั้งเดียว ไม่สร้าง nonce ใหม่)', () async {
      final signed = Uint8List.fromList(List<int>.generate(90, (i) => 255 - i));
      final seen = <String>[];
      final nodes = _FakeNodes({
        'rpc.tpix.online': (b) {
          seen.add((b['params'] as List).first as String);
          return http.Response('bad gateway', 502);
        },
        'rpc1.tpix.online': (b) {
          seen.add((b['params'] as List).first as String);
          return _ok(b, RpcPool.signedTxHash(signed));
        },
      });
      final pool = RpcPool([_a, _b], httpClientFactory: nodes.client);

      await pool.broadcastRaw(signed);
      expect(seen.length, 2);
      expect(seen[0], seen[1]);
    });

    test('broadcast: nonce ต่ำในการส่งครั้งแรก = error จริง ไม่ปลอมเป็นสำเร็จ', () async {
      final signed = Uint8List.fromList([1, 2, 3]);
      final nodes = _FakeNodes({
        'rpc.tpix.online': (b) => _rpcError(b, -32000, 'nonce too low'),
      });
      final pool = RpcPool([_a], httpClientFactory: nodes.client);

      await expectLater(pool.broadcastRaw(signed), throwsA(isA<RPCError>()));
    });
  });

  test('เชน TPIX ใช้ rpc1 เป็นตัวหลัก rpc.tpix.online เป็นตัวสำรอง', () {
    expect(ChainConfig.tpix.rpcUrl, _b);
    expect(ChainConfig.tpix.fallbackRpcs, contains(_a));
    expect(RpcPool.forChain(ChainConfig.tpix).orderedUrls(), [_b, _a]);
    // RPC จากทะเบียนบนเซิร์ฟเวอร์มาก่อน แต่ไม่ซ้ำกับตัวในแอป
    expect(RpcPool.forChain(ChainConfig.tpix).orderedUrls(preferred: _b),
        [_b, _a]);
  });
}
