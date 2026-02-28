# Coding Standards

TPIX TRADE coding conventions and best practices. All contributors must follow these standards to maintain consistency across the codebase.

---

## Table of Contents

- [PHP Standards](#php-standards)
- [Vue.js Standards](#vuejs-standards)
- [TailwindCSS Conventions](#tailwindcss-conventions)
- [Database Standards](#database-standards)
- [API Standards](#api-standards)
- [Git Standards](#git-standards)
- [Testing Standards](#testing-standards)
- [Security Standards](#security-standards)
- [File Organization](#file-organization)

---

## PHP Standards

### Style Guide

TPIX TRADE follows **PSR-12** coding style, enforced by [Laravel Pint](https://laravel.com/docs/pint).

Run the formatter:
```bash
./vendor/bin/pint
```

Or via Make:
```bash
make lint
```

### Type Declarations

Always use typed properties, parameters, and return types:

```php
// CORRECT: Fully typed
public function getChain(int $chainId): ?array
{
    return config("chains.chains.{$chainId}");
}

public function calculateFee(float $amount, float $percentage): float
{
    return $amount * ($percentage / 100);
}

// INCORRECT: Missing types
public function getChain($chainId)
{
    return config("chains.chains.{$chainId}");
}
```

### Dependency Injection

Use constructor injection for services and dependencies:

```php
// CORRECT: Constructor injection
class TradingController extends Controller
{
    public function __construct(
        private TradingService $tradingService,
        private Web3Service $web3Service,
    ) {}

    public function createOrder(Request $request): JsonResponse
    {
        $order = $this->tradingService->createOrder($request->validated());
        return response()->json(['success' => true, 'data' => $order]);
    }
}

// INCORRECT: Static calls or manual instantiation
class TradingController extends Controller
{
    public function createOrder(Request $request): JsonResponse
    {
        $service = new TradingService();  // Don't do this
        $order = TradingService::create($request->all());  // Don't do this
    }
}
```

### Controller Conventions

- Keep controllers thin. Extract business logic into service classes.
- Use Form Requests for validation.
- Return consistent JSON response envelopes.

```php
// CORRECT: Thin controller with Form Request
public function createOrder(CreateOrderRequest $request): JsonResponse
{
    $order = $this->tradingService->createOrder($request->validated());

    return response()->json([
        'success' => true,
        'data' => $order,
    ], 201);
}

// INCORRECT: Fat controller with inline validation
public function createOrder(Request $request): JsonResponse
{
    $request->validate([...]);  // Use Form Request instead
    // ... 50 lines of business logic ...
}
```

### Eloquent & Database Queries

- Use Eloquent models and relationships.
- Never use raw queries without parameter binding.
- Use the query builder for complex queries.

```php
// CORRECT: Eloquent
$user = User::find($id);
$orders = Order::where('user_id', $userId)
    ->where('status', 'open')
    ->orderByDesc('created_at')
    ->paginate(20);

// CORRECT: Query builder with bindings
DB::table('orders')
    ->where('chain_id', $chainId)
    ->where('amount', '>', $minAmount)
    ->get();

// INCORRECT: Raw query (SQL injection risk)
DB::select("SELECT * FROM orders WHERE user_id = $userId");  // NEVER
```

### Error Handling

- Use Laravel's exception handling.
- Never expose internal error details in production.
- Use structured error codes.

```php
// CORRECT: Structured error response
if (! $chain) {
    return response()->json([
        'success' => false,
        'error' => [
            'code' => 'CHAIN_NOT_FOUND',
            'message' => "Chain with ID {$chainId} not found",
        ],
    ], 404);
}

// INCORRECT: Exposing internal errors
return response()->json(['error' => $exception->getMessage()]);  // Info leak
```

### Naming Conventions

| Element | Convention | Example |
|---------|-----------|---------|
| Classes | PascalCase | `TradingService`, `OrderBook` |
| Methods | camelCase | `getChain()`, `calculateFee()` |
| Properties | camelCase | `$chainId`, `$tradingFee` |
| Constants | UPPER_SNAKE | `MAX_ORDER_SIZE`, `DEFAULT_CHAIN_ID` |
| Config keys | snake_case | `chains.default`, `trading_fee` |
| Table names | snake_case, plural | `users`, `trading_pairs` |
| Column names | snake_case | `chain_id`, `created_at` |
| Route names | dot notation | `trade.pair`, `markets.spot` |

---

## Vue.js Standards

### Composition API

All new components must use the Composition API with `<script setup>`:

```vue
<!-- CORRECT: Composition API with <script setup> -->
<script setup>
import { ref, computed, onMounted } from 'vue';

const props = defineProps({
    symbol: { type: String, required: true },
    initialPrice: { type: Number, default: 0 },
});

const emit = defineEmits(['price-update', 'order-submit']);

const price = ref(props.initialPrice);
const formattedPrice = computed(() => `$${price.value.toLocaleString()}`);

onMounted(() => {
    // Initialize data
});
</script>

<template>
    <div class="trading-card glass-dark">
        <h2 class="text-lg font-semibold text-white">{{ symbol }}</h2>
        <span class="text-trading-green">{{ formattedPrice }}</span>
    </div>
</template>
```

```vue
<!-- INCORRECT: Options API (do not use for new components) -->
<script>
export default {
    data() {
        return { price: 0 };
    },
    computed: {
        formattedPrice() { return `$${this.price}`; }
    },
};
</script>
```

### Component Naming

- Use **PascalCase** for component file names.
- Prefix components with their feature domain.
- Use descriptive, specific names.

```
CORRECT:
  TradingChart.vue
  OrderBook.vue
  WalletModal.vue
  NavBar.vue

INCORRECT:
  Chart.vue          (too generic)
  chart.vue          (wrong case)
  trading-chart.vue  (wrong case for files)
  TC.vue             (too abbreviated)
```

### Component Organization

Follow this order within `<script setup>`:

```vue
<script setup>
// 1. Imports
import { ref, computed, watch, onMounted } from 'vue';
import { useTradingStore } from '@/Stores/useTradingStore';
import OrderRow from './OrderRow.vue';

// 2. Props
const props = defineProps({
    pair: { type: String, required: true },
});

// 3. Emits
const emit = defineEmits(['order-selected']);

// 4. Composables / stores
const tradingStore = useTradingStore();

// 5. Reactive state
const isLoading = ref(true);
const orders = ref([]);

// 6. Computed properties
const totalVolume = computed(() =>
    orders.value.reduce((sum, o) => sum + o.amount, 0)
);

// 7. Watchers
watch(() => props.pair, (newPair) => {
    fetchOrders(newPair);
});

// 8. Methods
function fetchOrders(pair) {
    // ...
}

function selectOrder(order) {
    emit('order-selected', order);
}

// 9. Lifecycle hooks
onMounted(() => {
    fetchOrders(props.pair);
});
</script>
```

### Template Guidelines

- Use semantic HTML elements where possible.
- Keep template expressions simple; use computed for complex logic.
- Use `v-for` with `:key` always.
- Avoid `v-if` and `v-for` on the same element.

```vue
<!-- CORRECT -->
<template>
    <ul class="order-list">
        <li v-for="order in filteredOrders" :key="order.id" class="order-item">
            <span class="text-white">{{ order.formattedPrice }}</span>
        </li>
    </ul>
</template>

<!-- INCORRECT: Complex expression in template -->
<template>
    <span>{{ `$${(price * (1 + fee / 100)).toFixed(2)}` }}</span>
</template>
```

### State Management (Pinia)

- One store per domain (wallet, trading, market, chain, UI).
- Use `defineStore` with the setup syntax.
- Keep store actions focused.

```javascript
// stores/useTradingStore.js
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

export const useTradingStore = defineStore('trading', () => {
    // State
    const currentPair = ref('BTC/USDT');
    const orders = ref([]);

    // Getters
    const openOrders = computed(() =>
        orders.value.filter(o => o.status === 'open')
    );

    // Actions
    async function fetchOrders() {
        const response = await axios.get('/api/v1/trading/orders');
        orders.value = response.data.data;
    }

    return { currentPair, orders, openOrders, fetchOrders };
});
```

### Composables

- Prefix composable files and functions with `use`.
- Return reactive refs and functions.
- Handle cleanup in `onUnmounted`.

```javascript
// composables/useWebSocket.js
import { ref, onMounted, onUnmounted } from 'vue';

export function useWebSocket(channel) {
    const messages = ref([]);
    let subscription = null;

    onMounted(() => {
        subscription = Echo.channel(channel)
            .listen('NewMessage', (data) => {
                messages.value.push(data);
            });
    });

    onUnmounted(() => {
        if (subscription) {
            Echo.leave(channel);
        }
    });

    return { messages };
}
```

---

## TailwindCSS Conventions

### Design System

TPIX TRADE uses a **Glass Morphism Dark** theme. Always use the design system classes defined in `resources/css/app.css`:

| Class | Purpose |
|-------|---------|
| `.glass-dark` | Dark glass morphism background |
| `.glass-card` | Card with glass effect |
| `.btn-primary` | Primary action button (brand blue) |
| `.btn-success` | Buy/positive action button (green) |
| `.btn-danger` | Sell/negative action button (red) |
| `.trading-input` | Form input field |
| `.nav-link` | Navigation link |

### Color Palette

Use the project's predefined colors, not arbitrary values:

```html
<!-- CORRECT: Design system colors -->
<span class="text-trading-green">+2.34%</span>
<span class="text-trading-red">-1.52%</span>
<button class="bg-primary-500">Connect</button>

<!-- INCORRECT: Arbitrary or hardcoded colors -->
<span class="text-[#00C853]">+2.34%</span>
<span style="color: green;">+2.34%</span>
```

**Trading Colors:**

| Token | Hex | Usage |
|-------|-----|-------|
| `text-trading-green` | `#00C853` | Positive price changes, buy actions |
| `text-trading-red` | `#FF1744` | Negative price changes, sell actions |
| `bg-primary-500` | Brand blue | Primary buttons, links, highlights |

### Utility Class Ordering

Follow this order for TailwindCSS classes:

```
Layout -> Sizing -> Spacing -> Typography -> Colors -> Effects -> Responsive
```

```html
<!-- CORRECT: Ordered classes -->
<div class="flex items-center w-full p-4 text-sm text-white bg-gray-800 rounded-lg shadow-lg md:p-6">

<!-- INCORRECT: Random order -->
<div class="shadow-lg text-sm bg-gray-800 flex p-4 rounded-lg text-white w-full items-center md:p-6">
```

### Avoid Arbitrary Values

Minimize the use of arbitrary values (`w-[347px]`, `mt-[13px]`). Use the TailwindCSS spacing and sizing scales:

```html
<!-- CORRECT: Standard scale values -->
<div class="w-80 mt-4 p-6">

<!-- INCORRECT: Magic numbers -->
<div class="w-[347px] mt-[13px] p-[23px]">
```

### Responsive Design

Use mobile-first responsive design:

```html
<!-- Mobile first: base styles are mobile, then scale up -->
<div class="flex flex-col gap-4 md:flex-row md:gap-6 lg:gap-8">
    <div class="w-full md:w-1/2 lg:w-1/3">
        <!-- Content -->
    </div>
</div>
```

---

## Database Standards

### Migrations

- Use descriptive migration names.
- Always include both `up()` and `down()` methods.
- Use appropriate column types and constraints.

```php
// CORRECT
public function up(): void
{
    Schema::create('orders', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('pair', 20);               // BTC/USDT
        $table->enum('side', ['buy', 'sell']);
        $table->enum('type', ['limit', 'market', 'stop_limit']);
        $table->decimal('amount', 24, 8);
        $table->decimal('price', 24, 8)->nullable();
        $table->decimal('filled_amount', 24, 8)->default(0);
        $table->string('status', 20)->default('open');
        $table->unsignedInteger('chain_id');
        $table->string('tx_hash', 66)->nullable();
        $table->timestamps();

        $table->index(['user_id', 'status']);
        $table->index(['pair', 'side', 'status']);
    });
}

public function down(): void
{
    Schema::dropIfExists('orders');
}
```

### Naming Conventions

| Element | Convention | Example |
|---------|-----------|---------|
| Tables | snake_case, plural | `users`, `trading_pairs`, `order_fills` |
| Columns | snake_case | `chain_id`, `created_at`, `wallet_address` |
| Foreign keys | `{table_singular}_id` | `user_id`, `order_id` |
| Pivot tables | alphabetical, singular | `order_token`, `chain_user` |
| Indexes | meaningful names | `orders_user_status_index` |

### Decimal Precision

For financial data, always use `decimal(24, 8)` to avoid floating-point precision issues:

```php
$table->decimal('amount', 24, 8);    // Supports large crypto amounts
$table->decimal('price', 24, 8);     // High-precision pricing
$table->decimal('fee', 24, 8);       // Fee amounts
```

---

## API Standards

### URL Structure

```
/api/v1/{resource}                   # Collection
/api/v1/{resource}/{id}              # Single resource
/api/v1/{resource}/{id}/{subresource}  # Nested resource
```

### HTTP Methods

| Method | Purpose | Example |
|--------|---------|---------|
| `GET` | Read resource(s) | `GET /api/v1/chains` |
| `POST` | Create resource | `POST /api/v1/trading/order` |
| `PUT` | Full update | `PUT /api/v1/orders/{id}` |
| `PATCH` | Partial update | `PATCH /api/v1/orders/{id}` |
| `DELETE` | Delete resource | `DELETE /api/v1/trading/order/{id}` |

### Response Envelope

Every API response must use the standard envelope:

```php
// Success
return response()->json([
    'success' => true,
    'data' => $result,
]);

// Success with pagination
return response()->json([
    'success' => true,
    'data' => $items,
    'meta' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
    ],
]);

// Error
return response()->json([
    'success' => false,
    'error' => [
        'code' => 'ERROR_CODE',
        'message' => 'Human-readable description',
    ],
], $httpStatus);
```

### Validation

Always use Laravel Form Requests for input validation:

```php
// app/Http/Requests/CreateOrderRequest.php
class CreateOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'pair' => 'required|string|max:20',
            'side' => 'required|in:buy,sell',
            'type' => 'required|in:limit,market,stop_limit',
            'amount' => 'required|numeric|min:0.0001',
            'price' => 'required_if:type,limit,stop_limit|numeric|min:0',
            'stopPrice' => 'required_if:type,stop_limit|numeric|min:0',
            'chainId' => 'sometimes|integer',
        ];
    }
}
```

---

## Git Standards

### Branch Naming

| Type | Pattern | Example |
|------|---------|---------|
| Feature | `feature/{description}` | `feature/add-swap-page` |
| Bug Fix | `fix/{description}` | `fix/order-book-scroll` |
| Hotfix | `hotfix/{description}` | `hotfix/security-patch` |
| Refactor | `refactor/{description}` | `refactor/trading-service` |
| Documentation | `docs/{description}` | `docs/api-guide` |

### Commit Messages

Follow the [Conventional Commits](https://www.conventionalcommits.org/) format:

```
type(scope): description

[optional body]

[optional footer]
```

**Types:**

| Type | Description | Example |
|------|-------------|---------|
| `feat` | New feature | `feat(trading): add limit order support` |
| `fix` | Bug fix | `fix(wallet): resolve MetaMask connection issue` |
| `docs` | Documentation | `docs(readme): update installation guide` |
| `style` | Code formatting | `style(ui): adjust button spacing` |
| `refactor` | Code restructuring | `refactor(api): extract chain service` |
| `test` | Adding tests | `test(orders): add order creation tests` |
| `chore` | Maintenance | `chore(deps): update Laravel to 11.1` |
| `perf` | Performance | `perf(chart): optimize candlestick rendering` |

### Pre-Commit Checklist

Before every commit:

```bash
# 1. Format code
make lint

# 2. Run tests
make test

# 3. Check for security issues
make audit
```

### Pull Request Rules

1. One feature or fix per PR.
2. Include tests for new functionality.
3. Update documentation if behavior changes.
4. Squash merge is preferred for clean history.
5. At least one approval required before merge.
6. All CI checks must pass.

---

## Testing Standards

### Requirements

- All new features must include tests.
- Bug fixes must include a regression test.
- Maintain or improve code coverage.

### Test File Naming

| Test Type | Location | Naming |
|-----------|----------|--------|
| PHP Feature | `tests/Feature/` | `{Feature}Test.php` |
| PHP Unit | `tests/Unit/` | `{Class}Test.php` |
| API Tests | `tests/Feature/Api/` | `{Controller}Test.php` |
| JS Component | `tests/js/components/` | `{Component}.test.js` |
| JS Utility | `tests/js/utils/` | `{utility}.test.js` |

### PHP Test Structure

```php
class TradingTest extends TestCase
{
    use RefreshDatabase;

    public function test_trading_page_loads(): void
    {
        $response = $this->get('/trade/BTC-USDT');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->component('Trade')
                ->has('pair')
        );
    }

    public function test_can_create_limit_order(): void
    {
        $response = $this->postJson('/api/v1/trading/order', [
            'pair' => 'BTC/USDT',
            'side' => 'buy',
            'type' => 'limit',
            'amount' => 0.1,
            'price' => 67000,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pair', 'BTC/USDT')
            ->assertJsonPath('data.status', 'open');
    }

    public function test_cannot_create_order_with_invalid_pair(): void
    {
        $response = $this->postJson('/api/v1/trading/order', [
            'pair' => '',
            'side' => 'buy',
            'type' => 'limit',
            'amount' => 0.1,
            'price' => 67000,
        ]);

        $response->assertStatus(422);
    }
}
```

### JavaScript Test Structure

```javascript
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import OrderBook from '@/Components/Trading/OrderBook.vue';

describe('OrderBook', () => {
    it('renders bid and ask sections', () => {
        const wrapper = mount(OrderBook, {
            props: { symbol: 'BTC/USDT' },
        });

        expect(wrapper.text()).toContain('Order Book');
    });

    it('displays correct number of price levels', () => {
        const wrapper = mount(OrderBook, {
            props: {
                symbol: 'BTC/USDT',
                bids: [['67000', '0.5'], ['66999', '1.0']],
                asks: [['67001', '0.3'], ['67002', '0.8']],
            },
        });

        expect(wrapper.findAll('.bid-row')).toHaveLength(2);
        expect(wrapper.findAll('.ask-row')).toHaveLength(2);
    });

    it('emits price-selected on row click', async () => {
        const wrapper = mount(OrderBook, {
            props: {
                symbol: 'BTC/USDT',
                bids: [['67000', '0.5']],
            },
        });

        await wrapper.find('.bid-row').trigger('click');
        expect(wrapper.emitted('price-selected')).toBeTruthy();
        expect(wrapper.emitted('price-selected')[0]).toEqual(['67000']);
    });
});
```

### Test Naming Convention

Use descriptive test names that read as sentences:

```php
// CORRECT
test_can_create_limit_order()
test_returns_404_for_unknown_chain()
test_validates_minimum_order_amount()

// INCORRECT
test_order()
test1()
testCreate()
```

---

## Security Standards

### Absolute Rules

These rules must never be violated:

1. **Never store private keys** on the server or in the database.
2. **Never log sensitive data** (private keys, API secrets, wallet signatures).
3. **Never commit secrets** (`.env`, API keys, credentials) to version control.
4. **Never expose internal errors** in production API responses.
5. **Never trust client-side validation alone** -- always validate server-side.
6. **Never use raw SQL** without parameter binding.

### Input Validation

```php
// CORRECT: Validate all inputs
$validated = $request->validate([
    'amount' => 'required|numeric|min:0.0001|max:1000000',
    'chain_id' => 'required|integer',
    'address' => 'required|string|regex:/^0x[a-fA-F0-9]{40}$/',
]);

// INCORRECT: Using unvalidated input
$amount = $request->input('amount');  // Must validate first
```

### Output Sanitization

```php
// Blade auto-escapes by default (CORRECT)
{{ $userInput }}

// Only use raw output for trusted content
{!! $trustedHtml !!}  // Use with extreme caution
```

### CSRF Protection

All forms must include CSRF tokens:

```html
<form method="POST">
    @csrf
    <!-- form fields -->
</form>
```

### Rate Limiting

All API endpoints must have rate limiting:

```php
Route::middleware('throttle:60,1')->group(function () {
    // General endpoints: 60 requests per minute
});

Route::middleware('throttle:trading')->group(function () {
    // Trading endpoints: 100 requests per minute
});
```

---

## File Organization

### PHP Files

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Controller.php          # Base controller
│   │   └── Api/                    # API controllers only
│   ├── Middleware/                  # Custom middleware
│   └── Requests/                   # Form request validation
├── Models/                         # One model per file
├── Services/                       # Business logic services
├── Events/                         # Event classes
├── Listeners/                      # Event listeners
└── Providers/                      # Service providers
```

### Vue Files

```
resources/js/
├── Components/
│   ├── {Feature}/                  # Grouped by feature domain
│   │   ├── FeatureComponent.vue    # PascalCase
│   │   └── SubComponent.vue
│   └── Common/                     # Shared components
├── Composables/
│   └── use{Name}.js                # camelCase with "use" prefix
├── Layouts/
│   └── {Name}Layout.vue
├── Pages/
│   ├── {PageName}.vue              # Top-level pages
│   └── {Section}/                  # Grouped pages
│       └── {PageName}.vue
└── Stores/
    └── use{Name}Store.js           # camelCase with "use" prefix and "Store" suffix
```

### Test Files

```
tests/
├── Feature/
│   ├── Api/
│   │   └── {Controller}Test.php
│   └── {Feature}Test.php
├── Unit/
│   └── {Class}Test.php
└── js/
    ├── components/
    │   └── {Component}.test.js
    └── utils/
        └── {utility}.test.js
```

### Naming Summary

| Type | Convention | Example |
|------|-----------|---------|
| PHP Classes | PascalCase | `TradingService.php` |
| PHP Methods | camelCase | `calculateFee()` |
| PHP Constants | UPPER_SNAKE | `MAX_ORDER_SIZE` |
| Vue Components | PascalCase | `OrderBook.vue` |
| Vue Props | camelCase | `initialPrice` |
| Vue Events | kebab-case | `price-selected` |
| Composables | camelCase with `use` | `useWallet.js` |
| Pinia Stores | camelCase with `use` + `Store` | `useTradingStore.js` |
| CSS Classes | kebab-case | `glass-dark`, `btn-primary` |
| Config Files | kebab-case / snake_case | `chains.php` |
| Route Names | dot notation | `trade.pair` |
| Database Tables | snake_case, plural | `trading_pairs` |
| Database Columns | snake_case | `chain_id` |
| Environment Vars | UPPER_SNAKE | `TRADING_FEE_PERCENTAGE` |
| Git Branches | kebab-case with type prefix | `feature/add-swap-page` |

---

<p align="center">
  <strong><a href="https://xmanstudio.com">Xman Studio</a></strong> -- TPIX TRADE Coding Standards
</p>
