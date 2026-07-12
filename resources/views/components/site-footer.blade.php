{{--
    Site-wide credit line. Fixed text, not author-editable — it credits the
    person running the instance, so it appears on every tenant's blog.
--}}
{{-- Theme-variable colors: on non-themed pages (landing, 404) the :root
     defaults are exactly the old gray-500 / gray-200, so nothing changes
     there; on themed blogs the footer follows the theme. --}}
<footer class="mt-16 pt-6 border-t border-theme text-sm text-theme-muted">
    {{-- The glyph is hidden from screen readers (announced as "black heart
         suit"); they read the sr-only word instead. --}}
    Made with <span aria-hidden="true">♥</span><span class="sr-only">{{ __('love') }}</span> by Brian
</footer>
