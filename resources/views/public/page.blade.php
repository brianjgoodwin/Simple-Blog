<x-public-layout :author="$author" :title="ucfirst($page->slug)">
    <article>
        <h1 class="text-3xl font-bold mb-8">{{ ucfirst($page->slug) }}</h1>

        <div class="prose">
            {{ \App\Support\Markdown::toHtml($page->body) }}
        </div>
    </article>
</x-public-layout>
