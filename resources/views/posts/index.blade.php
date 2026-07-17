<x-app-layout>
    <x-slot name="title">{{ __('Dashboard') }}</x-slot>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Dashboard') }}
            </h1>
            <a href="{{ route('posts.create') }}"
               class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                {{ __('New Post') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div role="status" class="p-4 bg-green-100 text-green-800 rounded-lg">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Drafts --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-medium mb-4">{{ __('Drafts') }}</h3>

                    {{-- A real list, so screen readers get "list, N items"
                         and per-item navigation. --}}
                    @if ($drafts->isEmpty())
                        <p class="text-gray-500">{{ __('No drafts yet.') }}</p>
                    @else
                        <ul>
                            @foreach ($drafts as $post)
                                <li class="flex items-center justify-between py-2 border-b last:border-0">
                                    <a href="{{ route('posts.edit', $post) }}" class="text-gray-800 hover:underline">
                                        {{ $post->title }}
                                    </a>
                                    <span class="text-sm text-gray-500">
                                        {{ __('edited') }}
                                        <time datetime="{{ $post->updated_at->toIso8601String() }}">{{ $post->updated_at->diffForHumans() }}</time>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            {{-- Published --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-medium mb-4">{{ __('Published') }}</h3>

                    @if ($published->isEmpty())
                        <p class="text-gray-500">{{ __('Nothing published yet.') }}</p>
                    @else
                        <ul>
                            @foreach ($published as $post)
                                <li class="flex items-center justify-between py-2 border-b last:border-0">
                                    <a href="{{ route('posts.edit', $post) }}" class="text-gray-800 hover:underline">
                                        {{ $post->title }}
                                    </a>
                                    <span class="text-sm text-gray-500">
                                        {{ __('published') }}
                                        <time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->format('M j, Y') }}</time>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            {{-- Pages --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-medium mb-4">{{ __('Pages') }}</h3>
                    <div class="flex gap-4">
                        <a href="{{ route('pages.edit', 'about') }}" class="text-gray-800 hover:underline">
                            {{ __('Edit About') }}
                        </a>
                        <a href="{{ route('pages.edit', 'links') }}" class="text-gray-800 hover:underline">
                            {{ __('Edit Links') }}
                        </a>
                    </div>
                </div>
            </div>

            {{-- Appearance --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-medium mb-4">{{ __('Appearance') }}</h3>
                    <p class="text-gray-500 mb-4">
                        {{ __('Your public blog — a description, a color theme, and a font.') }}
                    </p>
                    <a href="{{ route('appearance.edit') }}" class="text-gray-800 underline decoration-gray-300 hover:decoration-current">
                        {{ __('Appearance settings') }}
                    </a>
                </div>
            </div>

            {{-- Export --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-medium mb-4">{{ __('Export') }}</h3>
                    <p class="text-gray-500 mb-4">
                        {{ __('Everything you\'ve written — posts, drafts, and pages — as plain Markdown files. Your words are yours.') }}
                    </p>
                    <a href="{{ route('export') }}" class="text-gray-800 underline decoration-gray-300 hover:decoration-current">
                        {{ __('Download export (.zip)') }}
                    </a>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
