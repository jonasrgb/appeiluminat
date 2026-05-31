<?php

namespace App\Services\Shopify\BemWatermark;

use Illuminate\Support\Str;

class BemImageIdentityService
{
    public function newUuid(): string
    {
        return 'bem_'.strtolower((string) Str::ulid());
    }

    public function uuidFromUrl(?string $url): ?string
    {
        return $this->uuidFromFilename($this->filenameFromUrl($url));
    }

    public function uuidFromFilename(?string $filename): ?string
    {
        if (!$filename) {
            return null;
        }

        if (preg_match('/(bem_[a-z0-9]+)/i', $filename, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    public function isWatermarkedUrl(?string $url): bool
    {
        return $this->isWatermarkedFilename($this->filenameFromUrl($url));
    }

    public function isWatermarkedFilename(?string $filename): bool
    {
        if (!$filename) {
            return false;
        }

        $filename = strtolower($filename);
        if (preg_match('/_w_p_\d+\.[a-z0-9]+$/', $filename) === 1) {
            return true;
        }

        $aliases = array_values(array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            (array) config('features.bem_watermark_sync.domain_aliases', [])
        )));

        foreach (array_unique($aliases) as $alias) {
            $prefix = str_replace('-', '\-', preg_quote($alias, '/'));
            if (preg_match('/^'.$prefix.'_.+_w_/', $filename) === 1) {
                return true;
            }
        }

        return false;
    }

    public function filenameFromUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $basename = basename($path);

        return $basename !== '' ? $basename : null;
    }

    public function canonicalUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || empty($parts['path'])) {
            return $url;
        }

        $scheme = ($parts['scheme'] ?? 'https') === 'http' ? 'http' : 'https';

        return $scheme.'://'.strtolower($parts['host']).$parts['path'];
    }

    public function extensionFromUrl(?string $url, string $fallback = 'jpg'): string
    {
        $filename = $this->filenameFromUrl($url);
        $extension = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));

        return $extension !== '' ? $extension : $fallback;
    }
}
