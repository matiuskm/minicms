<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Palette;
use App\Models\Setting;
use App\Support\PublicCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Global look of the public site (F-08): palettes, fonts, base size. Admin only (route group). */
class AppearanceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/appearance', [
            'palettes' => Palette::orderByDesc('is_preset')->orderBy('id')->get(['id', 'name', 'is_preset', 'is_active', 'tokens']),
            'fonts' => collect(config('cms.fonts'))->map(fn ($f, $key) => ['key' => $key, 'label' => $f['label'], 'stack' => $f['stack']])->values(),
            'typography' => [
                'heading' => Setting::get('font_heading'), 'body' => Setting::get('font_body'), 'size' => Setting::get('font_size'),
            ],
            'maxCustom' => config('cms.max_custom_palettes'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (Palette::where('is_preset', false)->count() >= config('cms.max_custom_palettes')) {
            throw ValidationException::withMessages(['name' => 'You have reached the limit of custom palettes. Delete one first.']);
        }

        $data = $this->validated($request);
        $palette = Palette::create([...$data, 'created_by' => $request->user()->id]);
        AuditLog::record('palette.created', $palette);

        return back()->with('status', 'Palette created.');
    }

    public function update(Request $request, Palette $palette): RedirectResponse
    {
        abort_if($palette->is_preset, 422, 'Built-in palettes cannot be edited. Duplicate it first.');

        $palette->update($this->validated($request, $palette));
        AuditLog::record('palette.updated', $palette);

        return back()->with('status', 'Palette saved.');
    }

    public function destroy(Palette $palette): RedirectResponse
    {
        abort_if($palette->is_preset, 422, 'Built-in palettes cannot be deleted.');

        if ($palette->is_active) {
            throw ValidationException::withMessages(['palette' => 'This palette is active. Activate another one before deleting it.']);
        }

        AuditLog::record('palette.deleted', $palette, ['name' => $palette->name]);
        $palette->delete();

        return back();
    }

    public function activate(Palette $palette): RedirectResponse
    {
        $this->makeActive($palette);
        AuditLog::record('palette.activated', $palette);

        return back()->with('status', "“{$palette->name}” is now live.");
    }

    public function duplicate(Request $request, Palette $palette): RedirectResponse
    {
        if (Palette::where('is_preset', false)->count() >= config('cms.max_custom_palettes')) {
            throw ValidationException::withMessages(['palette' => 'You have reached the limit of custom palettes. Delete one first.']);
        }

        $name = "{$palette->name} copy";
        for ($i = 2; Palette::where('name', $name)->exists(); $i++) {
            $name = "{$palette->name} copy {$i}";
        }

        $copy = Palette::create(['name' => mb_substr($name, 0, 60), 'tokens' => $palette->tokens, 'created_by' => $request->user()->id]);
        AuditLog::record('palette.created', $copy, ['from' => $palette->name]);

        return back()->with('status', 'Duplicated as a custom palette.');
    }

    public function typography(Request $request): RedirectResponse
    {
        $fonts = array_keys(config('cms.fonts'));
        $data = $request->validate([
            'heading' => ['required', Rule::in($fonts)],
            'body' => ['required', Rule::in($fonts)],
            'size' => ['required', Rule::in(array_keys(config('cms.font_sizes')))],
        ]);

        Setting::put(['font_heading' => $data['heading'], 'font_body' => $data['body'], 'font_size' => $data['size']]);
        AuditLog::record('typography.updated', null, $data);

        return back()->with('status', 'Typography saved.');
    }

    /** Back to the shipped look: Light Classic and the default fonts. */
    public function reset(): RedirectResponse
    {
        $this->makeActive(Palette::where('name', 'Light Classic')->firstOrFail());
        Setting::put(['font_heading' => 'system-sans', 'font_body' => 'system-sans', 'font_size' => 'm']);
        AuditLog::record('appearance.reset');

        return back()->with('status', 'Appearance reset to the defaults.');
    }

    private function makeActive(Palette $palette): void
    {
        DB::transaction(function () use ($palette) {
            Palette::where('is_active', true)->update(['is_active' => false]);
            $palette->forceFill(['is_active' => true])->save();
        });
        PublicCache::bust(); // the bulk update above skips model events
    }

    /** @return array{name: string, tokens: array<string, string>} */
    private function validated(Request $request, ?Palette $palette = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:60', Rule::unique('palettes', 'name')->ignore($palette?->id)],
            'tokens' => ['required', 'array'],
        ];
        foreach (Palette::TOKENS as $token) {
            $rules["tokens.{$token}"] = ['required', 'string', 'regex:'.Palette::HEX];
        }

        $data = $request->validate($rules);

        // Only the known tokens are kept, lower-cased; nothing else from the request is stored.
        return ['name' => trim($data['name']), 'tokens' => collect(Palette::TOKENS)->mapWithKeys(fn ($t) => [$t => strtolower($data['tokens'][$t])])->all()];
    }
}
