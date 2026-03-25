<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
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

        return Inertia::render('TokenFactory', [
            'tokens' => $tokens,
            'factoryConfig' => [
                'creation_fee_tpix' => (float) SiteSetting::get('factory', 'creation_fee_tpix', 100),
                'creation_fee_usd' => (float) SiteSetting::get('factory', 'creation_fee_usd', 10),
                'fee_payment_method' => SiteSetting::get('factory', 'fee_payment_method', 'tpix'),
                'fee_wallet' => SiteSetting::get('factory', 'fee_wallet', ''),
                'nft_enabled' => SiteSetting::get('factory', 'nft_enabled', true),
                'max_supply_limit' => (float) SiteSetting::get('factory', 'max_supply_limit', 999999999999999),
                'auto_approve' => SiteSetting::get('factory', 'auto_approve', false),
                'creation_enabled' => SiteSetting::get('factory', 'creation_enabled', true),
            ],
        ]);
    }
}
