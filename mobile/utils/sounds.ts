/**
 * Sound Effects for TPIX TRADE Mobile
 * เสียงเอฟเฟกต์สำหรับแอพมือถือ TPIX TRADE
 *
 * ใช้ expo-haptics สำหรับ haptic feedback บนอุปกรณ์จริง
 * Web Audio API สำหรับเสียงบน web
 */

import { Platform } from 'react-native';
import * as Haptics from 'expo-haptics';

// --- Web Audio API (ใช้บน web เท่านั้น) ---

let audioCtx: AudioContext | null = null;

function getAudioContext(): AudioContext | null {
  if (Platform.OS !== 'web') return null;
  try {
    if (!audioCtx) {
      audioCtx = new (window.AudioContext || (window as any).webkitAudioContext)();
    }
    if (audioCtx.state === 'suspended') {
      audioCtx.resume().catch(() => {});
    }
    return audioCtx;
  } catch {
    return null;
  }
}

function playTone(frequency: number, duration: number, type: OscillatorType = 'sine', volume = 0.12): void {
  const ctx = getAudioContext();
  if (!ctx) return;
  try {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = type;
    osc.frequency.setValueAtTime(frequency, ctx.currentTime);
    gain.gain.setValueAtTime(volume, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + duration);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start(ctx.currentTime);
    osc.stop(ctx.currentTime + duration);
  } catch {
    // เสียงไม่ critical
  }
}

function playMelody(notes: [number, number][], type: OscillatorType = 'sine', volume = 0.1): void {
  const ctx = getAudioContext();
  if (!ctx) return;
  try {
    let time = ctx.currentTime;
    for (const [freq, dur] of notes) {
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = type;
      osc.frequency.setValueAtTime(freq, time);
      gain.gain.setValueAtTime(volume, time);
      gain.gain.exponentialRampToValueAtTime(0.001, time + dur);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start(time);
      osc.stop(time + dur);
      time += dur * 0.8;
    }
  } catch {
    // silent fail
  }
}

// --- Public Sound API ---

/** เสียง splash/startup */
export function playSplashSound(): void {
  if (Platform.OS === 'web') {
    playMelody([
      [523.25, 0.15],
      [659.25, 0.15],
      [783.99, 0.2],
      [1046.50, 0.35],
    ], 'sine', 0.1);
  } else {
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
  }
}

/** เสียงเชื่อมต่อ wallet สำเร็จ */
export function playConnectSound(): void {
  if (Platform.OS === 'web') {
    playMelody([
      [880, 0.1],
      [1108, 0.1],
      [1318, 0.2],
    ], 'sine', 0.12);
  } else {
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
  }
}

/** เสียง disconnect */
export function playDisconnectSound(): void {
  if (Platform.OS === 'web') {
    playMelody([
      [880, 0.1],
      [660, 0.1],
      [440, 0.2],
    ], 'sine', 0.08);
  } else {
    Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Medium);
  }
}

/** เสียง trade/order สำเร็จ */
export function playTradeSound(): void {
  if (Platform.OS === 'web') {
    playMelody([
      [587.33, 0.08],
      [783.99, 0.08],
      [987.77, 0.12],
      [1174.66, 0.2],
    ], 'triangle', 0.1);
  } else {
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
  }
}

/** เสียง error/fail */
export function playErrorSound(): void {
  if (Platform.OS === 'web') {
    playMelody([
      [440, 0.15],
      [349.23, 0.25],
    ], 'sawtooth', 0.06);
  } else {
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error);
  }
}

/** เสียง notification */
export function playNotificationSound(): void {
  if (Platform.OS === 'web') {
    playTone(1046.50, 0.12, 'sine', 0.1);
  } else {
    Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light);
  }
}

/** เสียง click เบาๆ */
export function playClickSound(): void {
  if (Platform.OS === 'web') {
    playTone(1200, 0.05, 'sine', 0.06);
  } else {
    Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light);
  }
}

/** เสียง swap สำเร็จ */
export function playSwapSound(): void {
  if (Platform.OS === 'web') {
    playMelody([
      [659.25, 0.1],
      [783.99, 0.1],
      [1046.50, 0.15],
      [1318.51, 0.2],
    ], 'sine', 0.1);
  } else {
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
  }
}
