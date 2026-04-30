<?php

namespace App\Services;

use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;

class Uploader
{
    private const PART_SIZE = 5 * 1024 * 1024;

    /**
     * Upload a local file to the configured disk. Calls $onProgress($total, $uploaded)
     * during the operation.
     *
     * For s3, progress ticks once per 5 MiB part via MultipartUploader.
     * For local/public disks, progress ticks once at start and once at finish since
     * the operation is a fast local copy.
     *
     * @return string the storage path written
     */
    public function upload(string $localPath, string $key, callable $onProgress): string
    {
        $disk = config('app.disk');
        $size = (int) (filesize($localPath) ?: 0);

        if ($disk === 's3') {
            return $this->uploadToS3($localPath, $key, $size, $onProgress);
        }

        return $this->uploadToFilesystem($localPath, $key, $size, $disk, $onProgress);
    }

    private function uploadToS3(string $localPath, string $key, int $size, callable $onProgress): string
    {
        /** @var S3Client $client */
        $client = Storage::disk('s3')->getClient();

        $uploaded = 0;

        $uploader = new MultipartUploader($client, $localPath, [
            'bucket' => config('filesystems.disks.s3.bucket'),
            'key' => $key,
            'part_size' => self::PART_SIZE,
            'before_upload' => function () use (&$uploaded, $size, $onProgress) {
                $onProgress($size, min($uploaded, $size));
                $uploaded += self::PART_SIZE;
            },
        ]);

        $uploader->upload();
        $onProgress($size, $size);

        return $key;
    }

    private function uploadToFilesystem(string $localPath, string $key, int $size, string $disk, callable $onProgress): string
    {
        $onProgress($size, 0);

        $stream = fopen($localPath, 'r');
        try {
            Storage::disk($disk)->put($key, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $onProgress($size, $size);

        return $key;
    }
}
