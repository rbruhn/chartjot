<?php

namespace App\Enums;

enum CopiesSource: string
{
    case CopierLive      = 'copier_live';
    case CopierWorkspace = 'copier_workspace';
    case AutoDetect      = 'auto_detect';
}
