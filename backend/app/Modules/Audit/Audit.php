<?php

namespace App\Modules\Audit;

use App\Models\AuditLog;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    /** Append-only record of a sensitive action. */
    public static function record(string $action, ?Model $subject = null, ?array $old = null, ?array $new = null, ?int $schoolId = null): void
    {
        $req = request();

        AuditLog::create([
            'school_id' => $schoolId ?? app(CurrentSchool::class)->id(),
            'user_id' => $req?->user()?->id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip' => $req?->ip(),
            'user_agent' => $req ? substr((string) $req->userAgent(), 0, 255) : null,
        ]);
    }
}
