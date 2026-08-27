<?php

namespace App\Http\Controllers;

use App\Services\TokenFactoryService;
use Inertia\Inertia;
use Inertia\Response;

class TokenFactoryController extends Controller
{
    public function __construct(
        private TokenFactoryService $tokenFactoryService,
    ) {}

    public function index(): Response
    {
        $tokens = $this->tokenFactoryService->getDeployedTokens();
        $config = $this->tokenFactoryService->getFactoryConfig();
        $readiness = $this->tokenFactoryService->isFactoryReady();

        // ส่งผลตรวจความพร้อมไปทั้งก้อน
        // เดิมหยิบไปแค่ ready กับ issues ทำให้หน้าเว็บแยกไม่ออกว่า "รอติดตั้งสัญญา"
        // กับ "ตั้งค่ายังไม่ครบ" ต่างกัน ทั้งที่ผู้ใช้ต้องทำคนละอย่าง (อย่างแรกรอเฉย ๆ ได้)
        return Inertia::render('TokenFactory', [
            'tokens' => $tokens,
            'factoryConfig' => array_merge($config, $readiness),
        ]);
    }
}
