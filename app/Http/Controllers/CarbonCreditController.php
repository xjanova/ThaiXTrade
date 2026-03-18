<?php

namespace App\Http\Controllers;

use App\Services\CarbonCreditService;
use Inertia\Inertia;
use Inertia\Response;

class CarbonCreditController extends Controller
{
    public function __construct(
        private CarbonCreditService $carbonCreditService,
    ) {}

    public function index(): Response
    {
        $projects = $this->carbonCreditService->getActiveProjects();
        $stats = $this->carbonCreditService->getStats();

        return Inertia::render('CarbonCredit', [
            'projects' => $projects,
            'stats' => $stats,
        ]);
    }
}
