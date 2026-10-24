<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\IntegrityAlert;
use Illuminate\Support\Facades\Notification;

/**
 * Deface detection (NFR-03, FR-71): every save stores sha256(title + body). Recomputing it for published content
 * reveals rows edited outside the application. Alerts are sent when the set of mismatches changes, not every run.
 */
class IntegrityChecker
{
    /** @return array{checked: int, mismatches: list<array{type: string, id: int, title: string}>} */
    public static function run(): array
    {
        $checked = 0;
        $mismatches = [];

        foreach ([['post', Post::published()], ['page', Page::published()]] as [$type, $query]) {
            $query->select(['id', 'title', 'body', 'content_hash'])->chunkById(200, function ($rows) use ($type, &$checked, &$mismatches) {
                foreach ($rows as $row) {
                    $checked++;
                    if (! hash_equals($row->content_hash, hash('sha256', $row->title."\n".$row->body))) {
                        $mismatches[] = ['type' => $type, 'id' => $row->id, 'title' => mb_substr($row->title, 0, 120)];
                    }
                }
            });
        }

        $signature = hash('sha256', json_encode(array_map(fn ($m) => [$m['type'], $m['id']], $mismatches)));
        $previous = Setting::get('integrity_last');
        $changed = ($previous['signature'] ?? null) !== $signature;

        Setting::put(['integrity_last' => ['at' => now()->toIso8601String(), 'checked' => $checked, 'mismatches' => $mismatches, 'signature' => $signature]]);

        if ($mismatches && $changed) {
            AuditLog::record('integrity.mismatch', null, ['count' => count($mismatches), 'items' => array_slice($mismatches, 0, 20)]);
            Notification::send(User::where('role', 'admin')->where('status', 'active')->get(), new IntegrityAlert($mismatches));
        }

        return ['checked' => $checked, 'mismatches' => $mismatches];
    }

    /** The reviewer accepts the current content: record its hash as the new baseline. */
    public static function reseal(string $type, int $id): void
    {
        $model = $type === 'page' ? Page::findOrFail($id) : Post::findOrFail($id);
        $model->forceFill(['content_hash' => hash('sha256', $model->title."\n".$model->body)])->saveQuietly();
        AuditLog::record('integrity.resealed', $model);
    }
}
