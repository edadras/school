<?php

namespace App\Modules\Imports\Http;

use App\Http\Controllers\Controller;
use App\Models\GradeRecord;
use App\Models\Student;
use App\Modules\Audit\Audit;
use App\Modules\Imports\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function __construct(private ImportService $svc) {}

    public function import(Request $request, string $type): JsonResponse
    {
        abort_unless(in_array($type, ['students', 'teachers'], true), 404);
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,xlsx'], 'dry_run' => ['sometimes', 'boolean'],
            // Initial password for newly created accounts (users should change it); never stored in the import report.
            'initial_password' => ['required', Password::min(10)->letters()->numbers()],
        ]);
        $f = $request->file('file');
        $rows = $this->svc->readRows($f->getRealPath(), $f->getClientOriginalExtension());
        abort_if(count($rows) > 2000, 422, 'حداکثر ۲۰۰۰ ردیف در هر فایل مجاز است.');
        $job = $type === 'students'
            ? $this->svc->importStudents($request->user(), $rows, $request->boolean('dry_run'), $request->input('initial_password'))
            : $this->svc->importTeachers($request->user(), $rows, $request->boolean('dry_run'), $request->input('initial_password'));

        return response()->json(['data' => $job->only(['id', 'type', 'total', 'created', 'failed', 'errors'])], $job->failed && ! $job->created ? 422 : 201);
    }

    public function template(string $type): StreamedResponse
    {
        $cols = $type === 'teachers' ? ImportService::TEACHER_COLUMNS : ImportService::STUDENT_COLUMNS;

        return response()->streamDownload(fn () => print("\xEF\xBB\xBF".implode(',', $cols)."\n"), "$type-template.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** CSV export; authorised, audited, and spreadsheet-formula-safe. */
    public function export(Request $request, string $type): StreamedResponse
    {
        abort_unless(in_array($type, ['students', 'grades'], true), 404);
        Audit::record("export.$type", null, null, $request->only(['section_id', 'term_id']));
        $safe = fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'".$v : $v;       // CSV/Excel injection guard

        return response()->streamDownload(function () use ($type, $request, $safe) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            if ($type === 'students') {
                fputcsv($out, ['student_code', 'first_name', 'last_name', 'birth_date', 'status']);
                Student::orderBy('id')->chunk(500, fn ($c) => $c->each(fn ($s) => fputcsv($out, array_map($safe, [$s->student_code, $s->first_name, $s->last_name, $s->birth_date?->toDateString(), $s->status]))));
            } else {
                fputcsv($out, ['student_code', 'subject', 'kind', 'title', 'score', 'max_score', 'status']);
                GradeRecord::query()->when($request->filled('term_id'), fn ($q) => $q->where('term_id', $request->integer('term_id')))
                    ->when($request->filled('section_id'), fn ($q) => $q->where('section_id', $request->integer('section_id')))->orderBy('id')->chunk(500, function ($c) use ($out, $safe) {
                        $stu = Student::whereIn('id', $c->pluck('student_id'))->pluck('student_code', 'id');
                        $sub = \App\Models\Subject::whereIn('id', $c->pluck('subject_id'))->pluck('name', 'id');
                        foreach ($c as $g) {
                            fputcsv($out, array_map($safe, [$stu[$g->student_id] ?? '', $sub[$g->subject_id] ?? '', $g->kind, $g->title, $g->score, $g->max_score, $g->status]));
                        }
                    });
            }
            fclose($out);
        }, "$type-export.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
