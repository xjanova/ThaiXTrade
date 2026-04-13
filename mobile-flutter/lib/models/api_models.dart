/// TPIX TRADE — API Response Models
/// Data models for API communication
///
/// Developed by Xman Studio

class ApiResponse<T> {
  final bool success;
  final T? data;
  final ApiError? error;
  final ApiMeta? meta;

  const ApiResponse({
    required this.success,
    this.data,
    this.error,
    this.meta,
  });
}

class ApiError {
  final String code;
  final String message;

  const ApiError({required this.code, required this.message});

  factory ApiError.fromJson(Map<String, dynamic> json) => ApiError(
        code: json['code'] as String? ?? 'UNKNOWN',
        message: json['message'] as String? ?? 'Unknown error',
      );
}

class ApiMeta {
  final int page;
  final int perPage;
  final int total;

  const ApiMeta({
    required this.page,
    required this.perPage,
    required this.total,
  });

  factory ApiMeta.fromJson(Map<String, dynamic> json) => ApiMeta(
        page: json['page'] as int? ?? 1,
        perPage: json['per_page'] as int? ?? 20,
        total: json['total'] as int? ?? 0,
      );
}

// ── Ticker ──

class Ticker {
  final String symbol;
  final String baseAsset;
  final String quoteAsset;
  final double lastPrice;
  final double priceChange;
  final double priceChangePercent;
  final double high24h;
  final double low24h;
  final double volume24h;
  final double quoteVolume24h;

  const Ticker({
    required this.symbol,
    required this.baseAsset,
    required this.quoteAsset,
    required this.lastPrice,
    required this.priceChange,
    required this.priceChangePercent,
    required this.high24h,
    required this.low24h,
    required this.volume24h,
    required this.quoteVolume24h,
  });

  factory Ticker.fromJson(Map<String, dynamic> json) {
    final symbol = json['symbol'] as String? ?? '';
    final parts = symbol.split('-');
    return Ticker(
      symbol: symbol,
      baseAsset: parts.isNotEmpty ? parts[0] : '',
      quoteAsset: parts.length > 1 ? parts[1] : 'USDT',
      lastPrice: _toDouble(json['last_price'] ?? json['lastPrice']),
      priceChange: _toDouble(json['price_change'] ?? json['priceChange']),
      priceChangePercent: _toDouble(
          json['price_change_percent'] ?? json['priceChangePercent']),
      high24h: _toDouble(json['high_24h'] ?? json['highPrice']),
      low24h: _toDouble(json['low_24h'] ?? json['lowPrice']),
      volume24h: _toDouble(json['volume_24h'] ?? json['volume']),
      quoteVolume24h: _toDouble(json['quote_volume_24h'] ?? json['quoteVolume']),
    );
  }

  String get displaySymbol => '${baseAsset}/${quoteAsset}';
  bool get isPositive => priceChangePercent >= 0;
}

// ── Order Book ──

class OrderBookEntry {
  final double price;
  final double quantity;

  const OrderBookEntry({required this.price, required this.quantity});

  double get total => price * quantity;

  factory OrderBookEntry.fromList(List<dynamic> arr) => arr.length >= 2
      ? OrderBookEntry(
          price: _toDouble(arr[0]),
          quantity: _toDouble(arr[1]),
        )
      : const OrderBookEntry(price: 0, quantity: 0);
}

class OrderBook {
  final List<OrderBookEntry> bids;
  final List<OrderBookEntry> asks;

  const OrderBook({required this.bids, required this.asks});

  factory OrderBook.fromJson(Map<String, dynamic> json) => OrderBook(
        bids: (json['bids'] as List<dynamic>? ?? [])
            .map((e) => OrderBookEntry.fromList(e as List<dynamic>))
            .toList(),
        asks: (json['asks'] as List<dynamic>? ?? [])
            .map((e) => OrderBookEntry.fromList(e as List<dynamic>))
            .toList(),
      );
}

// ── Kline / Candle ──

class Kline {
  final DateTime openTime;
  final double open;
  final double high;
  final double low;
  final double close;
  final double volume;

  const Kline({
    required this.openTime,
    required this.open,
    required this.high,
    required this.low,
    required this.close,
    required this.volume,
  });

  factory Kline.fromJson(Map<String, dynamic> json) => Kline(
        openTime: DateTime.fromMillisecondsSinceEpoch(
            json['open_time'] as int? ?? 0),
        open: _toDouble(json['open']),
        high: _toDouble(json['high']),
        low: _toDouble(json['low']),
        close: _toDouble(json['close']),
        volume: _toDouble(json['volume']),
      );

  factory Kline.fromList(List<dynamic> arr) => arr.length >= 6
      ? Kline(
          openTime: DateTime.fromMillisecondsSinceEpoch(arr[0] as int),
          open: _toDouble(arr[1]),
          high: _toDouble(arr[2]),
          low: _toDouble(arr[3]),
          close: _toDouble(arr[4]),
          volume: _toDouble(arr[5]),
        )
      : Kline(
          openTime: DateTime.now(),
          open: 0, high: 0, low: 0, close: 0, volume: 0,
        );
}

// ── Trade Order ──

class TradeOrder {
  final String id;
  final String pair;
  final String side; // buy | sell
  final String type; // limit | market
  final double? price;
  final double amount;
  final double? total;
  final String status;
  final DateTime createdAt;

  const TradeOrder({
    required this.id,
    required this.pair,
    required this.side,
    required this.type,
    this.price,
    required this.amount,
    this.total,
    required this.status,
    required this.createdAt,
  });

  factory TradeOrder.fromJson(Map<String, dynamic> json) => TradeOrder(
        id: json['id']?.toString() ?? '',
        pair: json['pair'] as String? ?? '',
        side: json['side'] as String? ?? 'buy',
        type: json['type'] as String? ?? 'limit',
        price: json['price'] != null ? _toDouble(json['price']) : null,
        amount: _toDouble(json['amount']),
        total: json['total'] != null ? _toDouble(json['total']) : null,
        status: json['status'] as String? ?? 'pending',
        createdAt: DateTime.tryParse(json['created_at'] as String? ?? '') ??
            DateTime.now(),
      );

  bool get isBuy => side == 'buy';
}

// ── Wallet Balance ──

class TokenBalance {
  final String symbol;
  final String name;
  final double balance;
  final String? contractAddress;
  final double? usdValue;

  const TokenBalance({
    required this.symbol,
    required this.name,
    required this.balance,
    this.contractAddress,
    this.usdValue,
  });

  factory TokenBalance.fromJson(Map<String, dynamic> json) => TokenBalance(
        symbol: json['symbol'] as String? ?? '',
        name: json['name'] as String? ?? '',
        balance: _toDouble(json['balance']),
        contractAddress: json['contract_address'] as String?,
        usdValue:
            json['usd_value'] != null ? _toDouble(json['usd_value']) : null,
      );
}

// ── TPIX Price ──

class TpixPrice {
  final double price;
  final double change24h;
  final double volume24h;
  final double marketCap;

  const TpixPrice({
    required this.price,
    required this.change24h,
    required this.volume24h,
    required this.marketCap,
  });

  factory TpixPrice.fromJson(Map<String, dynamic> json) => TpixPrice(
        price: _toDouble(json['price']),
        change24h: _toDouble(json['change_24h']),
        volume24h: _toDouble(json['volume_24h']),
        marketCap: _toDouble(json['market_cap']),
      );
}

// ── Helpers ──

double _toDouble(dynamic value) {
  if (value == null) return 0;
  if (value is double) return value;
  if (value is int) return value.toDouble();
  if (value is String) return double.tryParse(value) ?? 0;
  return 0;
}
