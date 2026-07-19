# Task 3 Report: One-Time Declarative Multi-Variant Bootstrap

## Implementation

- Added the `replace_structure` branch to `bootstrapLegacyVariantIdentity()`.
- The branch fetches target options, invokes `productSetVariantStructure()` with an empty retained-mirror array and `allowCompatibleOptionStructure: true`, then re-fetches and validates target identity state before replacing variant mirrors.
- Added an opt-in `allowCompatibleOptionStructure` parameter to `productSetVariantStructure()`. Existing callers retain the strict default.
- Added the three identity-contract tests specified in the task brief.

## TDD Evidence

1. Added the three contract tests before changing production code.
2. Ran `php artisan test tests/Unit/ReplicateProductUpdateIdentityContractTest.php`.
   - Result: failed as expected: 2 failed, 20 passed, 75 assertions.
   - Missing replacement branch and explicit compatibility override caused the failures.
   - The brief's double-quoted `$decision['status']` assertion did not parse in PHP because it attempted string interpolation; it was represented as an equivalent single-quoted literal.
3. Implemented the minimal production changes.

## Verification

1. `php artisan test tests/Unit/LegacyParentVariantBootstrapPolicyTest.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php`
   - Result: passed: 29 tests, 99 assertions.
2. `php artisan test tests/Feature/ShopifyParentIdentityResolverTest.php`
   - Result: passed: 6 tests, 22 assertions.
3. `git diff --check -- app/Jobs/ReplicateProductUpdateToShop.php tests/Unit/ReplicateProductUpdateIdentityContractTest.php`
   - Result: passed with no whitespace errors.

## Self-Review

- The replacement path deliberately passes `[]` for verified mirrors, so an existing target variant cannot be matched through a mutable field.
- `assertBootstrapIdentityState()` executes against freshly fetched Shopify state before `replaceVariantMirrorsFromVerifiedState()` mutates local mirrors.
- Existing mixed-replacement callers omit the new flag and therefore retain the compatible-option rejection.

## Concerns

- PHPUnit emits the pre-existing deprecated XML schema warning on every test command.
- No functional concerns identified within Task 3 scope.
