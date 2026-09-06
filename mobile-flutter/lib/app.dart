/// TPIX TRADE — App Root
/// MaterialApp + GoRouter + Theme
///
/// Developed by Xman Studio

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import 'core/theme/app_colors.dart';
import 'core/theme/app_theme.dart';
import 'core/router.dart';
import 'providers/theme_provider.dart';

class TpixTradeApp extends StatelessWidget {
  const TpixTradeApp({super.key});

  @override
  Widget build(BuildContext context) {
    // ต้อง watch ไม่ใช่ read — ไม่งั้นสลับชุดสีแล้วหน้าจอไม่ขยับ
    final themeProvider = context.watch<ThemeProvider>();
    final isDark = themeProvider.palette.isDark;

    // สีแถบสถานะต้องตรงข้ามกับพื้น ไม่งั้นธีมสว่างจะได้ไอคอนขาวบนพื้นขาว
    SystemChrome.setSystemUIOverlayStyle(SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: isDark ? Brightness.light : Brightness.dark,
      statusBarBrightness: isDark ? Brightness.dark : Brightness.light,
      systemNavigationBarColor: AppColors.bgPrimary,
      systemNavigationBarIconBrightness:
          isDark ? Brightness.light : Brightness.dark,
    ));

    return MaterialApp.router(
      title: 'TPIX TRADE',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.darkTheme,
      routerConfig: appRouter,

      // บังคับสร้างต้นไม้ใหม่ทั้งหมดเมื่อสลับชุดสี
      //
      // จำเป็นเพราะสีอ่านผ่าน static getter ของ AppColors ไม่ได้ผูกกับ
      // InheritedWidget — Flutter จึงไม่รู้ว่าต้อง rebuild ใคร วิดเจ็ตที่ถูก
      // สร้างแบบ const ไว้ (ตัวเดิม ไม่ dirty) จะค้างสีเก่าทั้งที่ธีมเปลี่ยนแล้ว
      //
      // วาง key ไว้ที่ builder ไม่ใช่ที่ MaterialApp เพราะถ้าใส่ที่ MaterialApp
      // ตัว router จะถูกสร้างใหม่ไปด้วย แล้วผู้ใช้จะถูกเด้งกลับหน้าแรกทันที
      // ที่กดเลือกธีมในหน้าตั้งค่า
      builder: (context, child) => KeyedSubtree(
        key: themeProvider.appKey,
        child: child ?? const SizedBox.shrink(),
      ),
    );
  }
}
