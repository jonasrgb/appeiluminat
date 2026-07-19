# Task 4 Report: Safety, Scope, and Regression Verification

Date: 2026-07-19

## Change and Commit

- Modified and committed only: `tests/Unit/ReplicateProductUpdateIdentityContractTest.php`
- Commit: `b6a7cbb test: guard legacy parentvariant bootstrap scope`
- The test adds strict no-mutable-fallback and BG-first scope assertions, plus the unsafe-bootstrap-before-mutation ordering assertion.

## Source-Contract Quoting Adjustment

The brief's unsafe-source search used a double-quoted PHP string:

```php
"$decision['status'] === 'unsafe'"
```

That string interpolates `$decision` instead of matching the literal source contract. The implemented minimal equivalent is:

```php
'$decision[\'status\'] === \'unsafe\''
```

The requested no-fallback token checks were valid against the bootstrap method and were implemented unchanged in intent.

## Commands and Results

```bash
php artisan test tests/Unit/LegacyParentVariantBootstrapPolicyTest.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php tests/Unit/ReplicateProductCreateIdentityContractTest.php tests/Feature/ShopifyParentIdentityResolverTest.php tests/Feature/BemWatermarkFlowTest.php tests/Feature/BemWatermarkUpdateManifestTest.php
```

Result: exit 0. `63 passed (260 assertions)` in 1.61s. No Laravel HTTP-fake escape failures occurred. PHPUnit emitted its existing warning that the XML configuration uses a deprecated schema.

```bash
php -l app/Services/Shopify/LegacyParentVariantBootstrapPolicy.php
php -l app/Jobs/ReplicateProductUpdateToShop.php
php -l tests/Unit/LegacyParentVariantBootstrapPolicyTest.php
php -l tests/Unit/ReplicateProductUpdateIdentityContractTest.php
```

Result: each command exited 0 and printed `No syntax errors detected`.

```bash
vendor/bin/pint --test app/Services/Shopify/LegacyParentVariantBootstrapPolicy.php app/Jobs/ReplicateProductUpdateToShop.php tests/Unit/LegacyParentVariantBootstrapPolicyTest.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php
```

Result: exit 1. Pint reports exactly two pre-existing style issues in verify-only production files:

- `app/Jobs/ReplicateProductUpdateToShop.php`: `class_attributes_separation`, `single_quote`
- `app/Services/Shopify/LegacyParentVariantBootstrapPolicy.php`: `not_operator_with_successor_space`

The owned test file was corrected and passes its isolated Pint check:

```bash
vendor/bin/pint --test --verbose tests/Unit/ReplicateProductUpdateIdentityContractTest.php
```

Result: exit 0.

## Re-review Fixes

Commit: `05c50ce test: strengthen legacy bootstrap safety guard`

- Restored `app/Jobs/ReplicateProductUpdateToShop.php` and `app/Services/Shopify/LegacyParentVariantBootstrapPolicy.php` exactly to their `b6a7cbb` contents, reversing only the formatting introduced by `0602d29`.
- Strengthened the unsafe-bootstrap source contract by slicing the unsafe branch through the first mutation and matching its actual `return [` statement. Removing the return makes this test fail.
- Preserved the explicit unsafe-before-mutation and verified-state/postcondition-before-mirror contracts.

```bash
php artisan test tests/Unit/LegacyParentVariantBootstrapPolicyTest.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php tests/Unit/ReplicateProductCreateIdentityContractTest.php tests/Feature/ShopifyParentIdentityResolverTest.php tests/Feature/BemWatermarkFlowTest.php tests/Feature/BemWatermarkUpdateManifestTest.php
```

Result: exit 0. `63 passed (280 assertions)` in 1.53s. PHPUnit emitted the existing deprecated XML-schema warning.

```bash
php -l app/Services/Shopify/LegacyParentVariantBootstrapPolicy.php
php -l app/Jobs/ReplicateProductUpdateToShop.php
php -l tests/Unit/LegacyParentVariantBootstrapPolicyTest.php
php -l tests/Unit/ReplicateProductUpdateIdentityContractTest.php
```

Result: all four commands exited 0 with `No syntax errors detected`.

```bash
vendor/bin/pint --test tests/Unit/LegacyParentVariantBootstrapPolicyTest.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php
```

Result: exit 0; both focused test files pass.

```bash
git diff --check
```

Result: exit 0.

```bash
git diff b6a7cbb..HEAD -- app/Jobs/ReplicateProductUpdateToShop.php app/Services/Shopify/LegacyParentVariantBootstrapPolicy.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php
```

Result after commit: production files have no net Task 4 diff; only `tests/Unit/ReplicateProductUpdateIdentityContractTest.php` remains changed.

```bash
git diff --check
```

Result: exit 0; no whitespace errors.

```bash
git diff -- app/Services/Shopify/LegacyParentVariantBootstrapPolicy.php app/Jobs/ReplicateProductUpdateToShop.php tests/Unit/LegacyParentVariantBootstrapPolicyTest.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php
```

Result before commit: changes were confined to `tests/Unit/ReplicateProductUpdateIdentityContractTest.php`; no BEM, BG, route, migration, config, or frontend changes were made for this task.

## Concerns

- The prescribed full Pint command cannot exit 0 without modifying the two verify-only production files, which is outside Task 4 ownership. The owned test file itself is Pint-clean.
- The repository has extensive unrelated pre-existing modified and untracked files. They were preserved and were not staged or committed.
- PHP's automatic Git committer identity warning appeared during commit; the commit succeeded with the repository environment's configured identity.

## Review Fix Wave

Commit: `0602d29 test: clarify legacy bootstrap safety contracts`

- Replaced the potentially confusing `assertLessThan` guard-order checks with explicit `assertTrue($unsafe < ...)` comparisons.
- Added first/last-position contracts for both `attach_single` and `replace_structure`, proving the Shopify mutation, verified-state fetch, and identity postcondition each precede the corresponding `replaceVariantMirrorsFromVerifiedState` call.
- Applied the requested focused production style corrections: corrected class indentation for the new `handle` method and Laravel Pint spacing for the two policy negations.

### Verification Commands and Results

```bash
php artisan test tests/Unit/LegacyParentVariantBootstrapPolicyTest.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php tests/Unit/ReplicateProductCreateIdentityContractTest.php tests/Feature/ShopifyParentIdentityResolverTest.php tests/Feature/BemWatermarkFlowTest.php tests/Feature/BemWatermarkUpdateManifestTest.php
```

Result: exit 0. `63 passed (279 assertions)` in 1.69s. The existing PHPUnit deprecated XML-schema warning remained; no HTTP-fake escape failures occurred.

```bash
php -l app/Services/Shopify/LegacyParentVariantBootstrapPolicy.php
php -l app/Jobs/ReplicateProductUpdateToShop.php
php -l tests/Unit/LegacyParentVariantBootstrapPolicyTest.php
php -l tests/Unit/ReplicateProductUpdateIdentityContractTest.php
```

Result: all four commands exited 0 and printed `No syntax errors detected`.

```bash
vendor/bin/pint --test app/Services/Shopify/LegacyParentVariantBootstrapPolicy.php app/Jobs/ReplicateProductUpdateToShop.php tests/Unit/LegacyParentVariantBootstrapPolicyTest.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php
```

Result: exit 1. The policy and both test files pass; `app/Jobs/ReplicateProductUpdateToShop.php` still has one aggregated Pint failure (`class_attributes_separation, single...`). Verbose Pint output shows this consists of broad, unrelated legacy formatting throughout the rest of the job file (imports, property spacing, methods outside `handle`, and existing expression formatting). A zero exit would require a whole-file Pint rewrite, expressly excluded by this fix wave.

```bash
git diff --check
```

Result: exit 0.

### Remaining Blocker

The requested four-file Pint command cannot pass while preserving the requirement not to reformat unrelated code in `ReplicateProductUpdateToShop.php`. The focused changes are committed; resolving this requires authorization for a dedicated whole-file formatting change.

## Final Reverification

Commit: `0602d29 test: clarify legacy bootstrap safety contracts`

```bash
php artisan test tests/Unit/LegacyParentVariantBootstrapPolicyTest.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php tests/Unit/ReplicateProductCreateIdentityContractTest.php tests/Feature/ShopifyParentIdentityResolverTest.php tests/Feature/BemWatermarkFlowTest.php tests/Feature/BemWatermarkUpdateManifestTest.php
```

Result: exit 0. `63 passed (279 assertions)` in 1.68s. PHPUnit emitted the existing deprecated XML-schema warning.

```bash
php -l app/Services/Shopify/LegacyParentVariantBootstrapPolicy.php
php -l app/Jobs/ReplicateProductUpdateToShop.php
php -l tests/Unit/LegacyParentVariantBootstrapPolicyTest.php
php -l tests/Unit/ReplicateProductUpdateIdentityContractTest.php
```

Result: all four exited 0 with `No syntax errors detected`.

```bash
vendor/bin/pint --test app/Services/Shopify/LegacyParentVariantBootstrapPolicy.php app/Jobs/ReplicateProductUpdateToShop.php tests/Unit/LegacyParentVariantBootstrapPolicyTest.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php
```

Result: exit 1. The only failure is `app/Jobs/ReplicateProductUpdateToShop.php` (`class_attributes_separation, single...`); the other three focused files pass.

```bash
git diff --check
```

Result: exit 0.
