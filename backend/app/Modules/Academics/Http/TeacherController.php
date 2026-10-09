<?php

namespace App\Modules\Academics\Http;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\SchoolUserMembership;
use App\Models\Teacher;
use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class TeacherController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Contact details only for staff; colleagues see names.
        $staff = app(\App\Modules\Tenancy\Access::class)->isStaff($request->user());
        $q = Teacher::query()->with($staff ? 'user:id,name,email,phone' : 'user:id,name')->orderByDesc('id');
        $request->filled('q') && $q->whereHas('user', fn ($u) => $u->where('name', 'like', '%'.$request->string('q').'%'));

        return response()->json($q->paginate(min((int) $request->integer('per_page', 25), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'personnel_code' => ['nullable', 'string', 'max:40'],
            'password' => ['required', Password::min(10)->letters()->numbers()],
        ]);
        $school = app(CurrentSchool::class)->get();
        $this->enforceLimit($school->id);

        $teacher = DB::transaction(function () use ($data, $school) {
            // Re-use an existing platform account (a teacher may work at several schools) but never
            // overwrite its credentials: the password is only applied to brand-new accounts.
            $user = User::where('email', $data['email'])->first()
                ?? User::create(['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'password' => $data['password']]);

            if (Teacher::where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['email' => ['این معلم قبلاً در مدرسه ثبت شده است.']]);
            }
            SchoolUserMembership::firstOrCreate(['school_id' => $school->id, 'user_id' => $user->id, 'role_id' => Role::where('key', 'teacher')->value('id')], ['status' => 'active']);

            return Teacher::create(['user_id' => $user->id, 'personnel_code' => $data['personnel_code'] ?? null]);
        });
        Audit::record('teacher.created', $teacher, null, ['user_id' => $teacher->user_id]);

        return response()->json(['data' => $teacher->load('user:id,name,email,phone')], 201);
    }

    private function enforceLimit(int $schoolId): void
    {
        $max = DB::table('school_subscriptions')->where('school_id', $schoolId)->where('status', 'active')->value('max_teachers');

        if ($max !== null && Teacher::count() >= $max) {
            throw ValidationException::withMessages(['limit' => ['سقف تعداد معلمان طرح اشتراک پر شده است.']]);
        }
    }
}
