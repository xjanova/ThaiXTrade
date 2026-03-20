<?php

namespace App\Http\Controllers;

use App\Services\FoodPassportService;
use Inertia\Inertia;

class FoodPassportController extends Controller
{
    public function __construct(
        private FoodPassportService $foodPassportService,
    ) {}

    /**
     * หน้าหลัก FoodPassport — แสดง dashboard + tutorial
     */
    public function index()
    {
        $stats = $this->foodPassportService->getStats();
        $recentProducts = $this->foodPassportService->getProducts(perPage: 6);
        $certificates = $this->foodPassportService->getCertificates(perPage: 6);

        return Inertia::render('FoodPassport', [
            'stats' => $stats,
            'recentProducts' => $recentProducts,
            'certificates' => $certificates,
        ]);
    }

    /**
     * หน้า verify สินค้า — ผู้บริโภคสแกน QR มาที่นี่
     */
    public function verify(int $productId)
    {
        $data = $this->foodPassportService->verifyProduct($productId);

        return Inertia::render('FoodPassport', [
            'verifyMode' => true,
            'verifyData' => $data,
            'stats' => $this->foodPassportService->getStats(),
        ]);
    }
}
