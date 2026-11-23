---
paths:
  - 'app/Domain/Jet/**'
---

# Jet

## Kadi Jet: lock order and money only through the wager services
Every Jet write, round transition and snapshot read runs inside JetLock::run (jet_contract_state FIRST), then jet_rounds -> jet_bets -> WagerService/WagerRoundService (wager round -> wagers -> wallets -> house). Never touch wallets or the ledger from Jet code. Place and manual cash-out call WagerService inside the fence (a refusal throws JetException and rolls back the jet row and operation). Round settle/void call WagerRoundService OUTSIDE the fence (a crashed/voiding round's bets cannot change; the service is idempotent), then copy outcomes to jet_bets under the fence. Payouts passed to wagers are GROSS (stake x multiplier); winnings tax comes from products.winnings_tax_rate. Each bet's cap_multiplier = floor(max_payout / stake) keeps every winner within max_payout, because one winner above it makes WagerRoundService refuse the whole round. Equality loses: a target must be strictly below the crash point.
