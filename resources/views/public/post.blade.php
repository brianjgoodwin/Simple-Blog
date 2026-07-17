<x-public-layout :author="$author" :title="$post->title">
    <article>
        <h1 class="text-3xl font-bold">{{ $post->title }}</h1>
        <p class="text-sm text-theme-muted mt-2 mb-8">
            <time datetime="{{ $post->published_at->toDateString() }}">
                {{ $post->published_at->format('F j, Y') }}
            </time>
        </p>

        {{-- Served from the cached render (see Post::renderBodyHtml): raw HTML
             stripped and unsafe links neutralized when it was stored, so the
             HtmlString is safe to output unescaped. --}}
        <div class="prose">
            {{ $post->body_html }}
        </div>
    </article>
</x-public-layout>
