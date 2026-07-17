<x-public-layout :author="$author" :homepage="true" :description="$author->description">
    {{-- h-feed / h-entry: the river is machine-readable to IndieWeb readers and
         archivers using classes on markup we already render. --}}
    <div class="h-feed">
    @forelse ($posts as $post)
        <article class="mb-12 pb-12 border-b border-theme last:border-0 h-entry">
            {{-- Persistent underlines: links colored like the surrounding
                 text need a non-hover cue, or touch users get none at all. --}}
            <h2 class="text-2xl font-bold">
                <a href="{{ route('blog.post', [$author, $post->slug]) }}" class="underline decoration-theme hover:decoration-current p-name u-url">
                    {{ $post->title }}
                </a>
            </h2>

            <p class="text-sm text-theme-muted mt-1 mb-6">
                <a href="{{ route('blog.post', [$author, $post->slug]) }}" class="underline decoration-theme hover:decoration-current">
                    <time class="dt-published" datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->format('F j, Y') }}</time>
                </a>
            </p>

            {{-- Full body, served from the cached render (see Post::renderBodyHtml):
                 raw HTML stripped and links sanitized when it was stored, so the
                 HtmlString is safe to emit unescaped. --}}
            <div class="prose e-content">
                {{ $post->body_html }}
            </div>
        </article>
    @empty
        <p class="text-theme-muted">{{ __('No posts yet.') }}</p>
    @endforelse
    </div>

    @if ($posts->hasPages())
        <div class="mt-8">
            {{ $posts->links() }}
        </div>
    @endif
</x-public-layout>
