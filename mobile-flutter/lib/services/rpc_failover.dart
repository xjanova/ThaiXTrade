/// TPIX TRADE — RPC สำรองอัตโนมัติ (อ่านยอด / quote / ส่งธุรกรรม)
///
/// ทำไมต้องมี: rpc.tpix.online อยู่หลัง Cloudflare ซึ่งบางครั้งบล็อกไคลเอนต์ (403)
/// เดิมแอปมีแค่ host เดียว → อ่านยอดไม่ได้ก็โชว์ 0, ส่งธุรกรรมก็ล้ม ทั้งที่
/// rpc1.tpix.online (ตรงเข้าโหนด) ยังใช้ได้ปกติ
///
/// กติกาการสลับ:
///   - สลับไปตัวถัดไปเฉพาะ "ส่งไม่ถึง/โหนดไม่ตอบ" — เน็ตหลุด, หมดเวลา, HTTP 4xx/5xx,
///     หรือได้หน้า HTML แทน JSON (หน้า challenge ของ Cloudflare)
///   - **ไม่**สลับเมื่อโหนดตอบ JSON-RPC error กลับมา (revert, nonce ต่ำ, ยอดไม่พอ)
///     เพราะโหนดอีกตัวก็จะตอบแบบเดียวกัน ถามซ้ำมีแต่ทำให้ช้าและซ่อนสาเหตุจริง
///   - จำตัวที่ใช้ได้ล่าสุดไว้ คำขอถัดไปเริ่มที่ตัวนั้นเลย ไม่ต้องไปชน 403 ทุกครั้ง
///
/// การส่งธุรกรรม: เซ็นครั้งเดียว (nonce ตายตัว) แล้วค่อยกระจายไบต์เดิมไปทีละโหนด
/// — ถ้าโหนดแรกรับไปแล้วแต่คำตอบหาย การส่งซ้ำไปโหนดที่สองจะเป็นธุรกรรมเดิม
/// (hash เดิม) ไม่มีทางกลายเป็นซื้อซ้ำสองไม้
///
/// Developed by Xman Studio
library;

import 'dart:async';
import 'dart:io' show IOException;

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:web3dart/crypto.dart' show keccak256, bytesToHex;
import 'package:web3dart/json_rpc.dart' show RPCError;
import 'package:web3dart/web3dart.dart';

import '../models/chain_config.dart';

/// โหนดตอบเป็น HTTP status ที่ไม่ใช่ 2xx (เช่น Cloudflare 403, 502 ระหว่างรีสตาร์ต)
class RpcHttpException implements Exception {
  final int statusCode;
  final String host;

  const RpcHttpException(this.statusCode, this.host);

  @override
  String toString() => 'RpcHttpException($statusCode from $host)';
}

/// http.Client ที่โยน [RpcHttpException] ทันทีเมื่อ status ไม่ใช่ 2xx
///
/// web3dart อ่าน body เป็น JSON ตรง ๆ โดยไม่ดู status — 403 ของ Cloudflare จึงกลาย
/// เป็น FormatException ปน ๆ กับ error อื่น ห่อไว้ให้แยกแยะได้ชัดว่าเป็นปัญหาการส่ง
class StatusCheckingClient extends http.BaseClient {
  final http.Client _inner;

  StatusCheckingClient(this._inner);

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    final response = await _inner.send(request);
    if (response.statusCode < 200 || response.statusCode >= 300) {
      // ทิ้ง body ให้ connection คืนเข้าพูล — ไม่ต้องอ่าน (มักเป็นหน้า HTML)
      unawaited(response.stream.drain<void>().catchError((_) {}));
      throw RpcHttpException(response.statusCode, request.url.host);
    }
    return response;
  }

  @override
  void close() => _inner.close();
}

class RpcFailover {
  RpcFailover._();

  /// error นี้ควรลอง RPC ตัวถัดไปไหม — true = ปัญหาการส่ง/โหนดไม่ตอบ
  static bool shouldFailover(Object error) {
    // โหนดตอบแล้วว่าทำไม่ได้ — ถามโหนดอื่นก็ได้คำตอบเดิม
    if (error is RPCError) return false;
    return error is RpcHttpException ||
        error is TimeoutException ||
        error is http.ClientException ||
        error is IOException ||
        // ได้ HTML/ข้อความแทน JSON — หน้า challenge หรือ proxy error ที่ตอบ 200
        error is FormatException;
  }

  /// เรียง RPC ให้ตัวที่ใช้ได้ล่าสุดมาก่อน ตัดค่าว่าง/ซ้ำทิ้ง (ลำดับเดิมคงไว้)
  static List<String> order(Iterable<String?> urls, {String? lastGood}) {
    final seen = <String>{};
    final cleaned = <String>[];
    for (final u in urls) {
      if (u == null) continue;
      final t = u.trim();
      if (t.isEmpty || !seen.add(t)) continue;
      cleaned.add(t);
    }
    if (lastGood != null && cleaned.remove(lastGood)) {
      cleaned.insert(0, lastGood);
    }
    return cleaned;
  }

  /// ลอง [attempt] กับ url ทีละตัวจนกว่าจะสำเร็จ
  ///
  /// สลับตัวถัดไปเฉพาะเมื่อ [shouldFailover] — error อื่นโยนออกทันที
  /// ล้มครบทุกตัว = โยน error ของตัวสุดท้าย (ผู้เรียกแปลเป็นข้อความเอง)
  static Future<T> run<T>(
    List<String> urls,
    Future<T> Function(String url) attempt, {
    Duration? timeout,
    void Function(String url)? onSuccess,
  }) async {
    if (urls.isEmpty) {
      throw const RpcHttpException(0, 'no-rpc-configured');
    }
    Object? lastError;
    StackTrace? lastStack;
    for (final url in urls) {
      try {
        final future = attempt(url);
        final result =
            timeout == null ? await future : await future.timeout(timeout);
        onSuccess?.call(url);
        return result;
      } catch (e, s) {
        if (!shouldFailover(e)) rethrow;
        debugPrint('RPC failover: ${Uri.tryParse(url)?.host} → ${e.runtimeType}');
        lastError = e;
        lastStack = s;
      }
    }
    Error.throwWithStackTrace(lastError!, lastStack!);
  }

  /// โหนดบอกว่ารู้จักธุรกรรมนี้แล้ว — ไบต์เดียวกันเคยถูกส่งถึงมาก่อน
  /// (geth: "already known" · รุ่นเก่า/besu: "known transaction" · nethermind: AlreadyKnown)
  static bool isAlreadyKnown(Object error) {
    if (error is! RPCError) return false;
    return RegExp(r'already\s*known|known transaction', caseSensitive: false)
        .hasMatch(error.message);
  }

  /// nonce ถูกใช้ไปแล้ว — ถ้าเราเคยส่งไบต์นี้ไปโหนดก่อนหน้า แปลว่าธุรกรรมเราเข้าเชนแล้ว
  static bool isNonceTooLow(Object error) {
    if (error is! RPCError) return false;
    return RegExp(r'nonce\s*too\s*low', caseSensitive: false)
        .hasMatch(error.message);
  }
}

/// ชุด RPC ของเชนหนึ่ง — จำตัวที่ใช้ได้ล่าสุด (แชร์ทั้งแอปต่อ chainId)
class RpcPool {
  final List<String> urls;

  /// สร้าง http.Client ใหม่ต่อคำขอ — เทสต์ฉีด MockClient เข้ามาแทนได้
  final http.Client Function() _httpFactory;

  String? _lastGood;

  RpcPool(this.urls, {http.Client Function()? httpClientFactory})
      : _httpFactory = httpClientFactory ?? http.Client.new;

  static final Map<int, RpcPool> _byChain = {};

  /// pool ของเชน — RPC หลักตามด้วยตัวสำรองใน [ChainConfig.fallbackRpcs]
  static RpcPool forChain(ChainConfig chain) => _byChain.putIfAbsent(
        chain.chainId,
        () => RpcPool([chain.rpcUrl, ...chain.fallbackRpcs]),
      );

  String? get lastGood => _lastGood;

  /// ลำดับที่จะลอง — [preferred] (เช่น RPC จากทะเบียนบนเซิร์ฟเวอร์) มาก่อนตัวในแอป
  /// แต่ถ้ามีตัวที่เพิ่งใช้ได้ ตัวนั้นมาก่อนสุด
  List<String> orderedUrls({String? preferred}) =>
      RpcFailover.order([preferred, ...urls], lastGood: _lastGood);

  /// ลองทีละ url แบบดิบ — ใช้ภายในและในเทสต์
  Future<T> runOnUrls<T>(
    Future<T> Function(String url) attempt, {
    String? preferred,
    Duration? timeout,
  }) =>
      RpcFailover.run(
        orderedUrls(preferred: preferred),
        attempt,
        timeout: timeout,
        onSuccess: (u) => _lastGood = u,
      );

  /// เรียก [fn] ด้วย Web3Client ของ RPC ที่ใช้ได้ — สลับตัวสำรองเองเมื่อส่งไม่ถึง
  Future<T> withClient<T>(
    Future<T> Function(Web3Client client) fn, {
    String? preferred,
    Duration timeout = const Duration(seconds: 15),
  }) =>
      runOnUrls((url) async {
        final client = Web3Client(url, StatusCheckingClient(_httpFactory()));
        try {
          return await fn(client).timeout(timeout);
        } finally {
          client.dispose();
        }
      }, preferred: preferred);

  /// กระจายธุรกรรมที่ "เซ็นแล้ว" ไปทีละโหนดจนมีตัวรับ — คืน tx hash
  ///
  /// hash คำนวณเองจากไบต์ (keccak256) จึงตอบได้แม้โหนดที่สองบอกว่า "รู้จักแล้ว"
  /// ห้ามเรียกด้วยธุรกรรมที่ยังไม่เซ็น — การเซ็นใหม่ทุกรอบคือต้นเหตุซื้อซ้ำ
  Future<String> broadcastRaw(
    Uint8List signedTx, {
    String? preferred,
    Duration timeout = const Duration(seconds: 20),
  }) {
    final localHash = signedTxHash(signedTx);
    var sentBefore = false;
    return withClient((client) async {
      // ตั้งธงก่อน await — ถ้ารอบนี้หมดเวลา คำขอยังวิ่งอยู่เบื้องหลังและอาจถึงโหนดแล้ว
      final retrying = sentBefore;
      sentBefore = true;
      try {
        return await client.sendRawTransaction(signedTx);
      } catch (e) {
        // ไบต์เดิมถึงโหนดแล้ว (จากรอบนี้หรือรอบก่อน) — คือธุรกรรมเดียวกัน ไม่ใช่ error
        if (RpcFailover.isAlreadyKnown(e)) return localHash;
        if (retrying && RpcFailover.isNonceTooLow(e)) return localHash;
        rethrow;
      }
    }, preferred: preferred, timeout: timeout);
  }

  /// tx hash ของธุรกรรมที่เซ็นแล้ว (legacy RLP) = keccak256 ของไบต์ทั้งก้อน
  static String signedTxHash(Uint8List signedTx) =>
      bytesToHex(keccak256(signedTx), include0x: true);
}
