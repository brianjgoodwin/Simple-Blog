{{--
    Public reader-facing layout. Deliberately minimal and separate from the
    authenticated dashboard layout.
--}}
@props(['author', 'title' => null])
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
            <a href="{{ route('blog.home', $author) }}" class="text-2xl font-bold hover:underline">
                {{ $author->name }}
            </a>
            <nav class="mt-3 flex gap-4 text-sm text-gray-600">
                <a href="{{ route('blog.home', $author) }}" class="hover:underline">{{ __('Posts') }}</a>
                <a href="{{ route('blog.about', $author) }}" class="hover:underline">{{ __('About') }}</a>
                <a href="{{ route('blog.links', $author) }}" class="hover:underline">{{ __('Links') }}</a>
            </nav>
        </header>

        <main>
            {{ $slot }}
        </main>

    </div>
</body>
</html>
