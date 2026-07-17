<x-app-layout>
    <x-slot name="title">{{ __('Appearance') }}</x-slot>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Appearance') }}
        </h1>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div role="status" class="p-4 bg-green-100 text-green-800 rounded-lg">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form method="POST" action="{{ route('appearance.update') }}" class="space-y-8">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="description" :value="__('Description')" />
                        <x-text-input id="description" name="description" type="text" maxlength="200"
                                      class="mt-1 block w-full" :value="old('description', $user->description)" />
                        <p class="mt-1 text-sm text-gray-500">
                            {{ __('A short tagline, shown under your blog name and as your feed subtitle. Optional.') }}
                        </p>
                        <x-input-error field="description" :messages="$errors->get('description')" class="mt-2" />
                    </div>

                    <fieldset @error('theme') aria-describedby="theme-error" @enderror>
                        <legend class="text-lg font-medium">{{ __('Theme') }}</legend>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ __('The colors of your public blog. Every theme meets WCAG AA contrast — pick by taste.') }}
                        </p>
                        <div class="mt-4 space-y-3">
                            @foreach ($themes as $theme)
                                <label class="flex items-start gap-3">
                                    <input type="radio" name="theme" value="{{ $theme->value }}"
                                           @checked(old('theme', $user->theme->value) === $theme->value)
                                           class="mt-1 border-gray-300 text-gray-800 focus:ring-indigo-500">
                                    <span>
                                        <span class="flex items-center gap-2">
                                            <span class="font-medium">{{ $theme->label() }}</span>
                                            {{-- Preview dots: page background and accent color.
                                                 Decorative — the description carries the meaning. --}}
                                            <span aria-hidden="true" class="inline-block h-4 w-4 rounded-full border border-gray-300"
                                                  style="background-color: {{ $theme->swatch()['bg'] }}"></span>
                                            <span aria-hidden="true" class="inline-block h-4 w-4 rounded-full"
                                                  style="background-color: {{ $theme->swatch()['accent'] }}"></span>
                                        </span>
                                        <span class="block text-sm text-gray-500">{{ $theme->description() }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <x-input-error field="theme" :messages="$errors->get('theme')" class="mt-2" />
                    </fieldset>

                    <fieldset @error('font') aria-describedby="font-error" @enderror>
                        <legend class="text-lg font-medium">{{ __('Font') }}</legend>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ __('System fonts only — your readers never download a webfont, from us or anyone else.') }}
                        </p>
                        <div class="mt-4 space-y-3">
                            @foreach ($fonts as $font)
                                <label class="flex items-start gap-3">
                                    <input type="radio" name="font" value="{{ $font->value }}"
                                           @checked(old('font', $user->font->value) === $font->value)
                                           class="mt-1 border-gray-300 text-gray-800 focus:ring-indigo-500">
                                    <span>
                                        <span class="font-medium {{ $font === \App\Enums\BlogFont::Serif ? 'font-serif' : 'font-sans' }}">{{ $font->label() }}</span>
                                        <span class="block text-sm text-gray-500">{{ $font->description() }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <x-input-error field="font" :messages="$errors->get('font')" class="mt-2" />
                    </fieldset>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <a href="{{ route('blog.home', $user) }}" class="text-gray-600 hover:underline">
                            {{ __('View your blog') }}
                        </a>
                    </div>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
