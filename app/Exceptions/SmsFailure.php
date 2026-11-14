<?php

namespace App\Exceptions;

use RuntimeException;

class SmsFailure extends RuntimeException
{
    public function __construct(public readonly string $failureCode, public readonly bool $uncertain = false)
    {
        parent::__construct('SMS operation could not be confirmed.');
    }
}
