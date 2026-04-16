/// TPIX TRADE — API Response Models
/// Data models for API communication
///
/// Developed by Xman Studio

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

  Ticker copyWith({double? lastPrice, double? quoteVolume24h}) => Ticker(
        symbol: symbol,
        baseAsset: baseAsset,
        quoteAsset: quoteAsset,
        lastPrice: lastPrice ?? this.lastPrice,
        priceChange: priceChange,
        priceChangePercent: priceChangePercent,
        high24h: high24h,
        low24h: low24h,
        volume24h: volume24h,
        quoteVolume24h: quoteVolume24h ?? this.quoteVolume24h,
      );
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
  final String? logo; // โลโก้จริงจาก Token DB (ถ้ามี)

  const TokenBalance({
    required this.symbol,
    required this.name,
    required this.balance,
    this.contractAddress,
    this.usdValue,
    this.logo,
  });

  factory TokenBalance.fromJson(Map<String, dynamic> json) => TokenBalance(
        symbol: json['symbol'] as String? ?? '',
        name: json['name'] as String? ?? '',
        balance: _toDouble(json['balance']),
        contractAddress: json['contract_address'] as String?,
        usdValue:
            json['usd_value'] != null ? _toDouble(json['usd_value']) : null,
        logo: json['logo'] as String?,
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

// ── Fee Config ──

class FeeConfig {
  final double swapFeePercent;
  final String swapFeeWallet;
  final bool swapEnabled;

  final double bridgeFeePercent;
  final String bridgeFeeWallet;
  final double bridgeMinAmount;
  final double bridgeMaxAmount;
  final double bridgeMinFee;
  final int bridgeEstimatedMinutes;
  final bool bridgeEnabled;

  const FeeConfig({
    required this.swapFeePercent,
    required this.swapFeeWallet,
    required this.swapEnabled,
    required this.bridgeFeePercent,
    required this.bridgeFeeWallet,
    required this.bridgeMinAmount,
    required this.bridgeMaxAmount,
    required this.bridgeMinFee,
    required this.bridgeEstimatedMinutes,
    required this.bridgeEnabled,
  });

  factory FeeConfig.fromJson(Map<String, dynamic> json) {
    final swap = (json['swap'] as Map<String, dynamic>?) ?? {};
    final bridge = (json['bridge'] as Map<String, dynamic>?) ?? {};
    return FeeConfig(
      swapFeePercent: _toDouble(swap['feePercent']),
      swapFeeWallet: (swap['feeWallet'] as String?) ?? '',
      swapEnabled: swap['enabled'] == true,
      bridgeFeePercent: _toDouble(bridge['feePercent']),
      bridgeFeeWallet: (bridge['feeWallet'] as String?) ?? '',
      bridgeMinAmount: _toDouble(bridge['minAmount']),
      bridgeMaxAmount: _toDouble(bridge['maxAmount']),
      bridgeMinFee: _toDouble(bridge['minFee']),
      bridgeEstimatedMinutes: (bridge['estimatedMinutes'] as num?)?.toInt() ?? 5,
      bridgeEnabled: bridge['enabled'] == true,
    );
  }

  factory FeeConfig.empty() => const FeeConfig(
        swapFeePercent: 0.3,
        swapFeeWallet: '',
        swapEnabled: false,
        bridgeFeePercent: 0,
        bridgeFeeWallet: '',
        bridgeMinAmount: 0,
        bridgeMaxAmount: 0,
        bridgeMinFee: 0,
        bridgeEstimatedMinutes: 5,
        bridgeEnabled: false,
      );
}

// ── Chain Info (from /api/v1/chains) ──

class ChainInfo {
  final int chainId;
  final String name;
  final String shortName;
  final String symbol;
  final String rpcUrl;
  final List<String> fallbackRpcs;
  final String explorerUrl;
  final int decimals;
  final bool gasless;
  final bool enabled;
  final String? iconUrl;
  final String? color;

  const ChainInfo({
    required this.chainId,
    required this.name,
    required this.shortName,
    required this.symbol,
    required this.rpcUrl,
    this.fallbackRpcs = const [],
    required this.explorerUrl,
    required this.decimals,
    required this.gasless,
    this.enabled = true,
    this.iconUrl,
    this.color,
  });

  factory ChainInfo.fromJson(Map<String, dynamic> json) {
    // Parse rpc array — เอาตัวแรก + เก็บตัวที่เหลือเป็น fallback
    final rpcField = json['rpc'] ?? json['rpc_url'] ?? json['rpcUrl'];
    String rpc = '';
    List<String> fallbacks = [];
    if (rpcField is List && rpcField.isNotEmpty) {
      rpc = rpcField.first.toString();
      fallbacks = rpcField.skip(1).map((e) => e.toString()).toList();
    } else if (rpcField is String) {
      rpc = rpcField;
    }

    // Parse nativeCurrency nested object
    final nc = json['nativeCurrency'] as Map<String, dynamic>?;
    final symbol = (nc?['symbol'] ?? json['symbol']) as String? ?? '';
    final decimals = (nc?['decimals'] ?? json['decimals']) as int? ?? 18;

    return ChainInfo(
      chainId: (json['chainId'] ?? json['chain_id']) as int? ?? 0,
      name: (json['name'] as String?) ?? '',
      shortName: (json['shortName'] ?? json['short_name'] ?? symbol)
              as String? ??
          '',
      symbol: symbol,
      rpcUrl: rpc,
      fallbackRpcs: fallbacks,
      explorerUrl:
          (json['explorer'] ?? json['explorer_url'] ?? json['explorerUrl'])
                  as String? ??
              '',
      decimals: decimals,
      gasless: json['gasless'] == true,
      enabled: json['enabled'] != false, // default true
      iconUrl: json['icon'] as String?,
      color: json['color'] as String?,
    );
  }
}

// ── Trading Pair Info (from /api/v1/market/pairs) ──

class TradingPairInfo {
  final String symbol; // BTC-USDT
  final String baseAsset;
  final String quoteAsset;
  final double minTradeAmount;
  final double maxTradeAmount;
  final int pricePrecision;
  final int amountPrecision;
  final double? feeRateOverride;
  final String? baseLogo; // โลโก้จริงจาก Token DB (มี/ไม่มีก็ได้)
  final int? chainId; // chain ที่ pair นี้อยู่ — สำหรับ pre-submit validation

  const TradingPairInfo({
    required this.symbol,
    required this.baseAsset,
    required this.quoteAsset,
    required this.minTradeAmount,
    required this.maxTradeAmount,
    required this.pricePrecision,
    required this.amountPrecision,
    this.feeRateOverride,
    this.baseLogo,
    this.chainId,
  });

  factory TradingPairInfo.fromJson(Map<String, dynamic> json) {
    final symbol = (json['symbol'] as String?) ?? '';
    final parts = symbol.contains('-') ? symbol.split('-') : [symbol, 'USDT'];
    return TradingPairInfo(
      symbol: symbol,
      baseAsset: (json['base_asset'] ?? parts[0]) as String,
      quoteAsset:
          (json['quote_asset'] ?? (parts.length > 1 ? parts[1] : 'USDT'))
              as String,
      minTradeAmount: _toDouble(json['min_trade_amount']),
      maxTradeAmount: _toDouble(json['max_trade_amount']),
      pricePrecision: (json['price_precision'] as num?)?.toInt() ?? 2,
      amountPrecision: (json['amount_precision'] as num?)?.toInt() ?? 4,
      feeRateOverride:
          json['fee_rate'] != null ? _toDouble(json['fee_rate']) : null,
      baseLogo: json['base_logo'] as String?,
      chainId: (json['chain_id'] as num?)?.toInt(),
    );
  }
}

// ── User Profile (sync ระหว่าง mobile ↔ web) ──

class UserProfile {
  final String walletAddress;
  final String? email;
  final String? name;
  final String? avatar;
  final bool isVerified;
  final String kycStatus; // none | pending | approved | rejected
  final String? referralCode;
  final int totalTrades;
  final double totalVolumeUsd;
  final Map<String, dynamic> preferences; // language, theme, default_chain_id, ...
  final DateTime? createdAt;
  final DateTime? lastActiveAt;

  const UserProfile({
    required this.walletAddress,
    this.email,
    this.name,
    this.avatar,
    this.isVerified = false,
    this.kycStatus = 'none',
    this.referralCode,
    this.totalTrades = 0,
    this.totalVolumeUsd = 0,
    this.preferences = const {},
    this.createdAt,
    this.lastActiveAt,
  });

  factory UserProfile.fromJson(Map<String, dynamic> json) => UserProfile(
        walletAddress: (json['wallet_address'] as String?) ?? '',
        email: json['email'] as String?,
        name: json['name'] as String?,
        avatar: json['avatar'] as String?,
        isVerified: json['is_verified'] == true,
        kycStatus: (json['kyc_status'] as String?) ?? 'none',
        referralCode: json['referral_code'] as String?,
        totalTrades: (json['total_trades'] as num?)?.toInt() ?? 0,
        totalVolumeUsd: _toDouble(json['total_volume_usd']),
        preferences: (json['preferences'] as Map?)?.cast<String, dynamic>() ?? {},
        createdAt: DateTime.tryParse(json['created_at'] as String? ?? ''),
        lastActiveAt: DateTime.tryParse(json['last_active_at'] as String? ?? ''),
      );

  /// Helper getters สำหรับ preferences ที่ใช้บ่อย
  String? get prefLanguage => preferences['language'] as String?;
  String? get prefTheme => preferences['theme'] as String?;
  int? get prefDefaultChainId => (preferences['default_chain_id'] as num?)?.toInt();
}

// ── Helpers ──

double _toDouble(dynamic value) {
  if (value == null) return 0;
  if (value is double) return value;
  if (value is int) return value.toDouble();
  if (value is String) return double.tryParse(value) ?? 0;
  return 0;
}
