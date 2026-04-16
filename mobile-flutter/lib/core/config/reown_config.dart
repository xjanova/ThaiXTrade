/// TPIX TRADE — Reown AppKit / WalletConnect v2 Config
/// ใช้สำหรับเชื่อม External Wallet (MetaMask, Trust, Rainbow, OKX, Coinbase)
///
/// Setup:
/// 1. สมัคร Project ID ฟรีที่ https://cloud.reown.com
/// 2. รัน `flutter run --dart-define=REOWN_PROJECT_ID=xxx`
///    หรือใส่ใน build CI: --dart-define=REOWN_PROJECT_ID=xxx
///
/// Developed by Xman Studio

import 'package:reown_appkit/reown_appkit.dart';

class ReownConfig {
  ReownConfig._();

  /// Project ID จาก Reown Cloud — ใส่ผ่าน --dart-define ตอน build
  /// ห้าม hardcode ในโค้ดเพราะ APK reverse-engineerable
  static const String projectId =
      String.fromEnvironment('REOWN_PROJECT_ID', defaultValue: '');

  /// แอปได้ตั้ง Project ID หรือยัง
  static bool get isConfigured => projectId.isNotEmpty;

  /// App metadata ส่งไปแสดงใน external wallet ตอนขออนุญาต
  static PairingMetadata get metadata => const PairingMetadata(
        name: 'TPIX TRADE',
        description: 'Decentralized Exchange — TPIX Chain',
        url: 'https://tpix.online',
        icons: ['https://tpix.online/tpixlogo.webp'],
        redirect: Redirect(
          native: 'tpixtrade://',
          universal: 'https://tpix.online/app',
        ),
      );

  /// Chains ที่รองรับ — ตรงกับ ChainConfig ในแอป (TPIX, BSC, Polygon, ETH)
  /// CAIP-2 format: 'eip155:CHAIN_ID'
  static const List<String> supportedChainIds = [
    'eip155:4289', // TPIX Chain
    'eip155:56', // BSC
    'eip155:137', // Polygon
    'eip155:1', // Ethereum
  ];

  /// Methods ที่ขอจาก wallet — minimum สำหรับ DEX trading
  static const List<String> requiredMethods = [
    'personal_sign',
    'eth_sendTransaction',
    'eth_signTypedData_v4',
  ];

  /// Events ที่ subscribe — ตอบสนอง chain/account switch
  static const List<String> requiredEvents = [
    'chainChanged',
    'accountsChanged',
  ];
}
