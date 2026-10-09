{{--
    TPIX TRADE — กลับจาก Thaiprompt เข้าแอปมือถือ

    เปิดในเบราว์เซอร์ของมือถือหลังลูกค้ากดอนุญาตที่ Thaiprompt (ทางแอป ไม่มี session เว็บ)
    พยายามเด้งกลับเข้าแอปเอง แต่เบราว์เซอร์บางตัวไม่ยอมเปิด custom scheme โดยไม่มีคนกด
    จึงต้องมีปุ่มให้กดเสมอ

    ⚠️ deep link มี "รหัสรับผล" — ใช้ครั้งเดียว อายุ 8 นาที และแลกได้เฉพาะบัญชีที่เริ่มคำขอ
       (ดู ThaipromptKycService::captureAppCallback) · หน้านี้ no-store + no-referrer
--}}
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>กลับไปที่แอป TPIX TRADE</title>
    <style>
        :root { --bg:#0a0e1a; --card:#111827; --ink:#f3f4f6; --muted:#9ca3af; --line:#1f2937; --ok:#00c853; --warn:#f59e0b; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:16px;
               background:var(--bg); color:var(--ink); font-family:"Noto Sans Thai","Sarabun",system-ui,-apple-system,"Segoe UI",sans-serif; }
        .card { width:100%; max-width:420px; background:var(--card); border:1px solid var(--line); border-radius:20px; padding:28px 22px; text-align:center; }
        .icon { width:56px; height:56px; margin:0 auto 14px; border-radius:50%; display:grid; place-items:center; font-size:26px; }
        .icon.ok { background:rgba(0,200,83,.15); color:var(--ok); }
        .icon.warn { background:rgba(245,158,11,.15); color:var(--warn); }
        h1 { font-size:19px; margin:0 0 8px; line-height:1.4; }
        p { color:var(--muted); font-size:14px; line-height:1.6; margin:0 0 20px; }
        a.btn { display:block; padding:14px; border-radius:12px; background:linear-gradient(135deg,#0ea5e9,#16a34a); color:#fff; font-weight:600; text-decoration:none; font-size:15px; }
    </style>
</head>
<body>
    <main class="card">
        @if ($ok)
            <div class="icon ok" aria-hidden="true">✓</div>
            <h1>รับผลจาก Thaiprompt แล้ว</h1>
            <p>กดปุ่มด้านล่างเพื่อกลับไปที่แอป TPIX TRADE ระบบจะบันทึกผลยืนยันตัวตนให้ทันที</p>
        @elseif ($denied)
            <div class="icon warn" aria-hidden="true">!</div>
            <h1>ยังไม่ได้อนุญาต</h1>
            <p>คุณกด "ไม่อนุญาต" ที่ Thaiprompt — กลับไปที่แอปแล้วเริ่มใหม่ได้ทุกเมื่อ</p>
        @else
            <div class="icon warn" aria-hidden="true">!</div>
            <h1>ลิงก์หมดอายุ</h1>
            <p>กลับไปที่แอป TPIX TRADE แล้วกด "ยืนยันด้วย Thaiprompt" ใหม่อีกครั้ง</p>
        @endif

        <a class="btn" href="{{ $deepLink }}">กลับไปที่แอป TPIX TRADE</a>
    </main>

    <script>
        // ลองเด้งกลับเองหนึ่งครั้ง — ไม่ได้ก็ยังมีปุ่มด้านบน
        setTimeout(function () { window.location.href = @json($deepLink); }, 300);
    </script>
</body>
</html>
