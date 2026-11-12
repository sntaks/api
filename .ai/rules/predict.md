---
paths:
  - 'app/Services/Predict/**'
---

# Predict

## Prediction markets move money only through the wager services
Bets are wagers placed in-process with WagerService::place using the `predict` game's API key; a market is a wager_round and is settled or voided through WagerRoundService. Never post ledger entries or touch wallets from Predict code. The pool seed is notional (no ledger movement). Lock order for a bet: predict_markets (shared) -> predict_pools (for update) -> round -> wager -> player wallet -> house.

## Fixed-odds bets: lock order and Polymarket query gotchas
A fixed-odds bet locks predict_markets (shared) -> both predict_exposures rows of the market (for update, ordered by side) -> its predict_quotes row -> then WagerService::place (round, wager, wallet). Exposure rows are created when a market is published, never on a bet. Polymarket: /markets/keyset?closed=any only accepts a repeated id=…&id=… (build the query string by hand), and PolymarketHttp::get must not pass an empty query array (it wipes a query string already in the path).

## LMSR lock order, risk flags and the price stream
An LMSR bet locks predict_markets (shared) -> its predict_lmsr_states row -> its quote -> the side's predict_exposures row -> then WagerService::place. LMSR money maths uses LmsrMath (bcmath exp/ln); never PHP floats. RiskService rules only flag (RiskEvent types predict_*); they run after the bet transaction commits and must never refuse or roll back a bet. The WebSocket worker (predict:price-stream) must never write a price from a book it has not had a snapshot for, and drops all books on disconnect; PriceStream::isLive() switches the betting age guard to 10 s.
