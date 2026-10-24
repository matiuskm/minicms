<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Post> */
class PostFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return [
            'author_id' => User::factory(),
            'category_id' => fn () => Category::where('is_system', true)->value('id'),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'excerpt' => fake()->sentence(),
            'body' => '<p>'.fake()->paragraph().'</p>',
            'status' => 'draft',
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'published_at' => in_array($status, ['published', 'archived'], true) ? now()->subDay() : null,
        ]);
    }

    public function by(User $user): static
    {
        return $this->state(fn () => ['author_id' => $user->id]);
    }
}
