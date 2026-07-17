<x-public-layout :author="$author" :title="$post->title"
                 :description="$post->excerpt()" og-type="article" :published="$post->published_at">
    <article class="h-entry">
        <h1 class="text-3xl font-bold p-name">{{ $post->title }}</h1>
        <p class="text-sm text-theme-muted mt-2 mb-8">
            <time class="dt-published" datetime="{{ $post->published_at->toDateString() }}">
                {{ $post->published_at->format('F j, Y') }}
            </time>
        </p>

        {{-- Served from the cached render (see Post::renderBodyHtml): raw HTML
             stripped and unsafe links neutralized when it was stored, so the
             HtmlString is safe to output unescaped. --}}
        <div class="prose e-content">
            {{ $post->body_html }}
        </div>
    </article>
</x-public-layout>
