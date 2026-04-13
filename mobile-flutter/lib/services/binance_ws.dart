/// TPIX TRADE — Binance WebSocket Service
/// Real-time ticker, order book, trades จาก Binance stream
/// Auto-reconnect + lifecycle-aware
///
/// Developed by Xman Studio

import 'dart:async';
import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:web_socket_channel/web_socket_channel.dart';

class BinanceWS {
  static final BinanceWS _instance = BinanceWS._();
  factory BinanceWS() => _instance;
  BinanceWS._();

  static const _baseUrl = 'wss://stream.binance.com:9443/ws';

  WebSocketChannel? _tickerChannel;
  WebSocketChannel? _depthChannel;
  WebSocketChannel? _tradeChannel;

  // Streams สำหรับ consumers
  final _tickerController = StreamController<Map<String, dynamic>>.broadcast();
  final _depthController = StreamController<Map<String, dynamic>>.broadcast();
  final _tradeController = StreamController<Map<String, dynamic>>.broadcast();

  Stream<Map<String, dynamic>> get tickerStream => _tickerController.stream;
  Stream<Map<String, dynamic>> get depthStream => _depthController.stream;
  Stream<Map<String, dynamic>> get tradeStream => _tradeController.stream;

  String? _currentPairSymbol;
  bool _disposed = false;
  Timer? _reconnectTimer;

  // ── All Tickers Stream ──

  void connectTickers() {
    _disconnectChannel(_tickerChannel);
    try {
      _tickerChannel = WebSocketChannel.connect(
        Uri.parse('$_baseUrl/!miniTicker@arr'),
      );
      _tickerChannel!.stream.listen(
        (data) {
          try {
            final list = jsonDecode(data as String);
            if (list is List) {
              for (final item in list) {
                _tickerController.add(item as Map<String, dynamic>);
              }
            }
          } catch (_) {}
        },
        onError: (_) => _scheduleReconnectTickers(),
        onDone: () => _scheduleReconnectTickers(),
      );
    } catch (e) {
      debugPrint('BinanceWS ticker connect: ${e.runtimeType}');
      _scheduleReconnectTickers();
    }
  }

  void _scheduleReconnectTickers() {
    if (_disposed) return;
    _reconnectTimer?.cancel();
    _reconnectTimer = Timer(const Duration(seconds: 5), () {
      if (!_disposed) connectTickers();
    });
  }

  // ── Pair-specific Streams (Depth + Trades) ──

  void subscribePair(String symbol) {
    final lower = symbol.replaceAll('-', '').replaceAll('/', '').toLowerCase();
    if (_currentPairSymbol == lower) return;
    _currentPairSymbol = lower;

    // Depth stream (top 20 levels, 1s updates)
    _disconnectChannel(_depthChannel);
    try {
      _depthChannel = WebSocketChannel.connect(
        Uri.parse('$_baseUrl/${lower}@depth20@1000ms'),
      );
      _depthChannel!.stream.listen(
        (data) {
          try {
            _depthController.add(jsonDecode(data as String) as Map<String, dynamic>);
          } catch (_) {}
        },
        onError: (_) => _scheduleReconnectPair(lower),
        onDone: () => _scheduleReconnectPair(lower),
      );
    } catch (e) {
      debugPrint('BinanceWS depth connect: ${e.runtimeType}');
    }

    // Trade stream (aggregated trades)
    _disconnectChannel(_tradeChannel);
    try {
      _tradeChannel = WebSocketChannel.connect(
        Uri.parse('$_baseUrl/${lower}@aggTrade'),
      );
      _tradeChannel!.stream.listen(
        (data) {
          try {
            _tradeController.add(jsonDecode(data as String) as Map<String, dynamic>);
          } catch (_) {}
        },
        onError: (_) {},
        onDone: () {},
      );
    } catch (e) {
      debugPrint('BinanceWS trade connect: ${e.runtimeType}');
    }
  }

  void _scheduleReconnectPair(String symbol) {
    if (_disposed || _currentPairSymbol != symbol) return;
    Timer(const Duration(seconds: 5), () {
      if (!_disposed && _currentPairSymbol == symbol) {
        subscribePair(symbol);
      }
    });
  }

  void unsubscribePair() {
    _currentPairSymbol = null;
    _disconnectChannel(_depthChannel);
    _disconnectChannel(_tradeChannel);
    _depthChannel = null;
    _tradeChannel = null;
  }

  // ── Lifecycle ──

  void pause() {
    _disconnectChannel(_tickerChannel);
    _disconnectChannel(_depthChannel);
    _disconnectChannel(_tradeChannel);
    _tickerChannel = null;
    _depthChannel = null;
    _tradeChannel = null;
  }

  void resume() {
    connectTickers();
    if (_currentPairSymbol != null) {
      final symbol = _currentPairSymbol!;
      _currentPairSymbol = null; // reset เพื่อให้ subscribePair ทำงาน
      subscribePair(symbol);
    }
  }

  void dispose() {
    _disposed = true;
    _reconnectTimer?.cancel();
    pause();
    _tickerController.close();
    _depthController.close();
    _tradeController.close();
  }

  void _disconnectChannel(WebSocketChannel? channel) {
    try {
      channel?.sink.close();
    } catch (_) {}
  }
}
