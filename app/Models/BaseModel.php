<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spiritix\LadaCache\Database\LadaCacheTrait;

abstract class BaseModel extends Model
{
    use LadaCacheTrait;
}
