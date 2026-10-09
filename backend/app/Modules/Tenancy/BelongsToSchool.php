<?php

namespace App\Modules\Tenancy;

use App\Models\School;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToSchool
{
    public static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new SchoolScope);

        static::creating(function ($model) {
            $current = app(CurrentSchool::class)->id();

            if ($current === null) {
                throw new LogicException(static::class.' cannot be created without an active school.');
            }
            // The tenant always comes from the server-side context, overriding any input.
            $model->school_id = $current;
        });

        static::updating(function ($model) {
            if ($model->isDirty('school_id')) {
                throw new LogicException('school_id is immutable.');
            }
        });
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
