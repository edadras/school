<?php

use Illuminate\Support\Facades\Schedule;

// Bell engine: idempotent, so overlap protection is belt-and-braces, not a correctness requirement.
Schedule::command('bell:tick')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('exams:expire-attempts')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('reminders:run')->everyTenMinutes()->withoutOverlapping(10)->onOneServer();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('retention:run')->dailyAt('02:30')->withoutOverlapping(60)->onOneServer();
