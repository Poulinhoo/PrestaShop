# Shipping Cost Calculation — Architecture

## Pattern: Calculator Pipeline

The architecture mirrors `src/Core/Pricing` (product pricing). It rests on three principles:

- **A mutable DTO `ShippingCostContext`** flows through the entire pipeline, enriched step by step.
- **Calculators** each have a single responsibility: read from and/or write to the context.
- **Providers** each encapsulate a single business concern for data retrieval.

---

## Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│  CALLER                                                                          │
│  (e.g. CreateShipmentHandler)                                                    │
└────────────────────────────────┬────────────────────────────────────────────────┘
                                 │  ShippingCalculationRequest
                                 ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│  ENTRY POINT  [Adapter]                                                          │
│                                                                                  │
│  ShippingCostCalculator                                                          │
│    1. creates ShippingCostContext from request                                   │
│    2. runs pipeline                                                              │
│    3. returns ShippingCostResult (taxExcluded / taxIncluded)                     │
└────────────────────────────────┬────────────────────────────────────────────────┘
                                 │  ShippingCostContext (mutable DTO)
                                 ▼
┌─────────────────────────────────────────────────────────────────────────────────┐
│  PIPELINE  [Core orchestrator → Adapter calculators → Core calculators]          │
│                                                                                  │
│  ① ZoneResolutionCalculator  ─────────────────► AddressRepository (DBAL)        │
│     Resolves zoneId from addressId                state → country fallback       │
│                                                                                  │
│  ② CarrierDataCalculator  ─────────────────────► CarrierDataProviderInterface   │
│     Loads carrier config into context              └─ CarrierDataProvider        │
│     (method, rangeBehavior, handling, isFree)           └─ CarrierRepository    │
│     → sets isFreeShipping if carrier.is_free                                    │
│                                                                                  │
│  ③ WeightCalculator  [Core, no infra deps]                                      │
│     Computes total cart weight                                                   │
│     Σ (weight_attribute or weight) × quantity                                   │
│                                                                                  │
│  ④ FreeShippingCalculator  ────────────────────► FreeShippingCriteriaProviderInterface
│     Checks global free shipping thresholds         └─ ConfigFreeShippingCriteriaProvider
│     → sets isFreeShipping if total or weight           └─ PS_SHIPPING_FREE_PRICE
│       exceeds configured thresholds                    └─ PS_SHIPPING_FREE_WEIGHT
│                                                                                  │
│  ⑤ BaseRangeCostCalculator  ───────────────────► CarrierDataProviderInterface   │
│     Fetches base cost from carrier ranges           └─ CarrierDataProvider      │
│     (ps_delivery, by weight or by price)                └─ getDeliveryPriceBy*  │
│     → sets isFreeShipping if out-of-range (behavior=0)                          │
│                                                                                  │
│  ⑥ AdditionalProductCostCalculator  [Core, no infra deps]                      │
│     Adds per-product additional shipping costs                                   │
│     Σ additional_shipping_cost × quantity  (uses DecimalNumber::times())        │
│                                                                                  │
│  ⑦ HandlingCostCalculator                                                       │
│     Adds PS_SHIPPING_HANDLING global fee                                         │
│     only when carrier.shipping_handling = true                                   │
│                                                                                  │
│  ⑧ CurrencyConversionCalculator                                                 │
│     Converts accumulated cost from PS base currency                              │
│     to order currency via Tools                                                  │
│                                                                                  │
│  ⑨ TaxCalculator  ◄── ALWAYS LAST ────────────► ShippingTaxRateProviderInterface│
│     Applies carrier tax rate                       └─ ShippingTaxRateProvider   │
│     Writes costTaxExcluded + costTaxIncluded            └─ carrier.getTaxesRate()│
│     Writes zeros for free shipping case                                          │
└────────────────────────────────┬────────────────────────────────────────────────┘
                                 │  ShippingCostContext (fully populated)
                                 ▼
                         ShippingCostResult
                    { taxExcluded, taxIncluded }
```

---

## File Location

```
src/
├── Adapter/Carrier/
│   ├── ShippingCostCalculator.php              ← public entry point
│   └── ShippingCost/
│       ├── Calculator/
│       │   ├── ZoneResolutionCalculator.php
│       │   ├── CarrierDataCalculator.php
│       │   ├── FreeShippingCalculator.php
│       │   ├── BaseRangeCostCalculator.php
│       │   ├── HandlingCostCalculator.php
│       │   ├── CurrencyConversionCalculator.php
│       │   └── TaxCalculator.php
│       └── Provider/
│           ├── CarrierDataProvider.php
│           ├── ConfigFreeShippingCriteriaProvider.php
│           └── ShippingTaxRateProvider.php
│
└── Core/Domain/Carrier/ShippingCost/
    ├── ShippingCostContext.php                 ← mutable pipeline DTO
    ├── Calculator/
    │   ├── ShippingCostCalculatorInterface.php
    │   ├── ShippingCostCalculator.php          ← Core orchestrator
    │   ├── WeightCalculator.php
    │   └── AdditionalProductCostCalculator.php
    └── Provider/
        ├── ShippingCostProviderInterface.php   ← marker interface
        ├── CarrierDataProviderInterface.php
        ├── CarrierShippingData.php             ← carrier config DTO
        ├── FreeShippingCriteriaProviderInterface.php
        ├── FreeShippingCriteria.php            ← free shipping thresholds DTO
        └── ShippingTaxRateProviderInterface.php
```

---

## Symfony Service Registration

**`src/PrestaShopBundle/Resources/config/services/core/shipping_cost.yml`** — auto-imported via glob `services/core/*.yml`:
- Provider implementations + interface aliases
- All 9 calculators tagged `prestashop.carrier.shipping_cost_calculator` with priority
- Pipeline orchestrator `prestashop.carrier.shipping_cost.pipeline` with `!tagged_iterator`
- `ShippingCostCalculatorInterface` aliased to the pipeline

**`src/PrestaShopBundle/Resources/config/services/adapter/carrier.yml`**:
- `ShippingCostCalculator` (entry point) registered as public autowired service

### Calculator tag priorities (higher = runs first)

| Priority | Calculator |
|---|---|
| 900 | `ZoneResolutionCalculator` |
| 800 | `CarrierDataCalculator` |
| 700 | `WeightCalculator` |
| 600 | `FreeShippingCalculator` |
| 500 | `BaseRangeCostCalculator` |
| 400 | `AdditionalProductCostCalculator` |
| 300 | `HandlingCostCalculator` |
| 200 | `CurrencyConversionCalculator` |
| 100 | `TaxCalculator` ← always last |

---

## Usage in handlers — `PS_ORDER_RECALCULATE_SHIPPING`

The `PS_ORDER_RECALCULATE_SHIPPING` configuration flag controls whether shipping costs are (re)calculated at all. It applies globally to all shipment-related handlers.

| `PS_ORDER_RECALCULATE_SHIPPING` | Behavior |
|---|---|
| `1` (active) | **Calculate** shipment cost → save shipment → recompute order total by **summing all shipments** |
| `0` (inactive) | **Skip all calculation** — shipment cost stays at `0.00` (creation) or unchanged (update), order totals untouched |

### `CreateShipmentHandler` (creation)

When `PS_ORDER_RECALCULATE_SHIPPING = 1`:
1. Builds a `ShippingCalculationRequest` for the newly added product + chosen carrier
2. Runs the pipeline (`ShippingCostCalculatorInterface::compute`)
3. Sets `shippingCostTaxExcluded` / `shippingCostTaxIncluded` on the new `Shipment` entity
4. After saving the shipment: sums all shipment costs from `findByOrderId()` → updates `order.total_shipping_*`

When `PS_ORDER_RECALCULATE_SHIPPING = 0`: shipment is saved with `0.00` costs, order totals are not updated.

> **Note:** `CreateShipmentHandler` keeps its own private `updateOrderShippingTotal()` instead of delegating to `ShipmentShippingCostUpdater`. Reason: `ShipmentProduct` entities are not assigned at creation time — `ShipmentShippingCostUpdater::recalculateShipment` iterates `$shipment->getProducts()`, which would be empty for a newly created shipment.

### `UpdateProductInOrderHandler` (quantity change)

When `PS_ORDER_RECALCULATE_SHIPPING = 1` and the `improved_shipment` feature flag is active and a `shipment_mapping` is provided:
1. Updates product quantities across shipments via `ShipmentProductQuantityUpdater`
2. Calls `ShipmentShippingCostUpdater::recalculateForOrder(orderId)` which:
   - Re-runs the full pipeline for each existing shipment (using its current products + quantities)
   - Saves updated costs on each shipment
   - Sums all shipment costs and updates `order.total_shipping_*`

When `PS_ORDER_RECALCULATE_SHIPPING = 0`: shipment quantities are updated but no cost recalculation occurs.

### `ShipmentShippingCostUpdater`

Reusable service (`src/Adapter/Shipment/ShipmentShippingCostUpdater.php`) for recalculating shipping costs of all existing shipments of an order and updating the order totals. Used by `UpdateProductInOrderHandler`. Not used by `CreateShipmentHandler` (empty products constraint — see note above).

---

## `ShippingCalculationRequest` — input data

The `ShippingCalculationRequest` is built from:
- `product_weight` from `ps_order_detail` (weight recorded at order time, includes attribute delta)
- `is_virtual`, `additional_shipping_cost` from `ps_product`
- `unit_price_tax_incl` from `ps_order_detail`
- `countryZoneId: 0` — fallback unused since `addressId` is always provided; `ZoneResolutionCalculator` resolves zone from address directly

---

## Class Reference

### Entry Point

#### `Adapter\Carrier\ShippingCostCalculator`
Public entry point for shipping cost calculation. Builds a `ShippingCostContext` from the input parameters (carrierId, addressId, products, cart total, currency), triggers the pipeline, and returns the final result (`taxExcluded` / `taxIncluded`). Thin wrapper — no business logic here.

---

### Pipeline DTO

#### `Core\Domain\Carrier\ShippingCost\ShippingCostContext`
Mutable DTO flowing through the entire pipeline. Each calculator reads and/or writes into it. Contains:
- **Input data**: carrierId, addressId, currencyId, orderTotal, physical products
- **Resolved data** (populated step by step): zoneId, totalWeight, carrierShippingData, rangeCost, isFreeShipping
- **Final outputs**: costTaxExcluded, costTaxIncluded

---

### Pipeline Interface

#### `Core\...\Calculator\ShippingCostCalculatorInterface`
Common interface for all calculators. Single contract: `compute(ShippingCostContext $context): void`.

---

### Core Calculators (no infrastructure dependencies)

#### `Core\...\Calculator\ShippingCostCalculator`
Pipeline orchestrator. Receives an `iterable<ShippingCostCalculatorInterface>` (Symfony tagged iterator, priority-sorted) and calls `compute()` on each step in order.

#### `Core\...\Calculator\WeightCalculator`
Computes total cart weight by summing `(weight_attribute or weight) × quantity` for each physical product. Skips if free shipping is already active.

#### `Core\...\Calculator\AdditionalProductCostCalculator`
Adds per-product additional shipping costs using `DecimalNumber::times()` for precision (`additional_shipping_cost × quantity`). Skips if free shipping is active.

---

### Adapter Calculators (with infrastructure dependencies)

#### `Adapter\...\Calculator\ZoneResolutionCalculator`
Resolves `zoneId` from `addressId` via `AddressRepository` (DBAL query: state → country fallback). Writes zoneId into the context for downstream calculators.

#### `Adapter\...\Calculator\CarrierDataCalculator`
Loads carrier configuration (method, range_behavior, handling, is_free) from `CarrierDataProviderInterface` and writes it into the context. Switches to free shipping if the carrier is not found or if `is_free = true`.

#### `Adapter\...\Calculator\FreeShippingCalculator`
Checks global free shipping thresholds (amount and/or weight) from `FreeShippingCriteriaProviderInterface`. Sets the `isFreeShipping` flag if either threshold is met.

#### `Adapter\...\Calculator\BaseRangeCostCalculator`
Fetches the base cost from carrier ranges (`ps_delivery`) via `CarrierDataProviderInterface::getRangeCost()`. If out of range with `range_behavior = 0`, switches to free shipping.

#### `Adapter\...\Calculator\HandlingCostCalculator`
Adds the global handling fee (`PS_SHIPPING_HANDLING`) to the running cost, only when the carrier has `shipping_handling = true`. Skips if free shipping is active.

#### `Adapter\...\Calculator\CurrencyConversionCalculator`
Converts the accumulated cost (stored in PS base currency) to the order currency via `Tools`. Skips if free shipping is active.

#### `Adapter\...\Calculator\TaxCalculator`
**Must always be the last calculator in the pipeline.** Applies the carrier tax rate (via `ShippingTaxRateProviderInterface`) to produce `costTaxExcluded` and `costTaxIncluded`. Writes zeros for the free shipping case. Sets precision from the currency context.

---

### Provider Interfaces (Core — contracts without infra dependencies)

#### `Core\...\Provider\ShippingCostProviderInterface`
Marker interface — all provider interfaces extend it. Makes the set of domain providers identifiable as a group.

#### `Core\...\Provider\CarrierDataProviderInterface`
Two related carrier responsibilities:
- `getCarrierShippingData(carrierId)` → `CarrierShippingData` (carrier config)
- `getRangeCost(carrierData, value, weight, zoneId, currencyId)` → `DecimalNumber|null` (price from delivery range)

#### `Core\...\Provider\FreeShippingCriteriaProviderInterface`
`getCriteria()` → `FreeShippingCriteria` (free shipping thresholds from PS configuration).

#### `Core\...\Provider\ShippingTaxRateProviderInterface`
`getTaxRate(carrierId, addressId)` → `float` (carrier tax rate for a given address).

---

### Provider DTOs (Core — infrastructure-free value objects)

#### `Core\...\Provider\CarrierShippingData`
Immutable value object representing carrier configuration: `shippingMethod`, `rangeBehavior`, `shippingHandling`, `isFreeMethod`.

#### `Core\...\Provider\FreeShippingCriteria`
Immutable value object holding thresholds: `freeShippingPrice` and `freeShippingWeight`. `hasFreePrice()` and `hasFreeWeight()` return true when the respective threshold is set and greater than zero.

---

### Provider Implementations (Adapter — data access)

#### `Adapter\...\Provider\CarrierDataProvider`
Implements `CarrierDataProviderInterface`. Uses `CarrierRepository` (legacy ObjectModel) to load the carrier and build `CarrierShippingData`. `getRangeCost()` delegates to `getDeliveryPriceByWeight()` or `getDeliveryPriceByPrice()` based on shipping method.

#### `Adapter\...\Provider\ConfigFreeShippingCriteriaProvider`
Implements `FreeShippingCriteriaProviderInterface`. Reads `PS_SHIPPING_FREE_PRICE` and `PS_SHIPPING_FREE_WEIGHT` from `Configuration` and returns a `FreeShippingCriteria`.

#### `Adapter\...\Provider\ShippingTaxRateProvider`
Implements `ShippingTaxRateProviderInterface`. Loads the carrier and address via their repositories, calls `getTaxesRate()` on the legacy carrier ObjectModel. Returns `0.0` on any exception.

---

## Typing Rules

- All amounts and weights use `DecimalNumber` (never `float`) throughout Core and Adapters.
- Legacy methods requiring floats (`Carrier::getDeliveryPriceByWeight`, `Tools::round`, `Tools::convertPrice`): cast via `(float)(string)$decimalNumber`.
- `AdditionalProductCostCalculator`: use `DecimalNumber::times()` for multiplications, never `float × int`.
- `TaxCalculator`: initialize `$taxIncluded` to `$cost` (DecimalNumber), not `0` (int), to keep a consistent type.
- `TaxCalculator` **must always be the last** calculator in the pipeline.
