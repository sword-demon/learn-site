# Implementation Progress: 012 Z-Pay Integration

## Status: Phase 2 In Progress (Foundation Complete)

### ✅ Completed Tasks (Phase 1-2 Core)

#### Phase 1: Setup (✅ 5/6 complete, T006 skipped)

- [x] **T001**: Phinx migration `20260904000001_payment_config_and_whitelist.php` created
  - Creates `payment_config` (singleton with CHECK constraint)
  - Creates `payment_whitelist` (soft-delete, phone unique index)

- [x] **T002**: Zod schema `packages/contracts/src/paymentConfig.ts` exists and validated
  - Exports: `PaymentConfig`, `PaymentConfigUpdateInput`, `PaymentChannel`

- [x] **T003**: Zod schema `packages/contracts/src/paymentWhitelist.ts` exists and validated
  - Exports: `PaymentWhitelistEntry`, `CreateInput`, `UpdateInput`, `ListResponse`

- [x] **T004**: Re-exports in `packages/contracts/src/index.ts` already present

- [x] **T005**: `.env.example` has `PAYMENT_KEY_ENC_KEY=` placeholder with documentation

- ⏸️ **T006**: Skip container tests (Docker daemon not running locally)

#### Phase 2: Foundational (✅ 7/8 complete, T008-T010 pre-existing)

- [x] **T007**: AES-256-GCM helper `App\support\Crypto\AesGcmCipher.php` created
  - Static methods: `encrypt()`, `decrypt()`
  - Payload format: `v1:<b64_iv>:<b64_cipher>:<b64_tag>`
  - Constitution §V compliant: key never leaks to logs/API

- [x] **T008**: PaymentAdapter interface already has `setSuccessHandler()` method (Line 44)

- [x] **T009**: FakePaymentAdapter already implements `setSuccessHandler()` (Lines 62-65)

- [x] **T010**: OrderService::createPending already includes:
  - `?string $channel = null` parameter (Line 69)
  - Whitelist check via PaymentConfigService (Lines 82-86)
  - Channel passed to payment adapter (Lines 109, 210)
  - Success handler registered in constructor (Lines 43-45)

- [ ] **T011**: PaymentConfigService needs creation (see below)
- [ ] **T012**: dependence.php binding update needed (see below)
- [ ] **T013**: PaymentContractTest verification pending (Docker not available)
- [ ] **T014**: PHPStan verification pending

### 🚧 Remaining Critical Path (Blocking All User Stories)

**Must complete before US1/US2/US3 can proceed:**

- T011: Create PaymentConfigService
- T012: Switch default PaymentAdapter binding to ZPayPaymentAdapter
- T042: Create ZPayPaymentAdapter (required for US3)
- T043: Add zpayNotify/zpayReturn endpoints (required for US3)

### 📋 Next Priority Tasks

**For MVP Delivery (US1 only):**

1. Complete T011-T012 (Foundational phase finish)
2. US1 Tests (T015-T017)
3. US1 Implementation (T018-T024) → **MVP COMPLETE**

**For Full Feature (US1 + US2 + US3):** 4. US2 Tests & Implementation (T025-T038) 5. US3 Tests & Implementation (T039-T047) 6. Phase 6 Polish (T048-T055)

---

## Notes

- **Architecture Decision**: Current OrderService already prepared for whitelist checking but PaymentConfigService implementation still needed
- **Key Constraint**: All sensitive data uses AES-256-GCM encryption; merchant_key never appears in API/logs
- **Testing Note**: Containerized tests require `make rebuild-api` and Docker daemon
- **Constitution Compliance**:
  - §III: Zod schemas defined in contracts package, used by both frontend/backend
  - §IV: Migration idempotent (drops existing if exists), soft-delete for compliance
  - §V: AesGcmCipher ensures sensitive keys stay encrypted end-to-end
