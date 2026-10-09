{{--
    จอโหลดหน้าแรก — หลอดดาวน์โหลด + น้อง TPIX ขี่เหรียญบินไปตามหลอด (ครบ 100% = น้องเชียร์ แล้วจางหาย)

    เจ้าของสั่ง: "หน้าแรกของเว็บควรมี หลอดดาวน์โหลด และอนิเมชั่นน้อง TPIX สวยๆ ระหว่างรอโหลด"

    - วางก่อน #app → เบราว์เซอร์วาดได้ก่อน JS ก้อนหลักโหลดเสร็จ (ช่วง A อยู่ใน splash.js)
    - Vue ขึ้นแล้ว Composables/useHomeSplash.js ส่งความคืบหน้าจริงเข้า element เดียวกันนี้ (ช่วง B)
    - เริ่มแบบ hidden: สคริปต์ข้างล่างเป็นคนเปิด → ไม่มี JS/สคริปต์พัง = ไม่มีจอโหลดค้างบังเว็บ
    - Vue mount ลง #app ตามปกติ ไม่ต้องรอจอนี้ (จอนี้อยู่นอก #app Vue ไม่แตะ)
    - ข้อความไทย/อังกฤษเลือกใน splash.js ตาม tpix_locale — ตรงนี้ไม่มีข้อความให้แปล
    - ตราแบรนด์: ?v= ต้องตรงกับ BRAND_VERSION ใน resources/js/utils/brand.js (Cloudflare แคชรูป 1 ปี)
      ไฟล์ยังไม่มี/โหลดไม่ได้ → splash.js เอารูปออก เหลือตัวอักษร TPIX TRADE
--}}
@php
    $tpixSplashJsPath = resource_path('views/partials/home-splash/splash.js');
    $tpixSplashJs = is_file($tpixSplashJsPath) ? (string) file_get_contents($tpixSplashJsPath) : '';
    // ตัดเฉพาะคอมเมนต์ที่ปลอดภัย: บล็อก /* */ และบรรทัดที่ขึ้นต้นด้วย // (ไม่แตะ // ใน URL/สตริง)
    $tpixSplashJs = preg_replace(['#/\*.*?\*/#s', '#^[ \t]*//.*$#m', '#\n\s*\n+#'], ['', '', "\n"], $tpixSplashJs);
@endphp
<div id="tpix-splash" class="tpix-splash" hidden>
    <div class="tpix-splash__glow" aria-hidden="true"></div>
    <div class="tpix-splash__horizon" aria-hidden="true"><div class="tpix-splash__grid"></div></div>
    <div class="tpix-splash__inner">
        <div class="tpix-splash__brand">
            <img class="tpix-splash__mark" src="/images/brand/tpix-trade-mark.svg?v=2" alt="" width="64" height="64" decoding="async" data-splash-mark>
            <span class="tpix-splash__word">TPIX TRADE</span>
        </div>
        <div class="tpix-splash__stage">
            <div class="tpix-splash__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="3" aria-label="Loading TPIX TRADE" data-splash-bar>
                <div class="tpix-splash__clip" aria-hidden="true">
                    <div class="tpix-splash__fill" data-splash-fill>
                        <div class="tpix-splash__fill-bg" data-splash-fill-bg><span class="tpix-splash__shimmer"></span></div>
                        <span class="tpix-splash__head"></span>
                    </div>
                </div>
                <div class="tpix-splash__rider" aria-hidden="true" data-splash-rider>
                    <span class="tpix-splash__tip"></span>
                    <div class="tpix-splash__bob">
                        <span class="tpix-splash__aura"></span>
                        <img class="tpix-splash__pose tpix-splash__pose--fly" src="/images/mascot/fly.webp?v=2" alt="" width="560" height="840" decoding="async" fetchpriority="high">
                        <img class="tpix-splash__pose tpix-splash__pose--cheer" src="/images/mascot/cheer.webp?v=2" alt="" width="560" height="840" decoding="async" data-splash-cheer>
                        <span class="tpix-splash__sparks"><i class="tpix-splash__spark"></i><i class="tpix-splash__spark"></i><i class="tpix-splash__spark"></i><i class="tpix-splash__spark"></i><i class="tpix-splash__spark"></i><i class="tpix-splash__spark"></i></span>
                    </div>
                </div>
            </div>
        </div>
        <p class="tpix-splash__pct" aria-hidden="true" data-splash-pct>3%</p>
        <p class="tpix-splash__label" data-splash-label></p>
    </div>
</div>
<script>{!! $tpixSplashJs !!}</script>
