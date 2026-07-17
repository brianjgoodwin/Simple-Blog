<x-public-layout :author="$author" :title="__('Search')">
    <h1 class="text-3xl font-bold mb-8">{{ __('Search') }}</h1>

    @include('public.partials.search', ['author' => $author, 'term' => $term])

    @if ($results === null)
        <p class="text-theme-muted">{{ __('Type a word or phrase to search this blog.') }}</p>
    @elseif ($results->isEmpty())
        <p class="text-theme-muted">{{ __('No posts match “:term”.', ['term' => $term]) }}</p>
    @else
        <p class="text-sm text-theme-muted mb-6">
            {{ trans_choice('{1} :count result|[2,*] :count results', $results->total(), ['count' => $results->total()]) }}
        </p>

        <ul class="space-y-8">
            @foreach ($results as $post)
                <li>
                    <a href="{{ route('blog.post', [$author, $post->slug]) }}"
                       class="text-xl font-bold underline decoration-theme hover:decoration-current">
                        {{ $post->title }}
                    </a>
                    <p class="text-sm text-theme-muted mt-1">
                        <time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->format('F j, Y') }}</time>
                    </p>
                    <p class="text-theme-muted mt-2">{{ $post->excerpt(140) }}</p>
                </li>
            @endforeach
        </ul>

        @if ($results->hasPages())
            <div class="mt-8">
                {{ $results->withQueryString()->links() }}
            </div>
        @endif
    @endif
</x-public-layout>
