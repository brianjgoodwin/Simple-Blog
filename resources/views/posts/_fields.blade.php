{{--
    Shared title/body fields for the create and edit forms.
    Expects $post (a Post or null for a new post).
--}}
<div>
    <x-input-label for="title" :value="__('Title')" />
    <x-text-input id="title" name="title" type="text" class="mt-1 block w-full"
                  :value="old('title', $post?->title)" required autofocus />
    <x-input-error :messages="$errors->get('title')" class="mt-2" />
</div>

<div>
    <x-input-label for="body" :value="__('Body (Markdown)')" />
    <textarea id="body" name="body" rows="18"
              class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm font-mono text-sm"
    >{{ old('body', $post?->body) }}</textarea>
    <x-input-error :messages="$errors->get('body')" class="mt-2" />
    <p class="mt-1 text-sm text-gray-500">
        {{ __('Markdown is supported. Raw HTML is not rendered.') }}
    </p>
</div>
