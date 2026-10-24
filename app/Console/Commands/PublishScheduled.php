<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Post;
use Illuminate\Console\Command;

class PublishScheduled extends Command
{
    protected $signature = 'cms:publish-scheduled';

    protected $description = 'Publish scheduled posts whose time has come (BR-11)';

    public function handle(): int
    {
        $due = Post::where('status', 'scheduled')->where('published_at', '<=', now())->get();

        foreach ($due as $post) {
            $post->moveTo('published');
            $post->save();
            AuditLog::record('post.published', $post, ['scheduled' => true]);
        }

        $this->info("Published {$due->count()} post(s).");

        return self::SUCCESS;
    }
}
