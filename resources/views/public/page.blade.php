<x-public-layout :author="$author" :title="ucfirst($page->slug)">
    <article class="prose max-w-none">
        {{ \App\Support\Markdown::toHtml($page->body) }}
    </article>
</x-public-layout>
