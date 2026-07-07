<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Dashboard') }}
            </h2>
            <a href="{{ route('posts.create') }}"
               class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                {{ __('New Post') }}
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-4 bg-green-100 text-green-800 rounded-lg">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Drafts --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-medium mb-4">{{ __('Drafts') }}</h3>

                    @forelse ($drafts as $post)
                        <div class="flex items-center justify-between py-2 border-b last:border-0">
                            <a href="{{ route('posts.edit', $post) }}" class="text-gray-800 hover:underline">
                                {{ $post->title }}
                            </a>
                            <span class="text-sm text-gray-500">
                                {{ __('edited') }} {{ $post->updated_at->diffForHumans() }}
                            </span>
                        </div>
                    @empty
                        <p class="text-gray-500">{{ __('No drafts yet.') }}</p>
                    @endforelse
                </div>
            </div>

            {{-- Published --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-medium mb-4">{{ __('Published') }}</h3>

                    @forelse ($published as $post)
                        <div class="flex items-center justify-between py-2 border-b last:border-0">
                            <a href="{{ route('posts.edit', $post) }}" class="text-gray-800 hover:underline">
                                {{ $post->title }}
                            </a>
                            <span class="text-sm text-gray-500">
                                {{ __('published') }} {{ $post->published_at->format('M j, Y') }}
                            </span>
                        </div>
                    @empty
                        <p class="text-gray-500">{{ __('Nothing published yet.') }}</p>
                    @endforelse
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
