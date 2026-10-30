/// TPIX TRADE — เทสต์บริการสะพาน USDT (ไม่ยิงเน็ต/เชนจริง)
///
/// สิ่งที่ต้องกันพลาด:
///   - fail-closed: เว็บบอกเปิดแต่ที่อยู่สัญญาไม่ครบ/ผิดรูป/เชน/ทศนิยมไม่ตรง = ไม่เปิดฟอร์ม
///   - เชนที่เว็บปิด (ETH/Polygon/Arbitrum/OP/Avalanche) ต้องไม่ถูกเสนอ · BSC USDT = 18 ทศนิยม
///   - USDT ที่เว็บบอกต้องตรงกับที่แอปรู้จัก (กันเซิร์ฟเวอร์ถูกแก้ให้ชี้สัญญาปลอม)
///   - ด่านก่อนยืนยัน: chain id ของ RPC · ทศนิยมจริง · ยอด · จำลองธุรกรรม · ค่าแก๊ส
library;

import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:tpix_trade/services/bridge_rules.dart';
import 'package:tpix_trade/services/bridge_service.dart';
import 'package:web3dart/json_rpc.dart' show RPCError;

const vault = '0xf46131c82819d7621163f482b3fe88a228a7807c';
const usdtTpix = '0x0a4ee87551ef041f3eecfbbfdcffc1b46a4878f1';
const bscUsdt = '0x55d398326f99059fF775485246999027B3197955';
const owner = '0x1111111111111111111111111111111111111111';
final e18 = BigInt.from(10).pow(18);

/// ค่าเดียวกับ https://tpix.online/api/v1/bridge/info ตอน 2026-10-21 (เปิดแค่ BSC)
Map<String, dynamic> livePayload({
  bool enabled = true,
  Object? vaultAddress = vault,
  int tpixChainId = 4289,
  Object? usdtTpixAddress = usdtTpix,
  int usdtTpixDecimals = 6,
  Object? bscUsdtAddress = bscUsdt,
  int bscDecimals = 18,
}) =>
    {
      'enabled': enabled,
      'installed': true,
      'paused_reason': null,
      'vault_address': vaultAddress,
      'deposit_max_per_tx': '1000',
      'tpix': {'chain_id': tpixChainId, 'usdt_tpix': usdtTpixAddress, 'usdt_decimals': usdtTpixDecimals, 'explorer': 'https://explorer.tpix.online'},
      'minter_address': '0x5c4bc48f2b20bcbfabbfd35c23c023f72ab9d90a',
      'minter': {'max_per_tx': '1000', 'daily_cap': '5000', 'remaining_today': '5000', 'paused': false},
      'chains': [
        {'chain_id': 1, 'name': 'Ethereum', 'native': 'ETH', 'enabled': false, 'usdt_address': '0xdAC17F958D2ee523a2206206994597C13D831ec7', 'usdt_decimals': 6, 'usdt_symbol': 'USDT', 'explorer': 'https://etherscan.io', 'withdraw_fee': '1', 'eta_minutes': 20},
        {'chain_id': 56, 'name': 'BNB Smart Chain', 'native': 'BNB', 'enabled': true, 'usdt_address': bscUsdtAddress, 'usdt_decimals': bscDecimals, 'usdt_symbol': 'USDT', 'explorer': 'https://bscscan.com', 'withdraw_fee': '0.3', 'eta_minutes': 2},
        {'chain_id': 137, 'name': 'Polygon PoS', 'native': 'POL', 'enabled': false, 'usdt_address': '0xc2132D05D31c914a87C6611C10748AEb04B58e8F', 'usdt_decimals': 6, 'usdt_symbol': 'USDT0', 'explorer': 'https://polygonscan.com', 'withdraw_fee': '0.3', 'eta_minutes': 3},
        {'chain_id': 42161, 'name': 'Arbitrum One', 'native': 'ETH', 'enabled': false, 'usdt_address': '0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9', 'usdt_decimals': 6, 'usdt_symbol': 'USD₮0', 'explorer': 'https://arbiscan.io', 'withdraw_fee': '0.3', 'eta_minutes': 20},
        {'chain_id': 10, 'name': 'OP Mainnet', 'native': 'ETH', 'enabled': false, 'usdt_address': '0x94b008aA00579c1307B0EF2c499aD98a8ce58e58', 'usdt_decimals': 6, 'usdt_symbol': 'USDT', 'explorer': 'https://optimistic.etherscan.io', 'withdraw_fee': '0.3', 'eta_minutes': 25},
        {'chain_id': 43114, 'name': 'Avalanche C-Chain', 'native': 'AVAX', 'enabled': false, 'usdt_address': '0x9702230A8Ea53601f5cD2dc00fDBc13d4dF4A8c7', 'usdt_decimals': 6, 'usdt_symbol': 'USDt', 'explorer': 'https://snowtrace.io', 'withdraw_fee': '0.3', 'eta_minutes': 2},
      ],
      'limits': {'min_deposit': '1', 'max_deposit_per_tx': '1000', 'max_deposit_per_address_daily': '2000', 'deposit_fee': '0', 'min_withdraw': '2', 'max_withdraw_per_tx': '1000', 'max_payout_daily': '5000'},
    };

/// เชนปลอมสำหรับด่านตรวจ — แก้ทีละค่าในแต่ละเทสต์
class FakeReader implements BridgeChainReader {
  BigInt id;
  int decimals;
  BigInt tokenBal;
  BigInt nativeBal;
  BigInt price;
  Object? estimateError;
  Object? decimalsError;
  final calls = <String>[];

  FakeReader({
    int chainId = 56,
    this.decimals = 18,
    BigInt? tokenBal,
    BigInt? nativeBal,
    BigInt? price,
  })  : id = BigInt.from(chainId),
        tokenBal = tokenBal ?? BigInt.from(100) * e18,
        nativeBal = nativeBal ?? e18,
        price = price ?? BigInt.from(1000000000);

  @override
  Future<BigInt> chainId() async {
    calls.add('chainId');
    return id;
  }

  @override
  Future<int> tokenDecimals(String token) async {
    calls.add('decimals');
    if (decimalsError != null) throw decimalsError!;
    return decimals;
  }

  @override
  Future<BigInt> tokenBalance(String token, String owner) async => tokenBal;

  @override
  Future<BigInt> nativeBalance(String owner) async => nativeBal;

  @override
  Future<BigInt> estimateGas({required String from, required String to, required Uint8List data}) async {
    calls.add('estimate');
    if (estimateError != null) throw estimateError!;
    return BigInt.from(50000);
  }

  @override
  Future<BigInt> gasPrice() async => price;
}

Future<BridgeTxPlan> runCheck(FakeReader r, {int chainId = 56, BigInt? amount, int decimals = 18, String token = bscUsdt}) =>
    BridgeTx.check(
      r,
      chainId: chainId,
      nativeSymbol: chainId == 4289 ? 'TPIX' : 'BNB',
      from: owner,
      to: token,
      data: BridgeTx.encodeTransfer(vault, amount ?? BigInt.from(25) * e18),
      spend: (token: token, amount: amount ?? BigInt.from(25) * e18, decimals: decimals),
    );

Matcher throwsKey(String key) =>
    throwsA(isA<BridgeCheckException>().having((e) => e.key, 'key', key));

void main() {
  group('UsdtBridgeInfo.fromServer — ข้อมูลจริงจาก /bridge/info', () {
    test('เปิดอยู่ · ฝาก/ถอนได้เฉพาะ BSC · BSC USDT 18 ทศนิยม · เชนที่ปิดไม่ถูกเสนอ', () {
      final info = UsdtBridgeInfo.fromServer(livePayload());
      expect(info.open, isTrue);
      expect(info.depositChains.map((c) => c.chainId), [56]);
      expect(info.withdrawChains.map((c) => c.chainId), [56]);
      final bsc = info.chain(56)!;
      expect(bsc.usdtDecimals, 18);
      expect(bsc.withdrawFee, '0.3');
      expect(bsc.matchesAppToken, isTrue);
      for (final off in [1, 137, 42161, 10, 43114]) {
        expect(info.chain(off)!.depositable, isFalse, reason: '$off');
        expect(info.chain(off)!.withdrawable, isFalse, reason: '$off');
      }
      expect(info.depositMaxPerTx, '1000');
      expect(info.minterRemainingToday, '5000');
      expect(info.minterPaused, isFalse);
      expect(info.limits['max_deposit_per_address_daily'], '2000');
      expect(info.depositFee, '0');
    });

    test('fail-closed: enabled=false / vault ผิด / chain id ผิด / ทศนิยม USDT_TPIX ผิด → ไม่เปิดฟอร์ม', () {
      expect(UsdtBridgeInfo.fromServer(livePayload(enabled: false)).open, isFalse);
      expect(UsdtBridgeInfo.fromServer(livePayload(vaultAddress: null)).open, isFalse);
      expect(UsdtBridgeInfo.fromServer(livePayload(vaultAddress: '0x0000000000000000000000000000000000000000')).open, isFalse);
      expect(UsdtBridgeInfo.fromServer(livePayload(vaultAddress: '0xF46131C82819d7621163F482b3fe88a228A7807c')).open, isFalse);
      expect(UsdtBridgeInfo.fromServer(livePayload(tpixChainId: 4290)).open, isFalse);
      expect(UsdtBridgeInfo.fromServer(livePayload(usdtTpixAddress: 'abc')).open, isFalse);
      expect(UsdtBridgeInfo.fromServer(livePayload(usdtTpixDecimals: 18)).open, isFalse);
      // enabled ต้องเป็น true จริง ไม่ใช่ "true"
      expect(UsdtBridgeInfo.fromServer(livePayload()..['enabled'] = 'true').open, isFalse);
    });

    test('USDT บน BSC ที่เว็บบอกไม่ตรงกับที่แอปรู้จัก (ที่อยู่หรือทศนิยม) → ไม่เปิดให้ฝากจาก BSC', () {
      final fakeToken = UsdtBridgeInfo.fromServer(livePayload(bscUsdtAddress: '0x2222222222222222222222222222222222222222'));
      expect(fakeToken.depositChains, isEmpty);
      // ถอนไม่ต้องเซ็นบน BSC — ยังเลือกเป็นปลายทางได้
      expect(fakeToken.withdrawChains.map((c) => c.chainId), [56]);
      expect(UsdtBridgeInfo.fromServer(livePayload(bscDecimals: 6)).depositChains, isEmpty);
    });

    test('เชนที่แอปไม่มี RPC (Arbitrum) แม้เว็บเปิด → ฝากไม่ได้ แต่ถอนไปได้', () {
      final p = livePayload();
      (p['chains'] as List)[3]['enabled'] = true;
      final info = UsdtBridgeInfo.fromServer(p);
      expect(info.chain(42161)!.depositable, isFalse);
      expect(info.chain(42161)!.withdrawable, isTrue);
      expect(info.depositChains.map((c) => c.chainId), [56]);
    });

    test('ค่าธรรมเนียมถอนอ่านไม่ออก = ถอนไปเชนนั้นไม่ได้ (ไม่เดาว่า 0)', () {
      final p = livePayload();
      (p['chains'] as List)[1]['withdraw_fee'] = 'free';
      expect(UsdtBridgeInfo.fromServer(p).withdrawChains, isEmpty);
    });

    test('ข้อมูลขยะไม่ทำให้พัง', () {
      final info = UsdtBridgeInfo.fromServer({'chains': 'x', 'limits': 3, 'tpix': null});
      expect(info.open, isFalse);
      expect(info.chains, isEmpty);
      expect(info.limits, isEmpty);
    });
  });

  group('BridgeApi', () {
    BridgeApi apiReturning(http.Response Function(http.Request) handler, List<Uri> seen) => BridgeApi(
          baseUrl: 'https://tpix.test/api/v1/bridge',
          clientFactory: () => MockClient((req) async {
            seen.add(req.url);
            return handler(req);
          }),
        );

    http.Response ok(Object data) =>
        http.Response(jsonEncode({'success': true, 'data': data}), 200, headers: const {'content-type': 'application/json; charset=utf-8'});

    test('info / status / withdrawals / history ยิงถูก path (hash/ที่อยู่ตัวเล็ก)', () async {
      final seen = <Uri>[];
      final api = apiReturning((req) {
        final p = req.url.path;
        if (p.endsWith('/info')) return ok(livePayload());
        if (p.contains('/status/')) return ok({'state': 'confirming', 'confirmations': 3, 'amount': '25', 'final': false});
        if (p.contains('/withdrawals/')) return ok({'state': 'paid', 'withdrawals': [{'payout_amount': '24.7', 'target_chain_id': 56, 'target_address': vault, 'payout_url': 'https://bscscan.com/tx/0x1'}]});
        if (p.contains('/history/')) {
          return ok({
            'deposits': [{'status': 'confirmed', 'amount': '25', 'source_chain_id': 56, 'tx_hash': '0x${'aa' * 32}'}],
            'withdrawals': [{'status': 'ignored', 'amount': '0.1', 'target_chain_id': 56, 'tx_hash': 'bad'}],
          });
        }
        return http.Response('nope', 404);
      }, seen);

      expect((await api.fetchInfo()).open, isTrue);
      final hash = '0x${'AB' * 32}';
      final s = await api.fetchDepositStatus(56, hash);
      expect(s.state, 'confirming');
      expect(s.confirmations, 3);
      final w = await api.fetchWithdrawal(hash);
      expect(w.state, 'paid');
      expect(w.rows.single.amount, '24.7');
      expect(w.rows.single.targetChainId, 56);
      final h = await api.fetchHistory('0xAAAA000000000000000000000000000000001111');
      expect(h.first.state, 'queued');
      expect(h.last.state, 'below_fee');
      expect(h.last.txHash, isNull);

      expect(seen[1].path, '/api/v1/bridge/status/56/0x${'ab' * 32}');
      expect(seen[2].path, '/api/v1/bridge/withdrawals/0x${'ab' * 32}');
      expect(seen[3].path, '/api/v1/bridge/history/0xaaaa000000000000000000000000000000001111');
    });

    test('429 = จำกัดความถี่ · 5xx/HTML/success:false = ติดต่อไม่ได้ (ไม่มี Exception ดิบ)', () async {
      expect(apiReturning((_) => http.Response('', 429), []).fetchInfo(), throwsA(isA<BridgeRateLimitedException>()));
      expect(apiReturning((_) => http.Response('', 502), []).fetchInfo(), throwsA(isA<BridgeUnavailableException>()));
      expect(apiReturning((_) => http.Response('<html>challenge</html>', 200), []).fetchInfo(),
          throwsA(isA<BridgeUnavailableException>()));
      expect(apiReturning((_) => http.Response('{"success":false}', 200), []).fetchInfo(),
          throwsA(isA<BridgeUnavailableException>()));
    });

    test('hash/ที่อยู่ผิดรูป = ไม่ยิงเลย', () async {
      final seen = <Uri>[];
      final api = apiReturning((_) => ok({}), seen);
      expect(api.fetchDepositStatus(56, '0x12'), throwsA(isA<BridgeUnavailableException>()));
      expect(api.fetchWithdrawal('nope'), throwsA(isA<BridgeUnavailableException>()));
      expect(await api.fetchHistory('0x12'), isEmpty);
      expect(seen, isEmpty);
    });
  });

  group('BridgeTx.check — ด่านก่อนให้ผู้ใช้ยืนยัน', () {
    test('ผ่านครบ: ตรวจ chain id ก่อน · แก๊สเผื่อ 30% · ค่าแก๊สบนกล่องยืนยัน', () async {
      final r = FakeReader();
      final plan = await runCheck(r);
      expect(r.calls.first, 'chainId');
      expect(plan.chainId, 56);
      expect(plan.gasLimit, 65000);
      expect(plan.maxFee, BigInt.from(65000) * BigInt.from(1000000000));
      expect(BridgeTx.feeText(plan), '0.000065');
      expect(plan.to, bscUsdt);
    });

    test('RPC ตอบเชนอื่น → ไม่ไปต่อ (ยังไม่จำลอง/ไม่อ่านยอด)', () async {
      final r = FakeReader(chainId: 97);
      await expectLater(runCheck(r), throwsKey('bridge.errWrongChain'));
      expect(r.calls, ['chainId']);
    });

    test('ทศนิยมบนเชนไม่ตรงกับเว็บ / decimals() อ่านไม่ได้ → token mismatch', () async {
      await expectLater(runCheck(FakeReader(decimals: 6)), throwsKey('bridge.errTokenMismatch'));
      final r = FakeReader()..decimalsError = const BridgeBadCallResult();
      await expectLater(runCheck(r), throwsKey('bridge.errTokenMismatch'));
    });

    test('ยอดโทเค็นไม่พอ → ยอดไม่พอ (ยังไม่จำลอง)', () async {
      final r = FakeReader(tokenBal: BigInt.from(24) * e18);
      await expectLater(runCheck(r), throwsKey('bridge.errInsufficient'));
      expect(r.calls.contains('estimate'), isFalse);
    });

    test('จำลองแล้วสัญญาปฏิเสธ → would revert / paused', () async {
      final r = FakeReader()..estimateError = RPCError(-32000, 'execution reverted', null);
      await expectLater(runCheck(r), throwsKey('bridge.errWouldRevert'));
      final p = FakeReader()..estimateError = RPCError(-32000, 'execution reverted: Pausable: paused', null);
      await expectLater(runCheck(p), throwsKey('bridge.errPausedOnChain'));
    });

    test('BNB ไม่พอจ่ายแก๊ส → บอกจำนวนที่ต้องมี', () async {
      final r = FakeReader(nativeBal: BigInt.from(1000));
      await expectLater(
        runCheck(r),
        throwsA(isA<BridgeCheckException>()
            .having((e) => e.key, 'key', 'bridge.errGasNeed')
            .having((e) => e.params['symbol'], 'symbol', 'BNB')),
      );
    });

    test('ถอนบนเชน TPIX: แก๊ส 0 ไม่ต้องมีเหรียญหลัก', () async {
      final r = FakeReader(chainId: 4289, decimals: 6, tokenBal: BigInt.from(30000000), nativeBal: BigInt.zero, price: BigInt.zero);
      final units = BigInt.from(25000000);
      final plan = await BridgeTx.check(
        r,
        chainId: 4289,
        nativeSymbol: 'TPIX',
        from: owner,
        to: usdtTpix,
        data: BridgeTx.encodeBridgeBurn(units, 56, owner),
        spend: (token: usdtTpix, amount: units, decimals: 6),
      );
      expect(plan.gasPrice, BigInt.zero);
      expect(plan.maxFee, BigInt.zero);
    });

    test('ที่อยู่ผิดรูป → ไม่ตรวจต่อ', () async {
      final r = FakeReader();
      await expectLater(
        BridgeTx.check(r, chainId: 56, nativeSymbol: 'BNB', from: '0x12', to: bscUsdt, data: Uint8List(0)),
        throwsKey('bridge.errNotConfigured'),
      );
      expect(r.calls, isEmpty);
    });

    test('BridgeOnChain: เชนที่แอปไม่มี RPC → ไม่รองรับ (ไม่ตกไปใช้ RPC ของเชน TPIX)', () async {
      await expectLater(
        const BridgeOnChain().prepare(chainId: 42161, from: owner, to: bscUsdt, data: Uint8List(0)),
        throwsKey('bridge.errChainUnsupported'),
      );
      expect(BridgeChains.config(42161), isNull);
      expect(BridgeChains.canDepositFrom(4289), isFalse);
      expect(BridgeChains.canDepositFrom(56), isTrue);
    });
  });
}
