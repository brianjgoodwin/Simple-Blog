{{--
    Shared title/body fields for the create and edit forms.
    Expects $post (a Post or null for a new post).

    The body field is an Alpine "composer": Write/Preview tabs, auto-growing
    textarea, word count, Ctrl/Cmd-S saves. Autosave is enabled ONLY for
    existing drafts — never for published posts (a timer must not push
    half-typed edits live) and not before the first manual save creates the
    record.
--}}
<div>
    <x-input-label for="title" :value="__('Title')" />
    <x-text-input id="title" name="title" type="text" class="mt-1 block w-full"
                  :value="old('title', $post?->title)" required autofocus />
    <x-input-error :messages="$errors->get('title')" class="mt-2" />
</div>

<div x-data="composer({
        previewUrl: @js(route('posts.preview')),
        autosaveUrl: @js($post && ! $post->isPublished() ? route('posts.update', $post) : null),
     })">

    {{-- Write / Preview tabs --}}
    <div class="flex items-center justify-between">
        <x-input-label for="body" :value="__('Body (Markdown)')" />
        <div class="flex text-sm rounded-md border border-gray-300 overflow-hidden" role="tablist">
            <button type="button" @click="tab = 'write'"
                    :class="tab === 'write' ? 'bg-gray-800 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    class="px-3 py-1">
                {{ __('Write') }}
            </button>
            <button type="button" @click="showPreview()"
                    :class="tab === 'preview' ? 'bg-gray-800 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    class="px-3 py-1 border-l border-gray-300">
                {{ __('Preview') }}
            </button>
        </div>
    </div>

    {{-- The textarea stays in the DOM when previewing (x-show, not x-if) so
         its value always submits with the form. Its content is rendered by
         Blade (not x-model) so the form still works with JavaScript disabled;
         Alpine reads the value in init() and mirrors it on input. --}}
    <textarea id="body" name="body" rows="18" x-ref="body"
              x-show="tab === 'write'" @input="body = $event.target.value; onInput()"
              class="mt-2 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm font-mono text-sm leading-relaxed"
    >{{ old('body', $post?->body) }}</textarea>

    {{-- Server-rendered preview, same pipeline as the public page. --}}
    <div x-show="tab === 'preview'" x-cloak
         class="mt-2 block w-full border border-gray-200 rounded-md p-4 min-h-[10rem]">
        <div class="prose" x-html="previewHtml"></div>
    </div>

    <x-input-error :messages="$errors->get('body')" class="mt-2" />

    {{-- Status line: hint + word count + autosave state --}}
    <div class="mt-1 flex items-center justify-between text-sm text-gray-500">
        <p>{{ __('Markdown is supported. Raw HTML is not rendered.') }}</p>
        <p class="flex items-center gap-3 whitespace-nowrap">
            {{-- One persistent live region whose text changes (see
                 autosaveMessage() in composer.js). The word count stays
                 OUTSIDE it — announcing every keystroke would be noise. --}}
            <span role="status" x-text="autosaveMessage()"
                  :class="autosaveStatus === 'error' ? 'text-red-600' : ''"></span>
            <span><span x-text="wordCount()"></span> {{ __('words') }}</span>
        </p>
    </div>
</div>
