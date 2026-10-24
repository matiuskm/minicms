<!DOCTYPE html>
{{-- Public pages (they carry a "seo" prop) use the content language; the admin UI stays English. --}}
<html lang="{{ isset($page['props']['seo']) ? ($page['props']['site']['language'] ?? 'en') : 'en' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        @if (! empty($page['props']['site']['favicon']))
            <link rel="icon" href="{{ $page['props']['site']['favicon'] }}">
        @endif
        @if (! empty($page['props']['seo']['jsonld']))
            {{-- JSON_HEX_* keeps "</script>" and friends from ever closing the tag. --}}
            <script type="application/ld+json">{!! json_encode($page['props']['seo']['jsonld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
        @endif

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
