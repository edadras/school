<?php

namespace App\Modules\VirtualClassrooms;

use App\Models\LessonSession;
use App\Models\SessionRecording;
use App\Models\StoredFile;
use App\Modules\Audit\Audit;
use App\Modules\Files\SettingsRepository;
use App\Modules\Realtime\Realtime;
use App\Modules\VirtualClassrooms\Media\MediaProvider;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Support\Str;

/**
 * Class recording through the SFU's egress. Opt-in twice (school policy `recording.allowed` AND the host switching it on),
 * participants are told in real time and in the join payload, and playback is limited to the host and school managers.
 */
class RecordingService
{
    public function __construct(private MediaProvider $media, private SettingsRepository $settings, private Realtime $realtime, private CurrentSchool $current) {}

    public function start(LessonSession $s, $by): SessionRecording
    {
        abort_unless($this->settings->get('recording.allowed', false), 422, 'ضبط کلاس طبق سیاست مدرسه مجاز نیست.');
        abort_unless($s->status === 'live', 422, 'فقط کلاس در حال برگزاری قابل ضبط است.');
        if (! $this->media->recordingConfigured()) {
            abort(503, 'سرویس ضبط (Egress و فضای ذخیره‌سازی) پیکربندی نشده است.', ['X-Error-Code' => 'recording_unconfigured']);
        }
        if ($open = SessionRecording::where('lesson_session_id', $s->id)->whereIn('status', ['starting', 'recording'])->first()) {
            return $open;                                   // idempotent
        }
        $key = 'schools/'.$this->current->id().'/recordings/'.$s->id.'-'.Str::lower(Str::ulid()).'.mp4';
        $rec = SessionRecording::create(['lesson_session_id' => $s->id, 'started_by' => $by->id, 'object_key' => $key, 'status' => 'starting', 'started_at' => now()]);
        try {
            $id = $this->media->startRecording($s->room_name, $key);
        } catch (\Throwable $e) {
            $rec->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 240, ''), 'ended_at' => now()]);
            abort(502, 'شروع ضبط ناموفق بود.');
        }
        $rec->update(['provider_id' => $id, 'status' => 'recording']);
        $s->update(['recording_enabled' => true]);
        Audit::record('session.recording_on', $s, null, ['recording_id' => $rec->id]);
        $this->realtime->session($s, 'recording', ['enabled' => true]);

        return $rec;
    }

    public function stop(LessonSession $s): void
    {
        $open = SessionRecording::where('lesson_session_id', $s->id)->whereIn('status', ['starting', 'recording'])->get();
        foreach ($open as $rec) {
            try {
                $rec->provider_id && $this->media->stopRecording($rec->provider_id);
                $rec->update(['status' => 'stopping']);
            } catch (\Throwable $e) {
                report($e);
                $rec->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 240, ''), 'ended_at' => now()]);
            }
        }
        if ($s->recording_enabled) {
            $s->update(['recording_enabled' => false]);
            Audit::record('session.recording_off', $s);
            $this->realtime->session($s, 'recording', ['enabled' => false]);
        }
    }

    /** Egress finished (webhook): attach the object as a private stored file. Idempotent. */
    public function completed(array $egress): void
    {
        $rec = SessionRecording::where('provider_id', $egress['id'] ?? '')->first();
        if (! $rec || $rec->status === 'ready') {
            return;
        }
        $file = $egress['file'] ?? null;
        $failed = ! $file || ! empty($egress['error']) || in_array($egress['status'] ?? '', ['EGRESS_FAILED', 'EGRESS_ABORTED', 'EGRESS_LIMIT_REACHED'], true) && ! $file;
        if ($failed) {
            $rec->update(['status' => 'failed', 'error' => Str::limit((string) ($egress['error'] ?? 'خروجی ضبط ساخته نشد'), 240, ''), 'ended_at' => now()]);

            return;
        }
        $size = (int) ($file['size'] ?? 0);
        $stored = StoredFile::create([
            'user_id' => $rec->started_by, 'disk' => config('media.livekit.egress.disk'), 'path' => $rec->object_key, 'original_name' => 'recording-'.$rec->lesson_session_id.'.mp4',
            'mime' => 'video/mp4', 'size' => $size, 'context_type' => 'recording', 'context_id' => $rec->id, 'scan_status' => 'skipped',
        ]);
        $dur = isset($file['duration']) ? (int) round(((float) $file['duration']) / (((float) $file['duration']) > 1e6 ? 1e9 : 1)) : null;   // egress reports nanoseconds
        $rec->update(['status' => 'ready', 'file_id' => $stored->id, 'size' => $size, 'duration_seconds' => $dur, 'ended_at' => now()]);
        Audit::record('session.recording_ready', $rec, null, ['file_id' => $stored->id]);
    }
}
