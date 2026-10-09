<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class School extends Model
{
    use SoftDeletes;

    // status/activated_at are set only by the approval service.
    protected $fillable = ['code', 'name', 'logo_path', 'phone', 'email', 'address', 'city', 'timezone', 'calendar', 'locale'];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function subscription()
    {
        return $this->hasOne(SchoolSubscription::class)->latestOfMany();
    }
}
