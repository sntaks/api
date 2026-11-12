<?php

namespace App\Services\Predict\Polymarket\Stream;

use RuntimeException;

/**
 * The market channel connection closed or failed; the worker reconnects.
 */
class StreamDisconnected extends RuntimeException {}
