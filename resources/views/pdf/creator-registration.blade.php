@php
    /**
     * TPIX TRADE — PDF ข้อตกลงผู้สร้างเหรียญที่กรอกและลงนามแล้ว (DomPDF)
     * ไทยเป็นหลัก อังกฤษกำกับ — ข้อตกลงพิมพ์ตามฉบับที่ผู้สร้างเซ็นจริง
     * Developed by Xman Studio
     */
    $r = $registration;
    $signed = $r->isSigned();
    $bkk = fn ($dt) => $dt ? $dt->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i:s').' (UTC+7)' : '—';
    $utc = fn ($dt) => $dt ? $dt->copy()->utc()->format('Y-m-d H:i:s').' UTC' : '';
    $wrap = fn (?string $s, int $n = 66) => $s ? trim(chunk_split($s, $n, "\n")) : '—';
    $v = fn (string $key) => ($form[$key] ?? null) === null || ($form[$key] ?? '') === '' ? '—' : (string) $form[$key];

    $statusLabel = [
        \App\Models\CreatorRegistration::STATUS_DRAFT => ['ร่าง — ยังไม่ได้ส่ง', 'Draft — not submitted'],
        \App\Models\CreatorRegistration::STATUS_SUBMITTED => ['ลงนามแล้ว — รอแอดมินตรวจ', 'Signed — awaiting review'],
        \App\Models\CreatorRegistration::STATUS_APPROVED => ['อนุมัติแล้ว', 'Approved'],
        \App\Models\CreatorRegistration::STATUS_REJECTED => ['ไม่อนุมัติ', 'Rejected'],
    ][$r->status] ?? [$r->status, $r->status];

    $categories = [
        'utility' => 'ยูทิลิตี้ / Utility', 'community' => 'ชุมชน / Community', 'gaming' => 'เกม / Gaming',
        'defi' => 'DeFi', 'rwa' => 'สินทรัพย์จริง / Real-world asset', 'payment' => 'การชำระเงิน / Payment', 'other' => 'อื่นๆ / Other',
    ];
    $fundSources = [
        'own_funds' => 'เงินทุนส่วนตัว / Own funds', 'business_revenue' => 'รายได้ธุรกิจ / Business revenue',
        'investors' => 'นักลงทุน / Investors', 'grants' => 'ทุนสนับสนุน / Grants', 'other' => 'อื่นๆ / Other',
    ];

    $isCompany = ($form['applicant_type'] ?? null) === \App\Models\CreatorRegistration::TYPE_COMPANY;
    $applicant = $isCompany
        ? [
            ['ประเภทผู้สมัคร', 'Applicant type', 'นิติบุคคล / Company'],
            ['ชื่อนิติบุคคล', 'Company name', $v('company_name')],
            ['ชื่อนิติบุคคล (อังกฤษ)', 'Company name (English)', $v('company_name_en')],
            ['เลขทะเบียนนิติบุคคล', 'Registration number', $v('registration_number')],
            ['ประเทศที่จดทะเบียน', 'Country of registration', $v('registration_country')],
            ['วันที่จดทะเบียน', 'Incorporation date', $v('incorporation_date')],
        ]
        : [
            ['ประเภทผู้สมัคร', 'Applicant type', 'บุคคลธรรมดา / Individual'],
            ['ชื่อ-นามสกุล', 'Full name', $v('full_name')],
            ['ชื่อ-นามสกุล (อังกฤษ)', 'Full name (English)', $v('full_name_en')],
            ['วันเกิด', 'Date of birth', $v('date_of_birth')],
            ['สัญชาติ', 'Nationality', $v('nationality')],
            ['อาชีพ', 'Occupation', $v('occupation')],
        ];
    $applicant = array_merge($applicant, [
        ['ผู้มีอำนาจลงนาม', 'Authorised signatory', $v('signatory_name')],
        ['ตำแหน่ง', 'Position', $v('signatory_position')],
        ['อีเมล', 'Email', $v('email')],
        ['เบอร์โทรศัพท์', 'Phone', $v('phone')],
        ['ที่อยู่', 'Address', $v('address')],
        ['ประเทศที่พำนัก', 'Country of residence', $v('country')],
        ['Telegram', 'Telegram', $v('telegram')],
        ['LINE ID', 'LINE ID', $v('line_id')],
    ]);

    $project = [
        ['ชื่อโครงการ', 'Project name', $v('project_name')],
        ['ชื่อเหรียญ', 'Token name', $v('token_name')],
        ['สัญลักษณ์', 'Symbol', $v('token_symbol')],
        ['ซัพพลายที่วางแผน', 'Planned supply', isset($form['planned_supply']) && $form['planned_supply'] !== null ? number_format((float) $form['planned_supply'], 0) : '—'],
        ['หมวดหมู่', 'Category', $categories[$form['category'] ?? ''] ?? '—'],
        ['รายละเอียด', 'Description', $v('description')],
        ['เว็บไซต์', 'Website', $v('website')],
        ['Whitepaper', 'Whitepaper', $v('whitepaper_url')],
        ['X (Twitter)', 'X (Twitter)', $v('twitter')],
        ['กลุ่ม Telegram', 'Telegram group', $v('telegram_group')],
        ['Facebook', 'Facebook', $v('facebook')],
        ['GitHub', 'GitHub', $v('github')],
        ['ทีมงาน', 'Team', $v('team_description')],
        ['แผนการใช้เงิน', 'Use of funds', $v('use_of_funds')],
        ['แหล่งที่มาของเงินทุน', 'Source of funds', ($fundSources[$form['source_of_funds'] ?? ''] ?? '—').(filled($form['source_of_funds_note'] ?? null) ? ' — '.$form['source_of_funds_note'] : '')],
        ['โครงการที่เคยทำ', 'Prior projects', $v('prior_projects')],
    ];

    $ok = fn (bool $b) => $b ? 'ผ่าน / Valid' : 'ไม่ผ่าน / Invalid';
@endphp
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>{{ $document['title_en'] }} — {{ $r->uuid }}</title>
<style>
    @font-face { font-family:'Sarabun'; font-style:normal; font-weight:400; src:url('{{ $fontDir }}/Sarabun-Regular.ttf') format('truetype'); }
    @font-face { font-family:'Sarabun'; font-style:normal; font-weight:700; src:url('{{ $fontDir }}/Sarabun-Bold.ttf') format('truetype'); }
    @page { margin: 22mm 16mm 20mm 16mm; }
    body { font-family:'Sarabun','DejaVu Sans',sans-serif; color:#1e293b; font-size:10pt; line-height:1.5; }
    h1 { font-size:15pt; margin:0 0 2px 0; color:#0f172a; }
    h2 { font-size:12pt; margin:16px 0 6px 0; padding-bottom:3px; border-bottom:1.5px solid #0ea5e9; color:#0f172a; }
    h3 { font-size:10.5pt; margin:10px 0 3px 0; color:#0f172a; }
    .en { color:#64748b; font-size:9pt; }
    .muted { color:#64748b; }
    .box { border:1px solid #cbd5e1; border-radius:4px; padding:8px 10px; margin:8px 0; }
    .warn { border-color:#f59e0b; background:#fffbeb; }
    .ok { color:#047857; font-weight:700; }
    .bad { color:#b91c1c; font-weight:700; }
    table.kv { width:100%; border-collapse:collapse; margin:4px 0; }
    table.kv td { border-bottom:1px solid #e2e8f0; padding:4px 6px; vertical-align:top; }
    table.kv td.k { width:34%; background:#f8fafc; }
    pre { font-family:'DejaVu Sans Mono',monospace; font-size:7.5pt; white-space:pre-wrap; background:#f1f5f9; padding:6px; border-radius:3px; margin:3px 0; }
    .clause { margin:0 0 5px 0; }
    .watermark { position:fixed; top:40%; left:8%; font-size:48pt; color:rgba(220,38,38,0.12); transform:rotate(-30deg); font-weight:700; }
    .footer { position:fixed; bottom:-12mm; left:0; right:0; font-size:7.5pt; color:#94a3b8; text-align:center; }
</style>
</head>
<body>
@unless ($signed)
    <div class="watermark">ร่าง — ยังไม่ได้ลงนาม</div>
@endunless
<div class="footer">TPIX TRADE · tpix.online · {{ $r->uuid }} · {{ $document['version'] }} · SHA-256 {{ substr($agreementHash, 0, 16) }}…</div>

<h1>{{ $document['title_th'] }}</h1>
<div class="en">{{ $document['title_en'] }}</div>

<table class="kv" style="margin-top:8px">
    <tr><td class="k">เลขที่ใบลงทะเบียน <span class="en">/ Registration ID</span></td><td>{{ $r->uuid }}</td></tr>
    <tr><td class="k">สถานะ <span class="en">/ Status</span></td><td>{{ $statusLabel[0] }} <span class="en">/ {{ $statusLabel[1] }}</span></td></tr>
    <tr><td class="k">กระเป๋าที่ลงทะเบียน (กระเป๋ารับเหรียญ) <span class="en">/ Registered wallet</span></td><td>{{ $r->wallet_address }}</td></tr>
    <tr><td class="k">ฉบับข้อตกลง <span class="en">/ Agreement version</span></td><td>{{ $document['version'] }}</td></tr>
    <tr><td class="k">ออกเอกสารเมื่อ <span class="en">/ Generated at</span></td><td>{{ $generatedAt->format('d/m/Y H:i:s') }} (UTC+7)</td></tr>
</table>

<div class="box warn">
    <strong>{{ $document['draft_notice_th'] }}</strong><br>
    <span class="en">{{ $document['draft_notice_en'] }}</span>
</div>

<h2>ส่วนที่ 1 ข้อมูลผู้สร้าง <span class="en">/ Part 1 Creator details</span></h2>
<table class="kv">
    @foreach ($applicant as [$th, $en, $value])
        <tr><td class="k">{{ $th }} <span class="en">/ {{ $en }}</span></td><td>{{ $value }}</td></tr>
    @endforeach
</table>

<h2>ส่วนที่ 2 ข้อมูลโครงการ <span class="en">/ Part 2 Project details</span></h2>
<table class="kv">
    @foreach ($project as [$th, $en, $value])
        <tr><td class="k">{{ $th }} <span class="en">/ {{ $en }}</span></td><td>{!! nl2br(e($value)) !!}</td></tr>
    @endforeach
</table>

<h2>ส่วนที่ 3 คำรับรอง <span class="en">/ Part 3 Declarations</span></h2>
<table class="kv">
    <tr><td class="k">บุคคลที่มีสถานภาพทางการเมือง (PEP) <span class="en">/ Politically exposed person</span></td>
        <td>{{ ($form['is_pep'] ?? false) ? 'ใช่ / Yes — '.($form['pep_details'] ?? '') : 'ไม่ใช่ / No' }}</td></tr>
    <tr><td class="k">ไม่อยู่ในรายชื่อคว่ำบาตร <span class="en">/ Not on sanctions lists</span></td>
        <td>{{ ($form['declare_not_sanctioned'] ?? false) ? 'รับรอง / Declared' : '—' }}</td></tr>
    <tr><td class="k">ข้อมูลเป็นความจริง <span class="en">/ Information is true</span></td>
        <td>{{ ($form['declare_true'] ?? false) ? 'รับรอง / Declared' : '—' }}</td></tr>
</table>

<h2>ส่วนที่ 4 ข้อตกลงและข้อกำหนด <span class="en">/ Part 4 Agreement and terms</span></h2>
<p class="clause">{{ $document['preamble_th'] }}</p>
<p class="clause en">{{ $document['preamble_en'] }}</p>
@foreach ($document['sections'] as $i => $section)
    <h3>{{ $i + 1 }}. {{ $section['title_th'] }} <span class="en">/ {{ $section['title_en'] }}</span></h3>
    @foreach ($section['clauses'] as $j => $clause)
        <p class="clause">{{ $i + 1 }}.{{ $j + 1 }} {{ $clause['th'] }}</p>
        <p class="clause en">{{ $i + 1 }}.{{ $j + 1 }} {{ $clause['en'] }}</p>
    @endforeach
@endforeach

<h2>ส่วนที่ 5 หลักฐานการลงลายมือชื่ออิเล็กทรอนิกส์ <span class="en">/ Part 5 Electronic signature evidence</span></h2>
@if ($signed)
    <table class="kv">
        <tr><td class="k">ลงนามด้วยกระเป๋า <span class="en">/ Signed by wallet</span></td><td>{{ $r->wallet_address }}</td></tr>
        <tr><td class="k">เวลาลงนาม <span class="en">/ Signed at</span></td><td>{{ $bkk($r->agreement_signed_at) }}<br><span class="en">{{ $utc($r->agreement_signed_at) }}</span></td></tr>
        <tr><td class="k">หมายเลข IP <span class="en">/ IP address</span></td><td>{{ $r->agreement_ip ?? '—' }}</td></tr>
        <tr><td class="k">SHA-256 ของข้อตกลง <span class="en">/ Agreement hash</span></td><td style="font-size:8pt">{{ $r->agreement_hash }}</td></tr>
        <tr><td class="k">SHA-256 ของข้อมูลใบลงทะเบียน <span class="en">/ Application data hash</span></td><td style="font-size:8pt">{{ $r->form_hash }}</td></tr>
        <tr><td class="k">ผลตรวจลายเซ็นข้อตกลง <span class="en">/ Agreement signature check</span></td>
            <td class="{{ $evidence['agreement_signature_valid'] ? 'ok' : 'bad' }}">{{ $ok($evidence['agreement_signature_valid']) }}</td></tr>
        <tr><td class="k">ข้อตกลงตรงกับฉบับที่เผยแพร่ <span class="en">/ Agreement hash matches published version</span></td>
            <td class="{{ $evidence['agreement_hash_matches'] ? 'ok' : 'bad' }}">{{ $ok($evidence['agreement_hash_matches']) }}</td></tr>
        <tr><td class="k">ข้อมูลตรงกับที่ลงนาม <span class="en">/ Application data matches signed hash</span></td>
            <td class="{{ $evidence['form_hash_matches'] ? 'ok' : 'bad' }}">{{ $ok($evidence['form_hash_matches']) }}</td></tr>
    </table>
    <h3>ข้อความที่ลงนาม (EIP-191 personal_sign) <span class="en">/ Signed message</span></h3>
    <pre>{{ $r->agreement_message }}</pre>
    <h3>ลายเซ็น <span class="en">/ Signature</span></h3>
    <pre>{{ $wrap($r->agreement_signature) }}</pre>
@else
    <div class="box warn">ยังไม่ได้ลงนามข้อตกลง — เอกสารนี้เป็นร่างเพื่อตรวจทานเท่านั้น<br>
        <span class="en">The agreement has not been signed — this document is a draft for review only.</span></div>
@endif

<h3>การยืนยันความเป็นเจ้าของกระเป๋า <span class="en">/ Wallet ownership proof</span></h3>
<table class="kv">
    <tr><td class="k">เวลาลงนาม <span class="en">/ Signed at</span></td><td>{{ $bkk($r->ownership_signed_at) }}</td></tr>
    <tr><td class="k">ผลตรวจ <span class="en">/ Check</span></td>
        <td class="{{ $evidence['ownership_signature_valid'] ? 'ok' : 'bad' }}">{{ $ok($evidence['ownership_signature_valid']) }}</td></tr>
</table>
<p class="muted" style="font-size:8.5pt">
    วิธีตรวจด้วยตนเอง: นำข้อความที่ลงนามและลายเซ็นข้างต้นไปกู้คืนที่อยู่ผู้ลงนามด้วย ecrecover (เช่น ethers.verifyMessage) ผลต้องตรงกับกระเป๋าที่ลงทะเบียน
    และคำนวณ SHA-256 ของไฟล์ข้อตกลงฉบับ {{ $document['version'] }} ที่เผยแพร่ ผลต้องตรงกับค่าแฮชในข้อความ<br>
    <span class="en">To verify independently: recover the signer of the message and signature above with ecrecover (e.g. ethers.verifyMessage); it must equal the registered wallet. The SHA-256 of the published agreement version {{ $document['version'] }} must equal the hash in the message.</span>
</p>

<h2>ส่วนที่ 6 ผลการพิจารณา <span class="en">/ Part 6 Review result</span></h2>
<table class="kv">
    <tr><td class="k">สถานะ <span class="en">/ Status</span></td><td>{{ $statusLabel[0] }} <span class="en">/ {{ $statusLabel[1] }}</span></td></tr>
    <tr><td class="k">พิจารณาเมื่อ <span class="en">/ Reviewed at</span></td><td>{{ $bkk($r->reviewed_at) }}</td></tr>
    @if ($r->status === \App\Models\CreatorRegistration::STATUS_APPROVED)
        <tr><td class="k">ส่วนเริ่มโครงการ <span class="en">/ Starter allocation</span></td>
            <td>{{ rtrim(rtrim(number_format(($r->starter_allocation_bps ?? 0) / 100, 2), '0'), '.') }}% ของซัพพลายเหรียญลูก — ผลิตเข้ากระเป๋าที่ลงทะเบียนตอนสร้างเหรียญ<br>
                <span class="en">of the Child Token supply — minted to the registered wallet at token creation</span></td></tr>
    @endif
    @if ($r->status === \App\Models\CreatorRegistration::STATUS_REJECTED && filled($r->rejection_reason))
        <tr><td class="k">เหตุผลที่ไม่อนุมัติ <span class="en">/ Reason</span></td><td>{!! nl2br(e($r->rejection_reason)) !!}</td></tr>
    @endif
</table>
</body>
</html>
