<?php

namespace App\Models\Enum;

class PlatformStatus
{
    const READY = 'Ready';
    const PENDING = 'Pending';
    const PROCESSING = 'Processing';
    const SYNCED = 'Synced';
    const FAILED = 'Failed';
    const IGNORE = 'Ignore';
    const PARTIAL = 'Partial';
    const INACTIVE = 'Inactive';
}
