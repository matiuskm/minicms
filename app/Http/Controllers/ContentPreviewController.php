<?php

namespace App\Http\Controllers;

use App\Support\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentPreviewController extends Controller
{
    /** Returns exactly what the server would store, so Preview shows the real result. */
    public function __invoke(Request $request, HtmlSanitizer $sanitizer): JsonResponse
    {
        $data = $request->validate(['html' => ['nullable', 'string', 'max:2000000']]);

        return response()->json(['html' => $sanitizer->clean($data['html'] ?? '')]);
    }
}
