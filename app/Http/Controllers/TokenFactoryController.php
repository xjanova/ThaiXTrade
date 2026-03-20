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

        return Inertia::render('TokenFactory', [
            'tokens' => $tokens,
        ]);
    }
}
