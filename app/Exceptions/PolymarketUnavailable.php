<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Polymarket could not be reached or answered with an error after retries, or its circuit is open.
 */
class PolymarketUnavailable extends RuntimeException {}
