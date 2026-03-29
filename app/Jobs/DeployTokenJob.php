<?php

namespace App\Jobs;

use App\Models\FactoryToken;
use App\Services\BlockscoutVerifyService;
use App\Services\Web3DeploymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * DeployTokenJob.
 *
 * Async job ที่ deploy ERC-20 token จริงบน TPIX Chain
 * ผ่าน TPIXTokenFactory contract.
 */
class DeployTokenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 15;

    public int $timeout = 120;

    public function __construct(
        public FactoryToken $token,
    ) {}

    public function handle(Web3DeploymentService $deploymentService, BlockscoutVerifyService $verifyService): void
    {
        Log::info("DeployTokenJob: deploying {$this->token->symbol} (attempt {$this->attempts()})", [
            'token_id' => $this->token->id,
        ]);

        // Phase 2: ส่ง sub_options + token_category ให้ Web3DeploymentService
        $metadata = $this->token->metadata ?? [];

        $result = $deploymentService->deployToken([
            'name' => $this->token->name,
            'symbol' => $this->token->symbol,
            'decimals' => $this->token->decimals,
            'total_supply' => $this->token->total_supply,
            'creator_address' => $this->token->creator_address,
            'token_type' => $this->token->token_type,
            'token_category' => $this->token->token_category,
            'sub_options' => $metadata['sub_options'] ?? [],
            'logo_url' => $this->token->logo_url,
        ]);

        if ($result['success']) {
            $this->token->update([
                'status' => 'deployed',
                'contract_address' => strtolower($result['contractAddress']),
                'tx_hash' => $result['txHash'],
                'is_listed' => true,
                'is_verified' => true,
                'metadata' => array_merge($this->token->metadata ?? [], [
                    'block_number' => $result['blockNumber'] ?? null,
                    'deployed_at' => now()->toIso8601String(),
                    'deploy_attempts' => $this->attempts(),
                ]),
            ]);

            Log::info("DeployTokenJob: {$this->token->symbol} deployed", [
                'contract' => $result['contractAddress'],
                'tx' => $result['txHash'],
            ]);

            // Auto-verify contract บน Blockscout Explorer
            try {
                $verifyResult = $verifyService->verifyFactoryToken(
                    $result['contractAddress'],
                    $this->token
                );

                if ($verifyResult['success']) {
                    $this->token->update([
                        'metadata' => array_merge($this->token->metadata ?? [], [
                            'explorer_verified' => true,
                            'explorer_verified_at' => now()->toIso8601String(),
                        ]),
                    ]);
                    Log::info("DeployTokenJob: {$this->token->symbol} verified on Blockscout", [
                        'contract' => $result['contractAddress'],
                    ]);
                } else {
                    Log::warning("DeployTokenJob: {$this->token->symbol} verification failed (non-critical)", [
                        'error' => $verifyResult['error'] ?? 'unknown',
                    ]);
                }
            } catch (\Throwable $e) {
                // Verification failure ไม่ควรทำให้ deploy job fail
                Log::warning("DeployTokenJob: {$this->token->symbol} auto-verify exception (non-critical)", [
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        // Deployment returned failure — throw exception to let Laravel handle retry/fail
        $error = $result['error'] ?? 'Unknown deployment error';
        throw new \RuntimeException("Token deployment failed: {$error}");
    }

    /**
     * Handle job failure after all retries exhausted.
     */
    public function failed(\Throwable $exception): void
    {
        $this->token->update([
            'status' => 'failed',
            'metadata' => array_merge($this->token->metadata ?? [], [
                'deploy_error' => $exception->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ]),
        ]);

        Log::error("DeployTokenJob: {$this->token->symbol} job failed", [
            'token_id' => $this->token->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
