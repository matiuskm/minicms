<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Media;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Optional sample content to see the public site: `php artisan db:seed --class=DemoContentSeeder`.
 * Posts are attributed to the first admin. Trash them from Posts when done.
 */
class DemoContentSeeder extends Seeder
{
    public function run(): void
    {
        $author = User::where('role', 'admin')->orderBy('id')->firstOrFail();

        if (Post::where('slug', 'like', 'demo-%')->exists()) {
            $this->command?->warn('Demo content already exists.');

            return;
        }

        $categories = collect(['Design', 'Product', 'Stories'])->mapWithKeys(fn ($n) => [$n => Category::firstOrCreate(['slug' => Str::slug($n)], ['name' => $n])]);
        $tags = collect(['Tips', 'Guide', 'News'])->map(fn ($n) => Tag::firstOrCreate(['slug' => Str::slug($n)], ['name' => $n]));

        $titles = [
            'Enhancing team collaboration with SaaS products: a game-changer for modern workflows',
            'Creating an intuitive user interface for your SaaS product',
            'Tips for designing clear and user-friendly navigation menus',
            'Exploring how to establish a visual hierarchy that guides users',
            'How to use colour to influence user emotions and actions',
            'Our people make the difference',
            'Why small teams ship faster',
            'A field guide to writing release notes people read',
            'What we learned running a three-day launch sprint',
            'Designing for the slowest device in the room',
        ];
        $tones = [[143, 63, 31], [214, 150, 96], [96, 70, 56], [200, 120, 70], [60, 80, 110]];

        foreach ($titles as $i => $title) {
            $media = $this->image($author, $tones[$i % count($tones)], $title);
            $post = Post::create([
                'author_id' => $author->id,
                'category_id' => $categories->values()[$i % 3]->id,
                'featured_media_id' => $media->id,
                'title' => Str::ucfirst($title),
                'slug' => 'demo-'.Str::slug(Str::limit($title, 50, '')),
                'excerpt' => "We're an extension of your customer service team, and all of our resources are free. Chat to our friendly team 24/7 when you need help.",
                'body' => '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Quis ipsum amet turpis nibh, eu ullamcorper donec.</p>'
                    .'<h2>A closer look</h2><p>Ultrices porttitor nullam volutpat et in. Vitae quis tortor a odio tincidunt, <strong>with a bold point</strong> and a <a href="https://example.com">link</a>.</p>'
                    .'<blockquote><p>Good design is as little design as possible.</p></blockquote><ul><li>First point</li><li>Second point</li></ul>'
                    .'<pre><code>echo "hello";</code></pre>',
                'status' => 'published',
                'published_at' => now()->subDays($i + 1),
            ]);
            $post->tags()->sync([$tags[$i % 3]->id]);
        }

        $about = Page::create(['updated_by' => $author->id, 'title' => 'About', 'slug' => 'about', 'status' => 'published',
            'body' => '<p>This is a sample page. Edit or delete it from Pages.</p>']);

        if (! MenuItem::count()) {
            MenuItem::insert([
                ['location' => 'header', 'label' => 'About', 'type' => 'page', 'page_id' => $about->id, 'category_id' => null, 'url' => null, 'sort_order' => 0],
                ['location' => 'header', 'label' => 'Blog', 'type' => 'url', 'page_id' => null, 'category_id' => null, 'url' => '/blog', 'sort_order' => 1],
            ]);
        }
    }

    /** A warm gradient JPEG stored like a real upload, with the same variants. */
    private function image(User $author, array $rgb, string $alt): Media
    {
        $uuid = (string) Str::uuid();
        $path = 'uploads/'.now()->format('Y/m')."/{$uuid}.jpg";
        $disk = Storage::disk('public');

        $make = function (int $w, int $h) use ($rgb) {
            $im = imagecreatetruecolor($w, $h);
            for ($y = 0; $y < $h; $y++) {
                $t = $y / $h;
                $c = imagecolorallocate($im, (int) ($rgb[0] * (1 - $t * 0.55) + 40 * $t), (int) ($rgb[1] * (1 - $t * 0.55) + 20 * $t), (int) ($rgb[2] * (1 - $t * 0.55) + 15 * $t));
                imageline($im, 0, $y, $w, $y, $c);
            }
            ob_start();
            imagejpeg($im, null, 82);

            return ob_get_clean();
        };

        $disk->put($path, $make(1600, 900));
        foreach (['thumb' => [150, 150], 'medium' => [400, 225], 'large' => [1200, 675]] as $name => [$w, $h]) {
            $disk->put(Media::variantPath($path, $name), $make($w, $h));
        }

        return Media::create(['uuid' => $uuid, 'uploader_id' => $author->id, 'path' => $path, 'original_name' => 'demo.jpg', 'mime' => 'image/jpeg',
            'size' => strlen($disk->get($path)), 'width' => 1600, 'height' => 900, 'alt_text' => Str::limit($alt, 80, '')]);
    }
}
