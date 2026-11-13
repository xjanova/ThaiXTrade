/// TPIX TRADE — ค่าวางไม้ด้วยเครดิต TPIX + ใบอนุญาตวางไม้ (mobile)
///
/// เจ้าของสั่ง (2026-11-13): **ทุกไม้บน TPIX Chain (4289) ต้องจ่ายค่าวางไม้ด้วยเครดิต TPIX**
/// mirror มาจาก useTradingFee.js ฝั่งเว็บ (`freshQuote` / `isCreditOnly` / `sendWithTicket`):
///
///   1. ถามใบเสนอราคาสด `POST /trading-fee/quote` — ถามไม่ได้ = **ไม่ส่งไม้** (fail-closed)
///   2. "จ่ายด้วยเครดิตเท่านั้น" = `enabled && !waived && !onchain.available`
///        - ไม่ใช่ (ระบบปิด / ได้รับยกเว้น / จ่ายบนเชนได้) → ส่งไม้ตามเดิม ไม่มีใบอนุญาต
///        - ใช่ แต่ `can_place == false` → ไม่ส่ง บอกเหตุผลของเซิร์ฟเวอร์ + ให้ไปเติมเครดิต
///   3. ขอใบอนุญาต `POST /trading-fee/tickets` (หักเครดิตตรงนี้) แล้วจึงส่งสวอป
///   4. ธุรกรรมออกจากกระเป๋า → ผูกใบกับ tx ทันที (`/bind`) · ไม้ยืนยันแล้ว → ปิดใบ (`/consume`)
///   5. ไม่มีอะไรออกจากกระเป๋า (ผู้ใช้ปฏิเสธ / ด่านก่อนส่งไม่ผ่าน) → คืนค่าบริการ (`/refund`)
///      ธุรกรรมออกไปแล้วแต่ยังไม่รู้ผล/ล้ม → **ห้ามคืนเอง** — ใบที่ผูก tx ไว้ เซิร์ฟเวอร์ตัดสินจากเชน
///
/// ทุกเส้นทางอยู่หลัง VerifyWalletOwnership — ใช้เซสชันกระเป๋าที่ ApiService แนบให้อยู่แล้ว
///
/// Developed by Xman Studio
library;

import 'package:flutter/foundation.dart';

import '../core/constants/api_constants.dart';
import 'api_service.dart';
import 'bsc_swap_service.dart' show SwapException;

/// ใบเสนอราคาค่าวางไม้ของไม้หนึ่งไม้ (เฉพาะช่องที่แอปใช้ตัดสิน)
class TradingFeeQuote {
  /// ระบบค่าวางไม้เปิดอยู่ไหม
  final bool enabled;

  /// กระเป๋านี้ได้รับยกเว้น (เช่าบอทคลาวด์อยู่)
  final bool waived;

  /// จ่ายค่าวางไม้เป็นเหรียญบนเชนได้ไหม — false บน TPIX Chain (ต้องใช้เครดิต)
  final bool onchainAvailable;

  /// วางไม้ได้ไหม (เครดิตพอ) — ไม่มีค่า = ถือว่าไม่ได้ (fail-closed)
  final bool canPlace;

  /// เหตุผลจากเซิร์ฟเวอร์เมื่อวางไม่ได้ (แปลตาม X-Locale แล้ว)
  final String? reason;

  final double? feeTpix;
  final double? shortfall;

  const TradingFeeQuote({
    required this.enabled,
    required this.waived,
    required this.onchainAvailable,
    required this.canPlace,
    this.reason,
    this.feeTpix,
    this.shortfall,
  });

  factory TradingFeeQuote.fromJson(Map<String, dynamic> json) {
    final onchain = json['onchain'];
    final tpix = json['tpix'];
    final reason = json['reason'];
    double? num_(Object? v) => v is num ? v.toDouble() : null;
    return TradingFeeQuote(
      enabled: json['enabled'] == true,
      waived: json['waived'] == true,
      // ไม่มีช่องนี้ / ไม่ใช่ false ชัด ๆ = ถือว่าจ่ายบนเชนได้ (ตรงกับ `=== false` ของเว็บ)
      onchainAvailable: !(onchain is Map && onchain['available'] == false),
      canPlace: json['can_place'] == true,
      reason: reason is String && reason.trim().isNotEmpty
          ? reason.trim()
          : null,
      feeTpix: tpix is Map ? num_(tpix['fee_tpix']) : null,
      shortfall: tpix is Map ? num_(tpix['shortfall']) : null,
    );
  }

  /// ไม้นี้ต้องจ่ายค่าวางไม้ด้วยเครดิต TPIX เท่านั้นไหม (กติกาเดียวกับ `isCreditOnly` ของเว็บ)
  bool get creditOnly => enabled && !waived && !onchainAvailable;
}

/// เหตุที่ส่งไม้ไม่ได้เพราะค่าวางไม้
enum TradingFeeBlock {
  /// ถามใบเสนอราคาไม่สำเร็จ — ไม่รู้ = ไม่ส่ง
  quoteFailed,

  /// ต้องจ่ายด้วยเครดิต TPIX แต่เครดิตไม่พอ — ต้องเติมเครดิตก่อน
  creditRequired,

  /// ต้องจ่ายด้วยเครดิต แต่ไม้นี้ไม่มีฝั่ง stablecoin ให้คิดมูลค่าเป็นดอลลาร์
  noStableLeg,

  /// ขอใบอนุญาตวางไม้ไม่สำเร็จ
  ticketFailed,
}

/// ส่งไม้ไม่ได้เพราะค่าวางไม้ — **ยังไม่มีอะไรออกจากกระเป๋า** ข้อความสองภาษาพร้อมแสดง
class TradingFeeException extends SwapException {
  final TradingFeeBlock block;

  const TradingFeeException(this.block, String en, String th) : super(en, th);

  /// ควรพาผู้ใช้ไปเติมเครดิต TPIX (หน้าเทรดบนเว็บ — แอปยังไม่มีหน้าเติมเครดิตนี้)
  bool get needsCredit => block == TradingFeeBlock.creditRequired;

  static const quoteFailed = TradingFeeException(
    TradingFeeBlock.quoteFailed,
    "Couldn't check the order fee — nothing was sent. Please try again.",
    'ตรวจค่าวางไม้ของคำสั่งนี้ไม่สำเร็จ — ยังไม่ได้ส่งอะไรออกไป ลองใหม่อีกครั้ง',
  );

  static const noStableLeg = TradingFeeException(
    TradingFeeBlock.noStableLeg,
    "Trades on TPIX Chain pay the order fee with TPIX credit, and this pair has no USD-stablecoin side to price the fee from — trade it on the web (tpix.online) for now.",
    'ไม้บน TPIX Chain จ่ายค่าวางไม้ด้วยเครดิต TPIX แต่คู่นี้ไม่มีฝั่ง stablecoin ดอลลาร์ให้คิดค่าวางไม้ — เทรดคู่นี้ที่หน้าเว็บ (tpix.online) ไปก่อน',
  );

  static const ticketFailed = TradingFeeException(
    TradingFeeBlock.ticketFailed,
    "Couldn't get the order permit — nothing was sent. Please try again.",
    'ขอใบอนุญาตวางไม้ไม่สำเร็จ — ยังไม่ได้ส่งอะไรออกไป ลองใหม่อีกครั้ง',
  );

  /// เครดิตไม่พอ — เหตุผลของเซิร์ฟเวอร์ (แปลตามภาษาของแอปแล้ว) + ทางออก
  /// เหตุผลว่าง = ข้อความสำรองสองภาษา
  factory TradingFeeException.creditRequired([String? serverReason]) {
    const hintEn =
        'Top up TPIX credit on the web trade page (tpix.online), then try again.';
    const hintTh = 'เติมเครดิต TPIX ได้ที่หน้าเทรดบนเว็บ (tpix.online) แล้วลองใหม่';
    var reason = serverReason?.trim() ?? '';
    if (reason.length > 200) reason = reason.substring(0, 200);
    return TradingFeeException(
      TradingFeeBlock.creditRequired,
      reason.isNotEmpty
          ? '$reason — $hintEn'
          : 'Not enough TPIX credit for the order fee (trades on TPIX Chain pay it with TPIX credit) — $hintEn',
      reason.isNotEmpty
          ? '$reason — $hintTh'
          : 'เครดิต TPIX ไม่พอจ่ายค่าวางไม้ (ไม้บน TPIX Chain จ่ายค่าวางไม้ด้วยเครดิต TPIX) — $hintTh',
    );
  }
}

/// ผลของการขอใบอนุญาต
class TicketIssue {
  /// uuid ของใบอนุญาต — null = ขอไม่สำเร็จ
  final String? uuid;

  /// `error.code` ของเซิร์ฟเวอร์เมื่อไม่สำเร็จ (เช่น `INSUFFICIENT_CREDIT`)
  final String? errorCode;

  /// `error.message` ของเซิร์ฟเวอร์ (แปลตามภาษาของแอปแล้ว)
  final String? errorMessage;

  const TicketIssue({this.uuid, this.errorCode, this.errorMessage});
}

/// ช่องทางคุยกับเซิร์ฟเวอร์ — แยกเป็น interface ให้เทสต์เสียบตัวปลอมได้
/// ทุกเมธอด **ไม่ throw**: ล้มเหลว = null / false / TicketIssue ที่ไม่มี uuid
abstract class TradingFeeClient {
  Future<TradingFeeQuote?> quote({
    required String wallet,
    required double orderValueUsd,
    required int chainId,
    String? pair,
  });

  Future<TicketIssue> issueTicket({
    required String wallet,
    required String pair,
    required String side,
    required double orderValueUsd,
    required int chainId,
  });

  Future<bool> bindTicket({
    required String wallet,
    required String uuid,
    required String orderTxHash,
  });

  Future<bool> consumeTicket({
    required String wallet,
    required String uuid,
    required String orderTxHash,
  });

  Future<bool> refundTicket({
    required String wallet,
    required String uuid,
    required String reason,
  });
}

/// ตัวจริง — ยิงผ่าน [ApiService] (แนบเซสชันกระเป๋า + X-Locale ให้แล้ว)
class TradingFeeApi implements TradingFeeClient {
  const TradingFeeApi();

  static bool _ok(({int? status, Map<String, dynamic>? body}) res) {
    final status = res.status;
    return status != null &&
        status >= 200 &&
        status < 300 &&
        res.body?['success'] == true;
  }

  static Map<String, dynamic>? _data(Map<String, dynamic>? body) {
    final data = body?['data'];
    return data is Map<String, dynamic> ? data : null;
  }

  @override
  Future<TradingFeeQuote?> quote({
    required String wallet,
    required double orderValueUsd,
    required int chainId,
    String? pair,
  }) async {
    final res = await ApiService().postJson(ApiConstants.tradingFeeQuote, {
      'wallet_address': wallet,
      'order_value_usd': orderValueUsd,
      'chain_id': chainId,
      if (pair != null && pair.isNotEmpty) 'pair': pair,
    });
    final data = _ok(res) ? _data(res.body) : null;
    if (data == null) return null;
    try {
      return TradingFeeQuote.fromJson(data);
    } catch (e) {
      debugPrint('trading-fee quote parse: ${e.runtimeType}');
      return null;
    }
  }

  @override
  Future<TicketIssue> issueTicket({
    required String wallet,
    required String pair,
    required String side,
    required double orderValueUsd,
    required int chainId,
  }) async {
    final res = await ApiService().postJson(ApiConstants.tradingFeeTickets, {
      'wallet_address': wallet,
      'pair': pair,
      'side': side,
      'order_value_usd': orderValueUsd,
      'chain_id': chainId,
      'method': 'tpix_credit',
    });
    if (_ok(res)) {
      final uuid = _data(res.body)?['uuid'];
      if (uuid is String && uuid.isNotEmpty) return TicketIssue(uuid: uuid);
      return const TicketIssue();
    }
    final err = res.body?['error'];
    return TicketIssue(
      errorCode: err is Map && err['code'] is String
          ? err['code'] as String
          : null,
      errorMessage: err is Map && err['message'] is String
          ? err['message'] as String
          : null,
    );
  }

  @override
  Future<bool> bindTicket({
    required String wallet,
    required String uuid,
    required String orderTxHash,
  }) async =>
      _ok(await ApiService().postJson(ApiConstants.tradingFeeTicketBind(uuid), {
        'wallet_address': wallet,
        'order_tx_hash': orderTxHash,
      }));

  @override
  Future<bool> consumeTicket({
    required String wallet,
    required String uuid,
    required String orderTxHash,
  }) async =>
      _ok(await ApiService()
          .postJson(ApiConstants.tradingFeeTicketConsume(uuid), {
        'wallet_address': wallet,
        'order_tx_hash': orderTxHash,
      }));

  @override
  Future<bool> refundTicket({
    required String wallet,
    required String uuid,
    required String reason,
  }) async =>
      _ok(await ApiService()
          .postJson(ApiConstants.tradingFeeTicketRefund(uuid), {
        'wallet_address': wallet,
        'reason': reason,
      }));
}

/// ผลการตัดสินทางจ่ายค่าวางไม้ของไม้หนึ่งไม้ — ได้มาจาก [TradingFeeFlow.plan]
class TradingFeePlan {
  /// ต้องขอใบอนุญาต (หักเครดิต TPIX) ก่อนส่งไม้ไหม
  final bool needsTicket;
  final String wallet;
  final String pair;
  final String side;
  final double orderValueUsd;
  final int chainId;

  const TradingFeePlan({
    required this.needsTicket,
    required this.wallet,
    required this.pair,
    required this.side,
    required this.orderValueUsd,
    required this.chainId,
  });
}

class TradingFeeFlow {
  TradingFeeFlow._();

  /// เหตุผลที่แนบไปกับคำขอคืนค่าบริการ (เก็บในบันทึกฝั่งเซิร์ฟเวอร์ ไม่ได้ขึ้นจอ)
  static const String refundReason = 'ไม้ไม่ได้ลง (แอป)';

  /// มูลค่าของไม้เป็นดอลลาร์ = ฝั่ง stablecoin ของไม้ — null = ไม่มีฝั่ง stablecoin (ไม่เดา)
  ///
  /// ซื้อ (จ่าย stablecoin): ยอดที่จ่าย · ขาย (ได้ stablecoin): ยอดที่จะได้จาก quote
  static double? orderValueUsd({
    required bool fromIsStable,
    required bool toIsStable,
    required double amountIn,
    required double netOutput,
  }) {
    final value = fromIsStable ? amountIn : (toIsStable ? netOutput : null);
    if (value == null || !value.isFinite || value <= 0) return null;
    return value;
  }

  /// ไม้นี้ต้องมีใบอนุญาตไหม — ตัดสินจากใบเสนอราคาเพียว ๆ (ทดสอบได้)
  ///
  /// คืน true = ต้องขอใบอนุญาต · false = ส่งตามเดิม ไม่มีใบ
  /// ส่งไม่ได้ = โยน [TradingFeeException] (ยังไม่มีอะไรออกจากกระเป๋า)
  static bool needsTicket(TradingFeeQuote? quote,
      {required bool hasOrderValue}) {
    if (quote == null) throw TradingFeeException.quoteFailed;
    if (!quote.creditOnly) return false;
    // ต้องจ่ายด้วยเครดิต แต่ไม่รู้มูลค่าไม้เป็นดอลลาร์ = คิดค่าวางไม้ไม่ได้ ไม่เดา
    if (!hasOrderValue) throw TradingFeeException.noStableLeg;
    if (!quote.canPlace) throw TradingFeeException.creditRequired(quote.reason);
    return true;
  }

  /// ถามใบเสนอราคาสดแล้วตัดสินทางจ่าย — เรียก **ก่อน** ขอให้ผู้ใช้ approve
  ///
  /// [orderValueUsd] null = ไม้นี้ไม่มีฝั่ง stablecoin: ยังถามเซิร์ฟเวอร์ (ด้วยมูลค่า 1) เพื่อรู้ว่า
  /// เชนนี้บังคับจ่ายด้วยเครดิตไหม — ไม่บังคับ = ส่งได้ตามเดิม · บังคับ = บล็อก (ไม่เดามูลค่า)
  static Future<TradingFeePlan> plan({
    required String wallet,
    required String pair,
    required String side,
    required double? orderValueUsd,
    required int chainId,
    TradingFeeClient client = const TradingFeeApi(),
  }) async {
    final hasValue =
        orderValueUsd != null && orderValueUsd.isFinite && orderValueUsd > 0;
    TradingFeeQuote? quote;
    try {
      quote = await client.quote(
        wallet: wallet,
        orderValueUsd: hasValue ? orderValueUsd : 1,
        chainId: chainId,
        pair: pair,
      );
    } catch (e) {
      debugPrint('trading-fee quote: ${e.runtimeType}');
      quote = null;
    }
    final ticket = needsTicket(quote, hasOrderValue: hasValue);
    return TradingFeePlan(
      needsTicket: ticket,
      wallet: wallet,
      pair: pair,
      side: side,
      orderValueUsd: hasValue ? orderValueUsd : 0,
      chainId: chainId,
    );
  }

  /// ส่งไม้ภายใต้ [plan] — ไม่ต้องมีใบ = เรียก [send] ตรง ๆ
  ///
  /// ต้องมีใบ: ขอใบ (หักเครดิต) → [send] → ผูกใบกับ tx ทันทีที่ [send] เรียก `onSent(hash)`
  /// → ไม้ยืนยันแล้ว ([isConfirmed]) ปิดใบ · ส่งออกไปแล้วแต่ยังไม่ยืนยัน = ผูกไว้เฉย ๆ
  /// [send] โยน error:
  ///   - ยังไม่เคยเรียก `onSent` (ไม่มีอะไรออกจากกระเป๋า) → คืนค่าบริการ
  ///   - เรียก `onSent` แล้ว → **ไม่คืน** ผูกใบให้ติด (ลองซ้ำหนึ่งครั้งถ้ารอบแรกพลาด) ให้เซิร์ฟเวอร์ตัดสินจากเชน
  /// error ของ [send] ถูกโยนต่อเสมอ
  static Future<T> send<T>({
    required TradingFeePlan plan,
    required Future<T> Function(void Function(String txHash) onSent) send,
    bool Function(T result)? isConfirmed,
    TradingFeeClient client = const TradingFeeApi(),
  }) async {
    if (!plan.needsTicket) return send((_) {});

    TicketIssue issue;
    try {
      issue = await client.issueTicket(
        wallet: plan.wallet,
        pair: plan.pair,
        side: plan.side,
        orderValueUsd: plan.orderValueUsd,
        chainId: plan.chainId,
      );
    } catch (e) {
      debugPrint('trading-fee ticket: ${e.runtimeType}');
      issue = const TicketIssue();
    }
    final uuid = issue.uuid;
    if (uuid == null) {
      // เครดิตหมดระหว่างทาง (ใบเสนอราคาบอกว่าพอ แต่ตอนหักไม่พอ) = ให้ไปเติม
      if (issue.errorCode == 'INSUFFICIENT_CREDIT') {
        throw TradingFeeException.creditRequired(issue.errorMessage);
      }
      throw TradingFeeException.ticketFailed;
    }

    String? sentHash;
    Future<bool>? binding;
    Future<bool> bind(String hash) async {
      try {
        return await client.bindTicket(
            wallet: plan.wallet, uuid: uuid, orderTxHash: hash);
      } catch (e) {
        debugPrint('trading-fee bind: ${e.runtimeType}');
        return false;
      }
    }

    void onSent(String txHash) {
      if (sentHash != null || txHash.isEmpty) return;
      sentHash = txHash;
      binding = bind(txHash);
    }

    // ผูกให้ติด — รอรอบแรก ถ้าพลาดลองอีกครั้ง (ใบที่ผูกแล้ว เซิร์ฟเวอร์ตัดสินจากเชนเอง)
    Future<void> ensureBound(String hash) async {
      final first = await (binding ?? Future<bool>.value(false));
      if (!first) await bind(hash);
    }

    try {
      final result = await send(onSent);
      final hash = sentHash;
      if (hash == null) {
        // send จบโดยไม่มีธุรกรรมออกจากกระเป๋า (ไม่ควรเกิด) — ไม่มีไม้ = คืนค่าบริการ
        await _refund(client, plan.wallet, uuid);
        return result;
      }
      await ensureBound(hash);
      if (isConfirmed == null || isConfirmed(result)) {
        try {
          await client.consumeTicket(
              wallet: plan.wallet, uuid: uuid, orderTxHash: hash);
        } catch (e) {
          // ปิดใบไม่สำเร็จไม่กระทบไม้ที่ลงแล้ว — ใบผูก tx ไว้ เซิร์ฟเวอร์ปิดให้เอง
          debugPrint('trading-fee consume: ${e.runtimeType}');
        }
      }
      return result;
    } catch (_) {
      final hash = sentHash;
      if (hash != null) {
        // ธุรกรรมออกไปแล้ว — ห้ามคืนเอง ผูกไว้ให้เซิร์ฟเวอร์ตัดสินจากเชน (ลง = ปิดใบ · ไม่ลง = คืน)
        await ensureBound(hash);
      } else {
        await _refund(client, plan.wallet, uuid);
      }
      rethrow;
    }
  }

  static Future<void> _refund(
      TradingFeeClient client, String wallet, String uuid) async {
    try {
      await client.refundTicket(
          wallet: wallet, uuid: uuid, reason: refundReason);
    } catch (e) {
      // คืนไม่สำเร็จรอบนี้ — ใบที่ไม่ได้ผูก tx หมดอายุแล้วเซิร์ฟเวอร์คืนให้เอง
      debugPrint('trading-fee refund: ${e.runtimeType}');
    }
  }
}
