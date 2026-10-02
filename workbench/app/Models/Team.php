<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The tenant model of the workbench's tenant panel. It is never queried: the panel only needs a
 * tenant model to count as a panel with tenancy.
 */
class Team extends Model
{
    protected $guarded = [];
}
