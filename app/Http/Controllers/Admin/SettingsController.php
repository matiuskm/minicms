<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Media;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(): Response
    {
        $s = Setting::everything();
        $media = Media::whereIn('id', array_filter([$s['logo_media_id'], $s['favicon_media_id']]))->get()->keyBy('id');

        return Inertia::render('admin/settings', [
            'settings' => [...$s, 'site_name' => $s['site_name'] ?? ''],
            'logo' => ($m = $media[$s['logo_media_id']] ?? null)?->toListArray(),
            'favicon' => ($m = $media[$s['favicon_media_id']] ?? null)?->toListArray(),
            'defaultName' => config('app.name'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $image = Rule::exists('media', 'id')->where(fn ($q) => $q->where('mime', 'like', 'image/%'));

        $data = $request->validate([
            'site_name' => ['nullable', 'string', 'max:60'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'logo_media_id' => ['nullable', 'integer', $image],
            'favicon_media_id' => ['nullable', 'integer', $image],
            'content_language' => ['required', 'string', 'regex:/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/'],
            'posts_per_page' => ['required', 'integer', 'between:5,50'],
            'cta_label' => ['nullable', 'string', 'max:30', 'required_with:cta_url'],
            'cta_url' => ['nullable', 'string', 'max:255', 'regex:#^(https?://\S+|/(?!/)\S*)$#', 'required_with:cta_label'],
            'pdf_max_mb' => ['required', 'integer', 'between:1,20'],
            'mail_from_name' => ['nullable', 'string', 'max:60'],
            'mail_from_address' => ['nullable', 'email', 'max:190'],
            'maintenance_mode' => ['required', 'boolean'],
            'analytics_enabled' => ['required', 'boolean'],
            'analytics_retention_days' => ['required', 'integer', 'between:30,365'],
        ]);

        $before = Setting::everything();
        Setting::put([...$data, 'site_name' => $data['site_name'] ?: null, 'cta_label' => $data['cta_label'] ?? '', 'cta_url' => $data['cta_url'] ?? '',
            'tagline' => $data['tagline'] ?? '', 'mail_from_name' => $data['mail_from_name'] ?? '', 'mail_from_address' => $data['mail_from_address'] ?? '']);

        AuditLog::record('settings.updated', null, ['changed' => array_keys(array_diff_assoc(Setting::everything(), $before))]);
        if ($before['maintenance_mode'] !== $data['maintenance_mode']) {
            AuditLog::record($data['maintenance_mode'] ? 'maintenance.enabled' : 'maintenance.disabled');
        }

        return back()->with('status', 'Settings saved.');
    }
}
