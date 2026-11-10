---
paths:
  - routes/api.php
---

# Routes

## No route parameter constraints on keyed API routes
Do not add whereNumber()/where() constraints to routes behind apikey.checker. RouteFenceTest calls every route with each parameter replaced by "x" and expects the key fence (403 route_not_allowed) to answer; a constraint makes it 404 before the middleware runs. Check ids in the controller instead (ctype_digit, as WagerAdminController and PredictController::routeId do).
