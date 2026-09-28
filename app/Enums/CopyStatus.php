<?php

namespace App\Enums;

enum CopyStatus: string
{
    case Matched   = 'matched';
    case Missed    = 'missed';
    case StillOpen = 'still_open';
}
