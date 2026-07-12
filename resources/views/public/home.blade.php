<x-public-layout :author="$author" :homepage="true">
    @forelse ($posts as $post)
        <article class="mb-12 pb-12 border-b border-theme last:border-0">
            {{-- Persistent underlines: links colored like the surrounding
                 text need a non-hover cue, or touch users get none at all. --}}
            <h2 class="text-2xl font-bold">
                <a href="{{ route('blog.post', [$author, $post->slug]) }}" class="underline decoration-theme hover:decoration-current">
                    {{ $post->title }}
                </a>
            </h2>

            <p class="text-sm text-theme-muted mt-1 mb-6">
                <a href="{{ route('blog.post', [$author, $post->slug]) }}" class="underline decoration-theme hover:decoration-current">
                    <time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->format('F j, Y') }}</time>
                </a>
            </p>

            {{-- Full body, rendered safely (raw HTML stripped, links sanitized). --}}
            <div class="prose">
                {{ \App\Support\Markdown::toHtml($post->body) }}
            </div>
        </article>
    @empty
        <p class="text-theme-muted">{{ __('No posts yet.') }}</p>
    @endforelse

    @if ($posts->hasPages())
        <div class="mt-8">
            {{ $posts->links() }}
        </div>
    @endif
</x-public-layout>
