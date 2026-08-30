/// TPIX TRADE — AI Monitor (barrel)
/// รวมแผงมอนิเตอร์ของหน้า AI TRADE ไว้ที่เดียว — หน้าจอ import ไฟล์เดียวจบ
///
/// มีอะไรบ้าง:
///   • [AiDecisionFeedPanel]   — ฟีด "AI คิดอะไรอยู่" ทุกรอบ พร้อมเหตุผล
///   • [AiDemoPortfolioPanel]  — พอร์ตทดลอง (ปิดแล้ว + ยังไม่ปิด + รวม)
///   • [AiMarketViewPanel]     — มุมมองตลาดของ AI
///   • [AiAnalyticsPanel]      — สรุปผลย้อนหลัง
///   • [AiAdvisorPanel]        — คำแนะนำจากที่ปรึกษา AI
///
/// วิธีใช้จากหน้า /ai (ตัวอย่าง):
/// ```dart
/// import '../../widgets/ai_bot/ai_monitor.dart';
///
/// AiDemoPortfolioPanel(
///   demo: provider.demo,                 // AiBotDemo? จาก models/ai_bot_models.dart
///   loading: provider.isLoadingDemo,
///   errorMessage: provider.demoErrorText(locale.isThai), // แปลรหัสก่อนส่งเข้ามา
///   onRetry: provider.loadDemo,
/// )
/// ```
///
/// ทุกแผงเป็น "จอล้วน" — ไม่ยิง API เอง ไม่ตั้ง Timer เอง ผู้เรียกคุมจังหวะโหลด
/// ทั้งหมด (สำคัญ เพราะโควตาจริงเหลือราว 15 คำขอ/นาที/IP และแชร์กับทั้งแอพ)
///
/// โมเดลข้อมูลอยู่ที่ `lib/models/ai_bot_models.dart` — barrel นี้ไม่ export ซ้ำ
/// ให้ import ไฟล์นั้นตรงๆ เมื่อหน้าจอต้องอ้างชนิดข้อมูล
///
/// Developed by Xman Studio
library;

export 'advisor_panel.dart';
export 'analytics_panel.dart';
export 'decision_feed_panel.dart';
export 'demo_portfolio_panel.dart';
export 'market_view_panel.dart';
export 'monitor_atoms.dart';
export 'monitor_format.dart';
