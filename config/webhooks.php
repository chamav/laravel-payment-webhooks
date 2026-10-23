<?php

declare(strict_types=1);

return [
    // Milliseconds per lock wait / statement; these settings last only for the transaction.
    'lock_timeout_ms' => env('WEBHOOK_LOCK_TIMEOUT_MS', 3000),
    'statement_timeout_ms' => env('WEBHOOK_STATEMENT_TIMEOUT_MS', 10000),
];
