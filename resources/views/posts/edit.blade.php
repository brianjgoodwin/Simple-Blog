<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Edit Post') }}
            </h2>
            <span class="text-sm px-2 py-1 rounded {{ $post->isPublished() ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                {{ $post->isPublished() ? __('Published') : __('Draft') }}
            </span>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-4 bg-green-100 text-green-800 rounded-lg">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('posts.update', $post) }}" class="space-y-6">
                    @csrf
                    @method('PUT')
                    @include('posts._fields', ['post' => $post])

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <a href="{{ route('dashboard') }}" class="text-gray-600 hover:underline">
                            {{ __('Back') }}
                        </a>
                    </div>
                </form>
            </div>

            {{-- Delete: its own form so it isn't nested inside the edit form. --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('posts.destroy', $post) }}"
                      onsubmit="return confirm('Delete this post permanently?');">
                    @csrf
                    @method('DELETE')
                    <x-danger-button>{{ __('Delete Post') }}</x-danger-button>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
