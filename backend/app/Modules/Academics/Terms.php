<?php

namespace App\Modules\Academics;

use App\Models\Term;
use Carbon\CarbonInterface;

class Terms
{
    /** Term containing the date (defaults to today), else the most recent one that already started. */
    public static function at(?CarbonInterface $when = null): ?Term
    {
        $d = ($when ?? now())->toDateString();

        return Term::whereDate('starts_on', '<=', $d)->whereDate('ends_on', '>=', $d)->first()
            ?? Term::whereDate('starts_on', '<=', $d)->orderByDesc('starts_on')->first();
    }
}
