# API Reference

TPIX TRADE REST API documentation.

---

## Table of Contents

- [Overview](#overview)
- [Base URL](#base-url)
- [Authentication](#authentication)
- [Response Format](#response-format)
- [Error Handling](#error-handling)
- [Rate Limiting](#rate-limiting)
- [Market API](#market-api)
- [Chain API](#chain-api)
- [Token API](#token-api)
- [Trading API](#trading-api)
- [Swap API](#swap-api)
- [Wallet API](#wallet-api)
- [AI API](#ai-api)
- [WebSocket Events](#websocket-events)
- [Health Check](#health-check)

---

## Overview

The TPIX TRADE API is a RESTful JSON API that provides access to market data, trading operations, wallet management, and AI-powered insights. All endpoints return data in a consistent envelope format.

### API Root

```
GET /api/
```

**Response:**
```json
{
    "name": "TPIX TRADE API",
    "version": "1.0.0",
    "status": "operational",
    "developer": "Xman Studio"
}
```

---

## Base URL

```
Development:  http://localhost:8000/api/v1
Production:   https://your-domain.com/api/v1
```

All API endpoints are prefixed with `/api/v1/`.

---

## Authentication

### Public Endpoints

Market data, chain information, and token data endpoints are publicly accessible. No authentication is required.

### Protected Endpoints

Trading, wallet, swap, and AI endpoints require authentication via wallet signature verification.

**Authentication Flow:**

1. Connect wallet and obtain a nonce from the server.
2. Sign the nonce message with your wallet (ECDSA signature).
3. Submit the signature to `/api/v1/wallet/connect`.
4. Receive an API token (Laravel Sanctum).
5. Include the token in subsequent requests.

**Request Header:**

```
Authorization: Bearer {your-api-token}
```

---

## Response Format

### Success Response

```json
{
    "success": true,
    "data": {
        "key": "value"
    }
}
```

### Success Response with Pagination

```json
{
    "success": true,
    "data": [ ... ],
    "meta": {
        "page": 1,
        "per_page": 20,
        "total": 100
    }
}
```

### Error Response

```json
{
    "success": false,
    "error": {
        "code": "ERROR_CODE",
        "message": "Human-readable error description"
    }
}
```

---

## Error Handling

### HTTP Status Codes

| Status | Meaning |
|--------|---------|
| `200` | Success |
| `201` | Created (e.g., new order) |
| `400` | Bad Request -- invalid parameters |
| `401` | Unauthorized -- missing or invalid auth |
| `403` | Forbidden -- insufficient permissions |
| `404` | Not Found -- resource does not exist |
| `422` | Validation Error -- input validation failed |
| `429` | Too Many Requests -- rate limit exceeded |
| `500` | Internal Server Error |

### Error Codes

| Code | Description |
|------|-------------|
| `CHAIN_NOT_FOUND` | The specified chain ID is not supported |
| `PAIR_NOT_FOUND` | The specified trading pair does not exist |
| `TOKEN_NOT_FOUND` | The specified token address is not found |
| `INVALID_ORDER` | Order parameters are invalid |
| `ORDER_NOT_FOUND` | The specified order ID does not exist |
| `INSUFFICIENT_BALANCE` | Wallet balance is insufficient for the operation |
| `INVALID_SIGNATURE` | Wallet signature verification failed |
| `WALLET_NOT_CONNECTED` | No wallet is connected to the session |
| `RATE_LIMIT_EXCEEDED` | Too many requests in the time window |
| `SLIPPAGE_EXCEEDED` | Swap slippage exceeds tolerance |
| `AI_SERVICE_UNAVAILABLE` | AI provider is not responding |

---

## Rate Limiting

| Endpoint Group | Limit | Window |
|---------------|-------|--------|
| General API | 60 requests | 1 minute |
| Trading operations | 100 requests | 1 minute |

When the rate limit is exceeded, the API returns HTTP `429` with headers:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
Retry-After: 34
```

---

## Market API

Public endpoints for market data. No authentication required.

### List All Tickers

```
GET /api/v1/market/tickers
```

Returns price tickers for all available trading pairs.

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "symbol": "BTC/USDT",
            "last": "67234.50",
            "high": "68100.00",
            "low": "66800.00",
            "volume": "1234.56",
            "change": "2.34",
            "changePercent": "3.61"
        }
    ]
}
```

### Get Ticker

```
GET /api/v1/market/ticker/{symbol}
```

**Parameters:**

| Name | Type | Location | Description |
|------|------|----------|-------------|
| `symbol` | string | path | Trading pair symbol (e.g., `BTC-USDT`) |

**Response:**
```json
{
    "success": true,
    "data": {
        "symbol": "BTC/USDT",
        "last": "67234.50",
        "bid": "67230.00",
        "ask": "67235.00",
        "high": "68100.00",
        "low": "66800.00",
        "volume": "1234.56",
        "quoteVolume": "82956789.12",
        "change": "2.34",
        "changePercent": "3.61",
        "timestamp": "2024-01-25T12:00:00Z"
    }
}
```

### Get Order Book

```
GET /api/v1/market/orderbook/{symbol}
```

**Parameters:**

| Name | Type | Location | Description |
|------|------|----------|-------------|
| `symbol` | string | path | Trading pair symbol |
| `limit` | integer | query | Number of levels (default: 20, max: 100) |

**Response:**
```json
{
    "success": true,
    "data": {
        "symbol": "BTC/USDT",
        "bids": [
            ["67230.00", "0.5000"],
            ["67225.00", "1.2000"]
        ],
        "asks": [
            ["67235.00", "0.3000"],
            ["67240.00", "0.8000"]
        ],
        "timestamp": "2024-01-25T12:00:00Z"
    }
}
```

### Get Recent Trades

```
GET /api/v1/market/trades/{symbol}
```

**Parameters:**

| Name | Type | Location | Description |
|------|------|----------|-------------|
| `symbol` | string | path | Trading pair symbol |
| `limit` | integer | query | Number of trades (default: 50, max: 500) |

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": "t-12345",
            "price": "67234.50",
            "amount": "0.1500",
            "side": "buy",
            "timestamp": "2024-01-25T12:00:00Z"
        }
    ]
}
```

### Get Kline / Candlestick Data

```
GET /api/v1/market/klines/{symbol}
```

**Parameters:**

| Name | Type | Location | Description |
|------|------|----------|-------------|
| `symbol` | string | path | Trading pair symbol |
| `interval` | string | query | Candlestick interval: `1m`, `5m`, `15m`, `1h`, `4h`, `1d`, `1w` |
| `limit` | integer | query | Number of candles (default: 100, max: 1000) |
| `startTime` | integer | query | Start timestamp (Unix ms) |
| `endTime` | integer | query | End timestamp (Unix ms) |

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "time": 1706140800,
            "open": "67100.00",
            "high": "67300.00",
            "low": "67050.00",
            "close": "67234.50",
            "volume": "12.34"
        }
    ]
}
```

### List Trading Pairs

```
GET /api/v1/market/pairs
```

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "symbol": "BTC/USDT",
            "baseAsset": "BTC",
            "quoteAsset": "USDT",
            "status": "active",
            "minAmount": "0.0001",
            "maxAmount": "100.0000",
            "pricePrecision": 2,
            "amountPrecision": 4
        }
    ]
}
```

---

## Chain API

Public endpoints for blockchain network configuration.

### List All Chains

```
GET /api/v1/chains
```

Returns all supported blockchain networks.

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "name": "Ethereum",
            "shortName": "ETH",
            "chainId": 1,
            "networkId": 1,
            "rpc": [
                "https://eth.llamarpc.com",
                "https://ethereum.publicnode.com"
            ],
            "explorer": "https://etherscan.io",
            "nativeCurrency": {
                "name": "Ether",
                "symbol": "ETH",
                "decimals": 18
            },
            "icon": "https://cryptologos.cc/logos/ethereum-eth-logo.svg",
            "color": "#627EEA",
            "enabled": true
        }
    ]
}
```

### Get Chain Details

```
GET /api/v1/chains/{chainId}
```

**Parameters:**

| Name | Type | Location | Description |
|------|------|----------|-------------|
| `chainId` | integer | path | Chain ID (e.g., `1` for Ethereum, `56` for BSC) |

**Response (success):**
```json
{
    "success": true,
    "data": {
        "name": "BNB Smart Chain",
        "shortName": "BSC",
        "chainId": 56,
        "networkId": 56,
        "rpc": ["https://bsc-dataseed.binance.org", "..."],
        "explorer": "https://bscscan.com",
        "nativeCurrency": {
            "name": "BNB",
            "symbol": "BNB",
            "decimals": 18
        },
        "icon": "https://cryptologos.cc/logos/bnb-bnb-logo.svg",
        "color": "#F3BA2F",
        "enabled": true
    }
}
```

**Response (error -- chain not found):**
```json
{
    "success": false,
    "error": {
        "code": "CHAIN_NOT_FOUND",
        "message": "Chain with ID 999 not found"
    }
}
```

### Get Chain Tokens

```
GET /api/v1/chains/{chainId}/tokens
```

Returns the list of tokens available on the specified chain.

**Parameters:**

| Name | Type | Location | Description |
|------|------|----------|-------------|
| `chainId` | integer | path | Chain ID |

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "address": "0xdAC17F958D2ee523a2206206994597C13D831ec7",
            "name": "Tether USD",
            "symbol": "USDT",
            "decimals": 6,
            "icon": "https://...",
            "verified": true
        }
    ]
}
```

### Get Gas Price

```
GET /api/v1/chains/{chainId}/gas
```

Returns the current gas price for the specified chain.

**Parameters:**

| Name | Type | Location | Description |
|------|------|----------|-------------|
| `chainId` | integer | path | Chain ID |

**Response:**
```json
{
    "success": true,
    "data": {
        "chainId": 1,
        "gasPrice": "20000000000",
        "timestamp": "2024-01-25T12:00:00+07:00"
    }
}
```

---

## Token API

Public endpoints for token information.

### Get Token Info

```
GET /api/v1/tokens/{address}
```

**Parameters:**

| Name | Type | Location | Description |
|------|------|----------|-------------|
| `address` | string | path | Token contract address |

**Response:**
```json
{
    "success": true,
    "data": {
        "address": "0xdAC17F958D2ee523a2206206994597C13D831ec7",
        "name": "Tether USD",
        "symbol": "USDT",
        "decimals": 6,
        "totalSupply": "39823456789000000",
        "chainId": 1,
        "verified": true
    }
}
```

### Get Token Price

```
GET /api/v1/tokens/{address}/price
```

**Parameters:**

| Name | Type | Location | Description |
|------|------|----------|-------------|
| `address` | string | path | Token contract address |

**Response:**
```json
{
    "success": true,
    "data": {
        "address": "0xdAC17F958D2ee523a2206206994597C13D831ec7",
        "symbol": "USDT",
        "priceUsd": "1.0001",
        "change24h": "-0.01",
        "volume24h": "45678901234.56",
        "timestamp": "2024-01-25T12:00:00Z"
    }
}
```

---

## Trading API

Protected endpoints for trading operations. Requires authentication.

### Create Order

```
POST /api/v1/trading/order
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `pair` | string | Yes | Trading pair (e.g., `BTC/USDT`) |
| `side` | string | Yes | Order side: `buy` or `sell` |
| `type` | string | Yes | Order type: `limit`, `market`, or `stop_limit` |
| `amount` | number | Yes | Order quantity in base asset |
| `price` | number | Conditional | Limit price (required for `limit` and `stop_limit`) |
| `stopPrice` | number | Conditional | Stop trigger price (required for `stop_limit`) |
| `chainId` | integer | No | Chain ID (defaults to active chain) |

**Example Request:**
```json
{
    "pair": "BTC/USDT",
    "side": "buy",
    "type": "limit",
    "amount": 0.1,
    "price": 67000.00
}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "orderId": "ord-abc123",
        "pair": "BTC/USDT",
        "side": "buy",
        "type": "limit",
        "amount": "0.10000000",
        "price": "67000.00000000",
        "status": "open",
        "createdAt": "2024-01-25T12:00:00Z"
    }
}
```

### Cancel Order

```
DELETE /api/v1/trading/order/{orderId}
```

**Parameters:**

| Name | Type | Location | Description |
|------|------|----------|-------------|
| `orderId` | string | path | Order ID to cancel |

**Response:**
```json
{
    "success": true,
    "data": {
        "orderId": "ord-abc123",
        "status": "cancelled",
        "cancelledAt": "2024-01-25T12:05:00Z"
    }
}
```

### List Orders

```
GET /api/v1/trading/orders
```

**Query Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `pair` | string | No | Filter by trading pair |
| `status` | string | No | Filter by status: `open`, `partial`, `filled`, `cancelled` |
| `side` | string | No | Filter by side: `buy`, `sell` |
| `page` | integer | No | Page number (default: 1) |
| `per_page` | integer | No | Items per page (default: 20, max: 100) |

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "orderId": "ord-abc123",
            "pair": "BTC/USDT",
            "side": "buy",
            "type": "limit",
            "amount": "0.10000000",
            "price": "67000.00000000",
            "filledAmount": "0.00000000",
            "status": "open",
            "createdAt": "2024-01-25T12:00:00Z"
        }
    ],
    "meta": {
        "page": 1,
        "per_page": 20,
        "total": 5
    }
}
```

### Get Order Details

```
GET /api/v1/trading/order/{orderId}
```

**Parameters:**

| Name | Type | Location | Description |
|------|------|----------|-------------|
| `orderId` | string | path | Order ID |

**Response:**
```json
{
    "success": true,
    "data": {
        "orderId": "ord-abc123",
        "pair": "BTC/USDT",
        "side": "buy",
        "type": "limit",
        "amount": "0.10000000",
        "price": "67000.00000000",
        "filledAmount": "0.05000000",
        "status": "partial",
        "chainId": 1,
        "txHash": "0xabc...",
        "trades": [
            {
                "tradeId": "t-456",
                "price": "67000.00",
                "amount": "0.05",
                "fee": "0.005",
                "timestamp": "2024-01-25T12:01:00Z"
            }
        ],
        "createdAt": "2024-01-25T12:00:00Z",
        "updatedAt": "2024-01-25T12:01:00Z"
    }
}
```

### Get Trade History

```
GET /api/v1/trading/history
```

**Query Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `pair` | string | No | Filter by trading pair |
| `startTime` | string | No | Start date (ISO 8601) |
| `endTime` | string | No | End date (ISO 8601) |
| `page` | integer | No | Page number |
| `per_page` | integer | No | Items per page |

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "tradeId": "t-789",
            "orderId": "ord-abc123",
            "pair": "BTC/USDT",
            "side": "buy",
            "price": "67000.00",
            "amount": "0.10",
            "fee": "0.01",
            "total": "6700.00",
            "txHash": "0xdef...",
            "timestamp": "2024-01-25T12:00:00Z"
        }
    ],
    "meta": {
        "page": 1,
        "per_page": 20,
        "total": 42
    }
}
```

---

## Swap API

Protected endpoints for token swap operations. Requires authentication.

### Get Swap Quote

```
POST /api/v1/swap/quote
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `fromToken` | string | Yes | Source token address |
| `toToken` | string | Yes | Destination token address |
| `amount` | string | Yes | Amount of source token |
| `chainId` | integer | No | Chain ID (defaults to active chain) |
| `slippage` | number | No | Slippage tolerance in % (default: 0.5, max: 5) |

**Example Request:**
```json
{
    "fromToken": "0xEeeeeEeeeEeEeeEeEeEeeEEEeeeeEeeeeeeeEEeE",
    "toToken": "0xdAC17F958D2ee523a2206206994597C13D831ec7",
    "amount": "1000000000000000000",
    "chainId": 1,
    "slippage": 0.5
}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "fromToken": "ETH",
        "toToken": "USDT",
        "fromAmount": "1000000000000000000",
        "toAmount": "67200000000",
        "price": "67200.00",
        "priceImpact": "0.02",
        "route": ["ETH", "WETH", "USDT"],
        "estimatedGas": "150000",
        "expiresAt": "2024-01-25T12:01:00Z"
    }
}
```

### Execute Swap

```
POST /api/v1/swap/execute
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `fromToken` | string | Yes | Source token address |
| `toToken` | string | Yes | Destination token address |
| `amount` | string | Yes | Amount of source token |
| `minReceived` | string | Yes | Minimum amount to receive (slippage protection) |
| `chainId` | integer | No | Chain ID |
| `deadline` | integer | No | Transaction deadline (Unix timestamp) |

**Response:**
```json
{
    "success": true,
    "data": {
        "txHash": "0xabc123...",
        "status": "pending",
        "fromToken": "ETH",
        "toToken": "USDT",
        "fromAmount": "1000000000000000000",
        "toAmount": "67200000000",
        "timestamp": "2024-01-25T12:00:00Z"
    }
}
```

### Get Swap Routes

```
GET /api/v1/swap/routes
```

Returns available swap routing paths for the active chain.

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "dex": "Uniswap V3",
            "router": "0x...",
            "factory": "0x...",
            "chainId": 1,
            "enabled": true
        }
    ]
}
```

---

## Wallet API

Protected endpoints for wallet management. Requires authentication.

### Connect Wallet

```
POST /api/v1/wallet/connect
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `address` | string | Yes | Wallet address (checksummed) |
| `signature` | string | Yes | Signed nonce message |
| `message` | string | Yes | Original nonce message that was signed |
| `chainId` | integer | No | Connected chain ID |

**Response:**
```json
{
    "success": true,
    "data": {
        "address": "0x1234...abcd",
        "chainId": 1,
        "token": "1|abc123...",
        "connectedAt": "2024-01-25T12:00:00Z"
    }
}
```

### Disconnect Wallet

```
POST /api/v1/wallet/disconnect
```

**Response:**
```json
{
    "success": true,
    "data": {
        "message": "Wallet disconnected successfully"
    }
}
```

### Get Balances

```
GET /api/v1/wallet/balances
```

**Query Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `chainId` | integer | No | Filter by chain |

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "token": "ETH",
            "address": "0xEeeeeEeeeEeEeeEeEeEeeEEEeeeeEeeeeeeeEEeE",
            "balance": "2.345678901234567890",
            "balanceUsd": "157654.32",
            "chainId": 1
        },
        {
            "token": "USDT",
            "address": "0xdAC17F958D2ee523a2206206994597C13D831ec7",
            "balance": "10000.000000",
            "balanceUsd": "10000.00",
            "chainId": 1
        }
    ]
}
```

### Get Transactions

```
GET /api/v1/wallet/transactions
```

**Query Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `chainId` | integer | No | Filter by chain |
| `page` | integer | No | Page number |
| `per_page` | integer | No | Items per page |

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "txHash": "0xabc...",
            "type": "swap",
            "from": "ETH",
            "to": "USDT",
            "amount": "1.0",
            "status": "confirmed",
            "chainId": 1,
            "blockNumber": 19012345,
            "timestamp": "2024-01-25T12:00:00Z"
        }
    ],
    "meta": {
        "page": 1,
        "per_page": 20,
        "total": 15
    }
}
```

### Request Signature

```
POST /api/v1/wallet/sign
```

Requests a message to be signed by the connected wallet for authentication purposes.

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `message` | string | Yes | Message to sign |
| `address` | string | Yes | Wallet address |

**Response:**
```json
{
    "success": true,
    "data": {
        "nonce": "TPIX TRADE Authentication\nNonce: abc123\nTimestamp: 2024-01-25T12:00:00Z",
        "expiresAt": "2024-01-25T12:05:00Z"
    }
}
```

---

## AI API

Protected endpoints for AI-powered trading insights. Requires authentication.

### Analyze Market

```
POST /api/v1/ai/analyze
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `symbol` | string | Yes | Trading pair symbol |
| `timeframe` | string | No | Analysis timeframe: `1h`, `4h`, `1d`, `1w` |
| `indicators` | array | No | Technical indicators to include |

**Response:**
```json
{
    "success": true,
    "data": {
        "symbol": "BTC/USDT",
        "timeframe": "4h",
        "sentiment": "bullish",
        "confidence": 0.78,
        "analysis": "Based on the current price action and volume patterns...",
        "indicators": {
            "rsi": 62.5,
            "macd": "bullish_crossover",
            "ma_200": "above"
        },
        "timestamp": "2024-01-25T12:00:00Z"
    }
}
```

### Price Prediction

```
POST /api/v1/ai/predict
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `symbol` | string | Yes | Trading pair symbol |
| `horizon` | string | No | Prediction horizon: `1h`, `4h`, `1d`, `1w` |

**Response:**
```json
{
    "success": true,
    "data": {
        "symbol": "BTC/USDT",
        "currentPrice": "67234.50",
        "predictedPrice": "68500.00",
        "predictedChange": "1.88",
        "confidence": 0.65,
        "horizon": "1d",
        "disclaimer": "This is not financial advice. AI predictions are for informational purposes only.",
        "timestamp": "2024-01-25T12:00:00Z"
    }
}
```

### Trade Suggestions

```
POST /api/v1/ai/suggest
```

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `symbol` | string | Yes | Trading pair symbol |
| `riskLevel` | string | No | Risk tolerance: `low`, `medium`, `high` |
| `budget` | number | No | Available budget in quote currency |

**Response:**
```json
{
    "success": true,
    "data": {
        "symbol": "BTC/USDT",
        "action": "buy",
        "entryPrice": "67000.00",
        "targetPrice": "69500.00",
        "stopLoss": "65500.00",
        "riskReward": "1.67",
        "reasoning": "Strong support at 67000 with increasing volume...",
        "disclaimer": "This is not financial advice.",
        "timestamp": "2024-01-25T12:00:00Z"
    }
}
```

### Get Symbol Insights

```
GET /api/v1/ai/insights/{symbol}
```

**Parameters:**

| Name | Type | Location | Description |
|------|------|----------|-------------|
| `symbol` | string | path | Trading pair symbol |

**Response:**
```json
{
    "success": true,
    "data": {
        "symbol": "BTC/USDT",
        "summary": "Bitcoin is showing strength above the 200-day moving average...",
        "keyLevels": {
            "support": ["65000", "62000", "58000"],
            "resistance": ["70000", "73000", "75000"]
        },
        "trendDirection": "uptrend",
        "volatility": "moderate",
        "volume": "above_average",
        "lastUpdated": "2024-01-25T12:00:00Z"
    }
}
```

---

## WebSocket Events

TPIX TRADE uses Laravel Reverb for real-time updates.

### Connection

```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT,
    forceTLS: false,
    disableStats: true,
});
```

### Public Channels

**Ticker Updates:**
```javascript
echo.channel('market.BTC-USDT')
    .listen('TickerUpdate', (data) => {
        // { symbol, last, high, low, volume, change }
    });
```

**Order Book Updates:**
```javascript
echo.channel('orderbook.BTC-USDT')
    .listen('OrderBookUpdate', (data) => {
        // { bids: [[price, amount]], asks: [[price, amount]] }
    });
```

**Trade Stream:**
```javascript
echo.channel('trades.BTC-USDT')
    .listen('NewTrade', (data) => {
        // { price, amount, side, timestamp }
    });
```

### Private Channels

**User Order Updates:**
```javascript
echo.private(`user.${walletAddress}`)
    .listen('OrderUpdate', (data) => {
        // { orderId, status, filledAmount, ... }
    });
```

---

## Health Check

### Application Health

```
GET /health
```

No authentication required.

**Response:**
```json
{
    "status": "healthy",
    "app": "TPIX TRADE",
    "version": "1.0.0",
    "timestamp": "2024-01-25T12:00:00+07:00"
}
```

### Static Health Check

```
GET /health.php
```

A static PHP health check file that can be used by load balancers and monitoring services without bootstrapping the full Laravel application.

---

## Supported Chains Quick Reference

| Chain ID | Name | Short | Explorer |
|----------|------|-------|----------|
| 1 | Ethereum | ETH | etherscan.io |
| 56 | BNB Smart Chain | BSC | bscscan.com |
| 137 | Polygon | MATIC | polygonscan.com |
| 42161 | Arbitrum One | ARB | arbiscan.io |
| 10 | Optimism | OP | optimistic.etherscan.io |
| 43114 | Avalanche C-Chain | AVAX | snowtrace.io |
| 250 | Fantom | FTM | ftmscan.com |
| 8453 | Base | BASE | basescan.org |
| 324 | zkSync Era | ZKSYNC | explorer.zksync.io |

**Testnets (development only):**

| Chain ID | Name | Short |
|----------|------|-------|
| 11155111 | Sepolia | SEP |
| 97 | BSC Testnet | tBNB |

---

<p align="center">
  <strong><a href="https://xmanstudio.com">Xman Studio</a></strong> -- TPIX TRADE API Reference
</p>
