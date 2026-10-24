<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $counts = Post::query()->visibleTo($user)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return Inertia::render('dashboard', ['counts' => $counts]);
    }
}
