<?php

namespace App\Services\Shopify\BemWatermark;

class BemSourceUpdateImageClassifier
{
    public function __construct(private readonly BemImageIdentityService $identity)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $sourceImages
     * @return array{
     *   existing: array<int, array<string, mixed>>,
     *   new_clean: array<int, array<string, mixed>>,
     *   deleted: array<int, array<string, mixed>>,
     *   unknown_watermarked: array<int, array<string, mixed>>,
     *   desired_order: array<int, string>
     * }
     */
    public function classify(array $sourceImages, array $manifest): array
    {
        $activeManifestImages = array_values(array_filter(
            (array) ($manifest['images'] ?? []),
            static fn ($image) => ($image['status'] ?? 'active') === 'active'
        ));

        $byUuid = [];
        $bySourceWatermarkedUrl = [];
        $bySourceWatermarkedMedia = [];

        foreach ($activeManifestImages as $image) {
            $uuid = $image['image_uuid'] ?? null;
            if (is_string($uuid) && $uuid !== '') {
                $byUuid[$uuid] = $image;
            }

            $sourceUrl = $this->identity->canonicalUrl($image['source_watermarked_url'] ?? null);
            if ($sourceUrl) {
                $bySourceWatermarkedUrl[$sourceUrl] = $image;
            }

            $mediaGid = $image['source_watermarked_media_gid'] ?? null;
            if (is_string($mediaGid) && $mediaGid !== '') {
                $bySourceWatermarkedMedia[$mediaGid] = $image;
            }
        }

        $seenUuids = [];
        $existing = [];
        $newClean = [];
        $unknownWatermarked = [];
        $desiredOrder = [];

        foreach ($sourceImages as $index => $sourceImage) {
            $sourceImage['position'] = (int) ($sourceImage['position'] ?? ($index + 1));
            $sourceUrl = $this->identity->canonicalUrl($sourceImage['url'] ?? $sourceImage['src'] ?? null);
            $sourceMediaGid = $sourceImage['media_gid'] ?? $sourceImage['image_id'] ?? null;
            $uuid = $sourceImage['image_uuid'] ?? null;
            $uuid = is_string($uuid) && $uuid !== '' ? $uuid : $this->identity->uuidFromUrl($sourceUrl);

            $manifestImage = null;
            if ($uuid && isset($byUuid[$uuid])) {
                $manifestImage = $byUuid[$uuid];
            } elseif ($sourceUrl && isset($bySourceWatermarkedUrl[$sourceUrl])) {
                $manifestImage = $bySourceWatermarkedUrl[$sourceUrl];
            } elseif (is_string($sourceMediaGid) && isset($bySourceWatermarkedMedia[$sourceMediaGid])) {
                $manifestImage = $bySourceWatermarkedMedia[$sourceMediaGid];
            }

            if ($manifestImage) {
                $matchedUuid = (string) ($manifestImage['image_uuid'] ?? $uuid);
                $seenUuids[$matchedUuid] = true;
                $desiredOrder[] = $matchedUuid;
                $existing[] = [
                    'image_uuid' => $matchedUuid,
                    'source_image' => $sourceImage,
                    'manifest_image' => $manifestImage,
                    'position' => $sourceImage['position'],
                ];
                continue;
            }

            if ($this->identity->isWatermarkedUrl($sourceUrl)) {
                $unknownWatermarked[] = [
                    'source_image' => $sourceImage,
                    'reason' => 'watermarked_image_not_found_in_manifest',
                    'position' => $sourceImage['position'],
                ];
                continue;
            }

            $newClean[] = [
                'source_image' => $sourceImage,
                'position' => $sourceImage['position'],
                'source_url' => $sourceUrl,
                'original_extension' => $this->identity->extensionFromUrl($sourceUrl),
            ];
        }

        $deleted = [];
        foreach ($activeManifestImages as $image) {
            $uuid = (string) ($image['image_uuid'] ?? '');
            if ($uuid !== '' && !isset($seenUuids[$uuid])) {
                $deleted[] = $image;
            }
        }

        return [
            'existing' => $existing,
            'new_clean' => $newClean,
            'deleted' => $deleted,
            'unknown_watermarked' => $unknownWatermarked,
            'desired_order' => $desiredOrder,
        ];
    }
}
