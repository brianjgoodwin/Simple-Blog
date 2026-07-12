<x-public-layout :author="$author" :title="$post->title">
    <article>
        <h1 class="text-3xl font-bold">{{ $post->title }}</h1>
        <p class="text-sm text-theme-muted mt-2 mb-8">
            <time datetime="{{ $post->published_at->toDateString() }}">
                {{ $post->published_at->format('F j, Y') }}
            </time>
        </p>

        {{-- Rendered through App\Support\Markdown: raw HTML stripped, unsafe
             links neutralized. Safe to output unescaped. --}}
        <div class="prose">
            {{ \App\Support\Markdown::toHtml($post->body) }}
        </div>
    </article>
</x-public-layout>
