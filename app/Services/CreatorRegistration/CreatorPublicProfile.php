<?php

namespace App\Services\CreatorRegistration;

use App\Models\CreatorRegistration;
use App\Models\CreatorRegistrationDocument;
use App\Models\FactoryToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ข้อมูลผู้สร้าง/โครงการที่เปิดให้คนทั่วไปเห็น — หน้าโครงการสาธารณะและไวท์เปเปอร์มาตรฐานใช้ชุดเดียวกัน.
 *
 * เผยแพร่เฉพาะที่ผู้สร้างยินยอมทีละรายการ (PDPA):
 *   consent_publish_project  ข้อมูลโครงการ + ชื่อ/เลขทะเบียนนิติบุคคล (ไม่ยินยอม = ไม่มีหน้าสาธารณะเลย)
 *   consent_publish_name     ชื่อผู้สร้าง (บุคคลธรรมดา) / ชื่อผู้มีอำนาจลงนาม (นิติบุคคล)
 *   เอกสารแต่ละไฟล์          publish = true และเป็นประเภทสาธารณะที่เป็นภาพ
 * ไม่มีวันเผยแพร่: อีเมล เบอร์ ที่อยู่ วันเกิด สัญชาติ PEP แหล่งเงินทุน บัตรผู้มีอำนาจลงนาม
 *
 * Developed by Xman Studio.
 */
class CreatorPublicProfile
{
    public const LINK_FIELDS = ['website', 'whitepaper_url', 'twitter', 'telegram_group', 'facebook', 'github'];

    public function __construct(private readonly CreatorRegistrationService $registrations) {}

    public function isPublic(?CreatorRegistration $registration): bool
    {
        return $registration !== null
            && $registration->isApproved()
            && (($registration->form_data['consent_publish_project'] ?? false) === true);
    }

    /** ใบที่อนุมัติแล้วของกระเป๋านี้ ถ้ายอมเผยแพร่ */
    public function forWallet(?string $wallet): ?CreatorRegistration
    {
        if (blank($wallet)) {
            return null;
        }

        $registration = CreatorRegistration::where('wallet_address', strtolower((string) $wallet))->first();

        return $this->isPublic($registration) ? $registration : null;
    }

    public function build(CreatorRegistration $registration): array
    {
        $form = (array) $registration->form_data;
        $entity = $this->entity($registration);

        $links = [];
        foreach (self::LINK_FIELDS as $key) {
            // เฉพาะ https — ลิงก์ไปออกในหน้าเว็บและไวท์เปเปอร์ ห้ามมี javascript:/data: หลุดไป
            $url = $form[$key] ?? null;
            if (is_string($url) && preg_match('#^https://[^\s<>"\']+$#i', $url)) {
                $links[$key] = $url;
            }
        }

        $documents = $registration->documents
            ->filter(fn (CreatorRegistrationDocument $d) => $d->isPubliclyViewable())
            ->map(fn (CreatorRegistrationDocument $d) => [
                'uuid' => $d->uuid,
                'type' => $d->type,
                'url' => route('token-creator.projects.document', [$registration->uuid, $d->uuid]),
            ])
            ->values()
            ->all();

        $tokens = FactoryToken::query()
            ->deployed()
            ->byCreator($registration->wallet_address)
            ->whereNotNull('contract_address')
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->map(fn (FactoryToken $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'symbol' => $t->symbol,
                'contract_address' => $t->contract_address,
                'whitepaper_url' => route('token-factory.whitepaper', $t->id),
            ])
            ->all();

        return [
            'uuid' => $registration->uuid,
            'url' => route('token-creator.projects.show', $registration->uuid),
            'applicant_type' => $registration->applicant_type,
            'entity' => $entity,
            'project_name' => $form['project_name'] ?? $registration->project_name,
            'token_name' => $form['token_name'] ?? null,
            'token_symbol' => $form['token_symbol'] ?? $registration->token_symbol,
            'category' => $form['category'] ?? null,
            'description' => $form['description'] ?? null,
            'team_description' => $form['team_description'] ?? null,
            'use_of_funds' => $form['use_of_funds'] ?? null,
            'links' => $links,
            'wallet_address' => $registration->wallet_address,
            'starter_allocation_bps' => $registration->starter_allocation_bps,
            'kyc_verified' => $this->registrations->hasKyc($registration->user),
            'approved_at' => $registration->reviewed_at?->toIso8601String(),
            'agreement_version' => $registration->agreement_version,
            'agreement_hash' => $registration->agreement_hash,
            'documents' => $documents,
            'tokens' => $tokens,
        ];
    }

    /**
     * แถวสั้นสำหรับหน้ารวมโครงการ — ชื่อโครงการ ชื่อที่เผยแพร่ ประเภท วันที่ผ่านการตรวจ (ไม่มีรายละเอียด/เอกสาร/กระเป๋า).
     * เหรียญแนบทีหลังด้วย tokensForRegistrations() เพื่อไม่ยิงคิวรีทีละใบ.
     */
    public function summary(CreatorRegistration $registration): array
    {
        $form = (array) $registration->form_data;
        $entity = $this->entity($registration);

        $displayName = $registration->isCompany()
            ? ($entity['company_name'] ?? $entity['company_name_en'] ?? null)
            : ($entity['full_name'] ?? $entity['full_name_en'] ?? null);

        return [
            'uuid' => $registration->uuid,
            'url' => route('token-creator.projects.show', $registration->uuid),
            'applicant_type' => $registration->applicant_type,
            'display_name' => $displayName,
            'display_name_en' => $registration->isCompany() ? ($entity['company_name_en'] ?? null) : ($entity['full_name_en'] ?? null),
            'registration_number' => $entity['registration_number'] ?? null,
            'project_name' => $form['project_name'] ?? $registration->project_name,
            'token_symbol' => $form['token_symbol'] ?? $registration->token_symbol,
            'category' => $form['category'] ?? null,
            'approved_at' => $registration->reviewed_at?->toIso8601String(),
        ];
    }

    /**
     * ใบที่อนุมัติและยอมเผยแพร่ทั้งหมด (ใหม่สุดก่อน) — form_data เข้ารหัสอยู่ กรองความยินยอมใน SQL ไม่ได้ ต้องถอดทีละใบ.
     *
     * @return Collection<int, CreatorRegistration>
     */
    public function publishedRegistrations(): Collection
    {
        return CreatorRegistration::query()
            ->where('status', CreatorRegistration::STATUS_APPROVED)
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (CreatorRegistration $r) => $this->isPublic($r))
            ->values();
    }

    /**
     * เหรียญของแต่ละใบสำหรับหน้ารวม — เหรียญที่ deploy แล้วจากกระเป๋าของใบ จับคู่แบบไม่สนตัวพิมพ์ คิวรีเดียวทั้งหน้า.
     *
     * จุดเดียวที่หน้ารวมใช้หาเหรียญ: ใบที่ผูกกับเหรียญที่ deploy ไปแล้วด้วยกระเป๋าอื่น
     * (factory_token_id — งาน claim existing token) ต้องเพิ่มเหรียญนั้นที่นี่ ให้ตรงกับ build()['tokens'].
     *
     * @param  Collection<int, CreatorRegistration>  $registrations
     * @return array<int, array<int, array<string, mixed>>> registration id => เหรียญ (สูงสุด 20 ต่อใบ)
     */
    public function tokensForRegistrations(Collection $registrations): array
    {
        $wallets = $registrations
            ->map(fn (CreatorRegistration $r) => strtolower((string) $r->wallet_address))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($wallets === []) {
            return [];
        }

        $explorer = rtrim((string) config('blockchain.explorer_url', 'https://explorer.tpix.online'), '/');
        $byWallet = [];

        FactoryToken::query()
            ->deployed()
            ->whereNotNull('contract_address')
            ->whereIn(DB::raw('LOWER(creator_address)'), $wallets)
            ->orderBy('id')
            ->get(['id', 'name', 'symbol', 'contract_address', 'creator_address'])
            ->each(function (FactoryToken $t) use (&$byWallet, $explorer) {
                $key = strtolower((string) $t->creator_address);
                if (count($byWallet[$key] ?? []) >= 20) {
                    return;
                }
                $byWallet[$key][] = [
                    'id' => $t->id,
                    'name' => $t->name,
                    'symbol' => $t->symbol,
                    'contract_address' => $t->contract_address,
                    'whitepaper_url' => route('token-factory.whitepaper', $t->id),
                    'explorer_url' => $explorer.'/address/'.$t->contract_address,
                ];
            });

        return $registrations
            ->mapWithKeys(fn (CreatorRegistration $r) => [$r->id => $byWallet[strtolower((string) $r->wallet_address)] ?? []])
            ->all();
    }

    /** ชื่อผู้สร้าง/นิติบุคคลที่เผยแพร่ได้ — ชื่อบุคคลออกเฉพาะเมื่อติ๊ก consent_publish_name */
    private function entity(CreatorRegistration $registration): array
    {
        $form = (array) $registration->form_data;
        $showName = ($form['consent_publish_name'] ?? false) === true;

        return $registration->isCompany()
            ? array_filter([
                'company_name' => $form['company_name'] ?? null,
                'company_name_en' => $form['company_name_en'] ?? null,
                'registration_number' => $form['registration_number'] ?? null,
                'registration_country' => $form['registration_country'] ?? null,
                'signatory_name' => $showName ? ($form['signatory_name'] ?? null) : null,
                'signatory_position' => $showName ? ($form['signatory_position'] ?? null) : null,
            ], fn ($v) => filled($v))
            : array_filter([
                'full_name' => $showName ? ($form['full_name'] ?? null) : null,
                'full_name_en' => $showName ? ($form['full_name_en'] ?? null) : null,
            ], fn ($v) => filled($v));
    }
}
