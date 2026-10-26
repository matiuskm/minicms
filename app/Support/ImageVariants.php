<?php

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/** Writes an uploaded image and its variants to the public disk (re-encoded, so EXIF and trailing payloads are gone). */
class ImageVariants
{
    /** JPG, PNG and WebP are stored as WebP (a fraction of the size) where GD can write it; GIFs and everything else keep their format. */
    public static function storedExt(string $ext): string
    {
        return in_array($ext, ['jpg', 'png', 'webp'], true) && function_exists('imagewebp') ? 'webp' : $ext;
    }

    /** @return array{int, int, int} width, height, stored bytes of the main file */
    public static function store(string $source, string $path, string $ext): array
    {
        $manager = new ImageManager(new Driver);
        $disk = Storage::disk('public');
        $quality = $ext === 'webp' ? (int) config('cms.uploads.webp_quality') : 85;

        // ponytail: GD keeps only the first frame of animated GIFs; use Imagick if animation matters.
        $encode = fn ($image) => $image->encodeUsingFileExtension($ext, quality: $quality);

        $main = $manager->decodePath($source)->scaleDown(width: 4000, height: 4000);
        $bytes = (string) $encode($main);
        $disk->put($path, $bytes);

        foreach (config('cms.uploads.variants') as $name => $size) {
            $variant = $manager->decodePath($source);
            $variant = $name === 'thumb' ? $variant->cover($size, $size) : $variant->scaleDown(width: $size);
            $disk->put(Media::variantPath($path, $name), (string) $encode($variant));
        }

        return [$main->width(), $main->height(), strlen($bytes)];
    }
}
