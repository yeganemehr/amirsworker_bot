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
use RuntimeException;
use SergiX44\Nutgram\Nutgram;
use Throwable;

class ProcessUrlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(
        public readonly int $chatId,
        public readonly int $statusMessageId,
        public readonly int $userId,
        public readonly ?string $url = null,
        public readonly ?string $telegramFileId = null,
        public readonly ?string $preferredFilename = null,
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

        $sourceLabel = $this->preferredFilename ?? $this->url ?? 'file';

        try {
            [$downloadUrl, $sourceLabel, $sourceUrl] = $this->resolveSource($bot, $reporter);

            $reporter->report("⏳ Starting…\n{$sourceLabel}", force: true);

            $result = $this->isLocalDriver(config('app.disk'))
                ? $this->processLocal($downloader, $reporter, $downloadUrl, $sourceLabel, $sourceUrl)
                : $this->processRemote($downloader, $uploader, $reporter, $downloadUrl, $sourceLabel, $sourceUrl);

            $reporter->report($result, force: true);
        } catch (Throwable $e) {
            Log::error('processing failed', [
                'url' => $this->url,
                'telegram_file_id' => $this->telegramFileId,
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);
            $reporter->report(sprintf("❌ %s\n%s", $sourceLabel, $e->getMessage()), force: true);
        }
    }

    /**
     * Resolve the job's source into a concrete [downloadUrl, displayLabel, sourceUrlForUploadRow].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveSource(Nutgram $bot, ProgressReporter $reporter): array
    {
        if ($this->telegramFileId !== null) {
            $reporter->report('⏳ Fetching file info from Telegram…', force: true);

            $file = $bot->getFile($this->telegramFileId);
            if ($file === null || empty($file->file_path)) {
                throw new RuntimeException('Could not resolve Telegram file (it may exceed 20 MB).');
            }

            $token = (string) config('nutgram.token');
            if ($token === '') {
                throw new RuntimeException('TELEGRAM_TOKEN is not configured.');
            }

            $downloadUrl = "https://api.telegram.org/file/bot{$token}/{$file->file_path}";
            $label = $this->preferredFilename ?? basename($file->file_path);
            $sourceUrl = 'telegram-file:'.$this->telegramFileId;

            return [$downloadUrl, $label, $sourceUrl];
        }

        if ($this->url !== null) {
            return [$this->url, $this->url, $this->url];
        }

        throw new RuntimeException('ProcessUrlJob requires either a url or a telegramFileId.');
    }

    private function processLocal(
        Downloader $downloader,
        ProgressReporter $reporter,
        string $downloadUrl,
        string $sourceLabel,
        string $sourceUrl,
    ): string {
        $disk = config('app.disk');
        $expiresAt = now()->addHours((int) config('app.retention_hours'));

        $key = null;
        $publicUrl = null;
        $info = $downloader->streamingDownload(
            url: $downloadUrl,
            destinationFactory: function (string $filename, int $contentLength) use (
                &$key, &$publicUrl, $disk, $expiresAt, $reporter, $sourceLabel,
            ) {
                $key = $this->buildStorageKey($filename);
                $publicUrl = $this->buildPublicUrl($disk, $key, $expiresAt);

                $absPath = Storage::disk($disk)->path($key);
                $dir = dirname($absPath);
                if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                    throw new RuntimeException("Could not create directory: {$dir}");
                }

                $reporter->report(sprintf(
                    "⬇️ Streaming %s\nSource: %s\nLink (live): %s",
                    $filename,
                    $sourceLabel,
                    $publicUrl,
                ), force: true);

                $sink = fopen($absPath, 'w');
                if ($sink === false) {
                    throw new RuntimeException("Could not open destination: {$absPath}");
                }

                return $sink;
            },
            onProgress: function (int $tot, int $dl) use ($reporter, &$publicUrl) {
                $reporter->report(sprintf(
                    "⬇️ Streaming\n%s\nLink (live): %s",
                    ProgressReporter::renderBar($tot, $dl),
                    $publicUrl,
                ));
            },
            maxBytes: (int) config('app.max_download_bytes'),
            preferredFilename: $this->preferredFilename,
        );

        Upload::create([
            'telegram_user_id' => $this->userId,
            'source_url' => $sourceUrl,
            'disk' => $disk,
            'path' => $key,
            'public_url' => $publicUrl,
            'size_bytes' => $info['size'],
            'expires_at' => $expiresAt,
        ]);

        return sprintf("✅ %s\n%s", $info['filename'], $publicUrl);
    }

    private function processRemote(
        Downloader $downloader,
        Uploader $uploader,
        ProgressReporter $reporter,
        string $downloadUrl,
        string $sourceLabel,
        string $sourceUrl,
    ): string {
        $tmp = tempnam(sys_get_temp_dir(), 'amrj_');

        try {
            $info = $downloader->download(
                $downloadUrl,
                $tmp,
                function (int $tot, int $dl) use ($reporter, $sourceLabel) {
                    $reporter->report(sprintf(
                        "⬇️ Downloading\n%s\n%s",
                        $sourceLabel,
                        ProgressReporter::renderBar($tot, $dl),
                    ));
                },
                (int) config('app.max_download_bytes'),
                $this->preferredFilename,
            );

            $key = $this->buildStorageKey($info['filename']);

            $uploader->upload(
                $tmp,
                $key,
                function (int $tot, int $up) use ($reporter, $info) {
                    $reporter->report(sprintf(
                        "⬆️ Uploading\n%s\n%s",
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
                'source_url' => $sourceUrl,
                'disk' => $disk,
                'path' => $key,
                'public_url' => $publicUrl,
                'size_bytes' => $info['size'],
                'expires_at' => $expiresAt,
            ]);

            return sprintf("✅ %s\n%s", $info['filename'], $publicUrl);
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

        return $fs->url($this->encodePathSegments($key));
    }

    private function encodePathSegments(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }
}
