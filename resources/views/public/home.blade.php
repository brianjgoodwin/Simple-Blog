<x-public-layout :author="$author">
    @forelse ($posts as $post)
        <article class="mb-12 pb-12 border-b last:border-0">
            <h2 class="text-2xl font-bold">
                <a href="{{ route('blog.post', [$author, $post->slug]) }}" class="hover:underline">
                    {{ $post->title }}
                </a>
            </h2>

            <p class="text-sm text-gray-500 mt-1 mb-6">
                <a href="{{ route('blog.post', [$author, $post->slug]) }}" class="hover:underline">
                    {{ $post->published_at->format('F j, Y') }}
                </a>
            </p>

            {{-- Full body, rendered safely (raw HTML stripped, links sanitized). --}}
            <div class="prose max-w-none">
                {{ \App\Support\Markdown::toHtml($post->body) }}
            </div>
        </article>
    @empty
        <p class="text-gray-500">{{ __('No posts yet.') }}</p>
    @endforelse

    @if ($posts->hasPages())
        <div class="mt-8">
            {{ $posts->links() }}
        </div>
    @endif
</x-public-layout>
