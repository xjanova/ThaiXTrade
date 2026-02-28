# Quick Start Guide

Get ThaiXTrade running on your local machine in 5 minutes.

---

## Prerequisites

Ensure you have the following installed before starting:

| Requirement | Minimum Version | Check Command |
|------------|----------------|---------------|
| **PHP** | 8.2+ | `php -v` |
| **Composer** | 2.x | `composer -V` |
| **Node.js** | 18+ | `node -v` |
| **NPM** | 9+ | `npm -v` |
| **Git** | 2.x | `git --version` |

### Required PHP Extensions

- OpenSSL
- PDO (SQLite, MySQL, or PostgreSQL)
- Mbstring
- Tokenizer
- XML
- Ctype
- JSON
- BCMath
- Fileinfo

Check your PHP extensions:
```bash
php -m
```

---

## 5-Minute Setup

### Option A: Automated Installation

```bash
# 1. Clone the repository
git clone https://github.com/xjanova/ThaiXTrade.git
cd ThaiXTrade

# 2. Run the installer
./install.sh

# 3. Start development servers
make dev
```

The installer handles everything: dependencies, environment file, application key, database, and asset building.

### Option B: Manual Installation

```bash
# 1. Clone the repository
git clone https://github.com/xjanova/ThaiXTrade.git
cd ThaiXTrade

# 2. Install PHP dependencies
composer install

# 3. Install Node.js dependencies
npm install

# 4. Create environment file
cp .env.example .env

# 5. Generate application key
php artisan key:generate

# 6. Create SQLite database (default)
touch database/database.sqlite

# 7. Run database migrations
php artisan migrate

# 8. Build frontend assets
npm run build

# 9. Start development servers
make dev
```

### Access the Application

Once the servers are running:

| Service | URL |
|---------|-----|
| **Application** | http://localhost:8000 |
| **Vite Dev Server** (HMR) | http://localhost:5173 |
| **WebSocket** (Reverb) | ws://localhost:8080 |
| **Health Check** | http://localhost:8000/health |
| **API Root** | http://localhost:8000/api/ |

---

## Common Commands

### Development

| Command | Description |
|---------|-------------|
| `make dev` | Start Laravel backend + Vite frontend dev servers |
| `php artisan serve` | Start Laravel backend server only |
| `npm run dev` | Start Vite frontend dev server only (with HMR) |
| `npm run build` | Build frontend assets for production |
| `npm run preview` | Preview the production build locally |

### Testing

| Command | Description |
|---------|-------------|
| `make test` | Run all tests (PHP + JavaScript) |
| `make test-php` | Run PHP tests only (PHPUnit) |
| `make test-js` | Run JavaScript tests only (Vitest) |
| `make test-coverage` | Run tests with coverage report |
| `npm run test` | Run Vitest in watch mode |
| `npm run test:ui` | Run Vitest with browser UI |
| `php artisan test` | Run PHPUnit tests directly |

### Code Quality

| Command | Description |
|---------|-------------|
| `make lint` | Run all linters (Pint + ESLint) |
| `./vendor/bin/pint` | Run PHP code formatter (PSR-12) |
| `npm run lint` | Run ESLint on JavaScript/Vue files |
| `npm run format` | Run Prettier on JavaScript/Vue files |
| `make audit` | Run security audit |

### Database

| Command | Description |
|---------|-------------|
| `make migrate` | Run pending migrations |
| `make migrate-fresh` | Drop all tables, re-migrate, and seed |
| `make seed` | Run database seeders |
| `make tinker` | Open Laravel Tinker interactive REPL |

### Cache & Maintenance

| Command | Description |
|---------|-------------|
| `make clean` | Clear all caches (config, route, view, app) |
| `make clean-all` | Deep cache clear including compiled files |
| `make fix-perms` | Fix file and directory permissions |

### Versioning

| Command | Description |
|---------|-------------|
| `make version` | Display current version |
| `make bump-patch` | Bump patch version (1.0.0 -> 1.0.1) |
| `make bump-minor` | Bump minor version (1.0.0 -> 1.1.0) |
| `make bump-major` | Bump major version (1.0.0 -> 2.0.0) |

### Production

| Command | Description |
|---------|-------------|
| `make deploy` | Full production deployment |
| `make deploy-quick` | Quick deployment (skip some checks) |
| `make optimize` | Optimize application for production |
| `make backup` | Create a backup |
| `make backup-full` | Create a full backup including database |
| `make rollback` | Show rollback options |

---

## Environment Configuration

### Database

ThaiXTrade defaults to SQLite for simplicity. To switch to MySQL or PostgreSQL:

**MySQL:**
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=thaixtrade
DB_USERNAME=root
DB_PASSWORD=your_password
```

**PostgreSQL:**
```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=thaixtrade
DB_USERNAME=postgres
DB_PASSWORD=your_password
```

After changing the database driver, run migrations:
```bash
php artisan migrate
```

### Web3 / Blockchain

Configure blockchain RPC providers for better reliability:

```env
# Default chain (56 = BSC)
DEFAULT_CHAIN_ID=56

# Optional: Premium RPC providers
INFURA_PROJECT_ID=your_infura_id
ALCHEMY_API_KEY=your_alchemy_key

# WalletConnect (required for WalletConnect support)
WALLETCONNECT_PROJECT_ID=your_project_id
```

You can get free API keys from:
- **Infura**: https://infura.io
- **Alchemy**: https://alchemy.com
- **WalletConnect**: https://cloud.walletconnect.com

### AI Features

To enable AI-powered trading insights:

```env
AI_ENABLED=true
AI_PROVIDER=openai
OPENAI_API_KEY=sk-your-api-key
OPENAI_MODEL=gpt-4
```

### WebSocket (Real-Time)

Laravel Reverb handles WebSocket connections:

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=thaixtrade
REVERB_APP_KEY=thaixtrade-key
REVERB_APP_SECRET=thaixtrade-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

Start the WebSocket server:
```bash
php artisan reverb:start
```

### Trading Configuration

```env
# Trading fee (0.1 = 0.1%)
TRADING_FEE_PERCENTAGE=0.1

# Order size limits (in USD)
MAX_ORDER_SIZE_USD=1000000
MIN_ORDER_SIZE_USD=1

# Slippage tolerance (in %)
DEFAULT_SLIPPAGE_TOLERANCE=0.5
MAX_SLIPPAGE_TOLERANCE=5
```

---

## Troubleshooting

### "PHP not found" or wrong version

Ensure PHP 8.2+ is in your system PATH:
```bash
php -v
```

On macOS with Homebrew:
```bash
brew install php@8.3
```

On Ubuntu/Debian:
```bash
sudo apt install php8.3 php8.3-cli php8.3-common php8.3-mbstring php8.3-xml php8.3-sqlite3
```

### "Composer not found"

Install Composer globally:
```bash
# macOS / Linux
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Windows
# Download from https://getcomposer.org/download/
```

### "Vite manifest not found"

The frontend assets have not been built. Run:
```bash
npm run build
```

Or for development with HMR:
```bash
npm run dev
```

### Permission Errors

Fix file permissions:
```bash
make fix-perms
```

Or manually:
```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### Database Errors

If using SQLite, ensure the database file exists:
```bash
touch database/database.sqlite
```

Then run migrations:
```bash
php artisan migrate
```

To start fresh:
```bash
make migrate-fresh
```

### Port Already in Use

If port 8000 is occupied:
```bash
php artisan serve --port=8001
```

If port 5173 (Vite) is occupied, Vite will automatically try the next available port.

### Cache Issues

Clear all caches:
```bash
make clean
```

Or manually:
```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
```

### NPM Install Fails

Clear NPM cache and reinstall:
```bash
rm -rf node_modules package-lock.json
npm cache clean --force
npm install
```

### Application Key Missing

If you see an "Application key not set" error:
```bash
php artisan key:generate
```

---

## Next Steps

- Read the [Architecture Overview](ARCHITECTURE.md) to understand the system design
- Review the [API Reference](API.md) for endpoint documentation
- Follow the [Coding Standards](CODING_STANDARDS.md) for consistent code
- See [CONTRIBUTING.md](CONTRIBUTING.md) for the contribution workflow

---

<p align="center">
  <strong><a href="https://xmanstudio.com">Xman Studio</a></strong> -- ThaiXTrade Quick Start Guide
</p>
