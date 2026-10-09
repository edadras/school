<?php

namespace App\Console\Commands;

use App\Models\Assignment;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\School;
use App\Models\Student;
use App\Models\Submission;
use App\Modules\Notifications\NotificationService;
use App\Modules\Scheduling\Recipients;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Console\Command;

class Reminders extends Command
{
    protected $signature = 'reminders:run';

    protected $description = 'Assignment-deadline and exam-start reminders (idempotent via dedupe keys)';

    public function handle(CurrentSchool $current, NotificationService $notify): int
    {
        $sent = 0;
        School::where('status', 'active')->each(function (School $school) use ($current, $notify, &$sent) {
            $current->run($school, function () use ($notify, &$sent) {
                // Deadline within 24h: remind students who have not submitted (and their guardians).
                Assignment::where('status', 'published')->whereBetween('due_at', [now(), now()->addHours(24)])->each(function (Assignment $a) use ($notify, &$sent) {
                    $done = Submission::where('assignment_id', $a->id)->whereNotIn('status', ['viewed', 'in_progress'])->pluck('student_id');
                    $pending = Student::whereIn('id', Enrollment::where('section_id', $a->section_id)->where('status', 'active')->select('student_id'))->whereNotIn('id', $done)->get();
                    foreach ($pending as $s) {
                        $users = collect([$s->user_id])->merge(Recipients::guardianUsers($s->id))->filter();
                        $sent += $notify->send($users, 'assignment.due_soon', "due24:{$a->id}:{$s->id}", 'مهلت تکلیف نزدیک است: '.$a->title, 'مهلت: '.$a->due_at->toDateTimeString(), ['assignment_id' => $a->id]);
                    }
                });
                // Exam starts within an hour.
                Exam::where('status', 'published')->whereBetween('start_at', [now(), now()->addHour()])->each(function (Exam $e) use ($notify, &$sent) {
                    $sent += $notify->send(Recipients::forSection(app(CurrentSchool::class)->id(), $e->section_id), 'exam.soon', "examsoon:{$e->id}", 'آزمون به‌زودی شروع می‌شود: '.$e->title, null, ['exam_id' => $e->id]);
                });
            });
        });
        $this->info("reminders sent: $sent");

        return self::SUCCESS;
    }
}
