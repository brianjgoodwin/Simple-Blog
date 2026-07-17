{{--
    Public reader-facing layout. Deliberately minimal and separate from the
    authenticated dashboard layout.
--}}
@props(['author', 'title' => null, 'homepage' => false, 'description' => null, 'ogType' => 'website', 'published' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' — '.$author->name : $author->name }}</title>
    {{-- Feed autodiscovery: readers and browser extensions find the Atom feed
         from any of the author's pages. --}}
    <link rel="alternate" type="application/atom+xml"
          title="{{ $author->name }}" href="{{ route('blog.feed', $author) }}">

    {{-- Social / search preview: a pasted post link unfurls with its title,
         author, and date. The description is a plain-text excerpt (post pages
         only — the home and About/Links pages have no natural summary). --}}
    <meta property="og:title" content="{{ $title ?? $author->name }}">
    <meta property="og:type" content="{{ $ogType }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:site_name" content="{{ $author->name }}">
    <meta name="twitter:card" content="summary">
    @if ($description)
        <meta name="description" content="{{ $description }}">
        <meta property="og:description" content="{{ $description }}">
    @endif
    @if ($ogType === 'article' && $published)
        <meta property="article:published_time" content="{{ $published->toAtomString() }}">
        <meta property="article:author" content="{{ $author->name }}">
    @endif
    @vite(['resources/css/app.css'])
</head>
{{-- The theme is ONLY this data-theme attribute plus the font class — the
     CSS variables in app.css say what each theme means. Post HTML is
     byte-identical across themes (a locked Phase 10 decision). The font
     classes are written out literally because Tailwind's compile-time scan
     can't see interpolated class names. --}}
<body data-theme="{{ $author->theme->value }}"
      class="bg-theme text-gray-900 antialiased {{ $author->font === \App\Enums\BlogFont::Serif ? 'font-serif' : 'font-sans' }}">
    <div class="max-w-xl mx-auto px-4 py-12">

        <header class="mb-10 pb-6 border-b border-theme">
            {{-- The blog name is the <h1> on the home page only; on post
                 and About/Links pages the content provides its own h1, and
                 two would compete. Same link either way. --}}
            {{-- h-card on the author-name link makes the blog's owner
                 machine-readable to the IndieWeb ecosystem; the link text is
                 the implied p-name. --}}
            @if ($homepage)
                <h1 class="text-2xl font-bold">
                    <a href="{{ route('blog.home', $author) }}" class="text-accent hover:underline h-card">
                        {{ $author->name }}
                    </a>
                </h1>
            @else
                <p class="text-2xl font-bold">
                    <a href="{{ route('blog.home', $author) }}" class="text-accent hover:underline h-card">
                        {{ $author->name }}
                    </a>
                </p>
            @endif
            {{-- The tagline shows under the blog name on the home page only;
                 it is the p-summary of the blog's h-card and the readable twin
                 of the Atom <subtitle> / meta description. --}}
            @if ($homepage && $author->description)
                <p class="mt-2 text-theme-muted p-note">{{ $author->description }}</p>
            @endif
            {{-- Persistent underlines: these links match the nav text color,
                 so hover-only underlines left no at-rest cue (and touch users
                 never see hover states). --}}
            <nav class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-600">
                <a href="{{ route('blog.home', $author) }}" class="underline decoration-theme hover:decoration-current">{{ __('Posts') }}</a>
                <a href="{{ route('blog.archive', $author) }}" class="underline decoration-theme hover:decoration-current">{{ __('Archive') }}</a>
                <a href="{{ route('blog.about', $author) }}" class="underline decoration-theme hover:decoration-current">{{ __('About') }}</a>
                <a href="{{ route('blog.links', $author) }}" class="underline decoration-theme hover:decoration-current">{{ __('Links') }}</a>
                <a href="{{ route('blog.feed', $author) }}" class="underline decoration-theme hover:decoration-current">{{ __('Feed') }}</a>
            </nav>
        </header>

        <main>
            {{ $slot }}
        </main>

        <x-site-footer />

    </div>
</body>
</html>
