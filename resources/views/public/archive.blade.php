<x-public-layout :author="$author" :title="__('Archive')">
    <h1 class="text-3xl font-bold mb-8">{{ __('Archive') }}</h1>

    @forelse ($postsByYear as $year => $posts)
        <section class="mb-8">
            <h2 class="text-xl font-bold mb-3">{{ $year }}</h2>
            {{-- A real list, so screen readers announce "list, N items". --}}
            <ul class="space-y-2">
                @foreach ($posts as $post)
                    <li class="flex items-baseline justify-between gap-4">
                        <a href="{{ route('blog.post', [$author, $post->slug]) }}"
                           class="underline decoration-theme hover:decoration-current">
                            {{ $post->title }}
                        </a>
                        <time class="text-sm text-theme-muted whitespace-nowrap"
                              datetime="{{ $post->published_at->toDateString() }}">
                            {{ $post->published_at->format('M j') }}
                        </time>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <p class="text-theme-muted">{{ __('No posts yet.') }}</p>
    @endforelse
</x-public-layout>
