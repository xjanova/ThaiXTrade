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

  group('AppColors (Luxury Dark / Gilded Metal)', () {
    test('champagne gold tokens are correct', () {
      expect(AppColors.gold1.toARGB32(), 0xFFFCEBB8);
      expect(AppColors.gold2.toARGB32(), 0xFFD4AF37);
      expect(AppColors.gold3.toARGB32(), 0xFF9C7A1E);
    });

    test('legacy brand aliases now resolve to gold', () {
      // brandCyan/brandPurple are kept for back-compat but re-skin to gold.
      expect(AppColors.brandCyan.toARGB32(), AppColors.gold2.toARGB32());
      expect(AppColors.brandPurple.toARGB32(), AppColors.gold2.toARGB32());
    });

    test('trading up/down match the design tokens', () {
      expect(AppColors.tradingGreen.toARGB32(), 0xFF4ED9A4); // --up
      expect(AppColors.tradingRed.toARGB32(), 0xFFFF6B7A); // --down
    });

    test('primary text is warm white', () {
      expect(AppColors.textPrimary.toARGB32(), 0xFFF3F1EA);
    });
  });
}
