<?php

namespace App\Exceptions;

use RuntimeException;

class UnknownAccountException extends RuntimeException
{
    public function __construct(public readonly string $accountName)
    {
        parent::__construct("Account '{$accountName}' not found. Create it on the Accounts page before importing.");
    }
}
