---
paths:
  - 'app/Services/Predict/**'
---

# Predict

## Prediction markets move money only through the wager services
Bets are wagers placed in-process with WagerService::place using the `predict` game's API key; a market is a wager_round and is settled or voided through WagerRoundService. Never post ledger entries or touch wallets from Predict code. The pool seed is notional (no ledger movement). Lock order for a bet: predict_markets (shared) -> predict_pools (for update) -> round -> wager -> player wallet -> house.
