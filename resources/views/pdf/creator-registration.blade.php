@php
    /**
     * TPIX TRADE — PDF ใบลงทะเบียนและข้อตกลงผู้สร้างเหรียญ (DomPDF)
     *
     * หน้าปก: คู่สัญญา (ชื่อ ที่อยู่ นิติบุคคล) · ส่วนข้อมูลแสดงเฉพาะช่องที่กรอก (บุคคลธรรมดาไม่มีบล็อกบริษัท ไม่มีช่องว่าง)
     * ไทยเป็นหลัก อังกฤษกำกับ · ข้อตกลงพิมพ์ตามฉบับที่ผู้สร้างเซ็นจริง · พื้นขาว สีแบรนด์ cyan/purple
     * Developed by Xman Studio
     */

    $r = $registration;
    $signed = $r->isSigned();
    $bkk = fn ($dt) => $dt ? $dt->copy()->timezone('Asia/Bangkok')->format('d/m/Y H:i:s').' (UTC+7)' : null;
    $utc = fn ($dt) => $dt ? $dt->copy()->utc()->format('Y-m-d H:i:s').' UTC' : '';
    $wrap = fn (?string $s, int $n = 66) => $s ? trim(chunk_split($s, $n, "\n")) : '';
    $f = fn (string $key) => filled($form[$key] ?? null) ? (string) $form[$key] : null;
    // เก็บเฉพาะแถวที่มีค่า — เอกสารต้องไม่มีช่องว่าง
    $rows = fn (array $list) => array_values(array_filter($list, fn ($row) => filled($row[2])));

    $statusLabel = [
        \App\Models\CreatorRegistration::STATUS_DRAFT => ['ร่าง — ยังไม่ได้ส่ง', 'Draft — not submitted', '#64748b'],
        \App\Models\CreatorRegistration::STATUS_SUBMITTED => ['ลงนามแล้ว — รอแอดมินตรวจ', 'Signed — awaiting review', '#0891b2'],
        \App\Models\CreatorRegistration::STATUS_APPROVED => ['อนุมัติแล้ว', 'Approved', '#047857'],
        \App\Models\CreatorRegistration::STATUS_REJECTED => ['ไม่อนุมัติ', 'Rejected', '#b91c1c'],
    ][$r->status] ?? [$r->status, $r->status, '#64748b'];

    $categories = [
        'utility' => 'ยูทิลิตี้ / Utility', 'community' => 'ชุมชน / Community', 'gaming' => 'เกม / Gaming',
        'defi' => 'DeFi', 'rwa' => 'สินทรัพย์จริง / Real-world asset', 'payment' => 'การชำระเงิน / Payment', 'other' => 'อื่นๆ / Other',
    ];
    $fundSources = [
        'own_funds' => 'เงินทุนส่วนตัว / Own funds', 'business_revenue' => 'รายได้ธุรกิจ / Business revenue',
        'investors' => 'นักลงทุน / Investors', 'grants' => 'ทุนสนับสนุน / Grants', 'other' => 'อื่นๆ / Other',
    ];
    $docTypes = [
        \App\Models\CreatorRegistrationDocument::TYPE_COMPANY_CERTIFICATE => ['หนังสือรับรองบริษัท', 'Company certificate'],
        \App\Models\CreatorRegistrationDocument::TYPE_VAT_CERTIFICATE => ['ใบทะเบียนภาษีมูลค่าเพิ่ม (ภ.พ.20)', 'VAT registration (Por Por 20)'],
        \App\Models\CreatorRegistrationDocument::TYPE_SHAREHOLDER_LIST => ['บัญชีรายชื่อผู้ถือหุ้น (บอจ.5)', 'Shareholder list'],
        \App\Models\CreatorRegistrationDocument::TYPE_COMPANY_OTHER => ['เอกสารบริษัทอื่น', 'Other company document'],
        \App\Models\CreatorRegistrationDocument::TYPE_PROJECT_PHOTO => ['รูปภาพโครงการ', 'Project photo'],
        \App\Models\CreatorRegistrationDocument::TYPE_SIGNATORY_ID => ['บัตรผู้มีอำนาจลงนาม (แอดมินเท่านั้น)', 'Signatory ID (admin only)'],
    ];

    $isCompany = ($form['applicant_type'] ?? null) === \App\Models\CreatorRegistration::TYPE_COMPANY;
    $creatorName = $isCompany ? ($f('company_name') ?? '') : ($f('full_name') ?? '');
    $creatorNameEn = $isCompany ? $f('company_name_en') : $f('full_name_en');

    $applicant = $rows($isCompany
        ? [
            ['ประเภทผู้สมัคร', 'Applicant type', 'นิติบุคคล / Company'],
            ['ชื่อนิติบุคคล', 'Company name', $f('company_name')],
            ['ชื่อนิติบุคคล (อังกฤษ)', 'Company name (English)', $f('company_name_en')],
            ['เลขทะเบียนนิติบุคคล', 'Registration number', $f('registration_number')],
            ['ประเทศที่จดทะเบียน', 'Country of registration', $f('registration_country')],
            ['วันที่จดทะเบียน', 'Incorporation date', $f('incorporation_date')],
        ]
        : [
            ['ประเภทผู้สมัคร', 'Applicant type', 'บุคคลธรรมดา / Individual'],
            ['ชื่อ-นามสกุล', 'Full name', $f('full_name')],
            ['ชื่อ-นามสกุล (อังกฤษ)', 'Full name (English)', $f('full_name_en')],
            ['วันเกิด', 'Date of birth', $f('date_of_birth')],
            ['สัญชาติ', 'Nationality', $f('nationality')],
            ['อาชีพ', 'Occupation', $f('occupation')],
        ]);
    $applicant = array_merge($applicant, $rows([
        ['ผู้มีอำนาจลงนาม', 'Authorised signatory', $f('signatory_name')],
        ['ตำแหน่ง', 'Position', $f('signatory_position')],
        ['ที่อยู่', 'Address', $f('address')],
        ['ประเทศที่พำนัก', 'Country of residence', $f('country')],
        ['อีเมล', 'Email', $f('email')],
        ['เบอร์โทรศัพท์', 'Phone', $f('phone')],
        ['Telegram', 'Telegram', $f('telegram')],
        ['LINE ID', 'LINE ID', $f('line_id')],
    ]));

    $project = $rows([
        ['ชื่อโครงการ', 'Project name', $f('project_name')],
        ['ชื่อเหรียญ', 'Token name', $f('token_name')],
        ['สัญลักษณ์', 'Symbol', $f('token_symbol')],
        ['ซัพพลายที่วางแผน', 'Planned supply', filled($form['planned_supply'] ?? null) ? number_format((float) $form['planned_supply'], 0) : null],
        ['หมวดหมู่', 'Category', $categories[$form['category'] ?? ''] ?? null],
        ['รายละเอียด', 'Description', $f('description')],
        ['เว็บไซต์', 'Website', $f('website')],
        ['Whitepaper', 'Whitepaper', $f('whitepaper_url')],
        ['X (Twitter)', 'X (Twitter)', $f('twitter')],
        ['กลุ่ม Telegram', 'Telegram group', $f('telegram_group')],
        ['Facebook', 'Facebook', $f('facebook')],
        ['GitHub', 'GitHub', $f('github')],
        ['ทีมงาน', 'Team', $f('team_description')],
        ['แผนการใช้เงิน', 'Use of funds', $f('use_of_funds')],
        ['แหล่งที่มาของเงินทุน', 'Source of funds', isset($fundSources[$form['source_of_funds'] ?? '']) ? $fundSources[$form['source_of_funds']].(filled($form['source_of_funds_note'] ?? null) ? ' — '.$form['source_of_funds_note'] : '') : null],
        ['โครงการที่เคยทำ', 'Prior projects', $f('prior_projects')],
    ]);

    $yes = fn ($v) => $v ? 'ใช่ / Yes' : 'ไม่ใช่ / No';
    $agree = fn ($v) => $v ? 'ยินยอม / Consented' : 'ไม่ยินยอม / Not consented';
    $declarations = $rows([
        ['บุคคลที่มีสถานภาพทางการเมือง (PEP)', 'Politically exposed person', ($form['is_pep'] ?? false) ? 'ใช่ / Yes — '.($form['pep_details'] ?? '') : 'ไม่ใช่ / No'],
        ['ไม่อยู่ในรายชื่อคว่ำบาตร', 'Not on sanctions lists', ($form['declare_not_sanctioned'] ?? false) ? 'รับรอง / Declared' : null],
        ['ข้อมูลเป็นความจริง', 'Information is true', ($form['declare_true'] ?? false) ? 'รับรอง / Declared' : null],
        ['เผยแพร่ข้อมูลโครงการและนิติบุคคล', 'Publish project and company details', array_key_exists('consent_publish_project', $form) ? $agree($form['consent_publish_project']) : null],
        ['เผยแพร่ชื่อผู้สร้าง/ผู้ลงนาม', 'Publish creator / signatory name', array_key_exists('consent_publish_name', $form) ? $agree($form['consent_publish_name']) : null],
    ]);

    $documents = $registration->documents;
    $ok = fn (bool $b) => $b ? 'ผ่าน / Valid' : 'ไม่ผ่าน / Invalid';
    $part = 0;
@endphp
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>{{ $document['title_en'] }} — {{ $r->uuid }}</title>
<style>
    @font-face { font-family:'Sarabun'; font-style:normal; font-weight:400; src:url('{{ $fontDir }}/Sarabun-Regular.ttf') format('truetype'); }
    @font-face { font-family:'Sarabun'; font-style:normal; font-weight:700; src:url('{{ $fontDir }}/Sarabun-Bold.ttf') format('truetype'); }
    @page { margin: 20mm 16mm 18mm 16mm; }
    body { font-family:'Sarabun','DejaVu Sans',sans-serif; color:#1e293b; font-size:10pt; line-height:1.5; background:#ffffff; }
    .brand { color:#0891b2; font-weight:700; letter-spacing:1px; }
    .cover { text-align:center; padding-top:18mm; }
    .cover .logo { font-size:24pt; color:#0891b2; font-weight:700; letter-spacing:3px; }
    .cover .bar { height:4px; background:#0891b2; border-right:90px solid #7c3aed; width:180px; margin:10px auto 22px auto; }
    .cover h1 { font-size:19pt; margin:0; color:#0f172a; }
    .cover .sub { font-size:11pt; color:#64748b; margin-top:2px; }
    .cover .project { font-size:15pt; margin-top:20px; color:#0f172a; font-weight:700; }
    .cover .symbol { display:inline-block; font-size:10pt; color:#7c3aed; border:1px solid #c4b5fd; border-radius:10px; padding:1px 10px; margin-top:4px; }
    .badge { display:inline-block; color:#ffffff; border-radius:10px; padding:2px 12px; font-size:9.5pt; font-weight:700; }
    .parties { width:100%; border-collapse:separate; border-spacing:8px 0; margin-top:22px; text-align:left; }
    .parties td { width:50%; vertical-align:top; border:1px solid #cbd5e1; border-top:3px solid #0891b2; border-radius:4px; padding:9px 11px; background:#f8fafc; }
    .parties td.creator { border-top-color:#7c3aed; }
    .parties .role { font-size:8.5pt; color:#64748b; text-transform:uppercase; letter-spacing:1px; }
    .parties .name { font-size:12pt; font-weight:700; color:#0f172a; margin:2px 0; }
    h2 { font-size:12pt; margin:16px 0 6px 0; padding:4px 8px; background:#ecfeff; border-left:4px solid #0891b2; color:#0f172a; }
    h3 { font-size:10.5pt; margin:10px 0 3px 0; color:#0e7490; }
    .en { color:#64748b; font-size:9pt; }
    .muted { color:#64748b; }
    .box { border:1px solid #cbd5e1; border-radius:4px; padding:8px 10px; margin:8px 0; }
    .warn { border-color:#f59e0b; background:#fffbeb; }
    .ok { color:#047857; font-weight:700; }
    .bad { color:#b91c1c; font-weight:700; }
    table.kv { width:100%; border-collapse:collapse; margin:4px 0; }
    table.kv td { border-bottom:1px solid #e2e8f0; padding:4px 6px; vertical-align:top; }
    table.kv td.k { width:34%; background:#f8fafc; }
    table.grid { width:100%; border-collapse:collapse; margin:4px 0; font-size:9pt; }
    table.grid th { background:#0891b2; color:#ffffff; text-align:left; padding:4px 6px; }
    table.grid td { border-bottom:1px solid #e2e8f0; padding:4px 6px; vertical-align:top; }
    .mono { font-family:'DejaVu Sans Mono',monospace; font-size:7pt; word-wrap:break-word; }
    pre { font-family:'DejaVu Sans Mono',monospace; font-size:7.5pt; white-space:pre-wrap; background:#f1f5f9; padding:6px; border-radius:3px; margin:3px 0; }
    .clause { margin:0 0 5px 0; }
    .watermark { position:fixed; top:40%; left:8%; font-size:48pt; color:rgba(220,38,38,0.12); transform:rotate(-30deg); font-weight:700; }
    .footer { position:fixed; bottom:-11mm; left:0; right:0; font-size:7.5pt; color:#94a3b8; text-align:center; border-top:1px solid #e2e8f0; padding-top:2px; }
    .page-break { page-break-after:always; }
</style>
</head>
<body>
@unless ($signed)
    <div class="watermark">ร่าง — ยังไม่ได้ลงนาม</div>
@endunless
<div class="footer">TPIX TRADE · tpix.online · {{ $r->uuid }} · {{ $document['version'] }} · SHA-256 {{ substr($agreementHash, 0, 16) }}…</div>

{{-- ===================== หน้าปก ===================== --}}
<div class="cover">
    <div class="logo">TPIX TRADE</div>
    <div class="bar"></div>
    <h1>{{ $document['title_th'] }}</h1>
    <div class="sub">{{ $document['title_en'] }}</div>

    @if (filled($f('project_name')))
        <div class="project">{{ $f('project_name') }}</div>
        @if (filled($f('token_symbol')))<div class="symbol">{{ $f('token_symbol') }}</div>@endif
    @endif

    <div style="margin-top:14px"><span class="badge" style="background:{{ $statusLabel[2] }}">{{ $statusLabel[0] }} · {{ $statusLabel[1] }}</span></div>

    <table class="parties">
        <tr>
            <td>
                <div class="role">ผู้ให้บริการ / Provider</div>
                <div class="name">TPIX TRADE</div>
                <div>พัฒนาและดำเนินการโดย Xman Studio</div>
                <div class="en">Developed and operated by Xman Studio</div>
                <div class="muted">tpix.online</div>
            </td>
            <td class="creator">
                <div class="role">ผู้สร้าง / Creator — {{ $isCompany ? 'นิติบุคคล / Company' : 'บุคคลธรรมดา / Individual' }}</div>
                @if (filled($creatorName))<div class="name">{{ $creatorName }}</div>@endif
                @if (filled($creatorNameEn))<div class="en">{{ $creatorNameEn }}</div>@endif
                @if ($isCompany && filled($f('registration_number')))
                    <div>เลขทะเบียน <span class="en">/ Reg. no.</span> {{ $f('registration_number') }}{{ filled($f('registration_country')) ? ' ('.$f('registration_country').')' : '' }}</div>
                @endif
                @if ($isCompany && filled($f('signatory_name')))
                    <div>ผู้มีอำนาจลงนาม <span class="en">/ Signatory</span>: {{ $f('signatory_name') }}{{ filled($f('signatory_position')) ? ' — '.$f('signatory_position') : '' }}</div>
                @endif
                @if (filled($f('address')))
                    <div style="margin-top:3px">{!! nl2br(e($f('address'))) !!}{{ filled($f('country')) ? ' · '.$f('country') : '' }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="kv" style="margin-top:18px; text-align:left">
        <tr><td class="k">เลขที่ใบลงทะเบียน <span class="en">/ Registration ID</span></td><td>{{ $r->uuid }}</td></tr>
        <tr><td class="k">กระเป๋าที่ลงทะเบียน <span class="en">/ Registered wallet</span></td><td>{{ $r->wallet_address }}</td></tr>
        <tr><td class="k">ฉบับข้อตกลง <span class="en">/ Agreement version</span></td><td>{{ $document['version'] }}</td></tr>
        @if ($signed)
            <tr><td class="k">ลงนามเมื่อ <span class="en">/ Signed at</span></td><td>{{ $bkk($r->agreement_signed_at) }}</td></tr>
        @endif
        <tr><td class="k">ออกเอกสารเมื่อ <span class="en">/ Generated at</span></td><td>{{ $generatedAt->format('d/m/Y H:i:s') }} (UTC+7)</td></tr>
    </table>
</div>
<div class="page-break"></div>

<div class="box warn">
    <strong>{{ $document['draft_notice_th'] }}</strong><br>
    <span class="en">{{ $document['draft_notice_en'] }}</span>
</div>

<h2>ส่วนที่ {{ ++$part }} ข้อมูลผู้สร้าง <span class="en">/ Part {{ $part }} Creator details</span></h2>
<table class="kv">
    @foreach ($applicant as [$th, $en, $value])
        <tr><td class="k">{{ $th }} <span class="en">/ {{ $en }}</span></td><td>{!! nl2br(e($value)) !!}</td></tr>
    @endforeach
</table>

<h2>ส่วนที่ {{ ++$part }} ข้อมูลโครงการ <span class="en">/ Part {{ $part }} Project details</span></h2>
<table class="kv">
    @foreach ($project as [$th, $en, $value])
        <tr><td class="k">{{ $th }} <span class="en">/ {{ $en }}</span></td><td>{!! nl2br(e($value)) !!}</td></tr>
    @endforeach
</table>

<h2>ส่วนที่ {{ ++$part }} คำรับรองและความยินยอม <span class="en">/ Part {{ $part }} Declarations and consents</span></h2>
<table class="kv">
    @foreach ($declarations as [$th, $en, $value])
        <tr><td class="k">{{ $th }} <span class="en">/ {{ $en }}</span></td><td>{{ $value }}</td></tr>
    @endforeach
</table>

@if ($documents->isNotEmpty())
    <h2>ส่วนที่ {{ ++$part }} เอกสารประกอบ <span class="en">/ Part {{ $part }} Supporting documents</span></h2>
    <table class="grid">
        <tr><th>ประเภท / Type</th><th>เผยแพร่ / Public</th><th>ขนาด / Size</th><th>SHA-256</th></tr>
        @foreach ($documents as $doc)
            <tr>
                <td>{{ $docTypes[$doc->type][0] ?? $doc->type }}<br><span class="en">{{ $docTypes[$doc->type][1] ?? $doc->type }}</span></td>
                <td>{{ $doc->isPubliclyViewable() ? 'เผยแพร่ (ภาพลายน้ำ) / Public (watermarked)' : 'ไม่เผยแพร่ / Not public' }}</td>
                <td>{{ number_format($doc->size / 1024, 0) }} KB</td>
                <td class="mono">{{ $doc->sha256 }}</td>
            </tr>
        @endforeach
    </table>
    <p class="muted" style="font-size:8.5pt">ค่าแฮชรายการเอกสาร <span class="en">/ Documents list hash</span>: <span class="mono">{{ $r->documents_hash }}</span></p>
@endif

<h2>ส่วนที่ {{ ++$part }} ข้อตกลงและข้อกำหนด <span class="en">/ Part {{ $part }} Agreement and terms</span></h2>
<p class="clause">{{ $document['preamble_th'] }}</p>
<p class="clause en">{{ $document['preamble_en'] }}</p>
@foreach ($document['sections'] as $i => $section)
    <h3>{{ $i + 1 }}. {{ $section['title_th'] }} <span class="en">/ {{ $section['title_en'] }}</span></h3>
    @foreach ($section['clauses'] as $j => $clause)
        <p class="clause">{{ $i + 1 }}.{{ $j + 1 }} {{ $clause['th'] }}</p>
        <p class="clause en">{{ $i + 1 }}.{{ $j + 1 }} {{ $clause['en'] }}</p>
    @endforeach
@endforeach

<h2>ส่วนที่ {{ ++$part }} หลักฐานการลงลายมือชื่ออิเล็กทรอนิกส์ <span class="en">/ Part {{ $part }} Electronic signature evidence</span></h2>
@if ($signed)
    <table class="kv">
        <tr><td class="k">ลงนามด้วยกระเป๋า <span class="en">/ Signed by wallet</span></td><td>{{ $r->wallet_address }}</td></tr>
        <tr><td class="k">เวลาลงนาม <span class="en">/ Signed at</span></td><td>{{ $bkk($r->agreement_signed_at) }}<br><span class="en">{{ $utc($r->agreement_signed_at) }}</span></td></tr>
        @if (filled($r->agreement_ip))
            <tr><td class="k">หมายเลข IP <span class="en">/ IP address</span></td><td>{{ $r->agreement_ip }}</td></tr>
        @endif
        @if (filled($r->agreement_user_agent))
            <tr><td class="k">โปรแกรมที่ใช้ <span class="en">/ User agent</span></td><td style="font-size:8pt">{{ $r->agreement_user_agent }}</td></tr>
        @endif
        <tr><td class="k">SHA-256 ของข้อตกลง <span class="en">/ Agreement hash</span></td><td class="mono">{{ $r->agreement_hash }}</td></tr>
        <tr><td class="k">SHA-256 ของข้อมูลใบลงทะเบียน <span class="en">/ Application data hash</span></td><td class="mono">{{ $r->form_hash }}</td></tr>
        @if (filled($r->documents_hash))
            <tr><td class="k">SHA-256 ของรายการเอกสาร <span class="en">/ Documents list hash</span></td><td class="mono">{{ $r->documents_hash }}</td></tr>
        @endif
        <tr><td class="k">ผลตรวจลายเซ็นข้อตกลง <span class="en">/ Agreement signature check</span></td>
            <td class="{{ $evidence['agreement_signature_valid'] ? 'ok' : 'bad' }}">{{ $ok($evidence['agreement_signature_valid']) }}</td></tr>
        <tr><td class="k">ข้อตกลงตรงกับฉบับที่เผยแพร่ <span class="en">/ Agreement hash matches published version</span></td>
            <td class="{{ $evidence['agreement_hash_matches'] ? 'ok' : 'bad' }}">{{ $ok($evidence['agreement_hash_matches']) }}</td></tr>
        <tr><td class="k">ข้อมูลตรงกับที่ลงนาม <span class="en">/ Application data matches signed hash</span></td>
            <td class="{{ $evidence['form_hash_matches'] ? 'ok' : 'bad' }}">{{ $ok($evidence['form_hash_matches']) }}</td></tr>
        <tr><td class="k">เอกสารตรงกับที่ลงนาม <span class="en">/ Documents match signed hash</span></td>
            <td class="{{ $evidence['documents_hash_matches'] ? 'ok' : 'bad' }}">{{ $ok($evidence['documents_hash_matches']) }}</td></tr>
    </table>
    <h3>ข้อความที่ลงนาม (EIP-191 personal_sign) <span class="en">/ Signed message</span></h3>
    <pre>{{ $r->agreement_message }}</pre>
    <h3>ลายเซ็น <span class="en">/ Signature</span></h3>
    <pre>{{ $wrap($r->agreement_signature) }}</pre>
@else
    <div class="box warn">ยังไม่ได้ลงนามข้อตกลง — เอกสารนี้เป็นร่างเพื่อตรวจทานเท่านั้น<br>
        <span class="en">The agreement has not been signed — this document is a draft for review only.</span></div>
@endif

@if ($r->ownership_signed_at)
    <h3>การยืนยันความเป็นเจ้าของกระเป๋า <span class="en">/ Wallet ownership proof</span></h3>
    <table class="kv">
        <tr><td class="k">เวลาลงนาม <span class="en">/ Signed at</span></td><td>{{ $bkk($r->ownership_signed_at) }}</td></tr>
        <tr><td class="k">ผลตรวจ <span class="en">/ Check</span></td>
            <td class="{{ $evidence['ownership_signature_valid'] ? 'ok' : 'bad' }}">{{ $ok($evidence['ownership_signature_valid']) }}</td></tr>
    </table>
@endif
<p class="muted" style="font-size:8.5pt">
    วิธีตรวจด้วยตนเอง: นำข้อความที่ลงนามและลายเซ็นข้างต้นไปกู้คืนที่อยู่ผู้ลงนามด้วย ecrecover (เช่น ethers.verifyMessage) ผลต้องตรงกับกระเป๋าที่ลงทะเบียน
    และคำนวณ SHA-256 ของไฟล์ข้อตกลงฉบับ {{ $document['version'] }} ที่เผยแพร่ ผลต้องตรงกับค่าแฮชในข้อความ<br>
    <span class="en">To verify independently: recover the signer of the message and signature above with ecrecover (e.g. ethers.verifyMessage); it must equal the registered wallet. The SHA-256 of the published agreement version {{ $document['version'] }} must equal the hash in the message.</span>
</p>

@if (in_array($r->status, [\App\Models\CreatorRegistration::STATUS_APPROVED, \App\Models\CreatorRegistration::STATUS_REJECTED], true))
    <h2>ส่วนที่ {{ ++$part }} ผลการพิจารณา <span class="en">/ Part {{ $part }} Review result</span></h2>
    <table class="kv">
        <tr><td class="k">สถานะ <span class="en">/ Status</span></td><td>{{ $statusLabel[0] }} <span class="en">/ {{ $statusLabel[1] }}</span></td></tr>
        @if ($r->reviewed_at)
            <tr><td class="k">พิจารณาเมื่อ <span class="en">/ Reviewed at</span></td><td>{{ $bkk($r->reviewed_at) }}</td></tr>
        @endif
        @if ($r->status === \App\Models\CreatorRegistration::STATUS_APPROVED)
            <tr><td class="k">ส่วนเริ่มโครงการ <span class="en">/ Starter allocation</span></td>
                <td>{{ rtrim(rtrim(number_format(($r->starter_allocation_bps ?? 0) / 100, 2), '0'), '.') }}% ของซัพพลายเหรียญลูก — ผลิตเข้ากระเป๋าที่ลงทะเบียนตอนสร้างเหรียญ<br>
                    <span class="en">of the Child Token supply — minted to the registered wallet at token creation</span></td></tr>
        @endif
        @if ($r->status === \App\Models\CreatorRegistration::STATUS_REJECTED && filled($r->rejection_reason))
            <tr><td class="k">เหตุผลที่ไม่อนุมัติ <span class="en">/ Reason</span></td><td>{!! nl2br(e($r->rejection_reason)) !!}</td></tr>
        @endif
    </table>
@endif
</body>
</html>
