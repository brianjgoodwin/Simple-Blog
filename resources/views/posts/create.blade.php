<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('New Post') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('posts.store') }}" class="space-y-6">
                    @csrf
                    @include('posts._fields', ['post' => null])

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save Draft') }}</x-primary-button>
                        <a href="{{ route('dashboard') }}" class="text-gray-600 hover:underline">
                            {{ __('Cancel') }}
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
