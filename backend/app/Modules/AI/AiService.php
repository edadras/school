<?php

namespace App\Modules\AI;

use App\Models\AiRequest;
use App\Models\Assignment;
use App\Models\LearningMaterial;
use App\Models\Section;
use App\Models\Student;
use App\Models\Submission;
use App\Models\User;
use App\Modules\Files\SettingsRepository;
use App\Modules\Tenancy\Access;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Support\Facades\DB;

/**
 * Policy + orchestration around an AiProvider:
 *  role/permission → school switch → grade-level rule → rate/budget limits → anonymise → ground in approved
 *  school sources → call → account usage → return text + visible sources.
 * AI output is ALWAYS advisory: nothing here can write a grade, a score or a decision.
 */
class AiService
{
    public function __construct(
        private AiProvider $provider, private Access $access, private SettingsRepository $settings, private CurrentSchool $current,
    ) {}

    public function status(User $u): array
    {
        return [
            'provider' => $this->provider->name(), 'configured' => $this->provider->isConfigured(), 'audio' => $this->provider->supportsAudio(),
            'enabled_for_school' => (bool) $this->settings->get('ai.enabled', false),
        ];
    }

    /** @return array{text:string, sources:list<array>, request_id:int} */
    public function run(User $u, string $purpose, string $userMessage, array $opts = []): array
    {
        $this->authorize($u, $purpose);
        $names = $this->namesToMask($u);
        $clean = Anonymizer::scrub($userMessage, $names);
        $sources = ! empty($opts['use_sources']) ? $this->retrieve($u, $clean, $opts['section_id'] ?? null) : [];

        if (! empty($opts['grounded_only']) && ! $sources) {
            return ['text' => 'در منابع تأییدشدهٔ مدرسه مطلبی دربارهٔ این پرسش پیدا نشد.', 'sources' => [], 'request_id' => 0, 'grounded' => false];
        }
        $this->enforceLimits($u, $purpose);

        $system = $this->systemPrompt($u, $purpose, $opts, $sources);
        $hash = hash('sha256', $clean);
        $t0 = microtime(true);
        try {
            $res = $this->provider->complete($system, [['role' => 'user', 'content' => $clean]], (int) ($opts['max_tokens'] ?? config('ai.max_output_tokens')));
        } catch (AiException $e) {
            $this->log($u, $purpose, 'error', $hash, 0, 0, (int) ((microtime(true) - $t0) * 1000), $e->getMessage(), [], null);
            throw $e;
        }
        $ms = (int) ((microtime(true) - $t0) * 1000);
        $id = $this->log($u, $purpose, 'ok', $hash, $res['input_tokens'], $res['output_tokens'], $ms, null, array_map(fn ($s) => ['id' => $s['id'], 'title' => $s['title']], $sources), $res['model']);

        return ['text' => $res['text'], 'sources' => array_map(fn ($s) => ['id' => $s['id'], 'title' => $s['title']], $sources), 'request_id' => $id, 'grounded' => (bool) $sources];
    }

    /** Ask for JSON; tolerate code fences. */
    public function runJson(User $u, string $purpose, string $instruction, array $opts = []): array
    {
        $r = $this->run($u, $purpose, $instruction."\n\nپاسخ را فقط به‌صورت JSON معتبر و بدون هیچ متن اضافه بده.", $opts);
        $txt = trim(preg_replace('/^```(?:json)?|```$/m', '', $r['text']));
        $data = json_decode($txt, true);
        if (! is_array($data)) {
            throw new AiException('خروجی هوش مصنوعی قابل پردازش نبود؛ دوباره تلاش کنید.', 'ai_bad_output', 502);
        }

        return ['data' => $data] + $r;
    }

    // ------------------------------------------------------------------ policy
    private function permissionFor(string $purpose): string
    {
        return match (true) {
            str_starts_with($purpose, 'student') => 'ai.use_student',
            str_starts_with($purpose, 'teacher') => 'ai.use_teacher',
            str_starts_with($purpose, 'admin') => 'ai.use_admin',
            default => 'ai.use_student',
        };
    }

    private function authorize(User $u, string $purpose): void
    {
        $perm = $this->permissionFor($purpose);
        abort_unless($this->access->can($u, $perm), 403, 'این قابلیت برای نقش شما فعال نیست.');
        if (! $this->settings->get('ai.enabled', false)) {
            throw new AiException('دستیار هوش مصنوعی توسط مدرسه فعال نشده است.', 'ai_disabled_by_school', 403);
        }
        if ($perm === 'ai.use_student') {
            if (! $this->settings->get('ai.student_enabled', true)) {
                throw new AiException('استفادهٔ دانش‌آموزان از دستیار غیرفعال است.', 'ai_disabled_for_students', 403);
            }
            $sid = $this->access->ownStudentId($u);
            $min = (int) $this->settings->get('ai.student_min_grade_level', 1);
            $level = $sid ? (int) DB::table('enrollments as e')->join('sections as s', 's.id', '=', 'e.section_id')->join('grades as g', 'g.id', '=', 's.grade_id')
                ->where('e.student_id', $sid)->where('e.status', 'active')->value('g.level') : 0;
            if ($level < $min) {
                throw new AiException('دستیار برای پایهٔ تحصیلی شما فعال نیست.', 'ai_grade_restricted', 403);
            }
        }
        if (! $this->provider->isConfigured()) {
            throw new AiException('سرویس هوش مصنوعی پیکربندی نشده است.', 'ai_unconfigured', 503);
        }
    }

    private function enforceLimits(User $u, string $purpose): void
    {
        $d = config('ai.defaults');
        $role = explode('_', $purpose)[0];
        $limit = (int) $this->settings->get("ai.daily_limit_$role", $d["daily_limit_$role"] ?? 30);
        $today = now()->startOfDay();
        $used = AiRequest::where('user_id', $u->id)->where('status', 'ok')->where('created_at', '>=', $today)->count();
        if ($used >= $limit) {
            $this->log($u, $purpose, 'limited', null, 0, 0, 0, 'user daily limit', [], null);
            throw new AiException('سقف روزانهٔ استفاده از دستیار برای شما پر شده است.', 'ai_user_limit', 429);
        }
        $budget = (int) $this->settings->get('ai.daily_token_budget', $d['daily_token_budget']);
        $tokens = (int) AiRequest::where('created_at', '>=', $today)->selectRaw('coalesce(sum(input_tokens+output_tokens),0) t')->value('t');
        if ($tokens >= $budget) {
            throw new AiException('سقف مصرف روزانهٔ مدرسه پر شده است.', 'ai_school_budget', 429);
        }
    }

    private function namesToMask(User $u): array
    {
        $names = [$u->name];
        $sid = $this->access->ownStudentId($u);
        foreach (Student::whereIn('id', $sid ? [$sid] : $this->access->studentIds($u))->get() as $s) {
            $names[] = $s->first_name;
            $names[] = $s->last_name;
            $names[] = $s->first_name.' '.$s->last_name;
        }

        return array_values(array_unique(array_merge($names, explode(' ', $u->name))));
    }

    // ------------------------------------------------------------------ grounding
    /** Keyword retrieval over materials the user may see and the school marked AI-usable. */
    private function retrieve(User $u, string $query, ?int $sectionId): array
    {
        $secs = $this->access->sectionIds($u);
        $q = LearningMaterial::query()->where('ai_indexable', true)->whereNotNull('published_at')->where('kind', 'text');
        $secs !== null && $q->whereIn('section_id', $secs);
        $sectionId && $q->where('section_id', $sectionId);

        $words = collect(preg_split('/\s+/u', \App\Support\TextNormalizer::normalize($query)))->filter(fn ($w) => mb_strlen($w) >= 3)->unique()->take(8)->values();
        if ($words->isEmpty()) {
            return [];
        }
        $scored = $q->limit(200)->get(['id', 'title', 'body'])->map(function ($m) use ($words) {
            $hay = \App\Support\TextNormalizer::normalize($m->title.' '.$m->body);

            return ['id' => $m->id, 'title' => $m->title, 'text' => mb_substr((string) $m->body, 0, 2000), 'score' => $words->filter(fn ($w) => str_contains($hay, $w))->count()];
        })->filter(fn ($x) => $x['score'] > 0)->sortByDesc('score')->take(3)->values()->all();

        return $scored;
    }

    private function systemPrompt(User $u, string $purpose, array $opts, array $sources): string
    {
        $base = "تو دستیار آموزشی یک مدرسه ایرانی هستی. همیشه به فارسی روان و محترمانه پاسخ بده. اطلاعات شخصی نخواه و تکرار نکن. اگر مطمئن نیستی، صریحاً بگو.";
        $role = match (explode('_', $purpose)[0]) {
            'student' => $this->studentRules($u),
            'teacher' => 'مخاطب تو معلم است. خروجی‌ها فقط پیشنهاد هستند و معلم تصمیم نهایی را می‌گیرد؛ هرگز نمرهٔ قطعی اعلام نکن.',
            'admin' => 'مخاطب تو مدیر مدرسه است. فقط بر پایهٔ آمار داده‌شده نتیجه بگیر، دربارهٔ افراد قضاوت نکن و تصمیم‌های حساس را به بررسی انسانی ارجاع بده.',
            default => '',
        };
        $src = '';
        if ($sources) {
            $src = "\n\nمنابع تأییدشدهٔ مدرسه (فقط برای پاسخ از آن‌ها استفاده کن و با [S1] و مانند آن ارجاع بده):\n";
            foreach ($sources as $i => $s) {
                $src .= '[S'.($i + 1)."] {$s['title']}: {$s['text']}\n";
            }
        }
        $extra = ! empty($opts['system_extra']) ? "\n".$opts['system_extra'] : '';

        return $base."\n".$role.$src.$extra;
    }

    private function studentRules(User $u): string
    {
        $sid = $this->access->ownStudentId($u);
        $level = $sid ? DB::table('enrollments as e')->join('sections as s', 's.id', '=', 'e.section_id')->join('grades as g', 'g.id', '=', 's.grade_id')
            ->where('e.student_id', $sid)->where('e.status', 'active')->value('g.name') : null;
        $open = [];
        if ($sid && ($sec = $this->access->sectionOfStudent($sid))) {
            $open = Assignment::where('section_id', $sec)->where('status', 'published')->where(fn ($q) => $q->whereNull('due_at')->orWhere('due_at', '>', now()))
                ->whereNotIn('id', Submission::where('student_id', $sid)->where('status', 'finalized')->select('assignment_id'))->pluck('title')->take(10)->all();
        }
        $rules = 'مخاطب تو دانش‌آموز'.($level ? " پایهٔ «{$level}»" : '').' است؛ زبان و سطح توضیح را متناسب با همین پایه نگه دار. '
            .'روش تو معلم‌گونه است: گام‌به‌گام راهنمایی کن، مثال بزن و از دانش‌آموز بپرس. پاسخ نهایی تکلیف یا آزمون را مستقیم نده؛ فقط راهنمایی و نکتهٔ حل بده. '
            .'از محتوای نامناسب کودکان دوری کن و اگر دانش‌آموز نگران‌کننده‌ای گفت، او را به معلم یا والد ارجاع بده.';
        if ($open) {
            $rules .= ' تکالیف باز فعلی: '.implode('؛ ', $open).'. اگر پرسش مستقیماً پاسخ یکی از این تکالیف بود، فقط راهنمایی کن.';
        }

        return $rules;
    }

    // ------------------------------------------------------------------ accounting
    private function log(User $u, string $purpose, string $status, ?string $hash, int $in, int $out, int $ms, ?string $err, array $sources, ?string $model): int
    {
        $cost = (int) round(($in * config('ai.price_in_per_mtok') + $out * config('ai.price_out_per_mtok')) * 1.0);   // micro-units: price per Mtok × tokens / 1e6 × 1e6
        $r = AiRequest::create(['user_id' => $u->id, 'purpose' => $purpose, 'provider' => $this->provider->name(), 'model' => $model, 'status' => $status,
            'prompt_hash' => $hash, 'input_tokens' => $in, 'output_tokens' => $out, 'cost_micro' => $cost, 'latency_ms' => $ms, 'error' => $err ? mb_substr($err, 0, 255) : null, 'sources' => $sources ?: null]);
        if ($status === 'ok') {
            $key = ['school_id' => $this->current->id(), 'on_date' => now()->toDateString(), 'purpose' => $purpose];
            // Atomic upsert-increment: concurrent requests never lose counts.
            DB::transaction(function () use ($key, $in, $out, $cost) {
                \App\Models\AiUsageRecord::withoutGlobalScopes()->firstOrCreate($key, ['requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_micro' => 0]);
                \App\Models\AiUsageRecord::withoutGlobalScopes()->where($key)->update([
                    'requests' => DB::raw('requests + 1'), 'input_tokens' => DB::raw("input_tokens + $in"), 'output_tokens' => DB::raw("output_tokens + $out"), 'cost_micro' => DB::raw("cost_micro + $cost"),
                ]);
            });
        }

        return $r->id;
    }

    public function transcribeFor(User $u, string $path, string $mime): string
    {
        $this->authorize($u, $this->access->can($u, 'ai.use_teacher') ? 'teacher_voice' : 'student_voice');
        if (! $this->provider->supportsAudio()) {
            throw new AiException('ورودی صوتی توسط این ارائه‌دهنده پشتیبانی نمی‌شود.', 'ai_audio_unsupported', 501);
        }

        return $this->provider->transcribe($path, $mime);
    }

    public function speakFor(User $u, string $text): array
    {
        $this->authorize($u, $this->access->can($u, 'ai.use_teacher') ? 'teacher_voice' : 'student_voice');
        if (! $this->provider->supportsAudio()) {
            throw new AiException('خروجی صوتی توسط این ارائه‌دهنده پشتیبانی نمی‌شود.', 'ai_audio_unsupported', 501);
        }

        return $this->provider->speak(Anonymizer::scrub($text));
    }
}
