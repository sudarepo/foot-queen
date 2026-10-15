<?php

namespace App\Mcp\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pulls an image from a URL onto the public disk — the MCP equivalent of
 * the panel's FileUpload fields, which an agent has no way to fill.
 *
 * The type is sniffed from the bytes rather than trusted from the URL or the
 * response headers, so a renamed HTML page can't land in storage/ as a "logo".
 */
class RemoteImage
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * @var array<string, string> MIME type => stored extension
     */
    private const EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    /**
     * @param  array<int, string>|null  $allowedMimeTypes  null for any type above
     * @return string The path on the public disk, as the model columns store it.
     *
     * @throws RuntimeException When the URL can't be fetched or isn't an allowed image.
     */
    public static function store(string $url, string $directory, ?array $allowedMimeTypes = null): string
    {
        if (! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException("Only http(s) image URLs can be fetched: {$url}");
        }

        $response = Http::timeout(20)->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Fetching {$url} failed with HTTP {$response->status()}.");
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException("The image at {$url} is larger than 5 MB.");
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body) ?: '';

        // finfo reports SVG as text/xml or text/plain depending on the prolog.
        if (in_array($mimeType, ['text/xml', 'application/xml', 'text/plain'], true) && str_contains($body, '<svg')) {
            $mimeType = 'image/svg+xml';
        }

        $allowed = $allowedMimeTypes ?? array_keys(self::EXTENSIONS);

        if (! isset(self::EXTENSIONS[$mimeType]) || ! in_array($mimeType, $allowed, true)) {
            throw new RuntimeException("The file at {$url} is {$mimeType}, which isn't an accepted image type (".implode(', ', $allowed).').');
        }

        $path = trim($directory, '/').'/'.Str::random(40).'.'.self::EXTENSIONS[$mimeType];

        Storage::disk('public')->put($path, $body, 'public');

        return $path;
    }
}
