<x-public-layout :author="$author" :title="ucfirst($page->slug)">
    <article class="prose">
        {{ \App\Support\Markdown::toHtml($page->body) }}
    </article>
</x-public-layout>
