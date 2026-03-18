import React, { useMemo } from 'react';
import { View } from 'react-native';
import Svg, { Path, Defs, LinearGradient, Stop } from 'react-native-svg';
import { colors } from '@/theme';

interface MiniChartProps {
  data: number[];
  color?: string;
  width?: number;
  height?: number;
}

function buildLinePath(
  data: number[],
  width: number,
  height: number,
  padding: number = 2,
): string {
  if (data.length < 2) return '';

  const min = Math.min(...data);
  const max = Math.max(...data);
  const range = max - min || 1;

  const usableHeight = height - padding * 2;
  const stepX = width / (data.length - 1);

  const points = data.map((value, index) => {
    const x = index * stepX;
    const y = padding + usableHeight - ((value - min) / range) * usableHeight;
    return { x, y };
  });

  // Build smooth cubic bezier path
  let path = `M ${points[0].x},${points[0].y}`;

  for (let i = 0; i < points.length - 1; i++) {
    const current = points[i];
    const next = points[i + 1];
    const prev = points[i - 1] || current;
    const afterNext = points[i + 2] || next;

    const tension = 0.3;
    const cp1x = current.x + (next.x - prev.x) * tension;
    const cp1y = current.y + (next.y - prev.y) * tension;
    const cp2x = next.x - (afterNext.x - current.x) * tension;
    const cp2y = next.y - (afterNext.y - current.y) * tension;

    path += ` C ${cp1x},${cp1y} ${cp2x},${cp2y} ${next.x},${next.y}`;
  }

  return path;
}

function buildFillPath(linePath: string, width: number, height: number): string {
  if (!linePath) return '';
  return `${linePath} L ${width},${height} L 0,${height} Z`;
}

// Counter for deterministic gradient IDs / ตัวนับสำหรับ ID ที่ไม่สุ่ม
let gradientCounter = 0;

export function MiniChart({
  data,
  color = colors.brand.cyan,
  width = 100,
  height = 40,
}: MiniChartProps) {
  const { linePath, fillPath, gradientId } = useMemo(() => {
    // Deterministic ID based on counter / ใช้ตัวนับแทนการสุ่ม
    const id = `gradient_mc_${++gradientCounter}`;
    const line = buildLinePath(data, width, height);
    const fill = buildFillPath(line, width, height);
    return { linePath: line, fillPath: fill, gradientId: id };
  }, [data, width, height]);

  if (data.length < 2) {
    return <View style={{ width, height }} />;
  }

  return (
    <View style={{ width, height }}>
      <Svg width={width} height={height} viewBox={`0 0 ${width} ${height}`}>
        <Defs>
          <LinearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
            <Stop offset="0" stopColor={color} stopOpacity={0.25} />
            <Stop offset="1" stopColor={color} stopOpacity={0} />
          </LinearGradient>
        </Defs>
        <Path
          d={fillPath}
          fill={`url(#${gradientId})`}
        />
        <Path
          d={linePath}
          fill="none"
          stroke={color}
          strokeWidth={1.5}
          strokeLinecap="round"
          strokeLinejoin="round"
        />
      </Svg>
    </View>
  );
}
