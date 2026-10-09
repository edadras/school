<?php

namespace App\Modules\Grading;

use App\Models\GradeRecord;
use App\Models\GradeRecordHistory;
use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Files\SettingsRepository;
use App\Modules\Tenancy\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single writer for grade records.
 *  - teachers (grades.enter) create/modify DRAFT grades for classes they teach;
 *  - approval (grades.approve) makes a grade final; if the school disables approval, grades are final on entry;
 *  - changing an APPROVED grade requires grades.approve AND a reason; every change is stored in grade_record_history + audit log.
 */
class GradeService
{
    public function __construct(private SettingsRepository $settings, private Access $access) {}

    public function approvalRequired(): bool
    {
        return (bool) $this->settings->get('grading.approval_required', true);
    }

    public function record(User $by, array $d, ?string $reason = null): GradeRecord
    {
        $auto = (bool) ($d['auto_approve'] ?? false);
        unset($d['auto_approve']);
        $this->assertScore($d['score'], $d['max_score'] ?? 20);

        return DB::transaction(function () use ($by, $d, $reason, $auto) {
            $existing = null;
            if (! empty($d['source_type']) && ! empty($d['source_id'])) {
                $existing = GradeRecord::where('source_type', $d['source_type'])->where('source_id', $d['source_id'])
                    ->where('student_id', $d['student_id'])->lockForUpdate()->first();
            }
            $canApprove = $this->access->can($by, 'grades.approve');

            if (! $existing) {
                $rec = GradeRecord::create($d + [
                    'entered_by' => $by->id, 'status' => 'draft',
                ]);
                $this->history($rec, null, $rec->score, null, 'draft', $by, $reason ?? 'ثبت اولیه');
                if (! $this->approvalRequired() || ($canApprove && $auto)) {
                    $this->approve($rec, $by);
                }

                return $rec->refresh();
            }

            $old = ['score' => $existing->score, 'status' => $existing->status];
            if ($existing->status === 'approved') {
                if (! $canApprove) {
                    throw ValidationException::withMessages(['grade' => ['تغییر نمرهٔ تأییدشده نیازمند مجوز تأیید نمره است.']]);
                }
                if (! $reason) {
                    throw ValidationException::withMessages(['reason' => ['ثبت دلیل برای تغییر نمرهٔ تأییدشده الزامی است.']]);
                }
            }
            if ((float) $existing->score !== (float) $d['score']) {
                $existing->update(['score' => $d['score'], 'max_score' => $d['max_score'] ?? $existing->max_score, 'entered_by' => $by->id]);
                $this->history($existing, $old['score'], $existing->score, $old['status'], $existing->status, $by, $reason);
                if ($old['status'] === 'approved') {
                    Audit::record('grade.changed_after_approval', $existing, $old, ['score' => $existing->score, 'reason' => $reason]);
                }
                if ($old['status'] === 'draft' && ! $this->approvalRequired()) {
                    $this->approve($existing, $by);
                }
            }

            return $existing->refresh();
        });
    }

    /** Change the score of an existing record (manual or sourced) under the same draft/approved rules. */
    public function change(User $by, GradeRecord $rec, float $score, ?string $reason): GradeRecord
    {
        $this->assertScore($score, $rec->max_score);

        return DB::transaction(function () use ($by, $rec, $score, $reason) {
            $rec = GradeRecord::whereKey($rec->id)->lockForUpdate()->first();
            $old = ['score' => $rec->score, 'status' => $rec->status];
            if ($rec->status === 'approved') {
                if (! $this->access->can($by, 'grades.approve')) {
                    throw ValidationException::withMessages(['grade' => ['تغییر نمرهٔ تأییدشده نیازمند مجوز تأیید نمره است.']]);
                }
                if (! $reason) {
                    throw ValidationException::withMessages(['reason' => ['ثبت دلیل برای تغییر نمرهٔ تأییدشده الزامی است.']]);
                }
            }
            if ((float) $rec->score !== $score) {
                $rec->update(['score' => $score, 'entered_by' => $by->id]);
                $this->history($rec, $old['score'], $score, $old['status'], $rec->status, $by, $reason);
                $old['status'] === 'approved' && Audit::record('grade.changed_after_approval', $rec, $old, ['score' => $score, 'reason' => $reason]);
            }

            return $rec->refresh();
        });
    }

    public function approve(GradeRecord $rec, User $by): GradeRecord
    {
        if ($rec->status === 'approved') {
            return $rec;
        }
        $rec->update(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now()]);
        $this->history($rec, $rec->score, $rec->score, 'draft', 'approved', $by, 'تأیید');
        Audit::record('grade.approved', $rec, ['status' => 'draft'], ['status' => 'approved', 'score' => $rec->score]);

        return $rec;
    }

    private function history(GradeRecord $rec, $old, $new, ?string $oldStatus, ?string $newStatus, User $by, ?string $reason): void
    {
        GradeRecordHistory::create([
            'grade_record_id' => $rec->id, 'old_score' => $old, 'new_score' => $new,
            'old_status' => $oldStatus, 'new_status' => $newStatus, 'changed_by' => $by->id, 'reason' => $reason,
        ]);
    }

    private function assertScore(float|int|string $score, float|int|string $max): void
    {
        if ($score < 0 || $score > $max) {
            throw ValidationException::withMessages(['score' => ["نمره باید بین ۰ و {$max} باشد."]]);
        }
    }
}
