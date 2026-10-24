<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Media;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        $user = $request->user();
        $kind = $request->query('kind');
        $q = trim((string) $request->query('q', ''));

        $items = Media::query()->visibleTo($user)
            ->when($user->isAdmin(), fn ($query) => $query->with('uploader:id,name'))
            ->when($kind === 'image', fn ($query) => $query->where('mime', 'like', 'image/%'))
            ->when($kind === 'pdf', fn ($query) => $query->where('mime', 'application/pdf'))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('original_name', 'like', "%{$q}%")->orWhere('alt_text', 'like', "%{$q}%")))
            ->latest('id')->paginate(24)->withQueryString()
            ->through(fn (Media $m) => $m->toListArray($user->isAdmin()));

        // The editor's media picker fetches the same listing as JSON.
        if ($request->wantsJson()) {
            return response()->json($items);
        }

        return Inertia::render('media/index', [
            'items' => $items,
            'filters' => ['q' => $q, 'kind' => $kind],
            'limits' => ['image_mb' => config('cms.uploads.image_max_kb') / 1024, 'pdf_mb' => (int) Setting::get('pdf_max_mb', 10)],
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file'],
            'alt_text' => ['required', 'string', 'max:255'], // alt text for images, title for PDFs (BR-23)
        ]);

        $file = $request->file('file');
        // Content-sniffed by finfo, never the client-supplied type or extension (BR-20).
        $mime = $file->getMimeType();
        $imageExt = config('cms.uploads.image_mimes')[$mime] ?? null;
        $isPdf = $mime === 'application/pdf';

        if (! $imageExt && ! $isPdf) {
            throw ValidationException::withMessages(['file' => 'Only JPG, PNG, GIF, WebP images and PDF files are allowed.']);
        }

        $maxKb = $isPdf ? (int) Setting::get('pdf_max_mb', 10) * 1024 : config('cms.uploads.image_max_kb');
        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages(['file' => 'The file may not be larger than '.($maxKb / 1024).' MB.']);
        }

        if ($isPdf && file_get_contents($file->getRealPath(), false, null, 0, 5) !== '%PDF-') {
            throw ValidationException::withMessages(['file' => 'The file is not a valid PDF.']);
        }

        $uuid = (string) Str::uuid();
        $dir = ($isPdf ? 'files/' : 'uploads/').now()->format('Y/m');
        $path = "{$dir}/{$uuid}.".($isPdf ? 'pdf' : $imageExt);
        $width = $height = null;

        if ($isPdf) {
            Storage::disk('local')->putFileAs($dir, $file, basename($path));
            $size = $file->getSize();
        } else {
            [$width, $height, $size] = $this->storeImage($file->getRealPath(), $path, $imageExt);
        }

        $media = Media::create([
            'uuid' => $uuid, 'uploader_id' => $request->user()->id, 'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => $mime, 'size' => $size, 'width' => $width, 'height' => $height,
            'alt_text' => $request->string('alt_text'),
        ]);
        AuditLog::record('media.uploaded', $media, ['mime' => $mime]);

        return $request->wantsJson()
            ? response()->json($media->toListArray(), 201)
            : back()->with('status', 'File uploaded.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $media = Media::query()->visibleTo($request->user())->findOrFail($id);
        $media->update($request->validate(['alt_text' => ['required', 'string', 'max:255']]));

        return back();
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $media = Media::query()->visibleTo($request->user())->findOrFail($id);

        if ($media->isInUse()) {
            throw ValidationException::withMessages(['media' => 'This file is used by a post or page. Remove it there first.']);
        }

        $media->deleteFiles();
        $media->delete();
        AuditLog::record('media.deleted', $media, ['name' => $media->original_name]);

        return back();
    }

    /** Public PDF download: always an attachment, sandboxed, never sniffed. */
    public function file(string $uuid): StreamedResponse
    {
        $media = Media::where('uuid', $uuid)->where('mime', 'application/pdf')->firstOrFail();

        return Storage::disk('local')->response($media->path, $media->original_name, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
        ], 'attachment');
    }

    /**
     * Decode + re-encode (strips EXIF and any trailing payload) and write variants.
     *
     * @return array{int, int, int} width, height, stored bytes
     */
    private function storeImage(string $source, string $path, string $ext): array
    {
        $manager = new ImageManager(new Driver);
        $disk = Storage::disk('public');

        // ponytail: GD keeps only the first frame of animated GIFs; use Imagick if animation matters.
        $encode = fn ($image) => $image->encodeUsingFileExtension($ext, quality: 85);

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
