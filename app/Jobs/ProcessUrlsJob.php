<?php

namespace App\Jobs;

use App\Models\Upload;
use App\Services\Downloader;
use App\Services\ProgressReporter;
use App\Services\Uploader;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SergiX44\Nutgram\Nutgram;
use Throwable;

class ProcessUrlsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;

    public int $tries = 1;

    /**
     * @param  array<int, string>  $urls
     */
    public function __construct(
        public readonly int $chatId,
        public readonly int $statusMessageId,
        public readonly int $userId,
        public readonly array $urls,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("tg-user:{$this->userId}"))
                ->expireAfter($this->timeout)
                ->releaseAfter(5),
        ];
    }

    public function handle(Nutgram $bot, Downloader $downloader, Uploader $uploader): void
    {
        $reporter = new ProgressReporter(
            $bot,
            $this->chatId,
            $this->statusMessageId,
            (int) config('app.progress_edit_interval_ms'),
        );

        $total = count($this->urls);
        $summary = [];

        foreach ($this->urls as $i => $url) {
            $idx = $i + 1;

            try {
                $reporter->report("⏳ [{$idx}/{$total}] Starting…\n{$url}", force: true);

                if ($this->isLocalDriver(config('app.disk'))) {
                    $entry = $this->processLocal($downloader, $reporter, $idx, $total, $url);
                } else {
                    $entry = $this->processRemote($downloader, $uploader, $reporter, $idx, $total, $url);
                }

                $summary[] = $entry;
            } catch (Throwable $e) {
                Log::error('amirworker: url processing failed', [
                    'url' => $url,
                    'user_id' => $this->userId,
                    'error' => $e->getMessage(),
                ]);
                $summary[] = sprintf("❌ [%d/%d] %s\n%s", $idx, $total, $url, $e->getMessage());
            }

            $reporter->report(implode("\n\n", $summary), force: true);
        }

        $reporter->report(implode("\n\n", $summary)."\n\nDone.", force: true);
    }

    private function processLocal(
        Downloader $downloader,
        ProgressReporter $reporter,
        int $idx,
        int $total,
        string $url,
    ): string {
        $disk = config('app.disk');
        $expiresAt = now()->addHours((int) config('app.retention_hours'));

        $key = null;
        $publicUrl = null;
        $info = $downloader->streamingDownload(
            url: $url,
            destinationFactory: function (string $filename, int $contentLength) use (
                &$key, &$publicUrl, $disk, $expiresAt, $reporter, $idx, $total, $url,
            ) {
                $key = $this->buildStorageKey($filename);
                $publicUrl = $this->buildPublicUrl($disk, $key, $expiresAt);

                $absPath = Storage::disk($disk)->path($key);
                $dir = dirname($absPath);
                if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                    throw new \RuntimeException("Could not create directory: {$dir}");
                }

                $reporter->report(sprintf(
                    "⬇️ [%d/%d] Streaming %s\nSource: %s\nLink (live): %s",
                    $idx,
                    $total,
                    $filename,
                    $url,
                    $publicUrl,
                ), force: true);

                $sink = fopen($absPath, 'w');
                if ($sink === false) {
                    throw new \RuntimeException("Could not open destination: {$absPath}");
                }

                return $sink;
            },
            onProgress: function (int $tot, int $dl) use ($reporter, $idx, $total, &$publicUrl) {
                $reporter->report(sprintf(
                    "⬇️ [%d/%d] Streaming\n%s\nLink (live): %s",
                    $idx,
                    $total,
                    ProgressReporter::renderBar($tot, $dl),
                    $publicUrl,
                ));
            },
            maxBytes: (int) config('app.max_download_bytes'),
        );

        Upload::create([
            'telegram_user_id' => $this->userId,
            'source_url' => $url,
            'disk' => $disk,
            'path' => $key,
            'public_url' => $publicUrl,
            'size_bytes' => $info['size'],
            'expires_at' => $expiresAt,
        ]);

        return sprintf("✅ [%d/%d] %s\n%s", $idx, $total, $info['filename'], $publicUrl);
    }

    private function processRemote(
        Downloader $downloader,
        Uploader $uploader,
        ProgressReporter $reporter,
        int $idx,
        int $total,
        string $url,
    ): string {
        $tmp = tempnam(sys_get_temp_dir(), 'amrj_');

        try {
            $info = $downloader->download(
                $url,
                $tmp,
                function (int $tot, int $dl) use ($reporter, $idx, $total, $url) {
                    $reporter->report(sprintf(
                        "⬇️ [%d/%d] Downloading\n%s\n%s",
                        $idx,
                        $total,
                        $url,
                        ProgressReporter::renderBar($tot, $dl),
                    ));
                },
                (int) config('app.max_download_bytes'),
            );

            $key = $this->buildStorageKey($info['filename']);

            $uploader->upload(
                $tmp,
                $key,
                function (int $tot, int $up) use ($reporter, $idx, $total, $info) {
                    $reporter->report(sprintf(
                        "⬆️ [%d/%d] Uploading\n%s\n%s",
                        $idx,
                        $total,
                        $info['filename'],
                        ProgressReporter::renderBar($tot, $up),
                    ));
                },
            );

            $disk = config('app.disk');
            $expiresAt = now()->addHours((int) config('app.retention_hours'));
            $publicUrl = $this->buildPublicUrl($disk, $key, $expiresAt);

            Upload::create([
                'telegram_user_id' => $this->userId,
                'source_url' => $url,
                'disk' => $disk,
                'path' => $key,
                'public_url' => $publicUrl,
                'size_bytes' => $info['size'],
                'expires_at' => $expiresAt,
            ]);

            return sprintf("✅ [%d/%d] %s\n%s", $idx, $total, $info['filename'], $publicUrl);
        } finally {
            if (is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function buildStorageKey(string $filename): string
    {
        return sprintf(
            '%s/%s/%s/%s',
            config('app.storage_prefix'),
            now()->format('Y-m-d'),
            (string) Str::ulid(),
            $filename,
        );
    }

    private function isLocalDriver(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.driver") === 'local';
    }

    private function buildPublicUrl(string $disk, string $key, DateTimeInterface $expiresAt): string
    {
        $fs = Storage::disk($disk);

        if ($disk === 's3' && empty(config('filesystems.disks.s3.url'))) {
            return $fs->temporaryUrl($key, $expiresAt);
        }

        return $fs->url($key);
    }
}
