<?php

namespace App\Modules\Academics\Http;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\SchoolUserMembership;
use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** School executive staff (deputies / educational officers / extra admins). Only school admins manage them. */
class StaffController extends Controller
{
    public function index(): JsonResponse
    {
        $schoolId = app(CurrentSchool::class)->id();
        $rows = SchoolUserMembership::where('school_id', $schoolId)->whereIn('role_id', Role::whereIn('key', ['school_admin', 'deputy'])->select('id'))->with('role:id,key,name')->get();
        $users = User::whereIn('id', $rows->pluck('user_id'))->get(['id', 'name', 'email', 'phone', 'status'])->keyBy('id');

        return response()->json(['data' => $rows->map(fn ($m) => ['membership_id' => $m->id, 'role' => $m->role->key, 'status' => $m->status] + ($users[$m->user_id]?->toArray() ?? []))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email'], 'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', Rule::in(['deputy', 'school_admin'])], 'password' => ['required', Password::min(10)->letters()->numbers()],
        ]);
        $schoolId = app(CurrentSchool::class)->id();
        $m = DB::transaction(function () use ($d, $schoolId) {
            $user = User::where('email', $d['email'])->first() ?? User::create(['name' => $d['name'], 'email' => $d['email'], 'phone' => $d['phone'] ?? null, 'password' => $d['password']]);
            $roleId = Role::where('key', $d['role'])->value('id');
            if (SchoolUserMembership::where('school_id', $schoolId)->where('user_id', $user->id)->where('role_id', $roleId)->exists()) {
                throw ValidationException::withMessages(['email' => ['این کاربر قبلاً با همین نقش عضو مدرسه است.']]);
            }

            return SchoolUserMembership::create(['school_id' => $schoolId, 'user_id' => $user->id, 'role_id' => $roleId]);
        });
        Audit::record('staff.created', $m, null, ['role' => $d['role'], 'user_id' => $m->user_id]);

        return response()->json(['data' => $m], 201);
    }

    public function update(Request $request, int $membershipId): JsonResponse
    {
        $m = SchoolUserMembership::where('school_id', app(CurrentSchool::class)->id())->whereIn('role_id', Role::whereIn('key', ['school_admin', 'deputy'])->select('id'))->findOrFail($membershipId);
        $d = $request->validate(['status' => ['required', Rule::in(['active', 'disabled'])]]);
        abort_if($m->user_id === $request->user()->id, 422, 'نمی‌توانید دسترسی خودتان را تغییر دهید.');
        $m->update($d);
        Audit::record('staff.status_changed', $m, null, $d);

        return response()->json(['data' => $m]);
    }
}
