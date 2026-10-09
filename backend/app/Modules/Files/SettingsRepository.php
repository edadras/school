<?php

namespace App\Modules\Files;

use App\Models\SchoolSetting;
use App\Modules\Tenancy\CurrentSchool;

/** Typed per-school settings with defaults (messaging policy, grading rules, uploads…). */
class SettingsRepository
{
    public function __construct(private CurrentSchool $current) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $row = SchoolSetting::where('school_id', $this->current->id())->where('key', $key)->first();

        return $row ? $row->value : $default;
    }

    public function set(string $key, mixed $value): void
    {
        SchoolSetting::updateOrCreate(['school_id' => $this->current->id(), 'key' => $key], ['value' => $value]);
    }

    public function all(): array
    {
        return SchoolSetting::where('school_id', $this->current->id())->get()->mapWithKeys(fn ($r) => [$r->key => $r->value])->all();
    }
}
