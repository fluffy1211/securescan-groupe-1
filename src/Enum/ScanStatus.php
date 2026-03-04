<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Status values for ScanJob entities.
 */
enum ScanStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case DONE = 'done';
    case FAILED = 'failed';
}
