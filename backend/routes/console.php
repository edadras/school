<?php

use Illuminate\Support\Facades\Schedule;

// Bell engine: idempotent, so overlap protection is belt-and-braces, not a correctness requirement.
Schedule::command('bell:tick')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
