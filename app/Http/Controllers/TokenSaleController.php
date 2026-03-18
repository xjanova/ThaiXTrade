<?php

namespace App\Http\Controllers;

use App\Services\TokenSaleService;
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
     * ดาวน์โหลด/ดู Whitepaper PDF.
     *
     * 1. ถ้ามีไฟล์ static PDF ก็ให้ดาวน์โหลดเลย (เร็วที่สุด).
     * 2. Fallback: render Blade template เป็น HTML สำหรับ print-to-PDF.
     */
    public function downloadWhitepaper()
    {
        // ลำดับที่ 1: ใช้ static PDF ถ้ามี
        $staticPath = public_path('whitepaper/TPIX-Whitepaper.pdf');
        if (file_exists($staticPath)) {
            return response()->download($staticPath, 'TPIX-Chain-Whitepaper.pdf');
        }

        // ลำดับที่ 2: render Blade HTML → ผู้ใช้กด Ctrl+P เพื่อ Save as PDF
        return view('whitepaper.pdf');
    }
}
