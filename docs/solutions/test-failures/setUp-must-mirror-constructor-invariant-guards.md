---
title: "When a service hardens a constructor invariant, every pre-existing test in the file silently flips from PASS to ERROR until its setUp re-enables the now-required global switch"
date: 2026-09-06
category: test-failures
module: apps/api
problem_type: test_failure
component: testing_framework
symptoms:
  - "Pre-existing tests in a file flip from PASS to ERROR after a service constructor adds a new global-config guard"
  - "Every test in the file throws the same guard exception at fixture load, not at the assertion under test"
  - "PHPUnit reports these as ERRORS, not FAILURES (the test never reaches an assertion — the throw happens during setUp/fixture mint)"
  - "A sibling test class for the same service stays GREEN, hiding the cross-file boundary"
  - "The first reflex (debug the guard) leads nowhere because the guard itself is correct per FR-010"
root_cause: test_isolation
resolution_type: test_fix
severity: high
tags:
  - php
  - webman
  - phpunit
  - test-isolation
  - setUp
  - global-config
  - site_settings
  - share-distribution
  - feature-016
---

# When a service hardens a constructor invariant, every pre-existing test silently breaks until setUp re-enables the now-required global switch

## Problem

Adding a `DISTRIBUTION_DISABLED` guard to `ShareEntryService::create()` (placed after scope/course validation, at `apps/api/app/service/ShareEntryService.php:57-60`) silently broke pre-existing tests in `ShareLandingControllerTest` because that file's `setUp` never seeded `site_settings.distribution_config.enabled=true`. Every fixture minted through `service->create(...)` now threw before the test body could run, surfacing as PHPUnit `ERRORS` (not `FAILURES`) with the new guard's exception name — which actively misdirected the investigation toward the new code instead of toward the missing test fixture pin. (Exact pre-existing test count is not in the cited source files; the historical report stated 7/9 ERROR.)

## Symptoms

- Pre-existing tests in a file flip from PASS to ERROR after a service constructor adds a new global-config guard.
- Every test in the file throws the same guard exception at fixture load, not at the assertion under test.
- PHPUnit reports these as `ERRORS`, not `FAILURES` (the test never reaches an assertion — the throw happens during setUp or fixture mint).
- A sibling test class for the same service stays GREEN, hiding the cross-file boundary — in this case `ShareEntryServiceTest` had already pinned the row for an earlier task (T006) and never noticed the regression.
- The first reflex (debug the new guard) leads nowhere because the guard itself is correct per the originating spec's distribution-switch requirement (FR-013 in `specs/016-course-distribution/spec.md`: configurable `enabled` switch on the distribution config; FR-014/FR-015 require site-level and course-level switches to be honored).

## What Didn't Work

- **Reading the new guard first.** When a new guard's exception name appears in test output, the natural reflex is to question the guard — wrong target. The guard was correct; it was enforcing the site-level `enabled` switch that FR-013/FR-014/FR-015 require.
- **Looking for an assertion regression in `ShareLandingControllerTest`.** There were no failing assertions because the tests never reached an assertion. PHPUnit `ERRORS` (not `FAILURES`) was the giveaway, but it only became visible on a second pass.
- **Adding per-test `expectException` / try-catch wrappers around the fixture mint.** Would have papered over the symptom — fixtures would still be `distribution_enabled_at_creation=0` and every redirect test would assert against a wrong-state row. The right pin is `setUp`, not the test bodies.

## Solution

Seed `site_settings.distribution_config` to `enabled=true` in `ShareLandingControllerTest::setUp()`, mirroring the seed already present in `ShareEntryServiceTest::setUp()` (added earlier in the same session for T006).

**File:** `apps/api/tests/ShareLandingControllerTest.php`

Before (setUp omitted the global switch pin — defaulted via `DistributionConfigService::normalize()` to `enabled=false`):

```php
protected function setUp(): void
{
    Db::startTrans();
    $now = date('Y-m-d H:i:s');
    $accountId = (int) Db::name('accounts')->insertGetId([
        'kind' => 'learner',
        // ...
    ]);
    // ...
    $this->service = new ShareEntryService();
    $this->controller = new ShareLandingController($this->service);
}
```

After (one-line seed mirroring `ShareEntryServiceTest.php:32-34` — the actual current source has this seed at `apps/api/tests/ShareLandingControllerTest.php:48-50`):

```php
protected function setUp(): void
{
    Db::startTrans();
    Db::name('site_settings')->where('key', 'distribution_config')->update([
        'value' => json_encode(['enabled' => true, 'max_levels' => 3, 'default_rate_bps' => 1000, 'settlement_delay_days' => 0], JSON_THROW_ON_ERROR),
    ]);
    $now = date('Y-m-d H:i:s');
    $accountId = (int) Db::name('accounts')->insertGetId([
        'kind' => 'learner',
        // ...
    ]);
    // ...
    $this->service = new ShareEntryService();
    $this->controller = new ShareLandingController($this->service);
}
```

Citations:

- The guard that triggered the regression: `apps/api/app/service/ShareEntryService.php:57-60` (`$config = $this->config->getConfig(); if (empty($config['enabled'])) { throw new BusinessException('FORBIDDEN', 'DISTRIBUTION_DISABLED'); }`).
- The default `enabled=false` baseline that broke the missing seed: `apps/api/app/service/DistributionConfigService.php:117` (`'enabled' => false` in `normalize()`).
- The sibling seed that already worked: `apps/api/tests/ShareEntryServiceTest.php:32-34`.
- The snapshot-at-create semantics that make this safe (revoking the global config later does not retroactively kill live shares): `apps/api/app/service/ShareEntryService.php:62-64` snapshots `$enabledAtCreation`; line 76 writes the `distribution_enabled_at_creation` column; `ShareEntryService::resolveByVisitor()` reads that snapshot at lines 249-253 and returns null when the entry was minted with distribution off. `ReferralBindingService` does not read the snapshot directly — it delegates to `ShareEntryService::resolveByVisitor()` (see `ReferralBindingService.php:33-39`).

## Why This Works

`ShareEntryService::create()` reads `site_settings.distribution_config` on every call (no DI override, no per-test constructor injection in test code). `DistributionConfigService::getConfig()` caches the row in-process for 60s (`CACHE_TTL` in `DistributionConfigService.php:34`) and falls back to `normalize()` defaults (`enabled => false` at line 117) when the row is missing. `ShareLandingControllerTest::setUp()` neither seeded the row nor cleared the cache, so it inherited whatever the previous test class left behind — and `Db::rollback()` on tearDown reverted rows but did not bust the in-process config cache. When the constructor guard landed, the cached `enabled=false` (from default-normalize, or from a prior test that disabled the switch) made every fixture mint throw.

`ShareEntryServiceTest` worked because T006 had already pinned the row to `enabled=true` in its own `setUp` (`apps/api/tests/ShareEntryServiceTest.php:32-34`). Adding the same one-line pin to the sibling `setUp` aligns the two files' invariants and stops the silent cross-file regression.

Root cause label: **test_isolation**. The new guard tightened an invariant (global switch must be on to mint) but did not surface that invariant as a setup step in every test file whose fixtures depend on it.

## Prevention

**Rule (grep-able):** Any service whose constructor reads `site_settings` must declare a `setUp` helper that pins the relevant row to a known state, and every test file that creates fixtures via that service must call the helper. Add this as a code-review checklist bullet for any future PR that touches a service reading `site_settings`.

Services in this codebase that read `site_settings` and therefore must be paired with a `setUp` seed (or a constructor-injectable mock) when their fixtures depend on the value:

- `DistributionConfigService` (already covered for distribution on/off — this learning)
- `PaymentWhitelistService`
- `BannerService`
- `ActivationCodeService`
- `CouponService`
- `CheckinService`
- `OpsInboxService`

**Code-review checklist bullet:**

- [ ] If this service reads `site_settings` in any constructor-reachable method, every test file in `apps/api/tests/` that creates fixtures through it pins the relevant row in `setUp` (or injects a config double). Grep `Db::name('site_settings')` under `apps/api/tests/` to confirm coverage before approving.

**Templated `setUp` helper that future tests can copy:**

```php
protected function setUp(): void
{
    Db::startTrans();
    // ponytail: pin global switches read by service constructors so adding
    // a new guard in production code does not silently flip tests here.
    Db::name('site_settings')->where('key', 'distribution_config')->update([
        'value' => json_encode([
            'enabled' => true,
            'max_levels' => 3,
            'default_rate_bps' => 1000,
            'settlement_delay_days' => 0,
        ], JSON_THROW_ON_ERROR),
    ]);
    // ...rest of fixture setup...
}
```

**Diagnostic tell for next time:** when `make test-api` shows PHPUnit `ERRORS` (not `FAILURES`) and the exception name matches a constructor guard, the fix is in `setUp`, not in the guard or the test body. `ERRORS` means the test never reached an assertion; `FAILURES` would mean an assertion tripped.

## Related Issues

- `specs/016-course-distribution/spec.md` FR-013 / FR-014 / FR-015 — the originating requirements the constructor guard enforces (configurable `enabled` switch; site-level switch hides learner-facing share entry points; course-level switch is independent of site-level switch). The guard at `ShareEntryService::create()` is the implementation that honors FR-014's "站点分销关闭时…不得产生" semantics for the minting path.
- `tasks/todo.md` (section "P1 — Spec missing") — T006 (snapshot flag) and T007 (constructor guard) are the two tasks that surfaced this learning. The day-dated filename `tasks-todo-2026-09-06-distribution-bugfix.md` references a session-scoped historical snapshot that lives outside the working tree; the canonical tracked version is `tasks/todo.md`.
</content>
</invoke>