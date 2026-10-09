<?php

namespace App\Modules\Grading;

use App\Models\GradeRecord;
use App\Models\Subject;
use App\Modules\Files\SettingsRepository;

/**
 * Configurable formulas (per school, setting `grading.rules`). Defaults:
 *   scale_max 20 · pass_mark 10 · decimals 2 · rounding half_up|floor|ceil
 *   kind_weights {exam:3, assignment:1, classwork:1, oral:1, project:1, final:4}
 *   min_subject_mark null · max_makeup_subjects 2
 * Subject score  = Σ(record% × scale_max × kindWeight × recordWeight) / Σ(kindWeight × recordWeight)
 * Term average   = Σ(subject score × subject coefficient) / Σ(coefficient)
 * Only APPROVED records are ever used.
 */
class GradeCalculator
{
    public const DEFAULTS = [
        'scale_max' => 20, 'pass_mark' => 10, 'decimals' => 2, 'rounding' => 'half_up',
        'kind_weights' => ['exam' => 3, 'assignment' => 1, 'classwork' => 1, 'oral' => 1, 'project' => 1, 'final' => 4],
        'min_subject_mark' => null, 'max_makeup_subjects' => 2,
    ];

    public function __construct(private SettingsRepository $settings) {}

    public function rules(): array
    {
        $r = (array) $this->settings->get('grading.rules', []);

        return array_replace(self::DEFAULTS, $r, ['kind_weights' => array_replace(self::DEFAULTS['kind_weights'], (array) ($r['kind_weights'] ?? []))]);
    }

    public function round(float $v, array $rules): float
    {
        $f = 10 ** (int) $rules['decimals'];

        return match ($rules['rounding']) {
            'floor' => floor($v * $f) / $f, 'ceil' => ceil($v * $f) / $f,
            default => round($v, (int) $rules['decimals'], PHP_ROUND_HALF_UP),
        };
    }

    /** @param iterable<GradeRecord> $records approved records of one student, one subject, one term */
    public function subjectScore(iterable $records, array $rules): ?float
    {
        $num = 0.0;
        $den = 0.0;
        foreach ($records as $r) {
            $w = ($rules['kind_weights'][$r->kind] ?? 1) * (float) $r->weight;
            if ($w <= 0 || (float) $r->max_score <= 0) {
                continue;
            }
            $num += ((float) $r->score / (float) $r->max_score) * $rules['scale_max'] * $w;
            $den += $w;
        }

        return $den > 0 ? $this->round($num / $den, $rules) : null;
    }

    /**
     * @param  array<int,float|null>  $subjectScores  subject_id => score
     * @return array{average:?float, result:?string, failed_subjects:int}
     */
    public function termResult(array $subjectScores, array $rules): array
    {
        $coefs = Subject::whereIn('id', array_keys($subjectScores))->pluck('coefficient', 'id');
        $num = $den = 0.0;
        $below = 0;
        foreach ($subjectScores as $sid => $score) {
            if ($score === null) {
                continue;
            }
            $c = (float) ($coefs[$sid] ?? 1);
            $num += $score * $c;
            $den += $c;
            $min = $rules['min_subject_mark'] ?? $rules['pass_mark'];
            $score < $min && $below++;
        }
        if ($den <= 0) {
            return ['average' => null, 'result' => null, 'failed_subjects' => 0];
        }
        $avg = $this->round($num / $den, $rules);
        $result = match (true) {
            $avg < $rules['pass_mark'] => 'failed',
            $below === 0 => 'passed',
            $below <= (int) $rules['max_makeup_subjects'] => 'makeup',
            default => 'failed',
        };

        return ['average' => $avg, 'result' => $result, 'failed_subjects' => $below];
    }
}
