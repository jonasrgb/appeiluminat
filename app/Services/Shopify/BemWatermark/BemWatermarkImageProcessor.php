<?php

namespace App\Services\Shopify\BemWatermark;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BemWatermarkImageProcessor
{
    private const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;

    public function __construct(private readonly BemWatermarkEligibilityService $eligibility)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $images
     * @return array{processed: array<int, array<string, mixed>>, temp_paths: array<int, string>}
     */
    public function process(Shop $target, string $title, array $images): array
    {
        $watermarkPath = $this->watermarkPathForShop($target);
        if (!$watermarkPath || !is_readable($watermarkPath)) {
            throw new \RuntimeException('BEM watermark asset missing for '.$target->domain);
        }

        $tmpDir = $this->createTempDirectory();

        $processed = [];
        $tempPaths = [];

        foreach ($images as $image) {
            $position = (int) ($image['position'] ?? (count($processed) + 1));
            $sourceUrl = (string) ($image['source_url'] ?? '');
            $extension = (string) ($image['original_extension'] ?? 'jpg');

            if ($sourceUrl === '') {
                $processed[] = $this->failedImage($image, $position, 'missing_source_url');
                continue;
            }

            try {
                $extension = $this->normalizeExtension($extension);
                $downloadPath = $this->downloadImage($sourceUrl, $tmpDir, $extension);
                $tempPaths[] = $downloadPath;
                $watermarkedPath = $this->applyWatermark($downloadPath, $watermarkPath, $extension);

                @unlink($downloadPath);

                $tempPaths[] = $watermarkedPath;
                $filename = $this->buildFilename($target, $title, $position, $extension);

                if ((filesize($watermarkedPath) ?: 0) > self::MAX_UPLOAD_BYTES) {
                    $processed[] = $this->failedImage($image, $position, 'file_too_large_after_watermark', $filename, $extension);
                    continue;
                }

                $processed[] = [
                    'position' => $position,
                    'source_url' => $sourceUrl,
                    'watermarked_url' => null,
                    'filename' => $filename,
                    'original_extension' => $extension,
                    'status' => 'processed',
                    'path' => $watermarkedPath,
                    'mime' => $this->mimeFromExtension($extension),
                    'alt' => $image['alt'] ?? $title,
                ];
            } catch (\Throwable $e) {
                Log::error('BEM watermark image processing failed', [
                    'target_shop' => $target->domain,
                    'position' => $position,
                    'source_url' => $sourceUrl,
                    'error' => $e->getMessage(),
                ]);

                $processed[] = $this->failedImage($image, $position, $e->getMessage(), null, $extension);
            }
        }

        return ['processed' => $processed, 'temp_paths' => $tempPaths];
    }

    public function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                @unlink($path);
                $this->removeEmptyTempDirectoryForPath($path);
            }
        }
    }

    private function createTempDirectory(): string
    {
        $baseDir = storage_path('app/watermark/bem_tmp/'.(app()->runningUnitTests() ? 'tests' : 'jobs'));
        $tmpDir = $baseDir.'/'.Str::uuid();

        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        return $tmpDir;
    }

    private function removeEmptyTempDirectoryForPath(string $path): void
    {
        $baseDir = realpath(storage_path('app/watermark/bem_tmp/'.(app()->runningUnitTests() ? 'tests' : 'jobs')));
        $dir = dirname($path);
        $realDir = is_dir($dir) ? realpath($dir) : false;

        if (!$baseDir || !$realDir || !str_starts_with($realDir, $baseDir.DIRECTORY_SEPARATOR)) {
            return;
        }

        @rmdir($realDir);
    }

    private function downloadImage(string $url, string $tmpDir, string $extension): string
    {
        $response = Http::timeout(60)->get($url);
        if ($response->failed()) {
            throw new \RuntimeException('download_failed_'.$response->status());
        }

        $path = $tmpDir.'/bem_original_'.Str::uuid().'.'.$extension;
        file_put_contents($path, $response->body());

        return $path;
    }

    private function applyWatermark(string $sourcePath, string $watermarkPath, string $extension): string
    {
        $image = $this->readGdImage($sourcePath);
        $watermark = $this->prepareWatermarkGdImage($watermarkPath, imagesx($image));

        $this->overlayWatermark($image, $watermark, $this->watermarkOpacity());

        $processedPath = preg_replace('/\.[^.]+$/', '', $sourcePath).'_bem_wm.'.$extension;
        if (!$processedPath) {
            imagedestroy($image);
            imagedestroy($watermark);
            throw new \RuntimeException('processed_path_failed');
        }

        $this->saveGdImage($image, $processedPath, $extension);

        imagedestroy($image);
        imagedestroy($watermark);

        return $processedPath;
    }

    private function readGdImage(string $path): \GdImage
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('image_read_failed');
        }

        $image = imagecreatefromstring($contents);
        if (!$image instanceof \GdImage) {
            throw new \RuntimeException('image_decode_failed');
        }

        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    private function prepareWatermarkGdImage(string $path, int $imageWidth): \GdImage
    {
        $watermark = imagecreatefrompng($path);
        if (!$watermark instanceof \GdImage) {
            throw new \RuntimeException('watermark_decode_failed');
        }

        imagealphablending($watermark, false);
        imagesavealpha($watermark, true);

        $transparent = imagecolorallocatealpha($watermark, 0, 0, 0, 127);
        $rotated = imagerotate($watermark, 45, $transparent);
        imagedestroy($watermark);

        if (!$rotated instanceof \GdImage) {
            throw new \RuntimeException('watermark_rotate_failed');
        }

        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);

        $targetWidth = max(1, (int) floor($imageWidth * $this->watermarkWidthRatio()));
        $targetHeight = max(1, (int) floor(imagesy($rotated) * ($targetWidth / imagesx($rotated))));
        $scaled = imagecreatetruecolor($targetWidth, $targetHeight);

        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));

        imagecopyresampled(
            $scaled,
            $rotated,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            imagesx($rotated),
            imagesy($rotated)
        );

        imagedestroy($rotated);

        return $scaled;
    }

    private function overlayWatermark(\GdImage $image, \GdImage $watermark, int $opacity): void
    {
        $startX = (int) floor((imagesx($image) - imagesx($watermark)) / 2);
        $startY = (int) floor((imagesy($image) - imagesy($watermark)) / 2);
        $opacityFactor = $opacity / 100;
        $colorCache = [];

        for ($watermarkY = 0; $watermarkY < imagesy($watermark); $watermarkY++) {
            $targetY = $startY + $watermarkY;
            if ($targetY < 0 || $targetY >= imagesy($image)) {
                continue;
            }

            for ($watermarkX = 0; $watermarkX < imagesx($watermark); $watermarkX++) {
                $targetX = $startX + $watermarkX;
                if ($targetX < 0 || $targetX >= imagesx($image)) {
                    continue;
                }

                $sourceRgba = imagecolorat($watermark, $watermarkX, $watermarkY);
                $sourceAlpha = ($sourceRgba & 0x7F000000) >> 24;
                if ($sourceAlpha >= 127) {
                    continue;
                }

                $sourceOpacity = ((127 - $sourceAlpha) / 127) * $opacityFactor;
                if ($sourceOpacity <= 0) {
                    continue;
                }

                $targetRgba = imagecolorat($image, $targetX, $targetY);
                $targetAlpha = ($targetRgba & 0x7F000000) >> 24;
                $targetOpacity = (127 - $targetAlpha) / 127;
                $outputOpacity = $sourceOpacity + ($targetOpacity * (1 - $sourceOpacity));

                if ($outputOpacity <= 0) {
                    continue;
                }

                $sourceRed = ($sourceRgba >> 16) & 255;
                $sourceGreen = ($sourceRgba >> 8) & 255;
                $sourceBlue = $sourceRgba & 255;
                $targetRed = ($targetRgba >> 16) & 255;
                $targetGreen = ($targetRgba >> 8) & 255;
                $targetBlue = $targetRgba & 255;

                $outputRed = (int) round((($sourceRed * $sourceOpacity) + ($targetRed * $targetOpacity * (1 - $sourceOpacity))) / $outputOpacity);
                $outputGreen = (int) round((($sourceGreen * $sourceOpacity) + ($targetGreen * $targetOpacity * (1 - $sourceOpacity))) / $outputOpacity);
                $outputBlue = (int) round((($sourceBlue * $sourceOpacity) + ($targetBlue * $targetOpacity * (1 - $sourceOpacity))) / $outputOpacity);
                $outputAlpha = 127 - (int) round($outputOpacity * 127);
                $cacheKey = "{$outputRed},{$outputGreen},{$outputBlue},{$outputAlpha}";

                if (!isset($colorCache[$cacheKey])) {
                    $colorCache[$cacheKey] = imagecolorallocatealpha($image, $outputRed, $outputGreen, $outputBlue, $outputAlpha);
                }

                imagesetpixel($image, $targetX, $targetY, $colorCache[$cacheKey]);
            }
        }
    }

    private function saveGdImage(\GdImage $image, string $path, string $extension): void
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $saved = match ($extension) {
            'png' => imagepng($image, $path),
            'webp' => imagewebp($image, $path, 100),
            default => imagejpeg($image, $path, 100),
        };

        if (!$saved) {
            throw new \RuntimeException('image_save_failed');
        }
    }

    private function watermarkWidthRatio(): float
    {
        $ratio = (float) config('features.bem_watermark_sync.width_ratio', 0.25);

        return max(0.1, min(0.9, $ratio));
    }

    private function watermarkOpacity(): int
    {
        $opacity = (int) config('features.bem_watermark_sync.opacity', 15);

        return max(1, min(100, $opacity));
    }

    private function watermarkPathForShop(Shop $target): ?string
    {
        $map = [
            'eiluminat.myshopify.com' => 'watermark_eiluminat.png',
            'lustreled.myshopify.com' => 'watermark_lustreled.png',
            'powerleds-ro.myshopify.com' => 'watermark_power.png',
            'iluminat-industrial.myshopify.com' => 'watermark_industrial.png',
        ];

        $filename = $map[strtolower((string) $target->domain)] ?? null;

        return $filename ? storage_path('app/watermark/'.$filename) : null;
    }

    private function buildFilename(Shop $target, string $title, int $position, string $extension): string
    {
        $shopSlug = $this->eligibility->targetAlias($target);
        $titleSlug = $this->slugPart($title !== '' ? $title : 'product');

        return "{$shopSlug}_{$titleSlug}_w_p_{$position}.{$extension}";
    }

    private function slugPart(string $value): string
    {
        $slug = str($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->toString();
        $slug = preg_replace('/-+/', '-', $slug) ?: 'product';

        return $slug;
    }

    private function normalizeExtension(string $extension): string
    {
        $extension = strtolower(trim($extension, '. '));

        return match ($extension) {
            'jpg', 'jpeg', 'png', 'webp' => $extension,
            default => throw new \RuntimeException('unsupported_image_extension_'.$extension),
        };
    }

    private function mimeFromExtension(string $extension): string
    {
        return match ($extension) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    private function failedImage(array $image, int $position, string $status, ?string $filename = null, ?string $extension = null): array
    {
        return [
            'position' => $position,
            'source_url' => $image['source_url'] ?? null,
            'watermarked_url' => null,
            'filename' => $filename,
            'original_extension' => $extension ?? ($image['original_extension'] ?? null),
            'status' => 'failed:'.$status,
        ];
    }
}
