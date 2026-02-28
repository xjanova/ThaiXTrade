# CLAUDE.md - AI Development Guidelines

คำแนะนำสำหรับ Claude AI ในการพัฒนา TPIX TRADE

## Project Overview

```
Name:       TPIX TRADE
Type:       Decentralized Exchange (DEX)
Developer:  Xman Studio
Stack:      Laravel 11 + Vue 3 + Inertia.js + TailwindCSS
Theme:      Glass Morphism Dark
```

---

## Directory Structure

```
TPIX TRADE/
├── app/                    # Laravel application code
│   ├── Http/Controllers/   # Controllers (Web & API)
│   ├── Models/             # Eloquent models
│   ├── Services/           # Business logic services
│   └── Providers/          # Service providers
├── bootstrap/              # Laravel bootstrap
├── config/                 # Configuration files
│   └── chains.php          # Blockchain chains config
├── database/               # Migrations & seeders
├── public_html/            # Document root (NOT public/)
├── resources/
│   ├── css/app.css         # TailwindCSS styles
│   ├── js/
│   │   ├── Components/     # Vue components
│   │   ├── Pages/          # Inertia pages
│   │   ├── Layouts/        # Layout components
│   │   ├── Composables/    # Vue composables
│   │   └── Stores/         # Pinia stores
│   └── views/              # Blade templates
├── routes/                 # Route definitions
├── scripts/                # DevOps scripts
├── storage/                # Laravel storage
├── tests/                  # Test suites
│   ├── Feature/            # Feature tests (PHPUnit)
│   ├── Unit/               # Unit tests (PHPUnit)
│   └── js/                 # JavaScript tests (Vitest)
└── version.json            # Version tracking
```

---

## Coding Standards

### PHP (Laravel)

```php
// ✅ DO: Use typed properties and return types
public function getChain(int $chainId): ?array
{
    return config("chains.chains.{$chainId}");
}

// ✅ DO: Use dependency injection
public function __construct(
    private TradingService $tradingService,
    private Web3Service $web3Service,
) {}

// ✅ DO: Use Laravel conventions
Route::get('/trade/{pair}', [TradingController::class, 'show']);

// ❌ DON'T: Use raw queries without binding
DB::select("SELECT * FROM users WHERE id = $id"); // SQL Injection!

// ✅ DO: Use Eloquent or query builder
User::find($id);
DB::table('users')->where('id', $id)->first();
```

### Vue.js

```vue
<!-- ✅ DO: Use Composition API with <script setup> -->
<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    symbol: { type: String, required: true },
});

const price = ref(0);
const formattedPrice = computed(() => `$${price.value.toLocaleString()}`);
</script>

<!-- ✅ DO: Use semantic class names -->
<template>
    <div class="trading-card glass-dark">
        <h2 class="text-lg font-semibold text-white">{{ symbol }}</h2>
    </div>
</template>

<!-- ❌ DON'T: Use Options API for new components -->
<script>
export default {
    data() { return {} }  // Old style, avoid
}
</script>
```

### TailwindCSS

```css
/* ✅ DO: Use design system classes from app.css */
.glass-dark       /* Dark glass morphism */
.glass-card       /* Card with glass effect */
.btn-primary      /* Primary button */
.btn-success      /* Green/buy button */
.btn-danger       /* Red/sell button */
.trading-input    /* Input field */
.nav-link         /* Navigation link */

/* ✅ DO: Use trading colors */
.text-trading-green   /* #00C853 */
.text-trading-red     /* #FF1744 */
.bg-primary-500       /* Brand blue */

/* ❌ DON'T: Use arbitrary values excessively */
<div class="w-[347px] h-[89px]">  /* Avoid magic numbers */
```

---

## Component Naming

```
Components/
├── Trading/
│   ├── TradingChart.vue      # PascalCase, descriptive
│   ├── OrderBook.vue
│   ├── TradeForm.vue
│   └── RecentTrades.vue
├── Wallet/
│   └── WalletModal.vue
└── Navigation/
    ├── NavBar.vue
    └── Sidebar.vue
```

**Rules:**
- Use PascalCase for component files
- Prefix with feature name (Trading, Wallet, etc.)
- Use descriptive names (not `Card.vue`, but `TradingCard.vue`)

---

## API Conventions

### Endpoints

```
GET    /api/v1/chains              # List all chains
GET    /api/v1/chains/{id}         # Get specific chain
GET    /api/v1/pairs               # List trading pairs
GET    /api/v1/pairs/{symbol}      # Get pair info
POST   /api/v1/orders              # Create order
GET    /api/v1/orders              # List user orders
DELETE /api/v1/orders/{id}         # Cancel order
```

### Response Format

```json
// Success
{
    "success": true,
    "data": { ... },
    "meta": {
        "page": 1,
        "per_page": 20,
        "total": 100
    }
}

// Error
{
    "success": false,
    "error": {
        "code": "INVALID_CHAIN",
        "message": "Chain ID 999 is not supported"
    }
}
```

---

## Security Rules

### CRITICAL - Never Do

```php
// ❌ NEVER store private keys
$privateKey = $request->input('private_key');  // NEVER!

// ❌ NEVER log sensitive data
Log::info('User wallet', ['key' => $privateKey]);  // NEVER!

// ❌ NEVER expose internal errors in production
return response()->json(['error' => $e->getMessage()]);  // Info leak!

// ❌ NEVER trust client-side validation alone
// Always validate on server
```

### Must Do

```php
// ✅ Validate all inputs
$validated = $request->validate([
    'amount' => 'required|numeric|min:0',
    'chain_id' => 'required|integer|exists:chains,id',
]);

// ✅ Use rate limiting
Route::middleware('throttle:60,1')->group(function () {
    Route::post('/api/v1/orders', ...);
});

// ✅ Sanitize outputs
{{ $userInput }}  // Blade auto-escapes
{!! $html !!}     // Only for trusted HTML

// ✅ Use CSRF protection
@csrf  // In forms

// ✅ Validate wallet signatures on server
$isValid = $this->web3Service->verifySignature($message, $signature, $address);
```

---

## Git Workflow

### Branch Naming

```
feature/add-swap-page
fix/order-book-scroll
hotfix/security-patch
refactor/trading-service
```

### Commit Messages

```bash
# Format: type(scope): description

feat(trading): add limit order support
fix(wallet): resolve MetaMask connection issue
docs(readme): update installation guide
refactor(api): extract chain service
test(orders): add order creation tests
chore(deps): update Laravel to 11.1
style(ui): adjust button colors
perf(chart): optimize candlestick rendering
```

### Before Committing

```bash
# 1. Run linting
make lint

# 2. Run tests
make test

# 3. Check for security issues
make audit
```

---

## Testing Requirements

### New Features Must Have

1. **Feature Test** - Test the endpoint/page works
2. **Unit Test** - Test business logic
3. **JS Test** - Test Vue components (if applicable)

### Test Examples

```php
// Feature Test
public function test_trading_page_loads(): void
{
    $response = $this->get('/trade/BTC-USDT');
    $response->assertStatus(200);
    $response->assertSee('BTC/USDT');
}

// Unit Test
public function test_price_formatter(): void
{
    $formatted = formatPrice(67234.50);
    $this->assertEquals('67,234.50', $formatted);
}
```

```javascript
// Vue Component Test
it('emits order on submit', async () => {
    const wrapper = mount(TradeForm);
    await wrapper.find('button.btn-success').trigger('click');
    expect(wrapper.emitted('submit-order')).toBeTruthy();
});
```

---

## Version Bumping

```bash
# After completing a feature
./scripts/bump-version.sh patch   # 1.0.0 -> 1.0.1

# After adding new functionality
./scripts/bump-version.sh minor   # 1.0.0 -> 1.1.0

# After breaking changes
./scripts/bump-version.sh major   # 1.0.0 -> 2.0.0
```

---

## Common Tasks

### Adding a New Page

1. Create Vue page: `resources/js/Pages/NewPage.vue`
2. Add route: `routes/web.php`
3. Create controller if needed
4. Add tests

### Adding a New API Endpoint

1. Create controller: `app/Http/Controllers/Api/`
2. Add route: `routes/api.php`
3. Create Form Request for validation
4. Add API tests
5. Update API documentation

### Adding a New Blockchain

1. Add to `config/chains.php`
2. Add chain icon to assets
3. Add tests for chain config
4. Update documentation

---

## Do Not Modify

- `public_html/index.php` - Laravel entry point
- `bootstrap/app.php` - App bootstrap
- `version.json` - Use bump-version.sh instead
- `.env` files - Never commit

---

## Quick Reference

| Task | Command |
|------|---------|
| Start dev server | `make dev` |
| Run tests | `make test` |
| Build for production | `make build` |
| Bump version | `make bump-patch` |
| Clear cache | `make clean` |
| Security audit | `make audit` |
| Show version | `make version` |

---

## Contact

- **Project**: TPIX TRADE
- **Developer**: Xman Studio
- **Website**: https://xmanstudio.com

---

*This file helps Claude AI understand project conventions and maintain consistency.*
