<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** #115: a write was attempted while the read-only demo account is signed in. */
class DemoIsReadOnly extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'The demo is read-only.');
    }
}
