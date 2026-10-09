<?php

namespace App\Modules\Files\Scan;

/** No scanner configured: nothing is claimed. Files are stored with scan_status=skipped and health says so. */
class NullScanner implements MalwareScanner
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function scanFile(string $path): array
    {
        return ['status' => 'clean', 'detail' => null];
    }

    public function health(): array
    {
        return ['ok' => false, 'configured' => false, 'detail' => 'پویشگر ویروس پیکربندی نشده است'];
    }
}
