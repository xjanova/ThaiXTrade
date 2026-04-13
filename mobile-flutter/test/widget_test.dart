import 'package:flutter_test/flutter_test.dart';
import 'package:tpix_trade/core/theme/app_colors.dart';
import 'package:tpix_trade/models/api_models.dart';
import 'package:tpix_trade/models/chain_config.dart';

void main() {
  group('ChainConfig', () {
    test('TPIX chain has correct chainId', () {
      expect(ChainConfig.tpix.chainId, 4289);
    });

    test('byId returns correct chain', () {
      expect(ChainConfig.byId(56).shortName, 'BSC');
    });

    test('byId falls back to TPIX for unknown', () {
      expect(ChainConfig.byId(9999).chainId, 4289);
    });
  });

  group('Ticker', () {
    test('fromJson parses correctly', () {
      final ticker = Ticker.fromJson({
        'symbol': 'BTC-USDT',
        'lastPrice': '67234.50',
        'priceChangePercent': '2.5',
        'volume': '12345.6',
      });
      expect(ticker.baseAsset, 'BTC');
      expect(ticker.quoteAsset, 'USDT');
      expect(ticker.lastPrice, 67234.50);
      expect(ticker.isPositive, true);
    });
  });

  group('AppColors', () {
    test('brand cyan is correct', () {
      expect(AppColors.brandCyan.value, 0xFF06B6D4);
    });

    test('trading green is correct', () {
      expect(AppColors.tradingGreen.value, 0xFF00C853);
    });
  });
}
