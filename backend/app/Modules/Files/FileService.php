<?php

namespace App\Modules\Files;

use App\Models\SchoolSubscription;
use App\Models\StoredFile;
use App\Models\User;
use App\Modules\Tenancy\CurrentSchool;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FileService
{
    public function __construct(private CurrentSchool $current, private SettingsRepository $settings, private \App\Modules\Files\Scan\MalwareScanner $scanner) {}

    public function store(UploadedFile $file, User $by, ?string $contextType = null, ?int $contextId = null): StoredFile
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());   // sniff real bytes; never trust the client type
        $ext = config('files.allowed_mimes')[$mime] ?? null;
        $maxMb = (int) $this->settings->get('files.max_mb', config('files.max_mb_default'));

        if (! $ext) {
            throw ValidationException::withMessages(['file' => ['نوع فایل مجاز نیست.']]);
        }
        if ($file->getSize() > $maxMb * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => ["حداکثر حجم فایل {$maxMb} مگابایت است."]]);
        }
        $this->assertQuota((int) $file->getSize());
        [$scanStatus, $scanDetail] = $this->scan($file, $by);

        $schoolId = $this->current->id();
        $disk = config('files.disk');
        $path = "schools/$schoolId/".($contextType ?: 'pending').'/'.Str::ulid().'.'.$ext;
        Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

        return StoredFile::create([
            'user_id' => $by->id, 'disk' => $disk, 'path' => $path,
            // Client-supplied name is display-only; stripped to a safe basename.
            'original_name' => Str::limit(preg_replace('/[^\p{L}\p{N}\.\-_ ]/u', '_', basename($file->getClientOriginalName())), 200, ''),
            'mime' => $mime, 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()),
            'context_type' => $contextType, 'context_id' => $contextId,
            'scan_status' => $scanStatus, 'scan_detail' => $scanDetail,
        ]);
    }

    /** @return array{0: string, 1: ?string} */
    private function scan(UploadedFile $file, User $by): array
    {
        if (! $this->scanner->isConfigured()) {
            return ['skipped', null];
        }
        $r = $this->scanner->scanFile($file->getRealPath());
        if ($r['status'] === 'infected') {
            \App\Modules\Audit\Audit::record('file.blocked_malware', null, null, ['signature' => $r['detail'], 'user_id' => $by->id, 'name' => $file->getClientOriginalName()]);
            throw ValidationException::withMessages(['file' => ['فایل آلوده شناسایی شد و پذیرفته نشد.']]);
        }
        if ($r['status'] === 'error') {
            report(new \RuntimeException('malware scan error: '.$r['detail']));
            if (config('files.scan.fail_closed')) {
                abort(503, 'پویشگر ویروس در دسترس نیست؛ بارگذاری موقتاً ممکن نیست.');
            }

            return ['error', $r['detail']];
        }

        return ['clean', null];
    }

    /** Attach a previously uploaded, still-unattached file owned by $by to a context. */
    public function claim(int $fileId, User $by, string $contextType, int $contextId): StoredFile
    {
        $f = StoredFile::where('id', $fileId)->where('user_id', $by->id)->whereNull('context_type')->first();
        if (! $f) {
            throw ValidationException::withMessages(['file_id' => ['فایل یافت نشد یا قبلاً استفاده شده است.']]);
        }
        $f->update(['context_type' => $contextType, 'context_id' => $contextId]);

        return $f;
    }

    private function assertQuota(int $incoming): void
    {
        $limitMb = SchoolSubscription::where('school_id', $this->current->id())->where('status', 'active')->value('max_storage_mb');
        if ($limitMb === null) {
            return;
        }
        $used = (int) StoredFile::sum('size');
        if (($used + $incoming) > $limitMb * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => ['فضای ذخیره‌سازی مدرسه پر شده است.']]);
        }
    }

    public function signedUrl(StoredFile $f): string
    {
        return \Illuminate\Support\Facades\URL::temporarySignedRoute('files.download', now()->addMinutes(config('files.signed_url_minutes')), ['id' => $f->id]);
    }
}
