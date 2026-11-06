<?php

namespace App\Services\CreatorRegistration;

use App\Models\CreatorRegistration;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF ข้อตกลงผู้สร้างเหรียญที่กรอกและลงนามแล้ว.
 *
 * พิมพ์ข้อตกลงฉบับที่ผู้สร้างเซ็นจริง (ไม่ใช่ฉบับล่าสุด) + ข้อมูลที่กรอก + หลักฐานลายเซ็น
 * พร้อมผลตรวจซ้ำ — ใบที่ยังไม่เซ็นมีลายน้ำ "ร่าง — ยังไม่ได้ลงนาม"
 *
 * ฟอนต์ Sarabun ติดตั้งด้วย `php artisan tpix:install-thai-font` (storage/fonts)
 * ไม่มีไฟล์ฟอนต์ DomPDF ถอยไปใช้ฟอนต์ตั้งต้น — ภาษาไทยจะเป็นกล่อง แต่ไฟล์ยังออกได้
 *
 * Developed by Xman Studio.
 */
class CreatorRegistrationPdf
{
    public function __construct(
        private readonly CreatorAgreement $agreement,
        private readonly CreatorRegistrationService $registrations,
    ) {}

    public function download(CreatorRegistration $registration): Response
    {
        $version = $registration->agreement_version && $this->agreement->exists($registration->agreement_version)
            ? $registration->agreement_version
            : $this->agreement->currentVersion();

        $pdf = Pdf::loadView('pdf.creator-registration', [
            'registration' => $registration,
            'form' => (array) $registration->form_data,
            'document' => $this->agreement->document($version),
            'agreementHash' => $this->agreement->hash($version),
            'evidence' => $this->registrations->verifyEvidence($registration),
            'claimedToken' => $this->registrations->boundToken($registration),
            'fontDir' => storage_path('fonts'),
            'generatedAt' => now()->timezone('Asia/Bangkok'),
        ])
            ->setPaper('a4')
            ->setOption('defaultFont', 'sarabun')
            ->setOption('isFontSubsettingEnabled', true)
            // ไม่ดึงไฟล์จากภายนอก — ข้อมูลที่ผู้ใช้กรอก (ลิงก์) ต้องไม่ทำให้เซิร์ฟเวอร์ยิงคำขอออกไป
            ->setOption('isRemoteEnabled', false);

        $symbol = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $registration->token_symbol)) ?: 'TOKEN';
        $filename = sprintf('TPIX-Creator-Agreement-%s-%s.pdf', $symbol, substr($registration->uuid, 0, 8));

        $response = $pdf->download($filename);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
