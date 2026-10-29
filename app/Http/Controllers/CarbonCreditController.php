<?php

namespace App\Http\Controllers;

use App\Services\CarbonCreditService;
use Inertia\Inertia;
use Inertia\Response;

class CarbonCreditController extends Controller
{
    public function __construct(
        private CarbonCreditService $carbonCreditService,
    ) {
        //
    }

    public function index(): Response
    {
        $projects = $this->carbonCreditService->getActiveProjects();
        $stats = $this->carbonCreditService->getStats();

        return Inertia::render('CarbonCredit', [
            'projects' => $projects,
            'stats' => $stats,
            // หน้าเว็บซ่อนปุ่มซื้อเมื่อปิด — ด่านจริงอยู่ที่ API (CarbonCreditApiController::purchase)
            'purchaseEnabled' => $this->carbonCreditService->purchaseOpen(),
        ]);
    }

    public function whitepaper(): Response
    {
        return Inertia::render('CarbonCreditWhitepaper');
    }
}
