<?php

namespace App\Modules\Files;

use App\Models\School;
use App\Models\StoredFile;
use App\Modules\Audit\Audit;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Data retention. A policy value of 0/absent means "keep forever": nothing is deleted unless the school (or the
 * safe built-ins below) asks for it. Academic records (grades, report cards, enrolments) and the audit log are never touched.
 *
 *   built-in (always):  uploaded files never attached to anything, older than 7 days
 *   school settings:    retention.messages_days, retention.notifications_days, retention.ai_requests_days,
 *                       retention.whiteboard_days, retention.tickets_days   (each ≥ 30 when set)
 */
class RetentionService
{
    public const KEYS = ['messages_days', 'notifications_days', 'ai_requests_days', 'whiteboard_days', 'tickets_days'];

    public function __construct(private CurrentSchool $current, private SettingsRepository $settings) {}

    /** @return array<string,int> rows (or would-be rows when $dry) per category */
    public function run(School $school, bool $dry = false): array
    {
        return $this->current->run($school, function () use ($school, $dry) {
            $out = ['orphan_files' => 0, 'messages' => 0, 'message_files' => 0, 'notifications' => 0, 'ai_requests' => 0, 'whiteboard' => 0, 'tickets' => 0];
            $sid = $school->id;

            $orphans = StoredFile::whereNull('context_type')->where('created_at', '<', now()->subDays(7));
            $out['orphan_files'] = $dry ? $orphans->count() : $this->purgeFiles($orphans->get());

            if ($d = $this->days('messages_days')) {
                $old = DB::table('messages')->where('school_id', $sid)->where('created_at', '<', now()->subDays($d));
                $out['messages'] = (clone $old)->count();
                $fileIds = DB::table('message_attachments')->whereIn('message_id', (clone $old)->select('id'))->pluck('file_id');
                $out['message_files'] = $fileIds->count();
                if (! $dry) {
                    $this->purgeFiles(StoredFile::whereIn('id', $fileIds)->get());
                    $old->delete();
                }
            }
            if ($d = $this->days('notifications_days')) {
                $q = DB::table('notifications_outbox')->where('school_id', $sid)->whereNotNull('read_at')->where('created_at', '<', now()->subDays($d));
                $out['notifications'] = $dry ? $q->count() : $q->delete();
            }
            if ($d = $this->days('ai_requests_days')) {
                $q = DB::table('ai_requests')->where('school_id', $sid)->where('created_at', '<', now()->subDays($d));
                $out['ai_requests'] = $dry ? $q->count() : $q->delete();
            }
            if ($d = $this->days('whiteboard_days')) {
                $q = DB::table('session_whiteboard_events')->where('school_id', $sid)->where('created_at', '<', now()->subDays($d));
                $out['whiteboard'] = $dry ? $q->count() : $q->delete();
            }
            if ($d = $this->days('tickets_days')) {
                $q = DB::table('support_tickets')->where('school_id', $sid)->where('status', 'closed')->where('updated_at', '<', now()->subDays($d));
                $out['tickets'] = $dry ? $q->count() : $q->delete();
            }

            if (! $dry && array_sum($out) > 0) {
                Audit::record('retention.purged', null, null, $out);
            }

            return $out;
        });
    }

    private function days(string $key): int
    {
        $v = (int) $this->settings->get("retention.$key", 0);

        return $v >= 30 ? $v : 0;
    }

    private function purgeFiles($files): int
    {
        $n = 0;
        foreach ($files as $f) {
            try {
                Storage::disk($f->disk)->delete($f->path);
            } catch (\Throwable $e) {
                report($e);

                continue;             // keep the row so the next run retries instead of leaking an object
            }
            $f->delete();
            $n++;
        }

        return $n;
    }
}
