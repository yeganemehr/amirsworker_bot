<?php

namespace App\Jobs;

use App\Models\Upload;
use App\Services\Downloader;
use App\Services\ProgressReporter;
use App\Services\Uploader;
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
            $tmp = tempnam(sys_get_temp_dir(), 'amrj_');

            try {
                $reporter->report("⏳ [{$idx}/{$total}] Starting…\n{$url}", force: true);

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

                $key = sprintf(
                    '%s/%s/%s/%s',
                    config('app.storage_prefix'),
                    now()->format('Y-m-d'),
                    (string) Str::ulid(),
                    $info['filename'],
                );

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

                $summary[] = sprintf(
                    "✅ [%d/%d] %s\n%s",
                    $idx,
                    $total,
                    $info['filename'],
                    $publicUrl,
                );
            } catch (Throwable $e) {
                Log::error('amirworker: url processing failed', [
                    'url' => $url,
                    'user_id' => $this->userId,
                    'error' => $e->getMessage(),
                ]);
                $summary[] = sprintf("❌ [%d/%d] %s\n%s", $idx, $total, $url, $e->getMessage());
            } finally {
                if (is_string($tmp) && is_file($tmp)) {
                    @unlink($tmp);
                }
            }

            $reporter->report(implode("\n\n", $summary), force: true);
        }

        $reporter->report(implode("\n\n", $summary)."\n\nDone.", force: true);
    }

    private function buildPublicUrl(string $disk, string $key, \DateTimeInterface $expiresAt): string
    {
        $fs = Storage::disk($disk);

        if ($disk === 's3' && empty(config('filesystems.disks.s3.url'))) {
            return $fs->temporaryUrl($key, $expiresAt);
        }

        return $fs->url($key);
    }
}
