<x-app-layout>
    <x-slot name="title">{{ __('New Post') }}</x-slot>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('New Post') }}
        </h1>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('posts.store') }}" class="space-y-6">
                    @csrf
                    @include('posts._fields', ['post' => null])

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save Draft') }}</x-primary-button>
                        <button type="submit" name="action" value="publish"
                                onclick="return confirm('Publish now? This makes the post public and locks its URL.');"
                                class="inline-flex items-center px-4 py-2 bg-green-700 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-800 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            {{ __('Publish') }}
                        </button>
                        <a href="{{ route('dashboard') }}" class="text-gray-600 hover:underline">
                            {{ __('Cancel') }}
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
