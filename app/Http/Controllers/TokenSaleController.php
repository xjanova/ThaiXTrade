<?php

namespace App\Http\Controllers;

use App\Services\TokenSaleService;
use Barryvdh\DomPDF\Facade\Pdf;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TokenSaleController — หน้าเว็บสำหรับขายเหรียญ TPIX และ Whitepaper.
 *
 * render หน้า Inertia ให้ frontend (Vue)
 */
class TokenSaleController extends Controller
{
    public function __construct(
        private TokenSaleService $saleService,
    ) {}

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
     * ดาวน์โหลด Whitepaper PDF — render จาก Blade template ด้วย DomPDF.
     * ถ้ามีไฟล์ static PDF อยู่แล้วจะใช้ไฟล์นั้นก่อน (เร็วกว่า).
     */
    public function downloadWhitepaper()
    {
        // ใช้ static PDF ถ้ามี (ประสิทธิภาพดีกว่า)
        $staticPath = public_path('whitepaper/TPIX-Whitepaper.pdf');
        if (file_exists($staticPath)) {
            return response()->download($staticPath, 'TPIX-Chain-Whitepaper.pdf');
        }

        // Fallback: render จาก Blade template ด้วย DomPDF
        $pdf = Pdf::loadView('whitepaper.pdf')
            ->setPaper('A4')
            ->setOption('isRemoteEnabled', true);

        return $pdf->download('TPIX-Chain-Whitepaper.pdf');
    }
}
