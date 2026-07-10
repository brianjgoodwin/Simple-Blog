<x-app-layout>
    <x-slot name="title">{{ __('Edit :page Page', ['page' => ucfirst($page->slug)]) }}</x-slot>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Edit :page Page', ['page' => ucfirst($page->slug)]) }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-4 bg-green-100 text-green-800 rounded-lg">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('pages.update', $page->slug) }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="body" :value="__('Content (Markdown)')" />
                        <textarea id="body" name="body" rows="18"
                                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm font-mono text-sm"
                        >{{ old('body', $page->body) }}</textarea>
                        <x-input-error :messages="$errors->get('body')" class="mt-2" />
                        <p class="mt-1 text-sm text-gray-500">
                            {{ __('Markdown is supported. Raw HTML is not rendered.') }}
                        </p>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <a href="{{ route('dashboard') }}" class="text-gray-600 hover:underline">
                            {{ __('Back') }}
                        </a>
                    </div>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
