<?php

namespace App\Jobs;

use App\Models\FactoryToken;
use App\Services\Web3DeploymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * DeployTokenJob
 *
 * Async job ที่ deploy ERC-20 token จริงบน TPIX Chain
 * ผ่าน TPIXTokenFactory contract
 */
class DeployTokenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retry up to 3 times.
     */
    public int $tries = 3;

    /**
     * Wait 15 seconds between retries.
     */
    public int $backoff = 15;

    /**
     * Timeout for the job (2 minutes).
     */
    public int $timeout = 120;

    public function __construct(
        public FactoryToken $token,
    ) {}

    public function handle(Web3DeploymentService $deploymentService): void
    {
        Log::info("DeployTokenJob: deploying {$this->token->symbol}", [
            'token_id' => $this->token->id,
        ]);

        $result = $deploymentService->deployToken([
            'name' => $this->token->name,
            'symbol' => $this->token->symbol,
            'decimals' => $this->token->decimals,
            'total_supply' => $this->token->total_supply,
            'creator_address' => $this->token->creator_address,
            'token_type' => $this->token->token_type,
        ]);

        if ($result['success']) {
            $this->token->update([
                'status' => 'deployed',
                'contract_address' => strtolower($result['contractAddress']),
                'tx_hash' => $result['txHash'],
                'is_listed' => true,
                'metadata' => array_merge($this->token->metadata ?? [], [
                    'block_number' => $result['blockNumber'] ?? null,
                    'deployed_at' => now()->toIso8601String(),
                ]),
            ]);

            Log::info("DeployTokenJob: {$this->token->symbol} deployed", [
                'contract' => $result['contractAddress'],
                'tx' => $result['txHash'],
            ]);
        } else {
            $error = $result['error'] ?? 'Unknown deployment error';

            // On last attempt, mark as failed
            if ($this->attempts() >= $this->tries) {
                $this->token->update([
                    'status' => 'failed',
                    'metadata' => array_merge($this->token->metadata ?? [], [
                        'deploy_error' => $error,
                        'failed_at' => now()->toIso8601String(),
                    ]),
                ]);

                Log::error("DeployTokenJob: {$this->token->symbol} failed permanently", [
                    'error' => $error,
                ]);
            } else {
                Log::warning("DeployTokenJob: {$this->token->symbol} attempt {$this->attempts()} failed", [
                    'error' => $error,
                ]);

                // Release back to queue for retry
                $this->release($this->backoff);
            }
        }
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
