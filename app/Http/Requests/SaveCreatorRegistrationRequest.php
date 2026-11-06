<?php

namespace App\Http\Requests;

use App\Models\CreatorRegistration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * แบบฟอร์มข้อมูลผู้สร้างเหรียญ — บุคคลธรรมดา/นิติบุคคล, ผู้ติดต่อ, โครงการ, คำรับรอง.
 *
 * ข้อความ error มาจาก lang/{th,en}/validation.php + ชื่อช่องใน attributes() ที่แปลผ่าน __()
 * จึงเป็นภาษาเดียวกับปุ่ม TH/EN ที่ผู้ใช้เลือก (คุกกี้ tpix_locale → SetLocale)
 *
 * Developed by Xman Studio.
 */
class SaveCreatorRegistrationRequest extends FormRequest
{
    public const CATEGORIES = ['utility', 'community', 'gaming', 'defi', 'rwa', 'payment', 'other'];

    public const FUND_SOURCES = ['own_funds', 'business_revenue', 'investors', 'grants', 'other'];

    /** ช่องเฉพาะบุคคลธรรมดา / นิติบุคคล — ตัดฝั่งที่ไม่ได้เลือกทิ้งก่อนบันทึก */
    public const INDIVIDUAL_FIELDS = ['full_name', 'full_name_en', 'date_of_birth', 'nationality', 'occupation'];

    public const COMPANY_FIELDS = ['company_name', 'company_name_en', 'registration_number', 'registration_country', 'incorporation_date'];

    public function authorize(): bool
    {
        return $this->user('web') !== null;
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        foreach ($this->all() as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }
            $data[$key] = $value;
        }

        foreach (['token_symbol', 'nationality', 'registration_country', 'country'] as $upper) {
            if (isset($data[$upper]) && is_string($data[$upper])) {
                $data[$upper] = strtoupper($data[$upper]);
            }
        }

        $this->replace($data);
    }

    public function rules(): array
    {
        $adultCutoff = now()->subYears(20)->toDateString();
        $httpsUrl = ['nullable', 'string', 'max:255', 'url:https'];

        return [
            'applicant_type' => ['required', Rule::in([CreatorRegistration::TYPE_INDIVIDUAL, CreatorRegistration::TYPE_COMPANY])],

            // บุคคลธรรมดา
            'full_name' => ['required_if:applicant_type,individual', 'nullable', 'string', 'max:150'],
            'full_name_en' => ['nullable', 'string', 'max:150'],
            'date_of_birth' => ['required_if:applicant_type,individual', 'nullable', 'date', 'before_or_equal:'.$adultCutoff],
            'nationality' => ['required_if:applicant_type,individual', 'nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'occupation' => ['nullable', 'string', 'max:100'],

            // นิติบุคคล
            'company_name' => ['required_if:applicant_type,company', 'nullable', 'string', 'max:200'],
            'company_name_en' => ['nullable', 'string', 'max:200'],
            'registration_number' => ['required_if:applicant_type,company', 'nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-\/ ]+$/'],
            'registration_country' => ['required_if:applicant_type,company', 'nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'incorporation_date' => ['nullable', 'date', 'before_or_equal:today'],

            // ผู้มีอำนาจลงนาม (บุคคลธรรมดา = ตัวเอง)
            'signatory_name' => ['required', 'string', 'max:150'],
            'signatory_position' => ['required', 'string', 'max:100'],

            // ผู้ติดต่อ
            'email' => ['required', 'string', 'max:150', 'email:rfc'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9][0-9 \-]{7,19}$/'],
            'address' => ['required', 'string', 'max:500'],
            'country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'telegram' => ['nullable', 'string', 'max:64'],
            'line_id' => ['nullable', 'string', 'max:64'],

            // โครงการ
            'project_name' => ['required', 'string', 'max:120'],
            'token_name' => ['required', 'string', 'max:64'],
            'token_symbol' => ['required', 'string', 'regex:/^[A-Z0-9]{2,11}$/'],
            'planned_supply' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,29}$/'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'description' => ['required', 'string', 'min:50', 'max:5000'],
            'website' => $httpsUrl,
            'whitepaper_url' => $httpsUrl,
            'twitter' => $httpsUrl,
            'telegram_group' => $httpsUrl,
            'facebook' => $httpsUrl,
            'github' => $httpsUrl,
            'team_description' => ['required', 'string', 'min:20', 'max:3000'],
            'use_of_funds' => ['required', 'string', 'min:20', 'max:3000'],
            'source_of_funds' => ['required', Rule::in(self::FUND_SOURCES)],
            'source_of_funds_note' => ['required_if:source_of_funds,other', 'nullable', 'string', 'max:500'],
            'prior_projects' => ['nullable', 'string', 'max:2000'],

            // คำรับรอง (AML / PEP)
            'is_pep' => ['required', 'boolean'],
            'pep_details' => ['required_if_accepted:is_pep', 'nullable', 'string', 'max:500'],
            'declare_not_sanctioned' => ['accepted'],
            'declare_true' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'date_of_birth.before_or_equal' => __('ผู้สร้างต้องมีอายุ 20 ปีบริบูรณ์ขึ้นไป'),
            'token_symbol.regex' => __('สัญลักษณ์เหรียญต้องเป็นตัวอักษรอังกฤษพิมพ์ใหญ่หรือตัวเลข 2–11 ตัว'),
            'phone.regex' => __('เบอร์โทรศัพท์ไม่ถูกต้อง (ตัวเลข 8–20 หลัก ขึ้นต้นด้วย + ได้)'),
            'planned_supply.regex' => __('ซัพพลายที่วางแผนต้องเป็นจำนวนเต็มบวก'),
            // ตัวแทนแบบ * ต้องอยู่ท้ายสุด — Laravel ไล่คีย์ตามลำดับแล้วหยุดที่ตัวแรกที่ตรง
            '*.regex' => __(':attribute รูปแบบไม่ถูกต้อง'),
            '*.url' => __(':attribute ต้องเป็นลิงก์ที่ขึ้นต้นด้วย https://'),
        ];
    }

    public function attributes(): array
    {
        return [
            'applicant_type' => __('ประเภทผู้สมัคร'),
            'full_name' => __('ชื่อ-นามสกุล'),
            'full_name_en' => __('ชื่อ-นามสกุล (ภาษาอังกฤษ)'),
            'date_of_birth' => __('วันเกิด'),
            'nationality' => __('สัญชาติ'),
            'occupation' => __('อาชีพ'),
            'company_name' => __('ชื่อนิติบุคคล'),
            'company_name_en' => __('ชื่อนิติบุคคล (ภาษาอังกฤษ)'),
            'registration_number' => __('เลขทะเบียนนิติบุคคล'),
            'registration_country' => __('ประเทศที่จดทะเบียน'),
            'incorporation_date' => __('วันที่จดทะเบียน'),
            'signatory_name' => __('ชื่อผู้มีอำนาจลงนาม'),
            'signatory_position' => __('ตำแหน่งผู้มีอำนาจลงนาม'),
            'email' => __('อีเมล'),
            'phone' => __('เบอร์โทรศัพท์'),
            'address' => __('ที่อยู่'),
            'country' => __('ประเทศที่พำนัก'),
            'telegram' => __('Telegram'),
            'line_id' => __('LINE ID'),
            'project_name' => __('ชื่อโครงการ'),
            'token_name' => __('ชื่อเหรียญ'),
            'token_symbol' => __('สัญลักษณ์เหรียญ'),
            'planned_supply' => __('ซัพพลายที่วางแผน'),
            'category' => __('หมวดหมู่โครงการ'),
            'description' => __('รายละเอียดโครงการ'),
            'website' => __('เว็บไซต์'),
            'whitepaper_url' => __('ลิงก์ whitepaper'),
            'twitter' => __('ลิงก์ X (Twitter)'),
            'telegram_group' => __('ลิงก์กลุ่ม Telegram'),
            'facebook' => __('ลิงก์ Facebook'),
            'github' => __('ลิงก์ GitHub'),
            'team_description' => __('ข้อมูลทีมงาน'),
            'use_of_funds' => __('แผนการใช้เงิน'),
            'source_of_funds' => __('แหล่งที่มาของเงินทุน'),
            'source_of_funds_note' => __('รายละเอียดแหล่งเงินทุน'),
            'prior_projects' => __('โครงการที่เคยทำ'),
            'is_pep' => __('สถานะบุคคลที่มีสถานภาพทางการเมือง'),
            'pep_details' => __('รายละเอียดสถานภาพทางการเมือง'),
            'declare_not_sanctioned' => __('คำรับรองว่าไม่อยู่ในรายชื่อคว่ำบาตร'),
            'declare_true' => __('คำรับรองว่าข้อมูลเป็นความจริง'),
        ];
    }

    /**
     * ข้อมูลที่จะเก็บ — เฉพาะช่องที่รู้จัก, ตัดช่องของประเภทที่ไม่ได้เลือก, เรียงคีย์ให้แฮชคงที่.
     */
    public function formData(): array
    {
        $data = $this->safe()->only(array_keys($this->rules()));

        $drop = $data['applicant_type'] === CreatorRegistration::TYPE_INDIVIDUAL
            ? self::COMPANY_FIELDS
            : self::INDIVIDUAL_FIELDS;

        foreach ($drop as $field) {
            unset($data[$field]);
        }

        $data['is_pep'] = filter_var($data['is_pep'], FILTER_VALIDATE_BOOLEAN);
        if (! $data['is_pep']) {
            $data['pep_details'] = null;
        }
        $data['declare_not_sanctioned'] = true;
        $data['declare_true'] = true;

        foreach (array_keys($this->rules()) as $key) {
            if (! in_array($key, $drop, true)) {
                $data[$key] ??= null;
            }
        }

        ksort($data);

        return $data;
    }
}
