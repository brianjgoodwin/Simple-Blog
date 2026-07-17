{{-- Blog search box: a plain GET form, works without JavaScript. The public
     CSP's form-action 'self' permits the same-origin submit. Expects $author
     and an optional $term (the current query, echoed back into the field). --}}
<form method="GET" action="{{ route('blog.search', $author) }}" role="search" class="mb-10 flex gap-2">
    <label for="q" class="sr-only">{{ __('Search posts') }}</label>
    <input type="search" name="q" id="q" value="{{ $term ?? '' }}"
           placeholder="{{ __('Search posts…') }}"
           class="flex-1 min-w-0 px-3 py-2 text-sm border border-theme rounded-md bg-transparent">
    <button type="submit"
            class="px-4 py-2 text-sm border border-theme rounded-md text-accent hover:underline whitespace-nowrap">
        {{ __('Search') }}
    </button>
</form>
