/**
 * TradingView Chart สำหรับ Mobile
 * ใช้ lightweight-charts ผ่าน WebView เหมือนเว็บ
 */

import React, { useMemo } from 'react';
import { View, StyleSheet, ActivityIndicator, Platform, Text } from 'react-native';
import { colors } from '@/theme';

// WebView สำหรับ native, iframe สำหรับ web
let WebView: any = null;
try {
  if (Platform.OS !== 'web') {
    WebView = require('react-native-webview').WebView;
  }
} catch {
  // react-native-webview ไม่พร้อมใช้
}

interface TradingViewChartProps {
  symbol: string; // เช่น "BTC/USDT"
  interval?: string; // เช่น "1h", "1d"
  height?: number;
}

function buildChartHtml(binanceSymbol: string, interval: string): string {
  return `<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { background: transparent; overflow: hidden; }
  #chart { width: 100%; height: 100vh; }
</style>
</head>
<body>
<div id="chart"></div>
<script src="https://unpkg.com/lightweight-charts@4.1.3/dist/lightweight-charts.standalone.production.js"></script>
<script>
(async function() {
  const chart = LightweightCharts.createChart(document.getElementById('chart'), {
    layout: {
      background: { type: 'solid', color: 'transparent' },
      textColor: 'rgba(255,255,255,0.5)',
      fontSize: 10,
    },
    grid: {
      vertLines: { color: 'rgba(255,255,255,0.04)' },
      horzLines: { color: 'rgba(255,255,255,0.04)' },
    },
    crosshair: {
      mode: LightweightCharts.CrosshairMode.Normal,
      vertLine: { color: 'rgba(6,182,212,0.3)', width: 1, style: 2 },
      horzLine: { color: 'rgba(6,182,212,0.3)', width: 1, style: 2 },
    },
    timeScale: {
      timeVisible: true,
      secondsVisible: false,
      borderColor: 'rgba(255,255,255,0.08)',
    },
    rightPriceScale: {
      borderColor: 'rgba(255,255,255,0.08)',
    },
    handleScroll: { vertTouchDrag: false },
  });

  const candleSeries = chart.addCandlestickSeries({
    upColor: '#00C853',
    downColor: '#FF1744',
    borderUpColor: '#00C853',
    borderDownColor: '#FF1744',
    wickUpColor: '#00C853',
    wickDownColor: '#FF1744',
  });

  const volumeSeries = chart.addHistogramSeries({
    priceFormat: { type: 'volume' },
    priceScaleId: 'volume',
  });
  chart.priceScale('volume').applyOptions({
    scaleMargins: { top: 0.85, bottom: 0 },
  });

  try {
    const res = await fetch(
      'https://api.binance.com/api/v3/klines?symbol=${binanceSymbol}&interval=${interval}&limit=200'
    );
    const data = await res.json();

    const candles = data.map(k => ({
      time: Math.floor(k[0] / 1000),
      open: parseFloat(k[1]),
      high: parseFloat(k[2]),
      low: parseFloat(k[3]),
      close: parseFloat(k[4]),
    }));

    const volumes = data.map(k => ({
      time: Math.floor(k[0] / 1000),
      value: parseFloat(k[5]),
      color: parseFloat(k[4]) >= parseFloat(k[1])
        ? 'rgba(0,200,83,0.3)'
        : 'rgba(255,23,68,0.3)',
    }));

    candleSeries.setData(candles);
    volumeSeries.setData(volumes);
    chart.timeScale().fitContent();

    // WebSocket สำหรับ real-time updates
    const wsSymbol = '${binanceSymbol}'.toLowerCase();
    const ws = new WebSocket('wss://stream.binance.com:9443/ws/' + wsSymbol + '@kline_${interval}');
    ws.onmessage = (event) => {
      const msg = JSON.parse(event.data);
      if (msg.k) {
        const k = msg.k;
        candleSeries.update({
          time: Math.floor(k.t / 1000),
          open: parseFloat(k.o),
          high: parseFloat(k.h),
          low: parseFloat(k.l),
          close: parseFloat(k.c),
        });
        volumeSeries.update({
          time: Math.floor(k.t / 1000),
          value: parseFloat(k.v),
          color: parseFloat(k.c) >= parseFloat(k.o)
            ? 'rgba(0,200,83,0.3)'
            : 'rgba(255,23,68,0.3)',
        });
      }
    };
  } catch (e) {
    document.getElementById('chart').innerHTML =
      '<div style="display:flex;height:100%;align-items:center;justify-content:center;color:rgba(255,255,255,0.3);font-family:sans-serif;">Chart unavailable</div>';
  }
})();
</script>
</body>
</html>`;
}

export default function TradingViewChart({
  symbol,
  interval = '1h',
  height = 300,
}: TradingViewChartProps) {
  const binanceSymbol = symbol.replace('/', '');

  const html = useMemo(
    () => buildChartHtml(binanceSymbol, interval),
    [binanceSymbol, interval],
  );

  // Web platform: ใช้ iframe
  if (Platform.OS === 'web') {
    return (
      <View style={[styles.container, { height }]}>
        <iframe
          srcDoc={html}
          style={{ width: '100%', height: '100%', border: 'none', background: 'transparent' }}
          sandbox="allow-scripts allow-same-origin"
        />
      </View>
    );
  }

  // Native: ใช้ WebView
  if (!WebView) {
    return (
      <View style={[styles.container, styles.fallback, { height }]}>
        <Text style={styles.fallbackText}>Chart requires WebView</Text>
      </View>
    );
  }

  return (
    <View style={[styles.container, { height }]}>
      <WebView
        source={{ html }}
        style={{ flex: 1, backgroundColor: 'transparent' }}
        scrollEnabled={false}
        javaScriptEnabled
        domStorageEnabled
        originWhitelist={['*']}
        mixedContentMode="compatibility"
        startInLoadingState
        renderLoading={() => (
          <View style={styles.loading}>
            <ActivityIndicator color={colors.brand.cyan} />
          </View>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    borderRadius: 12,
    overflow: 'hidden',
    backgroundColor: 'rgba(0,0,0,0.2)',
  },
  loading: {
    ...StyleSheet.absoluteFillObject,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(0,0,0,0.3)',
  },
  fallback: {
    alignItems: 'center',
    justifyContent: 'center',
  },
  fallbackText: {
    color: 'rgba(255,255,255,0.3)',
    fontSize: 12,
  },
});
