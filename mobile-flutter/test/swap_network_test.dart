/// TPIX TRADE — หน้าสลับเหรียญ: เลือกเครือข่าย BSC / TPIX Chain
///
/// สิ่งที่กันไว้:
///   - โหมด TPIX เปิดได้เฉพาะเมื่อทะเบียน DEX บนเซิร์ฟเวอร์พร้อมจริง (fail-closed)
///   - BSC = chain 56 ตายตัว ไม่ขึ้นกับเชนปริยาย/ทะเบียน DEX
///   - รายการเหรียญโหมด TPIX มาจากคู่บนเชน 4289 ที่ส่งคำสั่งได้จริงเท่านั้น
///     (ใช้ network_chain_id ไม่ใช่ chain_id ที่เป็น PK ของตาราง chains)
///   - เหรียญปลอมชื่อ TPIX/USDT, คู่ชื่อชนตลาดหลัก, WTPIX, ชื่อซ้ำกำกวม ต้องไม่หลุดเข้ารายการ
///
/// Developed by Xman Studio
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/models/api_models.dart';
import 'package:tpix_trade/services/bsc_swap_service.dart' show SwapException;
import 'package:tpix_trade/services/swap_network.dart';
import 'package:tpix_trade/services/tpix_dex_service.dart';

const _native = '0x0000000000000000000000000000000000000000';
const _usdt = '0x0a4ee87551ef041f3eecfbbfdcffc1b46a4878f1';
const _wtpix = '0x1111111111111111111111111111111111111111';
const _router = '0x2222222222222222222222222222222222222222';
const _factory = '0x3333333333333333333333333333333333333333';
const _idtc = '0x1234567890abcdef1234567890abcdef12345678';
const _fake = '0x9999999999999999999999999999999999999999';

const _ready = TpixDexConfig(
  ready: true,
  chainId: 4289,
  rpc: 'https://rpc.tpix.online',
  wtpix: _wtpix,
  usdt: _usdt,
  factory: _factory,
  router: _router,
);

TradingPairInfo _sp(
  String base,
  String? baseAddr,
  String quote,
  String? quoteAddr, {
  int? networkChainId = 4289,
  int? pk = 7,
  String mode = 'onchain',
  bool active = true,
  int baseDec = 18,
  int quoteDec = 6,
}) =>
    TradingPairInfo(
      symbol: '$base-$quote',
      baseAsset: base,
      quoteAsset: quote,
      minTradeAmount: 0,
      maxTradeAmount: 0,
      pricePrecision: 8,
      amountPrecision: 4,
      chainId: pk,
      networkChainId: networkChainId,
      executionMode: mode,
      baseAddress: baseAddr,
      quoteAddress: quoteAddr,
      baseDecimals: baseDec,
      quoteDecimals: quoteDec,
      isActive: active,
    );

DexPairInfo _vp(String base, String baseAddr, String quote, String quoteAddr,
        {int baseDec = 18, int quoteDec = 6, bool active = true}) =>
    DexPairInfo(
      symbol: '$base-$quote',
      baseAsset: base,
      quoteAsset: quote,
      baseAddress: baseAddr,
      quoteAddress: quoteAddr,
      baseDecimals: baseDec,
      quoteDecimals: quoteDec,
      isActive: active,
    );

List<String> _symbols(List<TpixSwapToken> list) =>
    list.map((t) => t.symbol).toList();

void main() {
  group('โหมด TPIX เปิดได้ไหม (fail-closed)', () {
    final two = [
      const TpixSwapToken(symbol: 'TPIX', address: _native, decimals: 18),
      const TpixSwapToken(symbol: 'USDT', address: _usdt, decimals: 6),
    ];

    test('ทะเบียนพร้อม + มีเหรียญ 2 ตัว = เปิด', () {
      expect(SwapNetworkRules.tpixDexUsable(_ready), isTrue);
      expect(SwapNetworkRules.tpixModeAvailable(_ready, two), isTrue);
    });

    test('ทะเบียนยังไม่ ready = ปิด (ค่าเริ่มต้นของแอปก่อนโหลด)', () {
      expect(SwapNetworkRules.tpixDexUsable(TpixDexConfig.notReady), isFalse);
      expect(
          SwapNetworkRules.tpixModeAvailable(TpixDexConfig.notReady, two), isFalse);
    });

    test('ready แต่ chain id ไม่ใช่เชน TPIX ที่แอปอ่าน RPC = ปิด', () {
      const cfg = TpixDexConfig(
        ready: true,
        chainId: 4290,
        rpc: 'x',
        wtpix: _wtpix,
        usdt: _usdt,
        router: _router,
      );
      expect(SwapNetworkRules.tpixModeAvailable(cfg, two), isFalse);
    });

    test('ready แต่ไม่มี router / router เป็น 0x0 / ผิดรูปแบบ = ปิด', () {
      for (final router in [null, _native, '0x1234', 'not-an-address']) {
        final cfg = TpixDexConfig(
          ready: true,
          chainId: 4289,
          rpc: 'x',
          wtpix: _wtpix,
          usdt: _usdt,
          router: router,
        );
        expect(SwapNetworkRules.tpixDexUsable(cfg), isFalse, reason: '$router');
      }
    });

    test('ready แต่ไม่มี WTPIX = ปิด (native TPIX วิ่งผ่าน WTPIX)', () {
      const cfg = TpixDexConfig(
          ready: true, chainId: 4289, rpc: 'x', usdt: _usdt, router: _router);
      expect(SwapNetworkRules.tpixDexUsable(cfg), isFalse);
    });

    test('เหรียญน้อยกว่า 2 ตัว = ปิด แม้ทะเบียนพร้อม', () {
      expect(SwapNetworkRules.tpixModeAvailable(_ready, const []), isFalse);
      expect(SwapNetworkRules.tpixModeAvailable(_ready, two.take(1).toList()),
          isFalse);
    });
  });

  group('chain id ของแต่ละเครือข่าย', () {
    test('BSC = 56 เสมอ ไม่ว่าทะเบียน DEX จะเป็นอย่างไร', () {
      expect(SwapNetworkRules.chainIdFor(SwapNetwork.bsc, _ready), 56);
      expect(
          SwapNetworkRules.chainIdFor(SwapNetwork.bsc, TpixDexConfig.notReady),
          56);
    });

    test('TPIX = 4289 เมื่อพร้อม', () {
      expect(SwapNetworkRules.chainIdFor(SwapNetwork.tpix, _ready), 4289);
    });

    test('TPIX ไม่พร้อม = null (ห้ามส่ง ไม่ใช่ตกไปเชนปริยาย)', () {
      expect(
          SwapNetworkRules.chainIdFor(
              SwapNetwork.tpix, TpixDexConfig.notReady),
          isNull);
      const wrong = TpixDexConfig(
          ready: true,
          chainId: 56,
          rpc: 'x',
          wtpix: _wtpix,
          usdt: _usdt,
          router: _router);
      expect(SwapNetworkRules.chainIdFor(SwapNetwork.tpix, wrong), isNull);
    });
  });

  group('รายการเหรียญโหมด TPIX', () {
    test('TPIX-USDT จากเซิร์ฟเวอร์ = TPIX เนทีฟ + USDT ตัวจริง (ก่อน /dex/pairs โหลด)', () {
      final list = SwapNetworkRules.buildTpixTokens(
        dex: _ready,
        serverPairs: [_sp('TPIX', _native, 'USDT', _usdt)],
        verifiedPairs: const {},
      );
      expect(_symbols(list), ['TPIX', 'USDT']);
      expect(list[0].native, isTrue);
      expect(list[0].verifiedMajor, isTrue);
      expect(list[1].address, _usdt);
      expect(list[1].verifiedMajor, isTrue);
    });

    test('ที่อยู่ base ว่าง/null ของ TPIX = เนทีฟ (ตามที่ฐานข้อมูลเก็บ)', () {
      final list = SwapNetworkRules.buildTpixTokens(
        dex: _ready,
        serverPairs: [_sp('TPIX', null, 'USDT', _usdt)],
        verifiedPairs: const {},
      );
      expect(_symbols(list), ['TPIX', 'USDT']);
    });

    test('ทะเบียนไม่พร้อม = รายการว่าง', () {
      final list = SwapNetworkRules.buildTpixTokens(
        dex: TpixDexConfig.notReady,
        serverPairs: [_sp('TPIX', _native, 'USDT', _usdt)],
        verifiedPairs: const {},
      );
      expect(list, isEmpty);
    });

    test('กับดัก chain_id: PK ของตาราง chains = 4289 แต่เชนจริงไม่ใช่ → ไม่นับ', () {
      final list = SwapNetworkRules.buildTpixTokens(
        dex: _ready,
        serverPairs: [
          _sp('TPIX', _native, 'USDT', _usdt, pk: 4289, networkChainId: 56),
          _sp('TPIX', _native, 'USDT', _usdt, pk: 4289, networkChainId: null),
        ],
        verifiedPairs: const {},
      );
      expect(list, isEmpty);
    });

    test('คู่ดัชนี (ดูราคาอย่างเดียว) และคู่ที่ปิดอยู่ ไม่นับ', () {
      final list = SwapNetworkRules.buildTpixTokens(
        dex: _ready,
        serverPairs: [
          _sp('TPIX', _native, 'USDT', _usdt, mode: 'index'),
          _sp('TPIX', _native, 'USDT', _usdt, active: false),
        ],
        verifiedPairs: const {},
      );
      expect(list, isEmpty);
    });

    test('USDT ปลอม (ที่อยู่ไม่ตรงทะเบียน) / TPIX ที่ไม่ใช่เนทีฟ ไม่นับ', () {
      final list = SwapNetworkRules.buildTpixTokens(
        dex: _ready,
        serverPairs: [
          _sp('TPIX', _native, 'USDT', _fake),
          _sp('TPIX', _fake, 'USDT', _usdt),
        ],
        verifiedPairs: const {},
      );
      expect(list, isEmpty);
    });

    test('เหรียญลูกต้องผ่านชุดที่ตลาดตรวจแล้ว และที่อยู่สองขาตรงกัน', () {
      final ok = SwapNetworkRules.buildTpixTokens(
        dex: _ready,
        serverPairs: [
          _sp('TPIX', _native, 'USDT', _usdt),
          _sp('IDTC', _idtc, 'USDT', _usdt),
        ],
        verifiedPairs: {'IDTC-USDT': _vp('IDTC', _idtc, 'USDT', _usdt)},
      );
      expect(_symbols(ok), ['TPIX', 'USDT', 'IDTC']);
      final idtc = ok.firstWhere((t) => t.symbol == 'IDTC');
      expect(idtc.verifiedMajor, isFalse); // ห้ามเดาโลโก้จากชื่อ

      // ชื่อคู่ไม่อยู่ในชุดที่ตรวจแล้ว (เช่นชนกับคู่ตลาดหลัก SUI-USDT) = ไม่นับ
      final shadowed = SwapNetworkRules.buildTpixTokens(
        dex: _ready,
        serverPairs: [_sp('SUI', _fake, 'USDT', _usdt)],
        verifiedPairs: const {},
      );
      expect(shadowed, isEmpty);

      // ชื่อเดียวกันแต่ที่อยู่คนละตัวกับที่ตรวจแล้ว = ไม่นับ
      final mismatch = SwapNetworkRules.buildTpixTokens(
        dex: _ready,
        serverPairs: [_sp('IDTC', _fake, 'USDT', _usdt)],
        verifiedPairs: {'IDTC-USDT': _vp('IDTC', _idtc, 'USDT', _usdt)},
      );
      // คู่จากเซิร์ฟเวอร์ถูกทิ้ง แต่คู่ที่ตรวจแล้ว (ที่อยู่จริง) ยังเข้าได้ทางสำรอง
      expect(mismatch.map((t) => t.address), isNot(contains(_fake)));
      expect(mismatch.map((t) => t.address), contains(_idtc));
    });

    test('คู่ที่เพิ่งเปิด (มีแค่ใน /dex/pairs) เข้ารายการได้ — กติกาเดียวกับหน้าเทรด', () {
      final list = SwapNetworkRules.buildTpixTokens(
        dex: _ready,
        serverPairs: const [],
        verifiedPairs: {'IDTC-TPIX': _vp('IDTC', _idtc, 'TPIX', _native)},
      );
      expect(_symbols(list), ['TPIX', 'IDTC']);
    });

    test('คู่ที่ตรวจแล้วแต่ปิด/อยู่เชนอื่น ไม่นับ', () {
      final list = SwapNetworkRules.buildTpixTokens(
        dex: _ready,
        serverPairs: const [],
        verifiedPairs: {
          'IDTC-USDT': _vp('IDTC', _idtc, 'USDT', _usdt, active: false),
        },
      );
      expect(list, isEmpty);
    });

    test('WTPIX และเหรียญที่เป็นที่อยู่ WTPIX แต่ตั้งชื่อใหม่ ไม่นับ (ห่อ/แกะ ≠ สวอป)', () {
      final list = SwapNetworkRules.buildTpixTokens(
        dex: _ready,
        serverPairs: const [],
        verifiedPairs: {
          'WTPIX-USDT': _vp('WTPIX', _wtpix, 'USDT', _usdt),
          'WRAP-USDT': _vp('WRAP', _wtpix, 'USDT', _usdt),
        },
      );
      expect(list, isEmpty);
    });

    test('ชื่อซ้ำคนละที่อยู่ = ตัดทิ้งทั้งคู่ (ผู้ใช้แยกไม่ออก)', () {
      final list = SwapNetworkRules.buildTpixTokens(
        dex: _ready,
        serverPairs: const [],
        verifiedPairs: {
          'FOO-USDT': _vp('FOO', _idtc, 'USDT', _usdt),
          'FOO-TPIX': _vp('FOO', _fake, 'TPIX', _native),
        },
      );
      expect(_symbols(list), ['TPIX', 'USDT']);
    });

    test('decimals ผิดช่วง ไม่นับ', () {
      final list = SwapNetworkRules.buildTpixTokens(
        dex: _ready,
        serverPairs: const [],
        verifiedPairs: {
          'IDTC-USDT': _vp('IDTC', _idtc, 'USDT', _usdt, baseDec: 77),
        },
      );
      expect(list, isEmpty);
    });

    test('โลโก้จาก token list ที่รับรองมาก่อนโลโก้ของคู่', () {
      final list = SwapNetworkRules.buildTpixTokens(
        dex: _ready,
        serverPairs: const [],
        verifiedPairs: {
          'IDTC-USDT': DexPairInfo(
            symbol: 'IDTC-USDT',
            baseAsset: 'IDTC',
            quoteAsset: 'USDT',
            baseAddress: _idtc,
            quoteAddress: _usdt,
            baseDecimals: 18,
            quoteDecimals: 6,
            baseLogo: 'https://cdn.example/pair.png',
          ),
        },
        approvedLogo: (a) => a == _idtc ? 'https://cdn.example/listed.png' : null,
      );
      expect(list.firstWhere((t) => t.symbol == 'IDTC').logo,
          'https://cdn.example/listed.png');
    });

    test('ที่อยู่ตัวพิมพ์ใหญ่/เล็กปนกัน = เหรียญเดียวกัน (ไม่ซ้ำในรายการ)', () {
      final list = SwapNetworkRules.buildTpixTokens(
        dex: _ready,
        serverPairs: [
          _sp('IDTC', _idtc.toUpperCase().replaceFirst('0X', '0x'), 'USDT',
              _usdt),
        ],
        verifiedPairs: {
          'IDTC-USDT': _vp('IDTC', _idtc, 'USDT', _usdt),
          'IDTC-TPIX': _vp('IDTC', _idtc, 'TPIX', _native),
        },
      );
      expect(_symbols(list), ['TPIX', 'USDT', 'IDTC']);
    });
  });

  group('คู่เริ่มต้น / สินทรัพย์เดียวกัน', () {
    const tpix = TpixSwapToken(
        symbol: 'TPIX', address: _native, decimals: 18, verifiedMajor: true);
    const usdt = TpixSwapToken(
        symbol: 'USDT', address: _usdt, decimals: 6, verifiedMajor: true);
    const idtc = TpixSwapToken(symbol: 'IDTC', address: _idtc, decimals: 18);

    test('เข้าโหมด TPIX = จ่าย TPIX รับ USDT', () {
      final d = SwapNetworkRules.defaultTpixSelection([tpix, usdt, idtc]);
      expect(d.from, _native);
      expect(d.to, _usdt);
    });

    test('ไม่มี USDT = รับเหรียญถัดไป · เหรียญไม่พอ = null', () {
      final d = SwapNetworkRules.defaultTpixSelection([tpix, idtc]);
      expect(d.from, _native);
      expect(d.to, _idtc);
      final none = SwapNetworkRules.defaultTpixSelection([tpix]);
      expect(none.from, isNull);
      expect(none.to, isNull);
    });

    test('TPIX เนทีฟกับโทเคนที่อยู่ WTPIX = สินทรัพย์เดียวกันใน router', () {
      const wrapped =
          TpixSwapToken(symbol: 'WRAP', address: _wtpix, decimals: 18);
      expect(SwapNetworkRules.sameDexAsset(tpix, wrapped, _ready), isTrue);
      expect(SwapNetworkRules.sameDexAsset(tpix, usdt, _ready), isFalse);
      expect(SwapNetworkRules.sameDexAsset(idtc, idtc, _ready), isTrue);
    });
  });

  group('ใช้สูงสุด (เหลือค่าแก๊สเฉพาะเมื่อจำเป็น)', () {
    final bal = BigInt.from(10).pow(18) * BigInt.from(5);
    final reserve = BigInt.from(10).pow(15);

    test('เชน TPIX ค่าแก๊ส 0: TPIX เนทีฟใช้ได้เต็มยอด', () {
      expect(
          SwapNetworkRules.maxSpendableWei(
              balanceWei: bal, native: true, gasless: true, gasReserveWei: reserve),
          bal);
    });

    test('โทเคนใช้ได้เต็มยอดเสมอ', () {
      expect(
          SwapNetworkRules.maxSpendableWei(
              balanceWei: bal, native: false, gasless: false, gasReserveWei: reserve),
          bal);
    });

    test('เหรียญเนทีฟบนเชนที่มีค่าแก๊ส: หักค่าแก๊สไว้ · ไม่ติดลบ', () {
      expect(
          SwapNetworkRules.maxSpendableWei(
              balanceWei: bal, native: true, gasless: false, gasReserveWei: reserve),
          bal - reserve);
      expect(
          SwapNetworkRules.maxSpendableWei(
              balanceWei: BigInt.from(10),
              native: true,
              gasless: false,
              gasReserveWei: reserve),
          BigInt.zero);
      expect(
          SwapNetworkRules.maxSpendableWei(
              balanceWei: BigInt.zero, native: false, gasless: true),
          BigInt.zero);
    });
  });

  group('ข้อความผิดพลาดจากกระเป๋า (ตามภาษาของแอป)', () {
    const fb = SwapException('Approval rejected.', 'การ approve ถูกปฏิเสธ');

    test('ไม่มีข้อความ = ใช้ข้อความสำรองตามภาษา', () {
      expect(SwapNetworkRules.walletErrorText(null, true, fb), fb.th);
      expect(SwapNetworkRules.walletErrorText('  ', false, fb), fb.en);
    });

    test('ข้อความไทยของกระเป๋า: ผู้ใช้ไทยเห็นตามจริง · ผู้ใช้อังกฤษเห็นข้อความสำรอง', () {
      const th = 'ธุรกรรมถูกปฏิเสธจาก wallet';
      expect(SwapNetworkRules.walletErrorText(th, true, fb), th);
      expect(SwapNetworkRules.walletErrorText(th, false, fb), fb.en);
    });

    test('ข้อความอังกฤษ: ผู้ใช้ไทยเห็นข้อความสำรองภาษาไทย', () {
      expect(SwapNetworkRules.walletErrorText('Wallet key not found', true, fb),
          fb.th);
      expect(SwapNetworkRules.walletErrorText('Wallet key not found', false, fb),
          'Wallet key not found');
    });

    test('ห้ามโชว์ข้อความดิบแบบ Exception: ...', () {
      expect(
          SwapNetworkRules.walletErrorText(
              'Exception: RPCError(-32000)', false, fb),
          fb.en);
    });
  });

  group('TradingPairInfo.isActive', () {
    test('is_active = false จากเซิร์ฟเวอร์ = ปิด · ไม่มีฟิลด์ = เปิด', () {
      final off = TradingPairInfo.fromJson({
        'symbol': 'TPIX-USDT',
        'execution_mode': 'onchain',
        'network_chain_id': 4289,
        'is_active': false,
      });
      expect(off.isActive, isFalse);
      final legacy = TradingPairInfo.fromJson({'symbol': 'TPIX-USDT'});
      expect(legacy.isActive, isTrue);
    });
  });

  test('ที่อยู่เนทีฟของ service ตรงกับที่รายการใช้', () {
    expect(TpixDexService.nativeAddress, _native);
  });
}
