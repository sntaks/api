---
paths:
  - 'app/Domain/Jet/**'
---

# Jet

## Kadi Jet: lock order and money only through the wager services
Every Jet write, round transition and snapshot read runs inside JetLock::run (jet_contract_state FIRST), then jet_rounds -> jet_bets -> WagerService/WagerRoundService (wager round -> wagers -> wallets -> house). Never touch wallets or the ledger from Jet code. Place and manual cash-out call WagerService inside the fence (a refusal throws JetException and rolls back the jet row and operation). Round settle/void call WagerRoundService OUTSIDE the fence (a crashed/voiding round's bets cannot change; the service is idempotent), then copy outcomes to jet_bets under the fence. Payouts passed to wagers are GROSS (stake x multiplier); winnings tax comes from products.winnings_tax_rate. Each bet's cap_multiplier = floor(max_payout / stake) keeps every winner within max_payout, because one winner above it makes WagerRoundService refuse the whole round. Equality loses: a target must be strictly below the crash point.

## Never throw inside RoundRecoveryService::fenced for an expected state
fenced() renews the loop lease in the same JetLock transaction as its callback, so an exception from the callback also rolls back the renewal; repeated errors lapse the 15 s lease and jet:run exits with LOOP_LEASE_LOST (production churned every ~20 s on 2026-11-24 because a missing jet_classic product threw in tick). Treat expected states (product missing, unavailable) as "no round" and return null; jet:run renews the lease on its own after a failed iteration. RoundRecoveryService is a container singleton: jet:run and RoundScheduler must share its lease owner.
