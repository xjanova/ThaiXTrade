@php
    /**
     * TPIX TRADE — ไวท์เปเปอร์มาตรฐานของเหรียญจาก Token Factory (หน้าเว็บ + PDF แม่แบบเดียวกัน)
     *
     * ข้อมูลเหรียญอ่านจากสัญญาบนเชน — อ่านไม่ได้แสดง "อ่านจากเชนไม่ได้" ไม่เดา
     * ส่วนโครงการ/ผู้สร้างมีเฉพาะเมื่อใบลงทะเบียนอนุมัติแล้วและยินยอมเผยแพร่ · หัวข้อที่ไม่มีข้อมูลไม่แสดง
     * ไทยเป็นหลัก อังกฤษกำกับ
     * Developed by Xman Studio
     */
    $web = $mode === 'web';
    $t = $token;
    $contract = (string) $t->contract_address;
    $name = $facts['name'] ?? $t->name;
    $symbol = $facts['symbol'] ?? $t->symbol;

    $flagInfo = [
        'mintable' => ['ผลิตเหรียญเพิ่มได้ (mint)', 'Can mint more tokens', 'ผู้มีสิทธิ์ในสัญญาผลิตเหรียญเพิ่มได้ ทำให้สัดส่วนของผู้ถือเดิมลดลง', 'The privileged account can mint more tokens, diluting existing holders.'],
        'pausable' => ['หยุดการโอนได้ (pause)', 'Can pause transfers', 'ผู้มีสิทธิ์ในสัญญาหยุดการโอนเหรียญทั้งหมดได้ชั่วคราว', 'The privileged account can halt all transfers.'],
        'blacklist' => ['ขึ้นบัญชีดำได้ (blacklist)', 'Can blacklist wallets', 'ผู้มีสิทธิ์ในสัญญาห้ามกระเป๋าบางใบโอนหรือขายได้', 'The privileged account can block specific wallets from transferring or selling.'],
        'auto_burn' => ['เผาอัตโนมัติเมื่อโอน (auto-burn)', 'Auto-burn on transfer', 'ทุกการโอนถูกหักเผาส่วนหนึ่ง ผู้รับได้ไม่ครบจำนวน', 'Part of every transfer is burned, so the receiver gets less.'],
    ];
    $flagText = fn ($v) => $v === null ? ['อ่านจากเชนไม่ได้', 'Could not read on chain', 'muted'] : ($v ? ['มี', 'Yes', 'bad'] : ['ไม่มี', 'No', 'ok']);
    $riskyOn = array_keys(array_filter($facts['flags'] ?? [], fn ($v) => $v === true));
    $zero = '0x'.str_repeat('0', 40);

    $categories = [
        'utility' => ['ยูทิลิตี้', 'Utility'], 'community' => ['ชุมชน', 'Community'], 'gaming' => ['เกม', 'Gaming'],
        'defi' => ['DeFi', 'DeFi'], 'rwa' => ['สินทรัพย์จริง', 'Real-world asset'], 'payment' => ['การชำระเงิน', 'Payment'], 'other' => ['อื่นๆ', 'Other'],
    ];
    $linkLabels = ['website' => 'Website', 'whitepaper_url' => 'Whitepaper', 'twitter' => 'X (Twitter)', 'telegram_group' => 'Telegram', 'facebook' => 'Facebook', 'github' => 'GitHub'];

    $overview = $creator['description'] ?? $t->description;
    $n = 0;
@endphp
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
@if ($web)
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="{{ $t->is_listed ? 'index, follow' : 'noindex' }}">
@endif
<title>{{ $name }} ({{ $symbol }}) — ไวท์เปเปอร์มาตรฐาน | TPIX TRADE</title>
<style>
    @if ($web)
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@400;700&display=swap');
    @else
        @font-face { font-family:'Sarabun'; font-style:normal; font-weight:400; src:url('{{ $fontDir }}/Sarabun-Regular.ttf') format('truetype'); }
        @font-face { font-family:'Sarabun'; font-style:normal; font-weight:700; src:url('{{ $fontDir }}/Sarabun-Bold.ttf') format('truetype'); }
        @page { margin: 20mm 16mm 18mm 16mm; }
    @endif
    body { font-family:'Sarabun','DejaVu Sans',sans-serif; color:#1e293b; font-size:10.5pt; line-height:1.6; background:#ffffff; margin:0; }
    .wrap { max-width:860px; margin:0 auto; padding:{{ $web ? '24px 16px 64px' : '0' }}; }
    .cover { text-align:center; padding:{{ $web ? '28px 0 8px' : '30mm 0 0' }}; }
    .cover .logo { font-size:13pt; color:#0891b2; font-weight:700; letter-spacing:3px; }
    .cover .bar { height:4px; background:#0891b2; border-right:70px solid #7c3aed; width:140px; margin:10px auto 26px auto; }
    .cover h1 { font-size:28pt; margin:0; color:#0f172a; line-height:1.2; }
    .cover .sym { display:inline-block; font-size:12pt; color:#7c3aed; border:1px solid #c4b5fd; border-radius:12px; padding:1px 12px; margin-top:8px; }
    .cover .kind { font-size:13pt; color:#334155; margin-top:18px; }
    .cover .kind .en { font-size:10.5pt; }
    .cover .meta { font-size:9pt; color:#64748b; margin-top:20px; word-wrap:break-word; }
    h2 { font-size:13pt; margin:22px 0 8px 0; padding:5px 10px; background:#ecfeff; border-left:4px solid #0891b2; color:#0f172a; }
    h3 { font-size:11pt; margin:12px 0 4px 0; color:#0e7490; }
    p { margin:0 0 6px 0; }
    .en { color:#64748b; font-size:9.5pt; }
    .muted { color:#64748b; }
    .ok { color:#047857; font-weight:700; }
    .bad { color:#b91c1c; font-weight:700; }
    .box { border:1px solid #cbd5e1; border-radius:6px; padding:9px 12px; margin:10px 0; }
    .warn { border-color:#f59e0b; background:#fffbeb; }
    .info { border-color:#a5f3fc; background:#f0fdff; }
    .badge { display:inline-block; background:#047857; color:#fff; border-radius:10px; padding:1px 10px; font-size:9pt; font-weight:700; }
    table.kv { width:100%; border-collapse:collapse; margin:4px 0; }
    table.kv td { border-bottom:1px solid #e2e8f0; padding:5px 7px; vertical-align:top; }
    table.kv td.k { width:36%; background:#f8fafc; }
    .mono { font-family:'DejaVu Sans Mono',monospace; font-size:8.5pt; word-break:break-all; }
    ul.risks { margin:4px 0 0 0; padding-left:18px; }
    ul.risks li { margin-bottom:6px; }
    a { color:#0891b2; }
    .actions { text-align:center; margin:18px 0 6px; }
    .btn { display:inline-block; background:#0891b2; color:#fff; text-decoration:none; padding:8px 18px; border-radius:8px; font-weight:700; margin:4px; }
    .btn.alt { background:#7c3aed; }
    .footer { {{ $web ? 'margin-top:28px; text-align:center;' : 'position:fixed; bottom:-11mm; left:0; right:0; text-align:center;' }} font-size:7.5pt; color:#94a3b8; border-top:1px solid #e2e8f0; padding-top:3px; }
    .page-break { page-break-after:always; }
    @if ($web)
        @media (max-width: 600px) { .cover h1 { font-size:22pt; } table.kv td.k { width:42%; } }
    @endif
</style>
</head>
<body>
<div class="wrap">
@unless ($web)
    <div class="footer">TPIX TRADE · tpix.online · {{ $symbol }} · {{ $contract }} · {{ $generatedAt->format('d/m/Y H:i') }}</div>
@endunless

<div class="cover">
    <div class="logo">TPIX TRADE · TOKEN FACTORY</div>
    <div class="bar"></div>
    <h1>{{ $name }}</h1>
    <div class="sym">{{ $symbol }}</div>
    <div class="kind">ไวท์เปเปอร์มาตรฐาน <span class="en">/ Standard Whitepaper</span></div>
    <div class="meta">
        TPIX Chain (4289) · <span class="mono">{{ $contract }}</span><br>
        ข้อมูลบนเชน ณ <span class="en">/ On-chain data as of</span> {{ \Illuminate\Support\Carbon::parse($facts['read_at'])->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} (UTC+7)
    </div>
</div>

@if ($web)
    <div class="actions">
        <a class="btn" href="{{ route('token-factory.whitepaper.pdf', $t->id) }}">ดาวน์โหลด PDF / Download PDF</a>
        @if ($creator)
            <a class="btn alt" href="{{ $creator['url'] }}">หน้าโครงการ / Project page</a>
        @endif
    </div>
@else
    <div class="page-break"></div>
@endif

<div class="box info">
    เอกสารนี้แพลตฟอร์ม TPIX TRADE จัดทำอัตโนมัติจากข้อมูลบนบล็อกเชนของเหรียญ{{ $creator ? ' และข้อมูลที่ผู้สร้างให้ไว้ในใบลงทะเบียนที่ผ่านการอนุมัติ' : '' }} ไม่ใช่คำรับรอง คำแนะนำ หรือการชักชวนให้ลงทุน ข้อความภาษาไทยมีผลเหนือกว่า<br>
    <span class="en">This document is generated automatically by TPIX TRADE from the token's on-chain data{{ $creator ? ' and the information the creator provided in an approved registration' : '' }}. It is not an endorsement, recommendation or solicitation to invest. The Thai text prevails.</span>
</div>

@if (filled($overview))
    <h2>{{ ++$n }}. ภาพรวมโครงการ <span class="en">/ Project overview</span></h2>
    <p>{!! nl2br(e($overview)) !!}</p>
    @if ($creator && filled($creator['category']) && isset($categories[$creator['category']]))
        <p class="muted">หมวดหมู่ <span class="en">/ Category</span>: {{ $categories[$creator['category']][0] }} / {{ $categories[$creator['category']][1] }}</p>
    @endif
@endif

<h2>{{ ++$n }}. ข้อมูลเหรียญบนเชน <span class="en">/ On-chain token facts</span></h2>
@unless ($facts['read'])
    <div class="box warn">อ่านข้อมูลจากเชนไม่ได้ในขณะนี้ ค่าที่แสดงมาจากบันทึกของแพลตฟอร์มและยังไม่ได้ยืนยันบนเชน<br>
        <span class="en">The chain could not be read right now; values below come from platform records and are not verified on chain.</span></div>
@endunless
<table class="kv">
    <tr><td class="k">ชื่อ / สัญลักษณ์ <span class="en">/ Name / Symbol</span></td><td>{{ $name }} / {{ $symbol }}</td></tr>
    <tr><td class="k">เครือข่าย <span class="en">/ Network</span></td><td>TPIX Chain (Chain ID 4289)</td></tr>
    <tr><td class="k">ที่อยู่สัญญา <span class="en">/ Contract</span></td>
        <td class="mono">@if ($web)<a href="{{ $explorer }}/address/{{ $contract }}" rel="noopener" target="_blank">{{ $contract }}</a>@else{{ $contract }}@endif</td></tr>
    <tr><td class="k">ซัพพลายทั้งหมด <span class="en">/ Total supply</span></td>
        <td>{{ $facts['total_supply'] !== null ? $facts['total_supply'] : $t->total_supply.' (บันทึกของแพลตฟอร์ม / platform record)' }} {{ $symbol }}</td></tr>
    <tr><td class="k">ทศนิยม <span class="en">/ Decimals</span></td><td>{{ $facts['decimals'] ?? $t->decimals }}</td></tr>
    @if (filled($t->creator_address))
        <tr><td class="k">กระเป๋าผู้สร้าง <span class="en">/ Creator wallet</span></td><td class="mono">{{ $t->creator_address }}</td></tr>
    @endif
    @if (filled($t->tx_hash))
        <tr><td class="k">ธุรกรรมที่สร้าง <span class="en">/ Creation transaction</span></td>
            <td class="mono">@if ($web)<a href="{{ $explorer }}/tx/{{ $t->tx_hash }}" rel="noopener" target="_blank">{{ $t->tx_hash }}</a>@else{{ $t->tx_hash }}@endif</td></tr>
    @endif
    @if ($t->created_at)
        <tr><td class="k">สร้างเมื่อ <span class="en">/ Created</span></td><td>{{ $t->created_at->timezone('Asia/Bangkok')->format('d/m/Y') }}</td></tr>
    @endif
    <tr><td class="k">ซอร์สโค้ดยืนยันบน explorer <span class="en">/ Source verified</span></td>
        <td>{{ $t->is_verified ? 'ยืนยันแล้ว / Verified' : 'ยังไม่ยืนยัน / Not verified' }}</td></tr>
</table>

<h2>{{ ++$n }}. สิทธิ์ควบคุมในสัญญา <span class="en">/ Contract controls</span></h2>
<p class="muted">ค่าเหล่านี้อ่านจากสัญญาโดยตรง และสำหรับเหรียญ ERC20V2 ของ Token Factory เป็นค่าที่ฝังถาวร เปลี่ยนภายหลังไม่ได้<br>
    <span class="en">Read directly from the contract; for Token Factory ERC20V2 tokens these switches are fixed forever.</span></p>
<table class="kv">
    @foreach ($flagInfo as $key => [$th, $en])
        @php [$vth, $ven, $cls] = $flagText($facts['flags'][$key] ?? null); @endphp
        <tr><td class="k">{{ $th }} <span class="en">/ {{ $en }}</span></td><td class="{{ $cls }}">{{ $vth }} <span class="en">/ {{ $ven }}</span></td></tr>
    @endforeach
    <tr><td class="k">เจ้าของสัญญา (owner) <span class="en">/ Contract owner</span></td>
        <td>
            @if ($facts['owner'] === null)
                <span class="muted">อ่านจากเชนไม่ได้ / Could not read</span>
            @elseif ($facts['owner'] === $zero)
                <span class="ok">สละสิทธิ์แล้ว / Renounced</span>
            @else
                <span class="mono">{{ $facts['owner'] }}</span>
            @endif
        </td></tr>
</table>

@if ($creator)
    <h2>{{ ++$n }}. ผู้สร้างและโครงการ <span class="en">/ Creator and project</span></h2>
    <table class="kv">
        <tr><td class="k">การยืนยันตัวตน <span class="en">/ Identity</span></td>
            <td>@if ($creator['kyc_verified'])<span class="badge">ยืนยันตัวตนผ่าน Thaiprompt แล้ว · Verified through Thaiprompt</span>@else<span class="muted">สถานะการยืนยันตัวตนหมดอายุหรือถูกถอน / Verification lapsed or withdrawn</span>@endif</td></tr>
        <tr><td class="k">ประเภทผู้สร้าง <span class="en">/ Creator type</span></td>
            <td>{{ $creator['applicant_type'] === 'company' ? 'นิติบุคคล / Company' : 'บุคคลธรรมดา / Individual' }}</td></tr>
        @foreach ([
            'company_name' => ['ชื่อนิติบุคคล', 'Company'], 'company_name_en' => ['ชื่อนิติบุคคล (อังกฤษ)', 'Company (English)'],
            'registration_number' => ['เลขทะเบียน', 'Registration no.'], 'registration_country' => ['ประเทศที่จดทะเบียน', 'Country'],
            'signatory_name' => ['ผู้มีอำนาจลงนาม', 'Signatory'], 'signatory_position' => ['ตำแหน่ง', 'Position'],
            'full_name' => ['ชื่อผู้สร้าง', 'Creator'], 'full_name_en' => ['ชื่อผู้สร้าง (อังกฤษ)', 'Creator (English)'],
        ] as $key => [$th, $en])
            @if (filled($creator['entity'][$key] ?? null))
                <tr><td class="k">{{ $th }} <span class="en">/ {{ $en }}</span></td><td>{{ $creator['entity'][$key] }}</td></tr>
            @endif
        @endforeach
        @if ($creator['approved_at'])
            <tr><td class="k">อนุมัติใบลงทะเบียนเมื่อ <span class="en">/ Registration approved</span></td>
                <td>{{ \Illuminate\Support\Carbon::parse($creator['approved_at'])->timezone('Asia/Bangkok')->format('d/m/Y') }}</td></tr>
        @endif
        @if ($creator['starter_allocation_bps'] !== null)
            <tr><td class="k">ส่วนเริ่มโครงการของผู้สร้าง (ไม่ล็อก) <span class="en">/ Creator starter allocation (unlocked)</span></td>
                <td>{{ rtrim(rtrim(number_format($creator['starter_allocation_bps'] / 100, 2), '0'), '.') }}%</td></tr>
        @endif
        @if (filled($creator['agreement_version']))
            <tr><td class="k">ข้อตกลงผู้สร้างที่ลงนาม <span class="en">/ Signed creator agreement</span></td>
                <td>ฉบับ <span class="en">/ version</span> {{ $creator['agreement_version'] }}<br><span class="mono">SHA-256 {{ $creator['agreement_hash'] }}</span></td></tr>
        @endif
    </table>
    @if (filled($creator['team_description']))
        <h3>ทีมงาน <span class="en">/ Team</span></h3>
        <p>{!! nl2br(e($creator['team_description'])) !!}</p>
    @endif
    @if (filled($creator['use_of_funds']))
        <h3>แผนการใช้เงิน <span class="en">/ Use of funds</span></h3>
        <p>{!! nl2br(e($creator['use_of_funds'])) !!}</p>
    @endif
    @if ($creator['links'] !== [])
        <h3>ช่องทางของโครงการ <span class="en">/ Project links</span></h3>
        <table class="kv">
            @foreach ($creator['links'] as $key => $url)
                <tr><td class="k">{{ $linkLabels[$key] ?? $key }}</td>
                    <td class="mono">@if ($web)<a href="{{ $url }}" rel="nofollow noopener noreferrer" target="_blank">{{ $url }}</a>@else{{ $url }}@endif</td></tr>
            @endforeach
        </table>
        <p class="muted">ลิงก์เหล่านี้ผู้สร้างเป็นผู้ให้ไว้ แพลตฟอร์มไม่ได้ตรวจเนื้อหาปลายทาง <span class="en">/ Links are supplied by the creator; the platform has not reviewed their content.</span></p>
    @endif
@endif

<h2>{{ ++$n }}. ความเสี่ยงที่ผู้ซื้อต้องรู้ <span class="en">/ Risks buyers must know</span></h2>
@if ($riskyOn !== [])
    <div class="box warn">
        <strong>สัญญานี้เปิดสิทธิ์ที่มีความเสี่ยง <span class="en">/ This contract has risky controls switched on</span></strong>
        <ul class="risks">
            @foreach ($riskyOn as $key)
                <li>{{ $flagInfo[$key][2] }}<br><span class="en">{{ $flagInfo[$key][3] }}</span></li>
            @endforeach
        </ul>
    </div>
@endif
<ul class="risks">
    @foreach ($risks as $risk)
        <li>{{ $risk['th'] }}<br><span class="en">{{ $risk['en'] }}</span></li>
    @endforeach
</ul>

<h2>{{ ++$n }}. ข้อจำกัดความรับผิด <span class="en">/ Disclaimer</span></h2>
<p>แพลตฟอร์มเป็นผู้ให้บริการเทคโนโลยี ไม่ใช่ผู้ออกเหรียญ นายหน้า หรือผู้ให้คำแนะนำการลงทุน ข้อมูลบนเชนอาจเปลี่ยนได้ตามการทำงานของสัญญา (เช่น ซัพพลายลดลงเมื่อมีการเผา) ตรวจสอบค่าล่าสุดบน explorer ก่อนตัดสินใจทุกครั้ง ข้อมูลโครงการเป็นความรับผิดชอบของผู้สร้าง</p>
<p class="en">The platform is a technology provider, not the issuer, a broker or an investment adviser. On-chain values can change as the contract operates (for example, supply falls when tokens are burned); always check the latest values on the explorer before deciding. Project information is the creator's responsibility.</p>

@if ($web)
    <div class="footer">
        สร้างโดย TPIX TRADE · tpix.online · {{ $generatedAt->format('d/m/Y H:i') }} (UTC+7) · <span class="en">Generated by TPIX TRADE</span>
    </div>
@endif
</div>
</body>
</html>
