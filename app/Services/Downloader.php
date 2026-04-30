<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Header;
use RuntimeException;

class Downloader
{
    public function __construct(private readonly Client $client = new Client) {}

    /**
     * Stream-download a URL to a local path. Calls $onProgress($total, $downloaded)
     * frequently while bytes flow.
     *
     * @return array{filename: string, size: int}
     */
    public function download(string $url, string $sinkPath, callable $onProgress, int $maxBytes): array
    {
        $sink = fopen($sinkPath, 'w');

        if ($sink === false) {
            throw new RuntimeException("Could not open sink path: {$sinkPath}");
        }

        try {
            $response = $this->client->request('GET', $url, [
                'sink' => $sink,
                'http_errors' => true,
                'connect_timeout' => 30,
                'timeout' => 0,
                'allow_redirects' => true,
                'headers' => [
                    'User-Agent' => 'amirworker_bot/1.0',
                ],
                'progress' => function ($total, $downloaded) use ($onProgress, $maxBytes) {
                    if ($maxBytes > 0 && ($total > $maxBytes || $downloaded > $maxBytes)) {
                        throw new RuntimeException(
                            'File exceeds size cap of '.$this->formatBytes($maxBytes)
                        );
                    }
                    $onProgress((int) $total, (int) $downloaded);
                },
            ]);
        } finally {
            if (is_resource($sink)) {
                fclose($sink);
            }
        }

        $size = (int) (filesize($sinkPath) ?: 0);
        $filename = $this->guessFilename($url, $response->getHeaderLine('Content-Disposition'));

        return ['filename' => $filename, 'size' => $size];
    }

    private function guessFilename(string $url, string $contentDisposition): string
    {
        if ($contentDisposition !== '') {
            $parts = Header::parse($contentDisposition);
            foreach ($parts as $part) {
                $name = $part['filename*'] ?? $part['filename'] ?? null;
                if ($name) {
                    if (str_starts_with($name, "UTF-8''")) {
                        $name = rawurldecode(substr($name, 7));
                    }
                    $name = trim($name, " \"'");
                    if ($name !== '') {
                        return $this->sanitize($name);
                    }
                }
            }
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $basename = basename($path);

        if ($basename !== '' && $basename !== '/') {
            return $this->sanitize(rawurldecode($basename));
        }

        return 'file-'.substr(sha1($url), 0, 12);
    }

    private function sanitize(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\/\\\\]/', '_', $name) ?? $name;

        return mb_substr($name, 0, 200);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $v, $units[$i]);
    }
}
