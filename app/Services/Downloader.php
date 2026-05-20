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
    public function download(
        string $url,
        string $sinkPath,
        callable $onProgress,
        int $maxBytes,
        ?string $preferredFilename = null,
    ): array {
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
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36',
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
        $filename = $preferredFilename !== null
            ? $this->sanitize($preferredFilename)
            : $this->guessFilename($url, $response->getHeaderLine('Content-Disposition'));

        return ['filename' => $filename, 'size' => $size];
    }

    /**
     * Stream-download a URL while the caller decides where to write — used for the
     * local-storage flow where we want to commit to a final path (and a public URL)
     * before any bytes hit disk.
     *
     * The destination factory receives the resolved filename and Content-Length
     * (0 if unknown) and must return an open writable stream resource. The download
     * body is piped into that stream and flushed after every chunk so HTTP clients
     * reading the path can see partial bytes as they arrive.
     *
     * @param  callable(string, int): resource  $destinationFactory
     * @return array{filename: string, size: int}
     */
    public function streamingDownload(
        string $url,
        callable $destinationFactory,
        callable $onProgress,
        int $maxBytes,
        ?string $preferredFilename = null,
    ): array {
        $response = $this->client->request('GET', $url, [
            'stream' => true,
            'http_errors' => true,
            'connect_timeout' => 30,
            'timeout' => 0,
            'allow_redirects' => true,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36',
            ],
        ]);

        $filename = $preferredFilename !== null
            ? $this->sanitize($preferredFilename)
            : $this->guessFilename($url, $response->getHeaderLine('Content-Disposition'));
        $contentLength = (int) ($response->getHeaderLine('Content-Length') ?: 0);

        if ($maxBytes > 0 && $contentLength > $maxBytes) {
            throw new RuntimeException('File exceeds size cap of '.$this->formatBytes($maxBytes));
        }

        $sink = $destinationFactory($filename, $contentLength);

        if (! is_resource($sink)) {
            throw new RuntimeException('destinationFactory must return an open resource');
        }

        $body = $response->getBody();
        $size = 0;

        try {
            while (! $body->eof()) {
                $chunk = $body->read(64 * 1024);
                if ($chunk === '') {
                    continue;
                }
                $written = fwrite($sink, $chunk);
                if ($written === false) {
                    throw new RuntimeException('Write failed');
                }
                $size += $written;
                if ($maxBytes > 0 && $size > $maxBytes) {
                    throw new RuntimeException('File exceeds size cap of '.$this->formatBytes($maxBytes));
                }
                fflush($sink);
                $onProgress($contentLength, $size);
            }
        } finally {
            if (is_resource($sink)) {
                fclose($sink);
            }
        }

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
