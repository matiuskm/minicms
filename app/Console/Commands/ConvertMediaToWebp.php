<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Support\ImageVariants;
use App\Support\PublicCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** New uploads are stored as WebP already; this brings the JPG/PNG images uploaded before that up to date. */
class ConvertMediaToWebp extends Command
{
    protected $signature = 'media:webp {--dry-run : List the images that would be converted and change nothing}';

    protected $description = 'Convert existing JPG/PNG images and their variants to WebP and point posts and pages at the new files';

    public function handle(): int
    {
        if (! function_exists('imagewebp')) {
            $this->error('This PHP build (GD) cannot write WebP.');

            return self::FAILURE;
        }

        $disk = Storage::disk('public');
        $dry = (bool) $this->option('dry-run');
        $count = $before = $after = 0;

        Media::whereIn('mime', ['image/jpeg', 'image/png'])->orderBy('id')->each(function (Media $m) use ($disk, $dry, &$count, &$before, &$after) {
            if (! $disk->exists($m->path)) {
                $this->warn("#{$m->id} {$m->path}: file missing, skipped");

                return;
            }

            $count++;
            if ($dry) {
                $this->line("#{$m->id} {$m->path}");

                return;
            }

            $old = $m->allPaths();
            $path = preg_replace('/\.[a-z0-9]+$/', '.webp', $m->path);
            [$width, $height, $bytes] = ImageVariants::store($disk->path($m->path), $path, 'webp');
            $before += $m->size;
            $after += $bytes;

            // Posts and pages embed the file URLs in their HTML, so those are rewritten too. The old files stay, so anything not
            // rewritten here (revisions, home blocks) keeps working; deleting the media removes them (Media::deleteFiles).
            $new = [$path, ...array_map(fn ($v) => Media::variantPath($path, $v), array_keys(config('cms.uploads.variants')))];
            $pairs = array_combine($old, $new);
            DB::transaction(function () use ($m, $path, $width, $height, $bytes, $pairs) {
                $m->update(['path' => $path, 'mime' => 'image/webp', 'size' => $bytes, 'width' => $width, 'height' => $height]);
                foreach (['posts', 'pages'] as $table) {
                    DB::table($table)->where('body', 'like', "%{$m->uuid}%")->orderBy('id')->each(
                        fn ($row) => DB::table($table)->where('id', $row->id)->update(['body' => strtr($row->body, $pairs)])
                    );
                }
            });
        });

        $this->info($dry
            ? "{$count} image(s) would be converted."
            : sprintf('%d image(s) converted: %s -> %s (main files).', $count, self::kb($before), self::kb($after)));

        if (! $dry && $count > 0) {
            PublicCache::bust();
        }

        return self::SUCCESS;
    }

    private static function kb(int $bytes): string
    {
        return number_format($bytes / 1024, 0).' KB';
    }
}
