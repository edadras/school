<?php

namespace App\Modules\Files\Scan;

/** ClamAV `clamd` over TCP or a unix socket, using the INSTREAM protocol (the file is streamed, never read from a shared path). */
class ClamAvScanner implements MalwareScanner
{
    public function __construct(private array $cfg) {}

    public function name(): string
    {
        return 'clamav';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->cfg['socket']) || ! empty($this->cfg['host']);
    }

    public function scanFile(string $path): array
    {
        $fp = $this->connect();
        if (! $fp) {
            return ['status' => 'error', 'detail' => 'اتصال به clamd برقرار نشد'];
        }
        try {
            fwrite($fp, "zINSTREAM\0");
            $in = fopen($path, 'rb');
            while (! feof($in)) {
                $chunk = fread($in, 65536);
                if ($chunk === '' || $chunk === false) {
                    break;
                }
                if (@fwrite($fp, pack('N', strlen($chunk)).$chunk) === false) {
                    break;           // clamd closed early (size limit / infected) — its reply is still readable
                }
            }
            fclose($in);
            @fwrite($fp, pack('N', 0));
            $reply = trim((string) stream_get_contents($fp), "\0 \n");
        } finally {
            fclose($fp);
        }

        return $this->parse($reply);
    }

    public function parse(string $reply): array
    {
        if (str_ends_with($reply, 'OK')) {
            return ['status' => 'clean', 'detail' => null];
        }
        if (preg_match('/^stream: (.+) FOUND$/', $reply, $m)) {
            return ['status' => 'infected', 'detail' => $m[1]];
        }

        return ['status' => 'error', 'detail' => mb_substr($reply ?: 'پاسخ نامعتبر از clamd', 0, 200)];
    }

    public function health(): array
    {
        $fp = $this->connect();
        if (! $fp) {
            return ['ok' => false, 'configured' => true, 'detail' => 'clamd در دسترس نیست'];
        }
        fwrite($fp, "zPING\0");
        $r = trim((string) stream_get_contents($fp), "\0 \n");
        fclose($fp);

        return ['ok' => $r === 'PONG', 'configured' => true, 'detail' => $r === 'PONG' ? 'clamd فعال' : 'پاسخ نامعتبر'];
    }

    /** @return resource|false */
    private function connect()
    {
        $addr = ! empty($this->cfg['socket']) ? 'unix://'.$this->cfg['socket'] : 'tcp://'.$this->cfg['host'].':'.($this->cfg['port'] ?? 3310);
        $fp = @stream_socket_client($addr, $errno, $err, (float) ($this->cfg['timeout'] ?? 5));
        $fp && stream_set_timeout($fp, (int) ($this->cfg['timeout'] ?? 5) * 6);

        return $fp;
    }
}
