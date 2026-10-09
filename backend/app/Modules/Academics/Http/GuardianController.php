<?php

namespace App\Modules\Academics\Http;

use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Models\Role;
use App\Models\SchoolUserMembership;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/** Guardian ↔ student links. A guardian sees a child only through an *approved* link. */
class GuardianController extends Controller
{
    public function store(Request $request, int $studentId): JsonResponse
    {
        $student = Student::findOrFail($studentId);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email'],
            'phone' => ['nullable', 'string', 'max:20'], 'relation' => ['sometimes', 'string', 'max:30'],
            'password' => ['required', Password::min(10)->letters()->numbers()],
        ]);
        $schoolId = app(CurrentSchool::class)->id();

        $link = DB::transaction(function () use ($data, $student, $schoolId, $request) {
            $user = User::where('email', $data['email'])->first()
                ?? User::create(['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'password' => $data['password']]);
            SchoolUserMembership::firstOrCreate(['school_id' => $schoolId, 'user_id' => $user->id, 'role_id' => Role::where('key', 'guardian')->value('id')], ['status' => 'active']);
            $guardian = Guardian::firstOrCreate(['user_id' => $user->id], ['phone' => $data['phone'] ?? null]);

            // Created by school staff => approved immediately; recorded for audit.
            return StudentGuardian::updateOrCreate(
                ['student_id' => $student->id, 'guardian_id' => $guardian->id],
                ['relation' => $data['relation'] ?? 'parent', 'status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()],
            );
        });
        Audit::record('guardian.linked', $student, null, ['guardian_id' => $link->guardian_id]);

        return response()->json(['data' => $link], 201);
    }

    public function revoke(int $studentId, int $guardianId): JsonResponse
    {
        $link = StudentGuardian::where('student_id', $studentId)->where('guardian_id', $guardianId)->firstOrFail();
        $link->update(['status' => 'revoked']);
        Audit::record('guardian.revoked', $link);

        return response()->json(['data' => $link]);
    }

    /** Children visible to the logged-in guardian: approved links only. */
    public function myChildren(Request $request): JsonResponse
    {
        $children = Student::query()
            ->whereIn('id', StudentGuardian::query()
                ->where('status', 'approved')
                ->whereIn('guardian_id', Guardian::where('user_id', $request->user()->id)->select('id'))
                ->select('student_id'))
            ->get(['id', 'first_name', 'last_name', 'student_code', 'status']);

        return response()->json(['data' => $children]);
    }
}
