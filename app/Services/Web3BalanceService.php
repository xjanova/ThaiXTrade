<?php

namespace App\Services;

use App\Models\Token;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Web3BalanceService.
 *
 * Fetches real wallet balances from blockchain RPCs using eth_getBalance
 * and ERC20 balanceOf calls. Caches results briefly for performance.
 */
class Web3BalanceService
{
    /**
     * Get native token balance for a wallet address.
     */
    public function getNativeBalance(string $walletAddress, int $chainId): string
    {
        $cacheKey = "balance:native:{$chainId}:{$walletAddress}";

        return Cache::remember($cacheKey, 15, function () use ($walletAddress, $chainId) {
            $rpcUrl = $this->getRpcUrl($chainId);
            if (! $rpcUrl) {
                return '0';
            }

            try {
                $response = Http::timeout(10)->post($rpcUrl, [
                    'jsonrpc' => '2.0',
                    'method' => 'eth_getBalance',
                    'params' => [$walletAddress, 'latest'],
                    'id' => 1,
                ]);

                if ($response->successful() && isset($response->json()['result'])) {
                    $hexBalance = $response->json()['result'];

                    return $this->hexToDecimal($hexBalance, 18);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to fetch native balance', [
                    'chain_id' => $chainId,
                    'error' => $e->getMessage(),
                ]);
            }

            return '0';
        });
    }

    /**
     * Get ERC20 token balance for a wallet address.
     */
    public function getTokenBalance(string $walletAddress, string $tokenAddress, int $chainId, int $decimals = 18): string
    {
        $cacheKey = "balance:token:{$chainId}:{$tokenAddress}:{$walletAddress}";

        return Cache::remember($cacheKey, 15, function () use ($walletAddress, $tokenAddress, $chainId, $decimals) {
            $rpcUrl = $this->getRpcUrl($chainId);
            if (! $rpcUrl) {
                return '0';
            }

            try {
                // ERC20 balanceOf(address) function selector: 0x70a08231
                $paddedAddress = str_pad(substr($walletAddress, 2), 64, '0', STR_PAD_LEFT);
                $data = '0x70a08231'.$paddedAddress;

                $response = Http::timeout(10)->post($rpcUrl, [
                    'jsonrpc' => '2.0',
                    'method' => 'eth_call',
                    'params' => [
                        [
                            'to' => $tokenAddress,
                            'data' => $data,
                        ],
                        'latest',
                    ],
                    'id' => 1,
                ]);

                if ($response->successful() && isset($response->json()['result'])) {
                    $hexBalance = $response->json()['result'];

                    return $this->hexToDecimal($hexBalance, $decimals);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to fetch token balance', [
                    'chain_id' => $chainId,
                    'token' => $tokenAddress,
                    'error' => $e->getMessage(),
                ]);
            }

            return '0';
        });
    }

    /**
     * Get balances for all tokens of a wallet on a given chain.
     */
    public function getWalletBalances(string $walletAddress, int $chainId): array
    {
        $balances = [];

        // Native balance
        $nativeBalance = $this->getNativeBalance($walletAddress, $chainId);
        $chainConfig = config("chains.chains.{$chainId}");

        if ($chainConfig) {
            $balances[] = [
                'token_address' => '0xEeeeeEeeeEeEeeEeEeEeeEEEeeeeEeeeeeeeEEeE',
                'symbol' => $chainConfig['nativeCurrency']['symbol'],
                'name' => $chainConfig['nativeCurrency']['name'],
                'decimals' => $chainConfig['nativeCurrency']['decimals'],
                'balance' => $nativeBalance,
                'is_native' => true,
            ];
        }

        // ERC20 token balances from database
        $tokens = Token::active()
            ->where('chain_id', $chainId)
            ->orderBy('sort_order')
            ->get();

        foreach ($tokens as $token) {
            $balance = $this->getTokenBalance(
                $walletAddress,
                $token->contract_address,
                $chainId,
                $token->decimals,
            );

            // Only include tokens with non-zero balance
            if (bccomp($balance, '0', 18) > 0) {
                $balances[] = [
                    'token_address' => $token->contract_address,
                    'symbol' => $token->symbol,
                    'name' => $token->name,
                    'decimals' => $token->decimals,
                    'balance' => $balance,
                    'logo' => $token->logo,
                    'is_native' => false,
                ];
            }
        }

        return $balances;
    }

    /**
     * Get real-time gas price from chain RPC.
     */
    public function getGasPrice(int $chainId): string
    {
        $cacheKey = "gas_price:{$chainId}";

        return Cache::remember($cacheKey, 10, function () use ($chainId) {
            $rpcUrl = $this->getRpcUrl($chainId);
            if (! $rpcUrl) {
                return '0';
            }

            try {
                $response = Http::timeout(10)->post($rpcUrl, [
                    'jsonrpc' => '2.0',
                    'method' => 'eth_gasPrice',
                    'params' => [],
                    'id' => 1,
                ]);

                if ($response->successful() && isset($response->json()['result'])) {
                    return $this->hexToBigInt($response->json()['result']);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to fetch gas price', [
                    'chain_id' => $chainId,
                    'error' => $e->getMessage(),
                ]);
            }

            return '0';
        });
    }

    /**
     * Get the RPC URL for a chain.
     */
    private function getRpcUrl(int $chainId): ?string
    {
        $chain = config("chains.chains.{$chainId}");
        if (! $chain || empty($chain['rpc'])) {
            return null;
        }

        return $chain['rpc'][0];
    }

    /**
     * Convert hex balance to human-readable decimal string.
     */
    private function hexToDecimal(string $hex, int $decimals): string
    {
        if ($hex === '0x' || $hex === '0x0') {
            return '0';
        }

        $hex = ltrim(substr($hex, 2), '0') ?: '0';
        $bigInt = gmp_init($hex, 16);
        $stringVal = gmp_strval($bigInt);

        if ($decimals === 0) {
            return $stringVal;
        }

        // Pad left to ensure enough digits
        $stringVal = str_pad($stringVal, $decimals + 1, '0', STR_PAD_LEFT);
        $intPart = substr($stringVal, 0, -$decimals);
        $fracPart = substr($stringVal, -$decimals);

        // Trim trailing zeros from fractional part
        $fracPart = rtrim($fracPart, '0');

        if ($fracPart === '') {
            return $intPart;
        }

        return $intPart.'.'.$fracPart;
    }

    /**
     * Convert hex to big integer string (for gas price etc).
     */
    private function hexToBigInt(string $hex): string
    {
        if ($hex === '0x' || $hex === '0x0') {
            return '0';
        }

        $hex = ltrim(substr($hex, 2), '0') ?: '0';

        return gmp_strval(gmp_init($hex, 16));
    }
}
