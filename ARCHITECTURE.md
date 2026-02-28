# Architecture Overview

ThaiXTrade system architecture and technical design documentation.

---

## Table of Contents

- [System Overview](#system-overview)
- [Technology Stack](#technology-stack)
- [Frontend Architecture](#frontend-architecture)
- [Backend Architecture](#backend-architecture)
- [Database Design](#database-design)
- [API Design](#api-design)
- [Web3 Integration](#web3-integration)
- [Real-Time Features](#real-time-features)
- [Security Architecture](#security-architecture)
- [Performance Considerations](#performance-considerations)
- [Deployment Architecture](#deployment-architecture)

---

## System Overview

```
+------------------------------------------------------------------+
|                        CLIENT BROWSER                             |
|                                                                   |
|   +------------------+  +------------------+  +---------------+   |
|   |   Vue 3 SPA      |  |  Ethers.js /     |  | WalletConnect |   |
|   |   (Inertia.js)   |  |  Web3.js         |  | Provider      |   |
|   +--------+---------+  +--------+---------+  +-------+-------+   |
|            |                      |                    |           |
+------------|----------------------|--------------------|-----------+
             |                      |                    |
             v                      v                    v
+---------------------------+  +----------------------------+
|     LARAVEL 11 SERVER     |  |   BLOCKCHAIN NETWORKS      |
|                           |  |                            |
|  +---------------------+  |  |  +-------+  +---------+   |
|  | Inertia.js Bridge   |  |  |  |  ETH  |  |   BSC   |   |
|  +---------------------+  |  |  +-------+  +---------+   |
|  | API Controllers     |  |  |  +-------+  +---------+   |
|  |  - Market           |  |  |  | Polygon| | Arbitrum|   |
|  |  - Trading          |  |  |  +-------+  +---------+   |
|  |  - Wallet           |  |  |  +-------+  +---------+   |
|  |  - Chain            |  |  |  |  AVAX  |  |  Base   |   |
|  |  - AI               |  |  |  +-------+  +---------+   |
|  +---------------------+  |  |  ... and 50+ more chains   |
|  | Service Layer       |  |  +----------------------------+
|  +---------------------+  |
|  | Eloquent ORM        |  |  +----------------------------+
|  +---------------------+  |  |   EXTERNAL SERVICES        |
|  | Laravel Reverb WS   |  |  |                            |
|  +---------------------+  |  |  +-------+  +---------+   |
|            |               |  |  |OpenAI |  | Infura  |   |
+------------|---------------+  |  +-------+  +---------+   |
             |                  |  +-------+  +---------+   |
             v                  |  |Alchemy|  | CoinGecko|  |
+---------------------------+   |  +-------+  +---------+   |
|     DATABASE              |   +----------------------------+
|  SQLite / MySQL / PgSQL   |
+---------------------------+
```

### Request Flow

1. **Page Navigation**: Browser requests go through Inertia.js, which renders Vue 3 pages server-side via Laravel and hydrates them client-side for SPA behavior.
2. **API Calls**: Frontend JavaScript calls `/api/v1/*` endpoints for market data, trading operations, and wallet interactions.
3. **Blockchain Interaction**: Web3 operations (signing, transaction submission) happen directly in the browser via Ethers.js/Web3.js connected to the user's wallet.
4. **Real-Time Updates**: Laravel Reverb pushes live data (prices, trades, order book changes) to the frontend via WebSocket connections.

---

## Technology Stack

### Backend

| Component | Technology | Version | Purpose |
|-----------|-----------|---------|---------|
| Framework | Laravel | 11.x | Application framework, routing, middleware |
| Language | PHP | 8.2+ | Server-side logic |
| ORM | Eloquent | -- | Database abstraction and model layer |
| Auth | Sanctum | -- | API token authentication |
| WebSocket | Laravel Reverb | -- | Real-time event broadcasting |
| Queue | Laravel Queue | -- | Background job processing |
| Cache | File / Redis | -- | Response and data caching |
| Linting | Laravel Pint | -- | PSR-12 code formatting |

### Frontend

| Component | Technology | Version | Purpose |
|-----------|-----------|---------|---------|
| Framework | Vue.js | 3.4 | Reactive component-based UI |
| Bridge | Inertia.js | 1.0 | SPA without separate API for pages |
| State | Pinia | 2.1 | Centralized state management |
| Styling | TailwindCSS | 3.4 | Utility-first CSS framework |
| Build Tool | Vite | 5.1 | Fast development server and bundler |
| Charts | Lightweight Charts | 4.1 | TradingView-style candlestick charts |
| Charts | ApexCharts + vue-chartjs | -- | Additional chart types |
| Web3 | Ethers.js | 6.11 | Blockchain wallet and contract interaction |
| Web3 | Web3.js | 4.5 | Alternative Web3 library |
| Wallet | WalletConnect | 1.8 | Multi-wallet connection protocol |
| UI Kit | Headless UI | 1.7 | Accessible unstyled UI primitives |
| Icons | Heroicons | 2.1 | SVG icon library |
| i18n | vue-i18n | 9.9 | Internationalization |
| Utilities | VueUse | 10.7 | Collection of Vue composition utilities |
| QR Codes | qrcode | 1.5 | QR code generation for wallet connections |
| Toast | vue-toastification | 2.0-rc | Notification toasts |

### Testing

| Component | Technology | Purpose |
|-----------|-----------|---------|
| PHP Tests | PHPUnit | Backend feature and unit tests |
| JS Tests | Vitest | Frontend component and utility tests |
| Component | @vue/test-utils | Vue component mounting and assertions |
| Coverage | @vitest/coverage-v8 | Code coverage reporting |
| DOM | jsdom | Browser environment simulation |

### DevOps

| Component | Technology | Purpose |
|-----------|-----------|---------|
| Task Runner | Makefile | Standardized development commands |
| Version | bump-version.sh | Semantic versioning automation |
| Deploy | deploy.sh | Production deployment pipeline |
| CI Scripts | scripts/ | Testing, auditing, backup utilities |

---

## Frontend Architecture

### Directory Layout

```
resources/js/
├── app.js                  # Vue application bootstrap + Inertia setup
├── Components/             # Reusable Vue components
│   ├── Navigation/         # NavBar, Sidebar
│   ├── Trading/            # TradingChart, OrderBook, TradeForm, etc.
│   └── Wallet/             # WalletModal
├── Composables/            # Shared reactive logic (useWallet, useChain, etc.)
├── Layouts/
│   └── AppLayout.vue       # Main app shell (nav + sidebar + content area)
├── Pages/                  # Inertia page components (routed via Laravel)
│   ├── Home.vue            # Landing / dashboard
│   ├── Trade.vue           # Trading interface
│   ├── Swap.vue            # Token swap
│   ├── Markets/            # Market overview pages
│   ├── Portfolio.vue       # User portfolio
│   ├── AIAssistant.vue     # AI insights dashboard
│   └── Settings.vue        # User settings
└── Stores/                 # Pinia state stores
```

### Component Architecture

Components follow a hierarchical pattern with clear data flow:

```
AppLayout.vue
├── NavBar.vue                  (global navigation, wallet status)
├── Sidebar.vue                 (market list, chain selector)
└── <Page>.vue                  (routed content)
    ├── TickerStrip.vue         (live price ticker)
    ├── TradingChart.vue        (candlestick chart -- Lightweight Charts)
    ├── OrderBook.vue           (bid/ask visualization)
    ├── TradeForm.vue           (buy/sell order entry)
    ├── RecentTrades.vue        (live trade stream)
    ├── OpenOrders.vue          (user's active orders)
    └── TradeHistory.vue        (user's completed trades)
```

### State Management (Pinia)

Pinia stores are organized by domain:

| Store | Responsibility |
|-------|---------------|
| `useWalletStore` | Connected wallet address, chain, provider instance |
| `useTradingStore` | Current pair, order book data, recent trades |
| `useMarketStore` | Ticker data, price feeds, market list |
| `useChainStore` | Active chain, supported chains list, gas prices |
| `useUIStore` | Theme, sidebar state, notification preferences |

### Composables

Vue composables extract reusable reactive logic:

| Composable | Purpose |
|------------|---------|
| `useWallet()` | Wallet connection, signing, disconnection |
| `useChain()` | Chain switching, network detection |
| `useWeb3()` | Contract interaction, transaction building |
| `useWebSocket()` | Reverb connection, channel subscriptions |
| `usePriceFormat()` | Consistent number and currency formatting |

### Routing

Inertia.js merges Laravel's server-side routing with Vue's client-side rendering:

```
Laravel Route (web.php)           Vue Page Component
─────────────────────────         ──────────────────
GET /                      -->    Pages/Home.vue
GET /trade                 -->    Pages/Trade.vue
GET /trade/{pair}          -->    Pages/Trade.vue (with pair prop)
GET /swap                  -->    Pages/Swap.vue
GET /markets               -->    Pages/Markets/Index.vue
GET /markets/spot          -->    Pages/Markets/Spot.vue
GET /markets/defi          -->    Pages/Markets/DeFi.vue
GET /markets/nft           -->    Pages/Markets/NFT.vue
GET /portfolio             -->    Pages/Portfolio.vue
GET /ai-assistant          -->    Pages/AIAssistant.vue
GET /settings              -->    Pages/Settings.vue
```

---

## Backend Architecture

### Directory Layout

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Controller.php          # Base controller
│   │   └── Api/
│   │       ├── AIController.php    # AI analysis endpoints
│   │       ├── ChainController.php # Blockchain configuration
│   │       ├── MarketController.php# Market data
│   │       ├── TradingController.php # Trading operations
│   │       └── WalletController.php# Wallet management
│   ├── Middleware/                  # Request middleware
│   └── Requests/                   # Form request validation
├── Models/                         # Eloquent models
├── Services/                       # Business logic services
└── Providers/                      # Service providers
```

### Controller Responsibilities

| Controller | Endpoints | Responsibility |
|-----------|-----------|---------------|
| `MarketController` | `/api/v1/market/*` | Tickers, order books, klines, pairs, token info |
| `TradingController` | `/api/v1/trading/*`, `/api/v1/swap/*` | Order CRUD, swap quotes, swap execution |
| `WalletController` | `/api/v1/wallet/*` | Connection, balances, transactions, signing |
| `ChainController` | `/api/v1/chains/*` | Chain listing, tokens per chain, gas prices |
| `AIController` | `/api/v1/ai/*` | Market analysis, predictions, suggestions |

### Service Layer Pattern

Business logic is extracted from controllers into dedicated service classes:

```
Controller --> Service --> Repository/Model
                       --> External API
                       --> Cache Layer
```

Services handle:
- Complex trading logic (order matching, fee calculation)
- Blockchain interaction (RPC calls, transaction building)
- External API integration (price feeds, AI providers)
- Data transformation and validation

### Configuration Architecture

Blockchain chain configuration is centralized in `config/chains.php`:

```php
// Each chain entry contains:
[
    'name'           => 'Ethereum',
    'shortName'      => 'ETH',
    'chainId'        => 1,
    'networkId'      => 1,
    'rpc'            => ['https://...', ...],   // Fallback RPC list
    'explorer'       => 'https://etherscan.io',
    'nativeCurrency' => ['name' => 'Ether', 'symbol' => 'ETH', 'decimals' => 18],
    'icon'           => 'https://...',
    'color'          => '#627EEA',
    'enabled'        => true,
]
```

Adding a new chain requires only adding an entry to this configuration file.

---

## Database Design

### Supported Databases

ThaiXTrade supports multiple database backends:

| Database | Use Case |
|----------|----------|
| **SQLite** | Development, single-server deployments |
| **MySQL** | Production, multi-server setups |
| **PostgreSQL** | Production, advanced query requirements |

### Core Tables (Planned)

```
users
├── id                  PK
├── wallet_address      UNIQUE, indexed
├── email               nullable
├── name                nullable
├── two_factor_enabled  boolean
├── created_at
└── updated_at

orders
├── id                  PK
├── user_id             FK -> users
├── pair                string (e.g., "BTC/USDT")
├── side                enum (buy, sell)
├── type                enum (limit, market, stop_limit)
├── amount              decimal(24,8)
├── price               decimal(24,8), nullable
├── stop_price          decimal(24,8), nullable
├── filled_amount       decimal(24,8)
├── status              enum (open, partial, filled, cancelled)
├── chain_id            integer
├── tx_hash             string, nullable
├── created_at
└── updated_at

trades
├── id                  PK
├── order_id            FK -> orders
├── buyer_id            FK -> users
├── seller_id           FK -> users
├── pair                string
├── price               decimal(24,8)
├── amount              decimal(24,8)
├── fee                 decimal(24,8)
├── chain_id            integer
├── tx_hash             string
├── created_at
└── updated_at

tokens
├── id                  PK
├── chain_id            integer
├── address             string
├── name                string
├── symbol              string
├── decimals            integer
├── icon                string, nullable
├── verified            boolean
├── created_at
└── updated_at
```

### Cache Layer

The cache table migration exists for session and cache storage:

```
cache
├── key                 VARCHAR, PK
├── value               MEDIUMTEXT
└── expiration          INTEGER
```

---

## API Design

### Principles

1. **RESTful**: Resource-oriented URL structure with standard HTTP methods.
2. **Versioned**: All endpoints under `/api/v1/` for forward compatibility.
3. **Consistent Envelope**: Every response wraps data in a standard format.
4. **Rate Limited**: Configurable per-minute throttling for all endpoints.

### Response Envelope

```json
// Success response
{
    "success": true,
    "data": { ... },
    "meta": {
        "page": 1,
        "per_page": 20,
        "total": 100
    }
}

// Error response
{
    "success": false,
    "error": {
        "code": "CHAIN_NOT_FOUND",
        "message": "Chain with ID 999 not found"
    }
}
```

### Route Groups

| Group | Middleware | Purpose |
|-------|-----------|---------|
| Public (`/api/v1/market`, `/api/v1/chains`, `/api/v1/tokens`) | `throttle:60,1` | Read-only market data |
| Protected (`/api/v1/trading`, `/api/v1/wallet`, `/api/v1/swap`, `/api/v1/ai`) | `throttle:trading` | Authenticated trading operations |

Full API reference: [API.md](API.md)

---

## Web3 Integration

### Architecture

```
+-------------------+     +------------------+     +----------------+
|  User's Wallet    |<--->|  Browser JS      |<--->| Smart Contracts|
|  (MetaMask, etc.) |     |  (Ethers.js)     |     | (on-chain)     |
+-------------------+     +--------+---------+     +----------------+
                                   |
                          +--------v---------+
                          |  Laravel Backend  |
                          |  (verification,   |
                          |   indexing, cache) |
                          +------------------+
```

### Key Design Decisions

1. **Non-Custodial**: ThaiXTrade never stores private keys. All signing happens in the user's wallet (browser extension or WalletConnect).
2. **Server-Side Verification**: The Laravel backend verifies wallet signatures to authenticate users without passwords.
3. **Multi-Provider RPC**: Each chain has multiple RPC endpoints for failover.
4. **Client-Side Signing**: Transaction creation and signing use Ethers.js in the browser, then broadcast to the blockchain.

### Libraries Used

| Library | Version | Purpose |
|---------|---------|---------|
| **ethers** | 6.11 | Primary Web3 library -- wallet connection, contract interaction, transaction building |
| **web3** | 4.5 | Secondary library -- used for specific utilities and compatibility |
| **@walletconnect/web3-provider** | 1.8 | WalletConnect protocol support for mobile and hardware wallets |

### Wallet Connection Flow

```
1. User clicks "Connect Wallet"
2. WalletModal.vue shows wallet options (MetaMask, WalletConnect, etc.)
3. Selected provider is initialized:
   - MetaMask: window.ethereum provider injection
   - WalletConnect: QR code scan or deep link
4. Ethers.js BrowserProvider wraps the wallet provider
5. User signs a nonce message to prove wallet ownership
6. Backend verifies signature and creates/retrieves session
7. Wallet address and chain ID stored in Pinia store
```

### Chain Switching

```
1. User selects new chain from UI
2. Frontend calls wallet_switchEthereumChain via provider
3. If chain not added: wallet_addEthereumChain with config from chains.php
4. ChainStore updates active chain ID
5. UI refreshes balances and market data for new chain
```

---

## Real-Time Features

### Laravel Reverb WebSocket

ThaiXTrade uses Laravel Reverb for real-time event broadcasting:

```
+------------------+         +------------------+         +----------------+
|  Data Source     |  Event  | Laravel Reverb   |   WS    |  Vue Frontend  |
|  (API, Chain,   |-------->| WebSocket Server |-------->|  (subscriber)  |
|   Scheduler)    |         | (port 8080)      |         |                |
+------------------+         +------------------+         +----------------+
```

### Channel Structure

| Channel | Type | Purpose |
|---------|------|---------|
| `market.{symbol}` | Public | Live price tickers, kline updates |
| `orderbook.{symbol}` | Public | Order book bid/ask changes |
| `trades.{symbol}` | Public | Recent trade stream |
| `user.{walletAddress}` | Private | User-specific order updates, balances |

### Configuration

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=thaixtrade
REVERB_APP_KEY=thaixtrade-key
REVERB_APP_SECRET=thaixtrade-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

Frontend connects via Vite-exposed environment variables:
```env
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

---

## Security Architecture

### Layers of Defense

```
+---------------------------------------------------------------+
|                    SECURITY LAYERS                              |
|                                                                 |
|  1. Network Layer                                               |
|     - HTTPS everywhere                                          |
|     - CSP (Content Security Policy) headers                     |
|     - Rate limiting (60 req/min general, 100 req/min trading)   |
|                                                                 |
|  2. Application Layer                                           |
|     - CSRF protection on all forms                              |
|     - XSS prevention (Blade auto-escaping)                      |
|     - Input validation (Laravel Form Requests)                  |
|     - Parameterized queries (Eloquent ORM)                      |
|     - Session encryption                                        |
|                                                                 |
|  3. Authentication Layer                                        |
|     - Wallet signature verification (ECDSA)                     |
|     - Laravel Sanctum API tokens                                |
|     - Two-factor authentication (TOTP)                          |
|     - Social OAuth (Google, Twitter, Discord, Telegram)         |
|                                                                 |
|  4. Cryptographic Layer                                         |
|     - AES-256 encryption for sensitive data                     |
|     - HMAC-SHA256 request signing                               |
|     - Bcrypt password hashing (12 rounds)                       |
|     - Non-custodial design (no private key storage)             |
|                                                                 |
|  5. Operational Layer                                           |
|     - Security audit scripts (make audit)                       |
|     - Dependency vulnerability scanning                          |
|     - Environment-based configuration                           |
|     - No secrets in version control                             |
+---------------------------------------------------------------+
```

### Critical Security Rules

1. **Never store private keys** on the server.
2. **Never log sensitive data** (wallet keys, signatures, tokens).
3. **Never expose internal errors** in production responses.
4. **Never trust client-side validation alone** -- always validate on the server.
5. **Always validate wallet signatures** server-side before trusting identity.
6. **Always use parameterized queries** via Eloquent or Query Builder.

### Rate Limiting

| Endpoint Group | Limit |
|---------------|-------|
| General API | 60 requests per minute |
| Trading operations | 100 requests per minute |

Configured via environment variables:
```env
RATE_LIMIT_PER_MINUTE=60
RATE_LIMIT_TRADING_PER_MINUTE=100
```

---

## Performance Considerations

### Frontend Performance

| Technique | Implementation |
|-----------|---------------|
| **Code Splitting** | Vite automatic chunk splitting per route |
| **Lazy Loading** | Dynamic `import()` for heavy components (charts, wallet modals) |
| **Tree Shaking** | Vite removes unused exports from bundles |
| **Asset Optimization** | Vite minifies CSS/JS, optimizes images |
| **CSS Purging** | TailwindCSS purges unused classes in production |

### Backend Performance

| Technique | Implementation |
|-----------|---------------|
| **Route Caching** | `php artisan route:cache` in production |
| **Config Caching** | `php artisan config:cache` in production |
| **View Caching** | `php artisan view:cache` in production |
| **Query Caching** | Redis or file cache for expensive queries |
| **Octane Support** | Laravel Octane for persistent application state |
| **Queue Workers** | Background processing for non-blocking operations |

### Caching Strategy

```
Request --> Route Cache --> Config Cache --> Application Cache --> Database
                                                  |
                                                  v
                                          Redis / File Cache
                                          (price data, chain info,
                                           token metadata)
```

Key cache targets:
- **Chain configuration**: Rarely changes, cached aggressively
- **Token metadata**: Cached with TTL (e.g., 5 minutes)
- **Price data**: Short TTL (10-30 seconds) or real-time via WebSocket
- **Gas prices**: Short TTL (15 seconds)

---

## Deployment Architecture

### Production Stack

```
                    +------------------+
                    |   Load Balancer  |
                    |   (Nginx/Caddy)  |
                    +--------+---------+
                             |
              +--------------+--------------+
              |                             |
    +---------v----------+       +----------v---------+
    |  Laravel App       |       |  Laravel App        |
    |  (PHP-FPM/Octane)  |       |  (PHP-FPM/Octane)   |
    +--------+-----------+       +----------+----------+
             |                              |
    +--------v------------------------------v----------+
    |              Shared Resources                     |
    |  +----------+  +----------+  +----------------+  |
    |  | Database |  |  Redis   |  | Laravel Reverb |  |
    |  | (MySQL/  |  | (Cache,  |  | (WebSocket)    |  |
    |  |  PgSQL)  |  |  Queue)  |  |                |  |
    |  +----------+  +----------+  +----------------+  |
    +--------------------------------------------------+
```

### Deployment Commands

```bash
# Standard deployment
make deploy

# Quick deployment (skip some checks)
make deploy-quick

# Create backup before deployment
make backup

# Rollback if needed
make rollback
```

### Document Root

ThaiXTrade uses `public_html/` as the document root instead of the Laravel default `public/`. This accommodates shared hosting environments. The entry point is `public_html/index.php`.

---

<p align="center">
  <strong><a href="https://xmanstudio.com">Xman Studio</a></strong> -- ThaiXTrade Architecture Document
</p>
