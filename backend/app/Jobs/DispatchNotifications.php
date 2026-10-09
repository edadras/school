<?php

namespace App\Jobs;

use App\Models\School;
use App\Modules\Notifications\NotificationDispatcher;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Runs inside the school's tenant context; safe to run twice (deliveries are unique per channel). */
class DispatchNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 30];

    public function __construct(public int $schoolId) {}

    public function handle(CurrentSchool $current, NotificationDispatcher $d): void
    {
        $school = School::find($this->schoolId);
        $school && $current->run($school, fn () => $d->dispatchPending());
    }
}
