{{--
    จอโหลดหน้าแรก — ส่วนใน <head>: โหลดภาพน้องล่วงหน้า + สไตล์ (ฝัง inline ให้วาดได้ตั้งแต่เฟรมแรก)

    ภาพน้องใช้ URL เดียวกับ spriteStage.js (?v= ต้องตรงกัน — มีเทสต์คุมใน tests/js/utils/homeSplash.test.js)
    หน้า 3D ใช้ท่าบิน/ท่าเชียร์อยู่แล้ว → โหลดตอนนี้ก็ไม่เปลืองเน็ตเพิ่ม ได้ใช้แคชเดียวกัน
    ท่าบินขึ้นจอทันที (สำคัญ) · ท่าเชียร์ใช้ตอนครบ 100% (ไม่รีบ ไม่แย่งเน็ตกับ JS)
--}}
@php
    // ตัดคอมเมนต์ทิ้งก่อนฝัง — ไฟล์ต้นฉบับอธิบายเป็นภาษาไทยเยอะ (UTF-8 ตัวละ 3 ไบต์) ผู้ใช้ไม่ต้องดาวน์โหลด
    $tpixSplashCssPath = resource_path('views/partials/home-splash/splash.css');
    $tpixSplashCss = is_file($tpixSplashCssPath) ? (string) file_get_contents($tpixSplashCssPath) : '';
    $tpixSplashCss = preg_replace(['#/\*.*?\*/#s', '#\n\s*\n+#'], ['', "\n"], $tpixSplashCss);
@endphp
<link rel="preload" as="image" href="/images/mascot/fly.webp?v=2" fetchpriority="high">
<link rel="preload" as="image" href="/images/mascot/cheer.webp?v=2" fetchpriority="low">
<style>{!! $tpixSplashCss !!}</style>
