# ThaiXTrade

**Thai Decentralized Exchange Platform**

![Laravel](https://img.shields.io/badge/Laravel-11-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)
![Vue.js](https://img.shields.io/badge/Vue.js-3-4FC08D?style=for-the-badge&logo=vuedotjs&logoColor=white)
![TailwindCSS](https://img.shields.io/badge/TailwindCSS-3-06B6D4?style=for-the-badge&logo=tailwindcss&logoColor=white)
![Inertia.js](https://img.shields.io/badge/Inertia.js-1.0-9553E9?style=for-the-badge)
![Web3](https://img.shields.io/badge/Web3-Ethers.js-3C3C3D?style=for-the-badge&logo=ethereum&logoColor=white)
![Vite](https://img.shields.io/badge/Vite-5-646CFF?style=for-the-badge&logo=vite&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![License](https://img.shields.io/badge/License-Proprietary-blue?style=for-the-badge)

ThaiXTrade is a non-custodial decentralized exchange (DEX) platform that enables users to trade cryptocurrencies directly from their wallets across multiple blockchain networks. Built with a modern Glass Morphism dark theme, it provides a professional trading experience with real-time charts, order book depth visualization, AI-powered insights, and multi-chain support for over 50 blockchains.

---

## Features

### Trading
- Real-time candlestick charts with TradingView-style interface (Lightweight Charts)
- Order book with bid/ask depth visualization
- Limit, market, and stop-limit order types
- Token swap with optimized routing
- Recent trades feed with live updates
- Open orders management and trade history with export

### Multi-Chain Support
- 9+ mainnet blockchains out of the box: Ethereum, BNB Smart Chain, Polygon, Arbitrum, Optimism, Avalanche, Fantom, Base, zkSync Era
- Testnet support (Sepolia, BSC Testnet) for development
- Easy chain addition via configuration file
- Per-chain RPC fallback endpoints

### Wallet Integration
- MetaMask
- WalletConnect v2
- Trust Wallet
- Coinbase Wallet
- Non-custodial architecture -- users always control their keys

### AI-Powered Insights
- Market analysis and trend detection
- Price prediction models
- Trade suggestions
- Per-symbol insights dashboard

### Real-Time Data
- WebSocket-based live updates via Laravel Reverb
- Live ticker strip across all pairs
- Real-time order book and trade feed

### Platform
- Glass Morphism dark theme UI
- Responsive design (mobile-first)
- PWA support for app-like experience
- Multi-language support via Google Translate integration
- Social authentication (Google, Twitter, Discord, Telegram)
- Two-factor authentication (2FA)

---

## Screenshots

> Screenshots will be added here. Place images in `docs/screenshots/`.

| Dashboard | Trading View | Swap |
|-----------|-------------|------|
| _Coming soon_ | _Coming soon_ | _Coming soon_ |

---

## Quick Start

```bash
# Clone the repository
git clone https://github.com/xjanova/ThaiXTrade.git
cd ThaiXTrade

# Automated installation
./install.sh

# Or manual setup
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build

# Start development servers
make dev
```

The application will be available at:
- **Backend**: http://localhost:8000
- **Vite Dev Server**: http://localhost:5173

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
| `make version` | Show current version |
| `make bump-patch` | Bump patch version (1.0.0 -> 1.0.1) |
| `make bump-minor` | Bump minor version (1.0.0 -> 1.1.0) |
| `make bump-major` | Bump major version (1.0.0 -> 2.0.0) |
| `make deploy` | Deploy to production |
| `make backup` | Create backup |
| `make rollback` | Rollback to previous version |
| `make migrate` | Run database migrations |
| `make migrate-fresh` | Fresh migrate with seeders |
| `make tinker` | Open Laravel Tinker REPL |

---

## Project Structure

```
ThaiXTrade/
├── app/
│   ├── Http/Controllers/
│   │   ├── Api/
│   │   │   ├── AIController.php        # AI analysis endpoints
│   │   │   ├── ChainController.php     # Blockchain chain data
│   │   │   ├── MarketController.php    # Market data & tickers
│   │   │   ├── TradingController.php   # Orders & swap operations
│   │   │   └── WalletController.php    # Wallet connection & balances
│   │   └── Controller.php
│   ├── Models/                         # Eloquent models
│   ├── Services/                       # Business logic layer
│   └── Providers/                      # Service providers
├── config/
│   └── chains.php                      # Blockchain network configuration
├── database/
│   └── migrations/                     # Database schema
├── public_html/                        # Document root (NOT public/)
├── resources/
│   ├── css/app.css                     # TailwindCSS + design system
│   ├── js/
│   │   ├── app.js                      # Application entry point
│   │   ├── Components/
│   │   │   ├── Navigation/
│   │   │   │   ├── NavBar.vue          # Top navigation bar
│   │   │   │   └── Sidebar.vue         # Side navigation
│   │   │   ├── Trading/
│   │   │   │   ├── TradingChart.vue    # Candlestick chart
│   │   │   │   ├── OrderBook.vue       # Bid/ask order book
│   │   │   │   ├── TradeForm.vue       # Buy/sell form
│   │   │   │   ├── RecentTrades.vue    # Live trade feed
│   │   │   │   ├── OpenOrders.vue      # Active orders
│   │   │   │   ├── TradeHistory.vue    # Historical trades
│   │   │   │   └── TickerStrip.vue     # Price ticker bar
│   │   │   └── Wallet/
│   │   │       └── WalletModal.vue     # Wallet connection modal
│   │   ├── Composables/                # Vue composable functions
│   │   ├── Layouts/
│   │   │   └── AppLayout.vue           # Main application layout
│   │   ├── Pages/
│   │   │   ├── Home.vue                # Landing / dashboard page
│   │   │   └── Trade.vue               # Trading interface page
│   │   └── Stores/                     # Pinia state stores
│   └── views/                          # Blade templates
├── routes/
│   ├── api.php                         # API route definitions
│   └── web.php                         # Web (Inertia) route definitions
├── scripts/                            # DevOps & automation scripts
│   ├── bump-version.sh                 # Semantic version management
│   ├── test.sh                         # Test runner
│   ├── backup.sh                       # Backup utility
│   ├── security-audit.sh              # Security checks
│   └── ...
├── tests/
│   ├── Feature/                        # HTTP / integration tests
│   ├── Unit/                           # Unit tests
│   └── js/                             # JavaScript / Vue tests (Vitest)
├── CLAUDE.md                           # AI development guidelines
├── CONTRIBUTING.md                     # Contribution guide
├── CHANGELOG.md                        # Version changelog
├── Makefile                            # Development commands
├── version.json                        # Version tracking
├── package.json                        # NPM dependencies
├── composer.json                       # PHP dependencies
├── vite.config.js                      # Vite build configuration
└── tailwind.config.js                  # TailwindCSS configuration
```

---

## Tech Stack

| Layer | Technology | Purpose |
|-------|-----------|---------|
| **Backend** | Laravel 11 / PHP 8.2+ | API, routing, business logic |
| **Frontend** | Vue.js 3 (Composition API) | Reactive UI components |
| **Bridge** | Inertia.js | SPA without building an API layer |
| **Styling** | TailwindCSS 3 | Utility-first CSS with Glass Morphism theme |
| **Build** | Vite 5 | Fast HMR and optimized production builds |
| **State** | Pinia | Vue state management |
| **Charts** | Lightweight Charts + ApexCharts | TradingView-style candlestick charts |
| **Web3** | Ethers.js 6 + Web3.js 4 | Blockchain interaction |
| **Wallet** | WalletConnect | Multi-wallet connection protocol |
| **WebSocket** | Laravel Reverb | Real-time event broadcasting |
| **Testing** | PHPUnit + Vitest | Backend and frontend testing |
| **Linting** | Laravel Pint + ESLint | Code style enforcement |
| **Icons** | Heroicons + Headless UI | Accessible UI primitives |

---

## Testing

```bash
# Run the full test suite
make test

# PHP tests only
php artisan test
# or
./vendor/bin/phpunit

# JavaScript tests only
npm run test:run

# Watch mode for JS tests
npm run test

# Coverage report
make test-coverage
# or
npm run test:coverage
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for test writing guidelines.

---

## API Overview

ThaiXTrade exposes a RESTful JSON API under `/api/v1/`. All responses follow a consistent envelope format:

```json
{
    "success": true,
    "data": { ... }
}
```

### Key Endpoint Groups

| Group | Prefix | Auth | Description |
|-------|--------|------|-------------|
| Market | `/api/v1/market` | No | Tickers, order books, klines, pairs |
| Chains | `/api/v1/chains` | No | Supported blockchains, tokens, gas |
| Tokens | `/api/v1/tokens` | No | Token info and pricing |
| Trading | `/api/v1/trading` | Yes | Orders, history, swaps |
| Wallet | `/api/v1/wallet` | Yes | Connect, balances, transactions |
| AI | `/api/v1/ai` | Yes | Analysis, predictions, insights |

For complete API documentation, see [API.md](API.md).

---

## Documentation

| Document | Description |
|----------|-------------|
| [README.md](README.md) | This file -- project overview |
| [QUICKSTART.md](QUICKSTART.md) | 5-minute setup guide |
| [ARCHITECTURE.md](ARCHITECTURE.md) | System architecture and design |
| [API.md](API.md) | Complete API reference |
| [CODING_STANDARDS.md](CODING_STANDARDS.md) | Code style and conventions |
| [CONTRIBUTING.md](CONTRIBUTING.md) | How to contribute |
| [CHANGELOG.md](CHANGELOG.md) | Version history |
| [CLAUDE.md](CLAUDE.md) | AI development guidelines |

---

## Environment Variables

Key configuration is managed through `.env`. Copy `.env.example` to get started:

```bash
cp .env.example .env
```

Important configuration groups:
- **App**: `APP_NAME`, `APP_ENV`, `APP_URL`, `APP_TIMEZONE` (Asia/Bangkok)
- **Database**: `DB_CONNECTION` (sqlite, mysql, pgsql)
- **Web3**: `DEFAULT_CHAIN_ID`, `INFURA_PROJECT_ID`, `ALCHEMY_API_KEY`, `WALLETCONNECT_PROJECT_ID`
- **WebSocket**: `REVERB_APP_KEY`, `REVERB_HOST`, `REVERB_PORT`
- **AI**: `AI_PROVIDER`, `OPENAI_API_KEY`, `OPENAI_MODEL`
- **Trading**: `TRADING_FEE_PERCENTAGE`, `MAX_ORDER_SIZE_USD`, `DEFAULT_SLIPPAGE_TOLERANCE`
- **Security**: `RATE_LIMIT_PER_MINUTE`, `TWO_FACTOR_ENABLED`, `ENCRYPTION_KEY`

See `.env.example` for the full list with documentation.

---

## Contributing

We welcome contributions. Please read [CONTRIBUTING.md](CONTRIBUTING.md) for:
- Code of conduct
- Development workflow and branch naming
- Coding standards
- Testing requirements
- Pull request process

---

## Security

If you discover a security vulnerability, **do not** open a public issue. Instead, email **security@xmanstudio.com** with a description, steps to reproduce, and potential impact.

---

## License

This project is proprietary software developed by Xman Studio. All rights reserved.

---

## Version

Current version: **1.0.0** (Build 1) -- Stable Channel

See [CHANGELOG.md](CHANGELOG.md) for release history.

---

<p align="center">
  Developed with dedication by <strong><a href="https://xmanstudio.com">Xman Studio</a></strong>
</p>
