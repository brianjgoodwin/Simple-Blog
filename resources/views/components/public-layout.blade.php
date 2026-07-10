{{--
    Public reader-facing layout. Deliberately minimal and separate from the
    authenticated dashboard layout.
--}}
@props(['author', 'title' => null, 'homepage' => false])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' — '.$author->name : $author->name }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-white text-gray-900 antialiased">
    <div class="max-w-xl mx-auto px-4 py-12">

        <header class="mb-10 pb-6 border-b">
            {{-- The blog name is the <h1> on the home page only; on post
                 and About/Links pages the content provides its own h1, and
                 two would compete. Same link either way. --}}
            @if ($homepage)
                <h1 class="text-2xl font-bold">
                    <a href="{{ route('blog.home', $author) }}" class="hover:underline">
                        {{ $author->name }}
                    </a>
                </h1>
            @else
                <p class="text-2xl font-bold">
                    <a href="{{ route('blog.home', $author) }}" class="hover:underline">
                        {{ $author->name }}
                    </a>
                </p>
            @endif
            {{-- Persistent underlines: these links match the nav text color,
                 so hover-only underlines left no at-rest cue (and touch users
                 never see hover states). --}}
            <nav class="mt-3 flex gap-4 text-sm text-gray-600">
                <a href="{{ route('blog.home', $author) }}" class="underline decoration-gray-300 hover:decoration-current">{{ __('Posts') }}</a>
                <a href="{{ route('blog.about', $author) }}" class="underline decoration-gray-300 hover:decoration-current">{{ __('About') }}</a>
                <a href="{{ route('blog.links', $author) }}" class="underline decoration-gray-300 hover:decoration-current">{{ __('Links') }}</a>
            </nav>
        </header>

        <main>
            {{ $slot }}
        </main>

        <x-site-footer />

    </div>
</body>
</html>
