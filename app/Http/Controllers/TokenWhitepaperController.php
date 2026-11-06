<?php

namespace App\Http\Controllers;

use App\Models\FactoryToken;
use App\Services\CreatorRegistration\CreatorPublicProfile;
use App\Services\TokenFactory\TokenOnchainFacts;
use App\Support\BuyerRiskWarnings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * TPIX TRADE — ไวท์เปเปอร์มาตรฐานสองภาษาของเหรียญจาก Token Factory.
 *
 *   GET /token-factory/{id}/whitepaper       หน้าเว็บ (แม่แบบเดียวกับ PDF)
 *   GET /token-factory/{id}/whitepaper/pdf   ดาวน์โหลด PDF
 *
 * ข้อมูลเหรียญอ่านจากสัญญาบนเชนโดยตรง (TokenOnchainFacts) — อ่านไม่ได้ก็บอกว่าอ่านไม่ได้ ไม่เดา
 * ข้อมูลโครงการ/ผู้สร้างมาจากใบลงทะเบียนที่อนุมัติแล้วและยินยอมเผยแพร่ (CreatorPublicProfile) ถ้ามี
 * ส่วน LP lock / vesting จะเพิ่มเมื่อ LaunchpadFactory ขึ้นเชนแล้ว — ตอนนี้ไม่แสดงหัวข้อว่าง
 *
 * Developed by Xman Studio.
 */
class TokenWhitepaperController extends Controller
{
    public function __construct(
        private readonly TokenOnchainFacts $facts,
        private readonly CreatorPublicProfile $profiles,
    ) {}

    public function show(int $id): View
    {
        return view('whitepaper.token', $this->data($id, 'web'));
    }

    public function pdf(int $id): Response
    {
        $data = $this->data($id, 'pdf');

        $pdf = Pdf::loadView('whitepaper.token', $data)
            ->setPaper('a4')
            ->setOption('defaultFont', 'sarabun')
            ->setOption('isFontSubsettingEnabled', true)
            ->setOption('isRemoteEnabled', false);

        $symbol = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $data['token']->symbol)) ?: 'TOKEN';
        $response = $pdf->download(sprintf('%s-Whitepaper-TPIX.pdf', $symbol));
        $response->headers->set('Cache-Control', 'public, max-age=600');

        return $response;
    }

    private function data(int $id, string $mode): array
    {
        $token = FactoryToken::query()->deployed()->whereNotNull('contract_address')->findOrFail($id);

        $registration = $this->profiles->forWallet($token->creator_address);
        $registration?->load(['documents', 'user']);

        return [
            'mode' => $mode,
            'token' => $token,
            'facts' => $this->facts->for((string) $token->contract_address),
            'creator' => $registration ? $this->profiles->build($registration) : null,
            'risks' => BuyerRiskWarnings::all(),
            'fontDir' => storage_path('fonts'),
            'explorer' => rtrim((string) config('blockchain.explorer_url', 'https://explorer.tpix.online'), '/'),
            'generatedAt' => now()->timezone('Asia/Bangkok'),
        ];
    }
}
