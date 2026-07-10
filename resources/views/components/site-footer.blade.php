{{--
    Site-wide credit line. Fixed text, not author-editable — it credits the
    person running the instance, so it appears on every tenant's blog.
--}}
<footer class="mt-16 pt-6 border-t text-sm text-gray-500">
    {{-- The glyph is hidden from screen readers (announced as "black heart
         suit"); they read the sr-only word instead. --}}
    Made with <span aria-hidden="true">♥</span><span class="sr-only">{{ __('love') }}</span> by Brian
</footer>
