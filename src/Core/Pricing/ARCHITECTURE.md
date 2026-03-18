# New Pricing Architecture - Specification

## Context

PrestaShop's current pricing system suffers from:
- Monolithic legacy code (`Product::getPriceStatic` with 17 parameters, recursive static calls)
- Float-based arithmetic causing rounding errors (~50 open bugs in "Taxes and Prices")
- Business logic coupled with persistence (ObjectModel)
- Untestable architecture (static methods, global state, hidden dependencies)

This spec defines a clean, composable, fully-tested pricing architecture in `PrestaShop\PrestaShop\Core\Pricing` that replaces the legacy system behind a feature flag. The architecture prioritizes:
- `DecimalNumber` exclusively (no native floats)
- Small, independent, testable calculator components
- Symfony DI with tagged services and priority-based chaining
- Debug-only audit trail (transparent to calculators)
- Module extensibility from day one
- FO + BO compatibility via shared service definitions
- Dual-context support: same architecture for Cart (FO) and Order (BO) with swappable providers

**Related issues:** #40948, #40949, #40951, #40952, #41014, #40979
**Related epics:** #9703, #19445

---

## 1. Namespace Structure

```
src/Core/Pricing/
├── Context/
│   ├── PriceContext.php                    # Injected service replacing getPriceStatic's 17 params
│   └── PriceContextFactory.php
├── ValueObject/
│   ├── TaxablePrice.php                   # Mutable, DecimalNumber-based, auto-sync tax incl/excl
│   ├── TaxRate.php
│   ├── PriceModification.php              # Debug: single modification record
│   └── PriceBreakdown.php                 # Debug: collection of PriceModification steps
├── Product/
│   ├── ProductPriceInterface.php
│   ├── ProductPrice.php                   # Lightweight DTO (no tracking)
│   ├── TrackedProductPrice.php            # Debug DTO (auto-tracks modifications via debug_backtrace)
│   ├── Calculator/
│   │   ├── ProductCalculatorInterface.php
│   │   ├── ProductCalculatorOrchestrator.php
│   │   ├── BaseProductCalculator.php
│   │   ├── CombinationCalculator.php
│   │   ├── SpecificPriceCalculator.php
│   │   ├── GroupReductionCalculator.php
│   │   ├── TaxCalculator.php
│   │   ├── EcoTaxCalculator.php
│   │   ├── CurrencyCalculator.php
│   │   └── RoundingCalculator.php
│   └── Provider/
│       ├── ProductProviderInterface.php
│       ├── DatabaseProductProvider.php     # FO: reads from ps_product
│       └── MockProductProvider.php         # For unit tests
├── Tax/
│   ├── TaxProviderInterface.php
│   ├── DatabaseTaxProvider.php
│   ├── TaxComputationMethod.php           # Enum: COMBINE, ONE_AFTER_ANOTHER
│   └── MockTaxProvider.php
├── SpecificPrice/
│   ├── SpecificPriceProviderInterface.php
│   ├── DatabaseSpecificPriceProvider.php
│   └── MockSpecificPriceProvider.php
├── Cart/
│   ├── CartPriceInterface.php
│   ├── CartPrice.php                      # Lightweight DTO
│   ├── TrackedCartPrice.php               # Debug DTO
│   ├── Calculator/
│   │   ├── CartCalculatorInterface.php
│   │   ├── CartCalculatorOrchestrator.php
│   │   ├── ProductTotalCalculator.php
│   │   ├── ShippingCalculator.php
│   │   ├── WrappingCalculator.php
│   │   └── Discount/                      # Split into multiple smaller calculators
│   │       ├── PercentageDiscountCalculator.php
│   │       ├── AmountDiscountCalculator.php
│   │       ├── FreeShippingDiscountCalculator.php
│   │       └── FreeGiftDiscountCalculator.php
│   ├── Provider/
│   │   ├── CartProductProviderInterface.php
│   │   ├── DatabaseCartProductProvider.php # FO: reads from ps_cart_product
│   │   └── MockCartProductProvider.php
│   ├── CartManager.php
│   ├── CartPersisterInterface.php
│   └── Checker/
│       ├── CartCheckerInterface.php
│       ├── CompositeCartChecker.php
│       ├── ProductPriceChecker.php
│       ├── DiscountChecker.php
│       ├── TaxRateChecker.php
│       └── ShippingChecker.php
├── Rounding/
│   ├── RoundingServiceInterface.php
│   └── RoundingService.php
└── Debug/
    ├── PricingRegistry.php                # Collects computed prices during request
    ├── PricingHistoryDisplayer.php        # Formats debug history for display
    └── PricingDataCollector.php           # Symfony profiler integration (in PrestaShopBundle)
```

---

## 2. Design Principles

### 2.1 Mutable DTOs, Transparent Debug Tracking

Price DTOs (`ProductPrice`, `CartPrice`) are **mutable** with setters. This keeps calculator code simple — calculators just call setters on the DTO they receive.

Debug tracking is **completely transparent to calculators**:

- `ProductPrice` / `CartPrice` — lightweight, no tracking overhead
- `TrackedProductPrice` / `TrackedCartPrice` — same interface, auto-records every setter call using `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)` to capture the calling calculator's class name and line number

The orchestrator creates the appropriate DTO based on `kernel.debug`. Calculators are completely unaware of which implementation they're working with.

```php
// In TrackedProductPrice — automatic, no calculator involvement:
public function setUnitPrice(TaxablePrice $unitPrice): void
{
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    $caller = $trace[1] ?? [];
    $this->breakdown->addStep(new PriceModification(
        callerClass: $caller['class'] ?? 'unknown',
        callerLine: $caller['line'] ?? 0,
        property: 'unitPrice',
        previousValue: (string) $this->unitPrice->getTaxExcluded(),
        newValue: (string) $unitPrice->getTaxExcluded(),
    ));
    $this->unitPrice = $unitPrice;
}
```

### 2.2 PriceContext as an Injected Service

`PriceContext` replaces `getPriceStatic`'s 17 parameters. It is **not** passed as a method parameter to `compute()`. Instead, it's a service created by `PriceContextFactory` and injected via constructor DI into calculators that need it.

This keeps the calculator interface minimal and avoids threading context through every method call.

### 2.3 No `supports()` Method

Calculators have a single `compute()` method. When a calculator is not relevant for the current computation (e.g., EcoTaxCalculator when eco-tax is disabled), it simply returns early, leaving the input DTO unchanged.

### 2.4 Dual-Context: Cart (FO) vs Order (BO)

The same architecture computes prices for both:
- **Cart context (FO):** reads from `ps_product`, `ps_cart_product`, `ps_specific_price`, etc.
- **Order context (BO):** reads from `ps_order_detail` (prices are stored, not computed from catalog)

This is achieved through:
- **Interface-based DI:** All providers use interfaces. Different contexts wire different implementations.
- **Separate tags per context:** `prestashop.pricing.cart.product_calculator` vs `prestashop.pricing.order.product_calculator`
- **Same calculator classes, different providers:** e.g., `BaseProductCalculator` is registered twice with different `ProductProviderInterface` implementations

```yaml
# Cart context: reads from product table
prestashop.pricing.cart.base_product_calculator:
    class: BaseProductCalculator
    arguments:
        $productProvider: '@...DatabaseProductProvider'
    tags: [{ name: 'prestashop.pricing.cart.product_calculator', priority: 100 }]

# Order context: reads from order_detail table
prestashop.pricing.order.base_product_calculator:
    class: BaseProductCalculator
    arguments:
        $productProvider: '@...OrderDetailProductProvider'
    tags: [{ name: 'prestashop.pricing.order.product_calculator', priority: 100 }]
```

Shared calculators (e.g., `RoundingCalculator`) are tagged under both contexts.

---

## 3. Value Objects

### 3.1 TaxRate

```php
namespace PrestaShop\PrestaShop\Core\Pricing\ValueObject;

use PrestaShop\Decimal\DecimalNumber;

final class TaxRate
{
    public function __construct(private readonly DecimalNumber $rate) {} // Validates >= 0

    public static function zero(): self;
    public function getRate(): DecimalNumber;         // e.g. "20" for 20%
    public function getMultiplier(): DecimalNumber;   // 1 + rate/100, e.g. "1.2"
    public function computeTaxAmount(DecimalNumber $taxExcluded): DecimalNumber; // taxExcl * rate / 100
}
```

### 3.2 TaxablePrice (Mutable, Auto-Sync)

```php
namespace PrestaShop\PrestaShop\Core\Pricing\ValueObject;

use PrestaShop\Decimal\DecimalNumber;

final class TaxablePrice
{
    private DecimalNumber $taxExcluded;
    private DecimalNumber $taxIncluded;
    private DecimalNumber $taxAmount;
    private TaxRate $taxRate;

    // Primary: derives taxIncluded from taxExcluded * taxRate.getMultiplier()
    public function __construct(DecimalNumber $taxExcluded, TaxRate $taxRate);

    // Reverse: derives taxExcluded from taxIncluded / taxRate.getMultiplier()
    public static function fromTaxIncluded(DecimalNumber $taxIncluded, TaxRate $taxRate): self;
    public static function zero(): self;

    public function getTaxExcluded(): DecimalNumber;
    public function getTaxIncluded(): DecimalNumber;
    public function getTaxAmount(): DecimalNumber;
    public function getTaxRate(): TaxRate;

    // Auto-sync: setting one side recomputes the other from the current taxRate
    public function setTaxExcluded(DecimalNumber $taxExcluded): void; // recomputes taxIncl + taxAmount
    public function setTaxIncluded(DecimalNumber $taxIncluded): void; // recomputes taxExcl + taxAmount
    public function setTaxRate(TaxRate $taxRate): void;               // recomputes from taxExcl (source of truth)
}
```

**Key design decisions:**
- **Mutable** — setters modify in place, recomputing counterparts automatically
- `taxExcluded` is the default source of truth (when taxRate changes, taxIncl is recomputed from taxExcl)
- All intermediate divisions use precision 20 to avoid premature truncation
- Always derive from one side + taxRate — no constructor that accepts both taxExcl and taxIncl directly

### 3.3 PriceContext (Injected Service)

```php
namespace PrestaShop\PrestaShop\Core\Pricing\Context;

final class PriceContext
{
    public function __construct(
        private readonly int $shopId,
        private readonly int $currencyId,
        private readonly int $countryId,
        private readonly int $stateId,
        private readonly string $zipCode,
        private readonly int $customerId,
        private readonly int $groupId,
        private readonly int $quantity,
        private readonly \DateTimeImmutable $date,
        private readonly ?int $addressId = null,
    ) {}

    // Getters...
}
```

Created by `PriceContextFactory` via DI factory method. Injected into calculators via constructor DI — **not** passed as a method parameter.

### 3.4 PriceModification + PriceBreakdown (Debug Only)

Used only by `TrackedProductPrice` / `TrackedCartPrice`. Calculators never interact with these directly.

```php
final class PriceModification
{
    public function __construct(
        private readonly string $callerClass,   // e.g. "BaseProductCalculator"
        private readonly int $callerLine,       // line number in the calculator
        private readonly string $property,      // e.g. "unitPrice", "totalPrice"
        private readonly string $previousValue, // (string) DecimalNumber
        private readonly string $newValue,
    ) {}
}

final class PriceBreakdown
{
    /** @var PriceModification[] */
    private array $steps = [];

    public function addStep(PriceModification $step): void;
    public function getSteps(): array;
    public function count(): int;
}
```

---

## 4. Product Pricing

### 4.1 ProductPriceInterface

```php
namespace PrestaShop\PrestaShop\Core\Pricing\Product;

use PrestaShop\PrestaShop\Core\Pricing\ValueObject\TaxablePrice;

interface ProductPriceInterface
{
    public function getProductId(): int;
    public function getCombinationId(): int;

    public function getUnitPrice(): TaxablePrice;
    public function setUnitPrice(TaxablePrice $unitPrice): void;

    public function getTotalPrice(): TaxablePrice;
    public function setTotalPrice(TaxablePrice $totalPrice): void;

    public function getOriginalPrice(): TaxablePrice;
    public function setOriginalPrice(TaxablePrice $originalPrice): void;
}
```

**Two implementations:**
- `ProductPrice` — lightweight, setters simply assign
- `TrackedProductPrice` — same interface, each setter also records a `PriceModification` via `debug_backtrace()`

### 4.2 ProductCalculatorInterface

```php
namespace PrestaShop\PrestaShop\Core\Pricing\Product\Calculator;

use PrestaShop\PrestaShop\Core\Pricing\Product\ProductPriceInterface;

interface ProductCalculatorInterface
{
    /**
     * Applies a pricing computation step to the ProductPrice, mutating it in place.
     * Returns early when not relevant for the current context.
     * PriceContext is injected via constructor DI, not passed as parameter.
     */
    public function compute(ProductPriceInterface $productPrice): void;
}
```

**No `supports()` method.** Calculators return early from `compute()` when not relevant.

### 4.3 ProductCalculatorOrchestrator

```php
namespace PrestaShop\PrestaShop\Core\Pricing\Product\Calculator;

use PrestaShop\PrestaShop\Core\Pricing\Product\ProductPriceInterface;

final class ProductCalculatorOrchestrator implements ProductPriceInterface
{
    /**
     * @param iterable<ProductCalculatorInterface> $calculators Tagged iterator, priority-sorted
     */
    public function __construct(
        private readonly iterable $calculators,
    ) {}

    public function compute(ProductPriceInterface $productPrice): ProductPrice
    {
        foreach ($this->calculators as $calculator) {
            $calculator->compute($productPrice);
        }

        return $productPrice;
    }
}
```

### 4.4 Calculator Implementations

| Calculator | Priority | Responsibility |
|---|---|---|
| `BaseProductCalculator` | 100 | Fetches base price from `ProductProviderInterface`, sets initial `unitPrice`, `originalPrice`, and `totalPrice` (unit * quantity) |
| `CombinationCalculator` | 90 | Adds combination price impact (`ps_product_attribute.price`) |
| `SpecificPriceCalculator` | 80 | Applies specific price rules (fixed override or percentage/amount reduction) |
| `GroupReductionCalculator` | 70 | Applies customer group reduction |
| `EcoTaxCalculator` | 60 | Adds eco-tax amount |
| `TaxCalculator` | 50 | Computes tax-included price from tax-excluded using tax rules |
| `CurrencyCalculator` | 30 | Converts prices from default currency to customer's currency |
| `RoundingCalculator` | 10 | Final rounding — the **only** place rounding happens |

**Higher priority = earlier execution.** Matches PrestaShop's existing convention (see `ProductCommandsBuilder`).

**Rounding principle:** All intermediate calculators work at full `DecimalNumber` precision — no rounding occurs until the `RoundingCalculator` at the very end. This eliminates cumulative rounding errors, which are the root cause of most "1 cent off" bugs. The `PS_ROUND_TYPE` configuration controls only cart-level behavior (whether rounding applied per line or per total), not product-level rounding.

Example implementation:

```php
namespace PrestaShop\PrestaShop\Core\Pricing\Product\Calculator;

use PrestaShop\PrestaShop\Core\Pricing\Product\Provider\ProductProviderInterface;

final class BaseProductCalculator implements ProductCalculatorInterface
{
    public function __construct(
        private readonly ProductProviderInterface $productProvider,
        private readonly PriceContext $priceContext,
    ) {}

    public function compute(ProductPriceInterface $productPrice): void
    {
        $basePrice = $this->productProvider->getBasePrice($productPrice->getProductId());
        $unitPrice = new TaxablePrice($basePrice, TaxRate::zero());

        $productPrice->setUnitPrice($unitPrice);
        $productPrice->setOriginalPrice(new TaxablePrice($basePrice, TaxRate::zero()));

        // Combination impact
        if ($productPrice->getCombinationId() > 0) {
            $impact = $this->productProvider->getCombinationPriceImpact(
                $productPrice->getProductId(),
                $productPrice->getCombinationId()
            );
            $productPrice->setUnitPrice(new TaxablePrice($basePrice->plus($impact), TaxRate::zero()));
        }

        // Total = unit * quantity
        // This is a bad example because product quantity won't come from the PriceContext, but it shows the principle
        $qty = new DecimalNumber((string) $this->priceContext->getQuantity());
        $productPrice->setTotalPrice(new TaxablePrice(
            $productPrice->getUnitPrice()->getTaxExcluded()->times($qty),
            TaxRate::zero()
        ));
    }
}
```

### 4.5 Providers (Data Access Layer)

Providers isolate database access. Each has a Database implementation and a Mock for unit testing.

```php
namespace PrestaShop\PrestaShop\Core\Pricing\Product\Provider;

use PrestaShop\Decimal\DecimalNumber;

interface ProductProviderInterface
{
    /**
     * Returns the base price (tax excluded) for a product.
     */
    public function getBasePrice(int $productId): DecimalNumber;

    /**
     * Returns the combination price impact.
     */
    public function getCombinationPriceImpact(int $productId, int $combinationId): DecimalNumber;
}
```

Providers query data from database directly, not via ObjectModel.

| Implementation               | Context | Source |
|------------------------------|---|---|
| `CatalogProductProvider`     | Cart (FO) | `ps_product.price`, `ps_product_attribute.price` |
| `OrderDetailProductProvider` | Order (BO) | `ps_order_detail.unit_price_tax_excl` |
| `MockProductProvider`        | Tests | In-memory arrays |

---

## 5. Cart Pricing

### 5.1 CartPriceInterface

```php
interface CartPriceInterface
{
    public function getCartId(): int;

    public function getProductTotal(): TaxablePrice;
    public function setProductTotal(TaxablePrice $productTotal): void;

    public function getShippingTotal(): TaxablePrice;
    public function setShippingTotal(TaxablePrice $shippingTotal): void;

    public function getWrappingTotal(): TaxablePrice;
    public function setWrappingTotal(TaxablePrice $wrappingTotal): void;

    public function getDiscountTotal(): TaxablePrice;
    public function setDiscountTotal(TaxablePrice $discountTotal): void;

    public function getCartTotal(): TaxablePrice;   // Renamed from grandTotal
    public function setCartTotal(TaxablePrice $cartTotal): void;

    /** @return ProductPriceInterface[] */
    public function getProductPrices(): array;
    public function setProductPrices(array $productPrices): void;
}
```

Same pattern: `CartPrice` (lightweight) and `TrackedCartPrice` (debug with auto-tracking).

### 5.2 CartCalculatorInterface

```php
namespace PrestaShop\PrestaShop\Core\Pricing\Cart\Calculator;

interface CartCalculatorInterface
{
    public function compute(CartPriceInterface $cartPrice): void;
}
```

Same principles: no `supports()`, PriceContext injected via constructor.

| Calculator | Priority | Responsibility |
|---|---|---|
| `ProductTotalCalculator` | 100 | Computes each product's price via `ProductCalculatorOrchestrator`, sums into `productTotal` |
| `ShippingCalculator` | 80 | Computes shipping fees based on carrier, weight, cart rules |
| `WrappingCalculator` | 70 | Gift wrapping costs |
| `PercentageDiscountCalculator` | 64 | Percentage-based cart rule discounts |
| `AmountDiscountCalculator` | 63 | Fixed amount cart rule discounts |
| `FreeShippingDiscountCalculator` | 62 | Free shipping cart rules |
| `FreeGiftDiscountCalculator` | 61 | Free gift cart rules |
| `CartRoundingCalculator` | 10 | Final cart-level rounding (`PS_ROUND_TYPE` controls per-line vs per-total) |

### 5.3 CartManager

Orchestrates cart computation lifecycle: read -> check freshness -> compute -> persist.

```php
namespace PrestaShop\PrestaShop\Core\Pricing\Cart;

class CartManager
{
    public function __construct(
        private readonly CartProductProviderInterface $cartProductProvider,
        private readonly Checker\CompositeCartChecker $cartChecker,
        private readonly Calculator\CartCalculatorOrchestrator $cartCalculator,
        private readonly CartPersisterInterface $cartPersister,
    ) {}

    public function getCartPrice(int $cartId): CartPriceInterface
    {
        $cartPrice = $this->cartProductProvider->getCartPrice($cartId);

        if (!$this->cartChecker->isUpToDate($cartPrice)) {
            $cartPrice = $this->cartCalculator->compute($cartPrice);
            $this->cartPersister->persist($cartPrice);
        }

        return $cartPrice;
    }

    public function invalidate(int $cartId): void;
    public function update(int $cartId, CartUpdate $update): CartPriceInterface;
}
```

### 5.4 CompositeCartChecker

Tagged `prestashop.pricing.cart_checker`. Detects staleness in: product prices, specific prices, tax rates, discount rules, shipping address, carrier selection.

```php
namespace PrestaShop\PrestaShop\Core\Pricing\Cart\Checker;

final class CompositeCartChecker
{
    /**
     * @param iterable<CartCheckerInterface> $checkers Injected via DI tagged_iterator
     */
    public function __construct(
        private readonly iterable $checkers,
    ) {}

    public function isUpToDate(CartPrice $cartPrice): bool
    {
        foreach ($this->checkers as $checker) {
            if (!$checker->isUpToDate($cartPrice)) {
                return false; // Short-circuit on first stale check
            }
        }
        return true;
    }
}
```

---

## 6. Rounding Strategy

**Core principle: rounding happens ONCE, at the end of the pipeline.** All intermediate calculators operate at full `DecimalNumber` precision.

A dedicated service replaces scattered `Tools::ps_round()` calls:

```php
interface RoundingServiceInterface
{
    public function round(DecimalNumber $value, int $precision): DecimalNumber;
}

final class RoundingService implements RoundingServiceInterface
{
    public function __construct(
        private readonly int $roundMode, // From PS_ROUND_MODE config (half-up, half-down, etc.)
        private readonly int $precision, // From currency precision
    ) {}

    public function round(DecimalNumber $value, ?int $precision = null): DecimalNumber
    {
        // Uses DecimalNumber::round() with the configured mode
        // Modes: ROUND_HALF_UP, ROUND_HALF_DOWN, ROUND_HALF_EVEN, ROUND_UP, etc.
    }
}
```

The `RoundingService` is **only** injected into `RoundingCalculator` and `CartRoundingCalculator`. No other calculator should round values. The `PS_ROUND_TYPE` configuration (per item / per line / per total) controls whether the `CartRoundingCalculator` rounds each line individually or only the final total.

---

## 7. Debug Tooling

### 7.1 PricingRegistry

Request-scoped service that collects all `ProductPriceInterface` and `CartPriceInterface` instances computed during a request. The orchestrator registers each result.

### 7.2 PricingHistoryDisplayer

Formats a `TrackedProductPrice`/`TrackedCartPrice` breakdown into:
- Human-readable string: `[BaseProductCalculator:42] unitPrice: 0 -> 29.99`
- Structured array for Twig rendering

Returns "No tracking data available" for non-tracked DTOs.

### 7.3 Symfony Debug Toolbar Integration

`PricingDataCollector` extends Symfony's `DataCollector`, reads from `PricingRegistry`, and renders in a dedicated profiler panel showing:
- Count of computed prices
- Per-product breakdown with modification history

---

## 8. Module Extensibility

### 8.1 Custom Calculators

Third-party modules add pricing rules by implementing `ProductCalculatorInterface` or `CartCalculatorInterface` and tagging their service:

```yaml
# In module's config/services.yml
services:
    MyModule\Pricing\LoyaltyDiscountCalculator:
        tags:
            - { name: 'prestashop.pricing.cart.product_calculator', priority: 65 }
```

### 8.2 Custom Cart Checkers

Modules can add their own staleness checks:

```yaml
services:
    MyModule\Pricing\LoyaltyPointsChecker:
        tags:
            - { name: 'prestashop.pricing.cart_checker' }
```

### 8.3 Replacing Providers

Modules can decorate data providers (they should not replace them completely):

```yaml
services:
    MyModule\Pricing\CustomProductProvider:
        decorates: PrestaShop\PrestaShop\Core\Pricing\Product\Provider\CatalogProductProvider
        arguments:
            $decorated: '@.inner'
```

---

## 9. Dependency Injection Configuration

### 9.1 Service Definition

File: `src/PrestaShopBundle/Resources/config/services/core/pricing.yml`

```yaml
services:
    _defaults:
        public: true
        autowire: true
        autoconfigure: true

    # Context
    PrestaShop\PrestaShop\Core\Pricing\Context\PriceContextFactory: ~
    PrestaShop\PrestaShop\Core\Pricing\Context\PriceContext:
        lazy: true
        factory: ['@PrestaShop\PrestaShop\Core\Pricing\Context\PriceContextFactory', 'create']

    # Providers (cart context)
    PrestaShop\PrestaShop\Core\Pricing\Product\Provider\CatalogProductProvider:
        arguments:
            $dbPrefix: '%database_prefix%'
    # Providers (order context)
    PrestaShop\PrestaShop\Core\Pricing\Product\Provider\OrderDetailProductProvider:
      arguments:
        $dbPrefix: '%database_prefix%'

    # Rounding
    PrestaShop\PrestaShop\Core\Pricing\Rounding\RoundingService:
        arguments:
            $legacyRoundMode: '@=service("prestashop.adapter.legacy.configuration").getInt("PS_PRICE_ROUND_MODE")'
    PrestaShop\PrestaShop\Core\Pricing\Rounding\RoundingServiceInterface:
        alias: PrestaShop\PrestaShop\Core\Pricing\Rounding\RoundingService

    # Calculators — cart context (separate tags per context)
    prestashop.pricing.cart.product.base_product_calculator:
        class: PrestaShop\PrestaShop\Core\Pricing\Product\Calculator\BaseProductCalculator
        arguments:
          - '@PrestaShop\PrestaShop\Core\Pricing\Product\Provider\CatalogProductProvider'
        tags: [{ name: 'prestashop.pricing.cart.product_calculator', priority: 100 }]

    # Calculators — order context (separate tags per context)
    prestashop.pricing.order.product.base_product_calculator:
      class: PrestaShop\PrestaShop\Core\Pricing\Product\Calculator\BaseProductCalculator
      arguments:
        - '@PrestaShop\PrestaShop\Core\Pricing\Product\Provider\OrderDetailProductProvider'
      tags: [{ name: 'prestashop.pricing.order.product_calculator', priority: 100 }]
      
    # Calculators, the generic principle is to have multiple calculators, their execution order depends on their assigned priority
    PrestaShop\PrestaShop\Core\Pricing\Product\Calculator\CombinationCalculator:
        tags: [{ name: 'prestashop.pricing.product_calculator', priority: 90 }]

    PrestaShop\PrestaShop\Core\Pricing\Product\Calculator\SpecificPriceCalculator:
      tags: [{ name: 'prestashop.pricing.product_calculator', priority: 80 }]

    PrestaShop\PrestaShop\Core\Pricing\Product\Calculator\GroupReductionCalculator:
      tags: [{ name: 'prestashop.pricing.product_calculator', priority: 70 }]

    PrestaShop\PrestaShop\Core\Pricing\Product\Calculator\EcoTaxCalculator:
      tags: [{ name: 'prestashop.pricing.product_calculator', priority: 60 }]

    PrestaShop\PrestaShop\Core\Pricing\Product\Calculator\TaxCalculator:
      tags: [{ name: 'prestashop.pricing.product_calculator', priority: 50 }]

    # Some services may be common for both context
    PrestaShop\PrestaShop\Core\Pricing\Product\Calculator\RoundingCalculator:
        tags:
          - { name: 'prestashop.pricing.cart.product_calculator', priority: 10 }
          - { name: 'prestashop.pricing.order.product_calculator', priority: 10 }

    # Cart Orchestrator
    prestashop.pricing.cart.product.product_calculator_orchestrator:
        class: PrestaShop\PrestaShop\Core\Pricing\Product\Calculator\ProductCalculatorOrchestrator
        arguments:
            $calculators: !tagged_iterator { tag: 'prestashop.pricing.cart.product_calculator' }

    # Order Orchestrator
    prestashop.pricing.order.product.product_calculator_orchestrator:
      class: PrestaShop\PrestaShop\Core\Pricing\Product\Calculator\ProductCalculatorOrchestrator
      arguments:
        $calculators: !tagged_iterator { tag: 'prestashop.pricing.order.product_calculator' }

    # Debug
    PrestaShop\PrestaShop\Core\Pricing\Debug\PricingRegistry: ~
    PrestaShop\PrestaShop\Core\Pricing\Debug\PricingHistoryDisplayer: ~
```

### 9.2 FO + BO Compatibility

The pricing services are defined in `config/services/common.yml` (imported by both FO and BO):

```yaml
# config/services/common.yml
imports:
    # ... existing imports ...
    - { resource: ../../src/PrestaShopBundle/Resources/config/services/core/pricing.yml }
```

**FO challenge:** The FO `ContainerBuilder` supports `_instanceof` auto-tagging, `!tagged_iterator`, and compiler passes, but does NOT support PHP 8 attributes like `#[AutowireLocator]` or `#[TaggedLocator]`. Therefore:
- Use YAML-based tagging (not PHP 8 attributes) for pricing services
- Use `!tagged_iterator` (not `#[TaggedIterator]`) for injection
- If needed, add a `PricingCompilerPass` to handle complex service wiring

---

## 10. Feature Flag

### 10.1 Configuration

Add `new_pricing` to `FeatureFlagSettings.php` and `feature_flag.xml`:

```php
public const FEATURE_FLAG_NEW_PRICING = 'new_pricing';
```

```xml
<feature_flag id="new_pricing" name="new_pricing" type="env,dotenv,db"
    label_wording="New pricing engine"
    label_domain="Admin.Advparameters.Feature"
    description_wording="Enable the new pricing computation engine. Warning: this is a development feature, prices will be incorrect until the implementation is complete."
    description_domain="Admin.Advparameters.Help"
    state="0" stability="beta" />
```

### 10.2 Integration Point

Inside `Product::getPriceStatic()`, the feature flag switches between legacy and new computation:

```php
// In Product::getPriceStatic() or a new ProductPriceFactory
if ($featureFlagManager->isEnabled(FeatureFlagSettings::FEATURE_FLAG_NEW_PRICING)) {
    // Maybe the PriceContext would need to be updated, but probably not the ideal place
    $orchestrator = $container->get(ProductCalculatorOrchestrator::class);
    // This may be the starting point where we create the TrackedProductPrice
    $productPrice = ProductPrice::create($id_product, $id_product_attribute);
    $result = $orchestrator->compute($productPrice);
    return (float) $result->getUnitPrice()->getTaxIncluded()->toPrecision($decimals);
}
// ... legacy code continues
```

This allows gradual migration: the new system runs alongside the old one, and we can compare outputs.

---

## 11. Testing Strategy

### 11.1 Unit Tests

Every value object, calculator, and debug service has dedicated unit tests using `MockProductProvider` / `MockTaxProvider`:

```php
class BaseProductCalculatorTest extends TestCase
{
    public function testComputeSetsBasePrice(): void
    {
        $provider = new MockProductProvider(['1' => '29.99']);
        $context = new PriceContext(shopId: 1, currencyId: 1, ...);
        $calculator = new BaseProductCalculator($provider, $context);

        $productPrice = ProductPrice::create(1, 0);
        $calculator->compute($productPrice);

        $this->assertTrue(
            $productPrice->getUnitPrice()->getTaxExcluded()->equals(new DecimalNumber('29.99'))
        );
    }
}
```

### 11.2 Integration Tests

- **Pipeline test:** Full orchestrator with all calculators, verify end-to-end price matches expected value
- **Container test:** Boot FO and BO containers, verify service availability and calculator ordering

### 11.3 Comparison Tests (Transition Phase)

During migration, run both old and new systems and compare:

```php
class PricingComparisonTest extends TestCase
{
    /**
     * @dataProvider productPriceProvider
     */
    public function testNewPricingMatchesLegacy(int $productId, array $params): void
    {
        $legacyPrice = Product::getPriceStatic($productId, ...);
        $newPrice = $this->orchestrator->compute(...);

        // Initially: just verify the new system doesn't crash
        // Later: verify prices match
        // Finally: verify new prices are MORE accurate
    }
}
```

---

## 12. Implementation Phases

### Phase 1: Foundation (Issues #40948, #40949, #40951)
- Feature flag `new_pricing`
- Value objects: `TaxRate`, `TaxablePrice`, `PriceModification`, `PriceBreakdown`
- `ProductPriceInterface` + `ProductPrice` + `TrackedProductPrice`
- `ProductCalculatorInterface` + `ProductCalculatorOrchestrator`
- `BaseProductCalculator` + `RoundingCalculator`
- `ProductProviderInterface` + `CatalogProductProvider` + `MockProductProvider`
- `RoundingServiceInterface` + `RoundingService`
- Debug tooling: `PricingRegistry`, `PricingHistoryDisplayer`, `PricingDataCollector`
- DI configuration in `pricing.yml` + import in `common.yml`
- Unit tests for all classes

### Phase 2: Cart Pricing initialization (Issues #41014, #40979)
- `CartPriceInterface` + `CartPrice` + `TrackedCartPrice`
- `CartCalculatorInterface` + `CartCalculatorOrchestrator`
- Only `ProductTotalCartCalculator` at the beginning
- `CartManager` + `CompositeCartChecker`

### Phase 3: Integration Spike (Issue #40952)
- Plug orchestrator into `Product::getPriceStatic` behind feature flag
- Map all call sites, to define an exhaustive list of code to adapt


### Phase 4: Cart integration (FO side)
- Implement each touchpoint in dedicated issues based on the user stories
- Check that all touchpoints have been adapted

### Phase 5: Order integration (BO side)
- Order-context providers (read from `ps_order_detail`)
- Order-context calculator tags: `prestashop.pricing.order.product_calculator`
- Order editing, refund/credit slip computation

### Phase 6: Product and Cart Price Accuracy
- `PriceContext` + `PriceContextFactory` for more advanced calculator that need the context
- Remaining product calculators: Combination, SpecificPrice, GroupReduction, EcoTax, Tax, Currency
- Database providers for tax and specific price
- Remaining Cart calculators: Shipping, Wrapping, Discount (split into sub-calculators)
- Comparison tests against legacy system

### Phase 7: Validation
- Verify against ~50 known bugs
- Performance benchmarks
- Module compatibility testing
- Edge cases: multi-tax, B2B, ecotax, currency conversion, multistore

---

## 12. Key Files to Modify

| File | Change |
|---|---|
| `src/Core/FeatureFlag/FeatureFlagSettings.php` | Add `FEATURE_FLAG_NEW_PRICING` constant |
| `install-dev/data/xml/feature_flag.xml` | Add `new_pricing` flag entry |
| `config/services/common.yml` | Import `pricing.yml` |
| `src/PrestaShopBundle/Resources/config/services/core/pricing.yml` | New file: all pricing DI |
| `classes/Product.php` (`getPriceStatic`) | Feature flag branch to new orchestrator |
| `classes/Cart.php` (`getOrderTotal`) | Feature flag branch to new cart calculator |
| `src/Adapter/ContainerBuilder.php` | May need CompilerPass for FO pricing |
| `src/PrestaShopBundle/PrestaShopBundle.php` | Register `PricingCompilerPass` if needed |

---

## 13. Resolved Design Decisions

1. **Mutable DTOs:** `ProductPrice`/`CartPrice` use setters, not immutable `withX()` methods. Simpler calculator code.
2. **Debug-only tracking:** `TrackedProductPrice` auto-records via `debug_backtrace()`. Calculators never interact with the audit trail.
3. **PriceContext as service:** Injected via constructor DI, not passed as method parameter.
4. **No `supports()` method:** Calculators return early from `compute()` when not relevant.
5. **Separate tags per context:** `prestashop.pricing.cart.product_calculator` / `prestashop.pricing.order.product_calculator` for clean FO/BO separation.
6. **Currency conversion** as a calculator step (priority 30), not a separate system.
7. **Discount calculators split** into smaller focused services (percentage, amount, free shipping, free gift).

## 14. Open Questions / Discussion Points

1. **Caching strategy:** The current system caches aggressively in static properties. Should the new system use PSR-6 cache for computed prices, or rely on the `CartChecker` freshness mechanism only?
2. **Backward compatibility for hooks:** The legacy system has hooks like `actionProductPriceCalculation`. Should the new calculators dispatch equivalent Symfony events, or should this be handled at the integration point in `getPriceStatic`?
3. **Multistore:** How should the pricing pipeline handle multistore? Should the `PriceContext` carry shop context, or should different shops get different service configurations entirely?
