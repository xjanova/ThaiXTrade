<?php

namespace App\Http\Controllers;

use App\Services\TokenSaleService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TokenSaleController — หน้าเว็บสำหรับขายเหรียญ TPIX และ Whitepaper.
 *
 * Render หน้า Inertia ให้ frontend (Vue).
 */
class TokenSaleController extends Controller
{
    public function __construct(
        private TokenSaleService $saleService,
    ) {
        //
    }

    /**
     * หน้าขายเหรียญ (ICO/IDO) — แสดง phase, ราคา, progress, buy form.
     */
    public function index(): Response
    {
        $sale = $this->saleService->getActiveSale();
        $stats = $this->saleService->getSaleStats($sale);

        return Inertia::render('TokenSale', [
            'sale' => $sale ? [
                'id' => $sale->id,
                'name' => $sale->name,
                'description' => $sale->description,
                'status' => $sale->status,
                'accept_currencies' => $sale->accept_currencies ?? ['BNB', 'USDT'],
                'starts_at' => $sale->starts_at?->toIso8601String(),
                'ends_at' => $sale->ends_at?->toIso8601String(),
            ] : null,
            'stats' => $stats,
        ]);
    }

    /**
     * หน้า Whitepaper — แสดง whitepaper แบบ interactive.
     */
    public function whitepaper(): Response
    {
        return Inertia::render('Whitepaper');
    }

    /**
     * ดาวน์โหลด Whitepaper PDF (รองรับไทย/อังกฤษ).
     *
     * ?lang=en  → ภาษาอังกฤษ (default)
     * ?lang=th  → ภาษาไทย
     *
     * 1. ถ้ามีไฟล์ static PDF ตามภาษา ให้ดาวน์โหลดเลย (เร็วที่สุด).
     * 2. Fallback: render Blade template เป็น PDF ด้วย DomPDF.
     */
    public function downloadWhitepaper(Request $request)
    {
        $lang = $request->query('lang', 'en');
        if (! in_array($lang, ['en', 'th'])) {
            $lang = 'en';
        }

        // ลำดับที่ 1: ใช้ static PDF ถ้ามี (เร็วที่สุด)
        $staticPath = public_path("whitepaper/TPIX-Whitepaper-{$lang}.pdf");
        if (file_exists($staticPath)) {
            $downloadName = $lang === 'th'
                ? 'TPIX-Chain-Whitepaper-Thai.pdf'
                : 'TPIX-Chain-Whitepaper.pdf';
            return response()->download($staticPath, $downloadName);
        }

        // ลำดับที่ 2: generate PDF ด้วย DomPDF จาก Blade template
        // ใช้ Sarabun เป็น default font เสมอ (รองรับทั้ง Thai + English)
        // ถ้าไม่มีไฟล์ฟอนต์ DomPDF จะ fallback เป็น sans-serif อัตโนมัติ
        $pdf = Pdf::loadView('whitepaper.pdf', ['lang' => $lang])
            ->setPaper('a4')
            ->setOption('isRemoteEnabled', true)
            ->setOption('defaultFont', 'sarabun')
            ->setOption('isFontSubsettingEnabled', true);

        $filename = $lang === 'th'
            ? 'TPIX-Chain-Whitepaper-Thai-v2.0.pdf'
            : 'TPIX-Chain-Whitepaper-v2.0.pdf';

        return $pdf->download($filename);
    }
}
