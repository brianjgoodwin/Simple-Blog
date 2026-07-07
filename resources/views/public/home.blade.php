<x-public-layout :author="$author">
    @forelse ($posts as $post)
        <article class="mb-8">
            <h2 class="text-xl font-semibold">
                <a href="{{ route('blog.post', [$author, $post->slug]) }}" class="hover:underline">
                    {{ $post->title }}
                </a>
            </h2>
            <p class="text-sm text-gray-500 mt-1">
                {{ $post->published_at->format('F j, Y') }}
            </p>
        </article>
    @empty
        <p class="text-gray-500">{{ __('No posts yet.') }}</p>
    @endforelse
</x-public-layout>
