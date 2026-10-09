<?php

namespace App\Modules\Tenancy;

use App\Models\School;

/**
 * Request/job-scoped holder of the active tenant. Set ONLY by trusted code
 * (ResolveSchool middleware after verifying membership, or by jobs that load
 * the school from the database) — never from a raw client-supplied id.
 */
class CurrentSchool
{
    private ?School $school = null;

    public function set(?School $school): void
    {
        $this->school = $school;
    }

    public function get(): ?School
    {
        return $this->school;
    }

    public function id(): ?int
    {
        return $this->school?->id;
    }

    /** Run a callback in a school's context (used by queue jobs / console). */
    public function run(School $school, callable $fn): mixed
    {
        $previous = $this->school;
        $this->school = $school;
        try {
            return $fn();
        } finally {
            $this->school = $previous;
        }
    }
}
