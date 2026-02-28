<p align="center">
  <img src="logo.png" alt="TPIX TRADE" width="120" height="120" style="border-radius: 24px;">
</p>

<h1 align="center">TPIX TRADE</h1>

<p align="center">
  <strong>Non-Custodial Decentralized Exchange Platform</strong><br>
  <em>Trade directly from your wallet across 50+ blockchains with AI-powered insights</em>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Version-1.0.19-06b6d4?style=for-the-badge" alt="Version">
  <img src="https://img.shields.io/badge/Laravel-11-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel">
  <img src="https://img.shields.io/badge/Vue.js-3.4-4FC08D?style=for-the-badge&logo=vuedotjs&logoColor=white" alt="Vue.js">
  <img src="https://img.shields.io/badge/Solidity-0.8.20-363636?style=for-the-badge&logo=solidity&logoColor=white" alt="Solidity">
  <img src="https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP">
</p>

<p align="center">
  <img src="https://img.shields.io/badge/TailwindCSS-3.4-06B6D4?style=flat-square&logo=tailwindcss&logoColor=white" alt="TailwindCSS">
  <img src="https://img.shields.io/badge/Inertia.js-1.0-9553E9?style=flat-square" alt="Inertia.js">
  <img src="https://img.shields.io/badge/Ethers.js-6.11-3C3C3D?style=flat-square&logo=ethereum&logoColor=white" alt="Ethers.js">
  <img src="https://img.shields.io/badge/Vite-5-646CFF?style=flat-square&logo=vite&logoColor=white" alt="Vite">
  <img src="https://img.shields.io/badge/Pinia-2.1-ffd859?style=flat-square" alt="Pinia">
  <img src="https://img.shields.io/badge/License-Proprietary-8b5cf6?style=flat-square" alt="License">
</p>

---

## Overview

TPIX TRADE is a production-grade decentralized exchange (DEX) platform that enables users to trade cryptocurrencies directly from their wallets. Built with a distinctive **Purple / Cyan / Orange** gradient brand identity, it delivers a professional trading experience with real-time charts, order book visualization, AI-powered market insights, and an on-chain fee collection system via the **TPIX Router** smart contract.

> **Live**: [tpix.online](https://tpix.online)

---

## Key Features

### Trading Engine
- Real-time candlestick charts with TradingView-style interface (Lightweight Charts)
- Order book with bid/ask depth visualization
- Limit, market, and stop-limit order types
- Recent trades feed with live WebSocket updates
- Open orders management and trade history with CSV export

### Token Swap & Fee Collection
- One-click token swap with optimized DEX routing
- **TPIX Router** smart contract wrapping Uniswap V2-compatible routers
- Configurable platform fee (default 0.3%) collected on-chain
- Fee hierarchy: Trading Pair override > Chain-specific > Global default
- Transparent fee breakdown shown before every swap
- Admin-configurable fee collector wallet address

### Multi-Chain Support
- **50+ blockchains** preconfigured: Ethereum, BNB Smart Chain, Polygon, Arbitrum, Optimism, Avalanche, Fantom, Base, zkSync Era, **Bitkub Chain**, and more
- Testnet support (Sepolia, BSC Testnet) for development
- Per-chain RPC endpoints with automatic fallback
- Easy chain addition via `config/chains.php`

### Wallet Integration
- MetaMask, WalletConnect v2, Trust Wallet, Coinbase Wallet
- Non-custodial architecture -- users always control their keys
- QR code generation for wallet addresses

### AI-Powered Insights
- Market analysis and trend detection via Groq LLM
- Price prediction models
- Trade suggestions per symbol
- AI news aggregation and admin management

### Real-Time Data
- WebSocket broadcasting via Laravel Reverb
- Live ticker strip across all trading pairs
- Real-time order book and trade feed updates

### Admin Panel (18 pages)
- Dashboard with volume, transactions, chains, pairs, and ticket stats
- Chain, token, and trading pair CRUD management
- Fee configuration with maker/taker models and per-chain overrides
- Swap configuration per chain (router address, slippage, factory)
- Trading settings with fee collector wallet, default/max fee rates
- AI analysis & news management
- Multi-language translations (Thai/English + Google Translate)
- Support ticket system with threaded messages
- Audit log with full admin action tracking
- Site settings (General, SEO, Trading, Security, Social)

### Platform
- Glass Morphism dark theme with triple-gradient brand identity
- Responsive design (mobile-first)
- Multi-language support (Thai primary, English fallback)
- Cloudflare Turnstile bot protection
- Two-factor authentication (2FA) support

---

## Tech Stack

| Layer | Technology | Version | Purpose |
|-------|-----------|---------|---------|
| **Backend** | Laravel | 11.x | API, routing, business logic, Inertia SSR |
| **Frontend** | Vue.js 3 | 3.4 | Composition API, reactive UI components |
| **Bridge** | Inertia.js | 1.0 | SPA without a separate API layer |
| **Styling** | TailwindCSS | 3.4 | Utility-first CSS, Glass Morphism theme |
| **Build** | Vite | 5.x | Fast HMR and optimized production builds |
| **State** | Pinia | 2.1 | Centralized Vue state management |
| **Charts** | Lightweight Charts | 4.1 | TradingView-style candlestick charts |
| **Charts** | ApexCharts + Chart.js | 3.46 / 4.4 | Dashboard analytics and stats |
| **Web3** | Ethers.js 6 + Web3.js 4 | 6.11 / 4.5 | Blockchain interaction and signing |
| **Wallet** | WalletConnect v2 | 1.8 | Multi-wallet connection protocol |
| **WebSocket** | Laravel Reverb | 1.0 | Real-time event broadcasting |
| **AI** | Groq API | - | LLM-powered market analysis |
| **Smart Contract** | Solidity | 0.8.20 | TPIX Router fee collection |
| **Testing** | PHPUnit + Vitest | 11 / 1.2 | Backend and frontend testing |
| **Linting** | Laravel Pint + ESLint | 1.13 | Code style enforcement |
| **Icons** | Heroicons + Headless UI | 2.1 / 1.7 | Accessible UI primitives |
| **i18n** | Vue I18n | 9.9 | Internationalization framework |
| **Notifications** | Vue Toastification | 2.0 | Toast notification system |

---

## Architecture

```
                                    TPIX TRADE Architecture

    +------------------+     +------------------+     +-------------------+
    |   Vue.js 3 SPA   |     |   Inertia.js     |     |   Laravel 11      |
    |                   |<--->|   (Bridge)       |<--->|   Backend         |
    |  - Pages (21)     |     |                  |     |  - Controllers    |
    |  - Components     |     |  Props & Router  |     |  - Services       |
    |  - Pinia Stores   |     |                  |     |  - Models (15)    |
    +--------+----------+     +------------------+     +--------+----------+
             |                                                  |
             v                                                  v
    +------------------+                              +-------------------+
    |   Ethers.js /    |                              |   MySQL / SQLite  |
    |   Web3.js        |                              |   Database        |
    |                  |                              |                   |
    |  Wallet Connect  |                              |  18 Migrations    |
    +--------+---------+                              +-------------------+
             |
             v
    +---------------------------------------------+
    |           Blockchain Networks                |
    |                                             |
    |  +----------+  +---------+  +----------+   |
    |  | Ethereum |  |   BSC   |  | Polygon  |   |
    |  +----------+  +---------+  +----------+   |
    |  +----------+  +---------+  +----------+   |
    |  | Arbitrum |  |  Base   |  | Bitkub   |   |
    |  +----------+  +---------+  +----------+   |
    |              ... 50+ chains                  |
    +---------------------+------------------------+
                          |
                          v
              +-----------------------+
              |   TPIX Router         |
              |   Smart Contract      |
              |                       |
              |  Fee: 0.3% (default)  |
              |  Max:  5.0%           |
              |  Pausable + Emergency |
              +-----------------------+
```

---

## Smart Contract: TPIX Router

The `TPIXRouter.sol` is a production-grade Solidity smart contract that wraps Uniswap V2-compatible routers (PancakeSwap, SushiSwap, etc.) to collect platform fees on-chain.

| Feature | Description |
|---------|-------------|
| **Fee Model** | Basis-point fee deducted from input token before routing to DEX |
| **Default Fee** | 30 basis points (0.3%) |
| **Max Fee Cap** | 500 basis points (5%) -- enforced in contract |
| **Swap Types** | ERC20-to-ERC20, Native-to-ERC20, ERC20-to-Native |
| **Security** | `ReentrancyGuard`, `Ownable`, `Pausable`, `SafeERC20` |
| **USDT Safe** | Uses `forceApprove` for non-standard ERC20 tokens |
| **Emergency** | Owner can withdraw stuck tokens/native currency |
| **Events** | `SwapExecuted`, `FeeCollected`, `FeeRateUpdated`, `FeeCollectorUpdated` |

```
contracts/
  TPIXRouter.sol                 # Main fee-collecting router
  interfaces/
    IUniswapV2Router02.sol       # Uniswap V2 Router interface
```

---

## Project Structure

```
ThaiXTrade/
├── app/
│   ├── Console/Commands/           # Artisan CLI commands
│   │   └── CreateAdminUser.php     # Create admin accounts
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/              # 13 admin panel controllers
│   │   │   │   ├── DashboardController.php
│   │   │   │   ├── ChainController.php
│   │   │   │   ├── TokenController.php
│   │   │   │   ├── FeeController.php
│   │   │   │   ├── SettingController.php
│   │   │   │   └── ...
│   │   │   └── Api/                # 6 REST API controllers
│   │   │       ├── MarketController.php
│   │   │       ├── TradingController.php
│   │   │       ├── SwapApiController.php
│   │   │       ├── WalletController.php
│   │   │       ├── ChainController.php
│   │   │       └── AIController.php
│   │   └── Middleware/             # 6 middleware classes
│   ├── Models/                     # 15 Eloquent models
│   │   ├── Chain.php
│   │   ├── Token.php
│   │   ├── TradingPair.php
│   │   ├── FeeConfig.php
│   │   ├── SwapConfig.php
│   │   ├── Transaction.php
│   │   ├── SiteSetting.php
│   │   └── ...
│   ├── Services/
│   │   ├── FeeCalculationService.php   # Fee hierarchy logic
│   │   └── GroqService.php             # AI LLM integration
│   └── Providers/
├── contracts/                      # Solidity smart contracts
│   ├── TPIXRouter.sol
│   └── interfaces/
│       └── IUniswapV2Router02.sol
├── config/
│   └── chains.php                  # 50+ blockchain configurations
├── database/
│   └── migrations/                 # 18 migration files
├── resources/
│   ├── css/app.css                 # TailwindCSS + brand design system
│   └── js/
│       ├── app.js                  # Application entry point
│       ├── Components/
│       │   ├── Admin/              # DataTable, Modal, StatCard, etc.
│       │   ├── Navigation/         # NavBar, Sidebar
│       │   ├── Trading/            # Chart, OrderBook, TradeForm, etc.
│       │   └── Wallet/             # WalletModal
│       ├── Layouts/
│       │   ├── AppLayout.vue       # Main app layout
│       │   └── AdminLayout.vue     # Admin panel layout
│       ├── Pages/
│       │   ├── Home.vue            # Landing page
│       │   ├── Trade.vue           # Trading interface
│       │   ├── Swap.vue            # Token swap with fee display
│       │   └── Admin/              # 18 admin pages
│       └── utils/                  # Utility functions
├── routes/
│   ├── api.php                     # REST API routes
│   └── web.php                     # Inertia web routes
├── tests/
│   ├── Feature/                    # Integration tests
│   ├── Unit/                       # Unit tests
│   └── js/                         # Vue/JS tests (Vitest)
├── scripts/                        # DevOps automation
├── public_html/                    # Document root
│   ├── build/                      # Vite production output
│   ├── logo.png                    # Brand logo
│   └── favicon.svg                 # Brand favicon
├── tailwind.config.js              # Theme configuration
├── vite.config.js                  # Build configuration
├── pint.json                       # PHP code style rules
├── Makefile                        # Development commands
├── package.json                    # NPM dependencies
├── composer.json                   # PHP dependencies
└── version.json                    # Version tracking
```

---

## API Reference

All API endpoints live under `/api/v1/` and return a consistent JSON envelope:

```json
{
  "success": true,
  "data": { ... }
}
```

### Public Endpoints (No Auth)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/v1/market/tickers` | All trading pair tickers |
| `GET` | `/v1/market/ticker/{symbol}` | Single pair ticker |
| `GET` | `/v1/market/orderbook/{symbol}` | Order book depth |
| `GET` | `/v1/market/trades/{symbol}` | Recent trades |
| `GET` | `/v1/market/klines/{symbol}` | Candlestick data |
| `GET` | `/v1/market/pairs` | All trading pairs |
| `GET` | `/v1/chains` | Supported blockchains |
| `GET` | `/v1/chains/{id}/tokens` | Tokens on a chain |
| `GET` | `/v1/chains/{id}/gas` | Gas price estimates |
| `GET` | `/v1/tokens/{address}` | Token information |
| `GET` | `/v1/tokens/{address}/price` | Token price |
| `GET` | `/v1/swap/quote` | Swap quote with fee breakdown |
| `GET` | `/v1/swap/routes` | Available swap routes |

### Protected Endpoints (Wallet Signature Required)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/v1/trading/order` | Create new order |
| `DELETE` | `/v1/trading/order/{id}` | Cancel order |
| `GET` | `/v1/trading/orders` | User's open orders |
| `GET` | `/v1/trading/history` | Trade history |
| `POST` | `/v1/wallet/connect` | Connect wallet |
| `GET` | `/v1/wallet/balances` | Wallet balances |
| `GET` | `/v1/wallet/transactions` | Wallet transactions |
| `POST` | `/v1/swap/execute` | Execute swap transaction |
| `POST` | `/v1/ai/analyze` | AI market analysis |
| `POST` | `/v1/ai/predict` | Price prediction |
| `GET` | `/v1/ai/insights/{symbol}` | Symbol insights |

For complete API documentation, see [API.md](API.md).

---

## Quick Start

### Prerequisites

- PHP 8.2+
- Node.js 20+
- Composer 2.x
- MySQL 8+ or SQLite

### Installation

```bash
# Clone the repository
git clone https://github.com/xjanova/ThaiXTrade.git
cd ThaiXTrade

# Automated setup (recommended)
./install.sh

# -- OR manual setup --
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
```

### Development

```bash
# Start Laravel + Vite dev servers
make dev

# Or individually
php artisan serve &
npm run dev
```

The application will be available at:

| Service | URL |
|---------|-----|
| **Application** | http://localhost:8000 |
| **Vite HMR** | http://localhost:5173 |
| **Admin Panel** | http://localhost:8000/admin |

For detailed setup instructions, see [QUICKSTART.md](QUICKSTART.md).

---

## Development Commands

| Command | Description |
|---------|-------------|
| `make dev` | Start Laravel + Vite development servers |
| `make build` | Build frontend assets for production |
| `make test` | Run all tests (PHP + JavaScript) |
| `make test-php` | Run PHP tests only (PHPUnit) |
| `make test-js` | Run JavaScript tests only (Vitest) |
| `make test-coverage` | Run tests with code coverage report |
| `make lint` | Run code linting (Pint + ESLint) |
| `make clean` | Clear all caches |
| `make audit` | Run security audit |
| `make deploy` | Deploy to production |
| `make backup` | Create backup |
| `make rollback` | Rollback to previous version |
| `make migrate` | Run database migrations |
| `make migrate-fresh` | Fresh migrate with seeders |
| `make version` | Show current version |
| `make bump-patch` | Bump patch version |

---

## Testing

```bash
# Full test suite
make test

# PHP tests
php artisan test

# JavaScript tests
npm run test:run

# Watch mode
npm run test

# Coverage report
npm run test:coverage

# Visual test UI
npm run test:ui
```

---

## Environment Configuration

Copy `.env.example` to `.env` and configure:

| Group | Variables | Description |
|-------|-----------|-------------|
| **App** | `APP_NAME`, `APP_URL`, `APP_TIMEZONE` | Application identity (default: Asia/Bangkok) |
| **Database** | `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE` | MySQL, PostgreSQL, or SQLite |
| **Web3** | `DEFAULT_CHAIN_ID`, `WEB3_RPC_*` | Blockchain RPC endpoints |
| **Wallet** | `WALLETCONNECT_PROJECT_ID` | WalletConnect v2 integration |
| **Trading** | `TRADING_FEE_PERCENTAGE`, `TRADING_FEE_WALLET` | Fee configuration |
| **AI** | `GROQ_API_KEY`, `GROQ_MODEL` | Groq LLM integration |
| **WebSocket** | `REVERB_APP_KEY`, `REVERB_HOST` | Real-time event broadcasting |
| **Security** | `CLOUDFLARE_TURNSTILE_*` | Bot protection |

See `.env.example` for the full list with documentation.

---

## CI/CD Pipeline

The project uses GitHub Actions for continuous integration and deployment:

```
Push to main
    |
    v
+----------------------------+
|  CI - Tests & Quality      |
|                            |
|  - PHP Tests (8.2 + 8.3)  |
|  - JavaScript Tests        |
|  - Laravel Pint (Style)    |
|  - Build Assets Check      |
|  - Security Audit          |
+-------------+--------------+
              |
              v (all pass)
+----------------------------+
|  Auto Release              |
|                            |
|  - Semantic version bump   |
|  - Git tag + GitHub Release|
+-------------+--------------+
              |
              v
+----------------------------+
|  Auto Deploy to Production |
|                            |
|  - SSH deploy to server    |
|  - Composer install        |
|  - npm build               |
|  - Migrate database        |
|  - Clear caches            |
+----------------------------+
```

---

## Brand Identity

TPIX TRADE uses a distinctive triple-gradient color palette derived from the logo:

| Color | Hex | Usage |
|-------|-----|-------|
| **Cyan** (Primary) | `#06b6d4` | Primary actions, links, active states |
| **Purple** (Accent) | `#8b5cf6` | Highlights, badges, gradient starts |
| **Orange** (Warm) | `#f97316` | CTAs, warnings, gradient ends |
| **Dark** | `#020617` - `#0f172a` | Backgrounds, cards |
| **Green** | `#00C853` | Positive price changes |
| **Red** | `#FF1744` | Negative price changes |

The Glass Morphism design system includes backdrop blur, translucent borders, and multi-color glow effects that create a premium trading experience.

---

## Documentation

| Document | Description |
|----------|-------------|
| [README.md](README.md) | Project overview (this file) |
| [QUICKSTART.md](QUICKSTART.md) | 5-minute setup guide |
| [ARCHITECTURE.md](ARCHITECTURE.md) | System architecture and design decisions |
| [API.md](API.md) | Complete REST API reference |
| [CODING_STANDARDS.md](CODING_STANDARDS.md) | Code style and conventions |
| [CONTRIBUTING.md](CONTRIBUTING.md) | How to contribute |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Production deployment guide |
| [CHANGELOG.md](CHANGELOG.md) | Version history and release notes |
| [CLAUDE.md](CLAUDE.md) | AI development guidelines |

---

## Security

- Non-custodial architecture: users control their own private keys at all times
- AES-256 encryption for sensitive data at rest
- HMAC-SHA256 request signing for API authentication
- Rate limiting on all endpoints
- Content Security Policy (CSP) headers
- Cloudflare Turnstile for bot protection
- Admin audit logging with full action trail

If you discover a security vulnerability, **do not** open a public issue. Email **security@xmanstudio.com** with a description, reproduction steps, and potential impact.

---

## Contributing

We welcome contributions! Please read [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on:

- Development workflow and branch naming
- Coding standards (Laravel Pint + ESLint)
- Testing requirements
- Pull request process

---

## License

This project is proprietary software developed by **Xman Studio**. All rights reserved.

---

<p align="center">
  <img src="logo.png" alt="TPIX TRADE" width="48" height="48" style="border-radius: 12px;">
  <br><br>
  <strong>TPIX TRADE</strong> v1.0.19<br>
  <em>Developed with dedication by <a href="https://xmanstudio.com">Xman Studio</a></em>
</p>
