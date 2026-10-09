<?php

namespace App\Modules\Analytics\Http;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\ExamAttempt;
use App\Models\LessonSession;
use App\Models\Section;
use App\Models\Student;
use App\Models\Submission;
use App\Models\Teacher;
use App\Modules\Grading\GradeCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /** School manager dashboard: numbers only, computed from real data. */
    public function overview(Request $request): JsonResponse
    {
        $from = $request->input('from', now()->subDays(30)->toDateString());
        $att = AttendanceRecord::whereDate('on_date', '>=', $from)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $attTotal = max(1, $att->sum());
        $sess = LessonSession::whereDate('on_date', '>=', $from)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $assign = Assignment::where('status', '!=', 'draft')->where('created_at', '>=', $from)->count();
        $subs = Submission::whereIn('assignment_id', Assignment::where('created_at', '>=', $from)->select('id'))->whereNotIn('status', ['viewed', 'in_progress'])->count();
        $expected = DB::table('assignments as a')->join('enrollments as e', function ($j) { $j->on('e.section_id', '=', 'a.section_id')->where('e.status', 'active'); })
            ->where('a.school_id', app(\App\Modules\Tenancy\CurrentSchool::class)->id())->where('a.status', '!=', 'draft')->where('a.created_at', '>=', $from)->count();

        return response()->json([
            'counts' => ['students' => Student::where('status', 'active')->count(), 'teachers' => Teacher::count(), 'sections' => Section::count()],
            'attendance' => ['by_status' => $att, 'present_rate' => round(($att['present'] ?? 0) / $attTotal * 100, 1), 'absent_rate' => round(($att['absent'] ?? 0) / $attTotal * 100, 1)],
            'sessions' => ['by_status' => $sess, 'held_rate' => $sess->sum() ? round((($sess['ended'] ?? 0) + ($sess['live'] ?? 0)) / $sess->sum() * 100, 1) : null],
            'assignments' => ['published' => $assign, 'submission_rate' => $expected ? round($subs / $expected * 100, 1) : null],
            'exams' => ['graded_attempts' => ExamAttempt::where('status', 'graded')->count(), 'pending_manual' => ExamAttempt::where('status', 'submitted')->count()],
        ]);
    }

    /** Students who need attention: repeated absences recently. */
    public function attention(Request $request, GradeCalculator $calc): JsonResponse
    {
        $absent = AttendanceRecord::whereDate('on_date', '>=', now()->subDays(14))->where('status', 'absent')->selectRaw('student_id, count(*) c')->groupBy('student_id')->having('c', '>=', 3)->pluck('c', 'student_id');
        $late = Submission::where('is_late', true)->where('created_at', '>=', now()->subDays(30))->selectRaw('student_id, count(*) c')->groupBy('student_id')->having('c', '>=', 3)->pluck('c', 'student_id');
        $ids = $absent->keys()->merge($late->keys())->unique();
        $students = Student::whereIn('id', $ids)->get(['id', 'first_name', 'last_name'])->keyBy('id');

        return response()->json(['data' => $ids->map(fn ($id) => ['student' => $students[$id] ?? null, 'absences_14d' => (int) ($absent[$id] ?? 0), 'late_submissions_30d' => (int) ($late[$id] ?? 0)])->values()]);
    }

    /** Teacher activity: sessions held vs scheduled, assignments created, work waiting to be graded. */
    public function teachers(Request $request): JsonResponse
    {
        $from = $request->input('from', now()->subDays(30)->toDateString());
        $rows = Teacher::with('user:id,name')->get()->map(function ($t) use ($from) {
            $s = LessonSession::where('teacher_id', $t->id)->whereDate('on_date', '>=', $from)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
            $total = $s->sum();

            return ['teacher_id' => $t->id, 'name' => $t->user->name, 'sessions_total' => (int) $total, 'sessions_held' => (int) (($s['ended'] ?? 0) + ($s['live'] ?? 0)), 'not_held' => (int) ($s['not_held'] ?? 0),
                'assignments_created' => Assignment::where('teacher_id', $t->id)->where('created_at', '>=', $from)->count(),
                'awaiting_grading' => Submission::whereIn('assignment_id', Assignment::where('teacher_id', $t->id)->select('id'))->whereIn('status', ['submitted', 'late', 'under_review'])->count()];
        });

        return response()->json(['data' => $rows]);
    }

    /** Per-student trend for the teacher/guardian: exam and assignment percentages over time. */
    public function studentProgress(Request $request, int $studentId, \App\Modules\Tenancy\Access $access): JsonResponse
    {
        abort_unless($access->canViewStudent($request->user(), $studentId), 404);
        $rows = \App\Models\GradeRecord::where('student_id', $studentId)->where('status', 'approved')->orderBy('created_at')->get(['subject_id', 'kind', 'title', 'score', 'max_score', 'created_at']);
        $subjects = \App\Models\Subject::whereIn('id', $rows->pluck('subject_id'))->pluck('name', 'id');

        return response()->json(['data' => $rows->map(fn ($r) => ['subject' => $subjects[$r->subject_id] ?? '', 'kind' => $r->kind, 'title' => $r->title,
            'percent' => $r->max_score > 0 ? round($r->score / $r->max_score * 100, 1) : null, 'date' => $r->created_at->toDateString()])->values(),
            'by_subject' => $rows->groupBy('subject_id')->map(fn ($g, $sid) => ['subject' => $subjects[$sid] ?? '', 'avg_percent' => round($g->avg(fn ($r) => $r->max_score > 0 ? $r->score / $r->max_score * 100 : 0), 1), 'n' => $g->count()])->values()]);
    }
}
