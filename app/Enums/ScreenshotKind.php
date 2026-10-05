<?php

namespace App\Enums;

/**
 * Which chart image a trade screenshot is (#72). The exit image is the trade's
 * main chart (shown inline); the entry image, captured when the trade opened,
 * is only shown on request.
 */
enum ScreenshotKind: string
{
    case Exit  = 'exit';
    case Entry = 'entry';
}
