<x-public-layout :author="$author" :title="$post->title">
    <article>
        <h1 class="text-3xl font-bold">{{ $post->title }}</h1>
        <p class="text-sm text-gray-500 mt-2 mb-8">
            {{ $post->published_at->format('F j, Y') }}
        </p>

        {{-- Rendered through App\Support\Markdown: raw HTML stripped, unsafe
             links neutralized. Safe to output unescaped. --}}
        <div class="prose">
            {{ \App\Support\Markdown::toHtml($post->body) }}
        </div>
    </article>
</x-public-layout>
