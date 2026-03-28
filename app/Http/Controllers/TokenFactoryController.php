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

        return Inertia::render('TokenFactory', [
            'tokens' => $tokens,
            'factoryConfig' => array_merge($config, [
                'ready' => $readiness['ready'],
                'issues' => $readiness['issues'],
            ]),
        ]);
    }
}
