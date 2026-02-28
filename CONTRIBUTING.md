# Contributing to TPIX TRADE

ขอบคุณที่สนใจร่วมพัฒนา TPIX TRADE! เอกสารนี้จะช่วยให้คุณเริ่มต้นได้อย่างรวดเร็ว

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Workflow](#development-workflow)
- [Coding Standards](#coding-standards)
- [Testing](#testing)
- [Pull Request Process](#pull-request-process)
- [Security](#security)

---

## Code of Conduct

- เคารพซึ่งกันและกัน
- ให้ feedback อย่างสร้างสรรค์
- ไม่ discriminate ไม่ว่าจะด้วยเหตุผลใด
- Focus ที่ code และ idea ไม่ใช่ตัวบุคคล

---

## Getting Started

### Prerequisites

- PHP 8.2+
- Node.js 18+
- Composer
- NPM or Yarn
- Git

### Installation

```bash
# Clone repository
git clone https://github.com/xjanova/ThaiXTrade.git
cd ThaiXTrade

# Install dependencies
./install.sh

# Or manually:
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
```

### Running Locally

```bash
# Start development servers
make dev

# Or separately:
php artisan serve    # Backend on :8000
npm run dev          # Vite on :5173
```

---

## Development Workflow

### 1. Create a Branch

```bash
# From main branch
git checkout main
git pull origin main

# Create feature branch
git checkout -b feature/your-feature-name
```

### Branch Naming Convention

| Type | Pattern | Example |
|------|---------|---------|
| Feature | `feature/description` | `feature/add-swap-page` |
| Bug Fix | `fix/description` | `fix/wallet-connection` |
| Hotfix | `hotfix/description` | `hotfix/security-issue` |
| Refactor | `refactor/description` | `refactor/trading-service` |
| Docs | `docs/description` | `docs/api-guide` |

### 2. Make Changes

- Follow [Coding Standards](#coding-standards)
- Write tests for new features
- Update documentation if needed

### 3. Test Your Changes

```bash
# Run all tests
make test

# Run specific tests
make test-php      # PHP only
make test-js       # JavaScript only

# Run with coverage
make test-coverage
```

### 4. Commit Your Changes

```bash
# Stage changes
git add .

# Commit with conventional message
git commit -m "feat(trading): add stop-loss order type"
```

#### Commit Message Format

```
type(scope): description

[optional body]

[optional footer]
```

**Types:**
- `feat` - New feature
- `fix` - Bug fix
- `docs` - Documentation
- `style` - Code style (formatting, etc.)
- `refactor` - Code refactoring
- `test` - Adding tests
- `chore` - Maintenance tasks
- `perf` - Performance improvements

**Examples:**
```bash
feat(wallet): add WalletConnect v2 support
fix(chart): resolve memory leak in candlestick updates
docs(readme): add deployment instructions
refactor(api): extract validation to form requests
test(orders): add edge case tests for partial fills
```

### 5. Push and Create PR

```bash
# Push to your branch
git push -u origin feature/your-feature-name

# Create Pull Request on GitHub
```

---

## Coding Standards

### PHP / Laravel

- Follow PSR-12 coding style
- Use Laravel Pint for formatting: `./vendor/bin/pint`
- Use typed properties and return types
- Use dependency injection
- Document complex logic with comments

```php
// Good
public function calculateFee(float $amount, float $percentage): float
{
    return $amount * ($percentage / 100);
}

// Bad
function calc($a, $p) {
    return $a * $p / 100;
}
```

### Vue.js

- Use Composition API with `<script setup>`
- Use TypeScript types where beneficial
- Keep components small and focused
- Use Pinia for state management

```vue
<!-- Good -->
<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    price: { type: Number, required: true },
});

const formatted = computed(() => `$${props.price.toLocaleString()}`);
</script>
```

### CSS / TailwindCSS

- Use design system classes from `resources/css/app.css`
- Avoid arbitrary values when possible
- Use semantic class names

```html
<!-- Good: Use design system -->
<button class="btn-primary">Connect Wallet</button>

<!-- Avoid: Arbitrary values -->
<button class="bg-[#0ea5e9] px-[17px]">Connect Wallet</button>
```

### File Naming

| Type | Convention | Example |
|------|------------|---------|
| PHP Classes | PascalCase | `TradingService.php` |
| Vue Components | PascalCase | `OrderBook.vue` |
| Config files | kebab-case | `chains.php` |
| Routes | kebab-case | `api.php` |
| Tests | PascalCase + Test | `OrderBookTest.php` |

---

## Testing

### Requirements

- All new features must have tests
- Bug fixes should include regression tests
- Maintain or improve code coverage

### Running Tests

```bash
# All tests
make test

# PHP tests only
php artisan test
# or
./vendor/bin/phpunit

# JavaScript tests only
npm run test:run

# With coverage
make test-coverage
```

### Test Structure

```
tests/
├── Feature/           # HTTP/Integration tests
│   ├── Api/          # API endpoint tests
│   └── *.php         # Page tests
├── Unit/             # Unit tests
│   └── *.php
└── js/               # JavaScript tests
    ├── components/   # Vue component tests
    └── utils/        # Utility function tests
```

### Writing Tests

**PHP Feature Test:**
```php
public function test_can_create_order(): void
{
    $response = $this->postJson('/api/v1/orders', [
        'pair' => 'BTC/USDT',
        'side' => 'buy',
        'amount' => 0.1,
        'price' => 67000,
    ]);

    $response->assertStatus(201)
             ->assertJsonPath('data.pair', 'BTC/USDT');
}
```

**JavaScript Component Test:**
```javascript
import { mount } from '@vue/test-utils';
import OrderBook from '@/Components/Trading/OrderBook.vue';

describe('OrderBook', () => {
    it('renders bid and ask sections', () => {
        const wrapper = mount(OrderBook, {
            props: { symbol: 'BTC/USDT' },
        });

        expect(wrapper.text()).toContain('Order Book');
    });
});
```

---

## Pull Request Process

### Before Submitting

1. ✅ Run linting: `make lint`
2. ✅ Run tests: `make test`
3. ✅ Run security audit: `make audit`
4. ✅ Update documentation if needed
5. ✅ Add changelog entry if significant

### PR Template

```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## Testing
- [ ] Tests pass locally
- [ ] Added new tests for this feature
- [ ] Tested manually

## Checklist
- [ ] Code follows project style guidelines
- [ ] Self-reviewed my code
- [ ] Commented complex logic
- [ ] Updated documentation
- [ ] No new warnings
```

### Review Process

1. At least 1 approval required
2. All CI checks must pass
3. No merge conflicts
4. Squash merge preferred

---

## Security

### Reporting Vulnerabilities

**DO NOT** create public issues for security vulnerabilities.

Instead, email: security@xmanstudio.com

Include:
- Description of vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

### Security Best Practices

- Never commit secrets or credentials
- Never store private keys
- Always validate user input
- Use parameterized queries
- Keep dependencies updated
- Use HTTPS everywhere

---

## Project Structure Quick Reference

```
Key Files:
├── CLAUDE.md           # AI development guidelines
├── CONTRIBUTING.md     # This file
├── CHANGELOG.md        # Version history
├── version.json        # Current version
├── Makefile            # Common commands
│
├── install.sh          # Installation script
├── deploy.sh           # Deployment script
└── scripts/
    ├── test.sh         # Test runner
    ├── bump-version.sh # Version management
    ├── backup.sh       # Backup utility
    └── security-audit.sh # Security checks
```

---

## Need Help?

- 📖 Read `CLAUDE.md` for detailed conventions
- 🔍 Search existing issues
- 💬 Open a discussion for questions
- 🐛 Create an issue for bugs

---

## License

By contributing, you agree that your contributions will be licensed under the same license as the project.

---

**Thank you for contributing to TPIX TRADE!** 🙏

*Developed by Xman Studio*
