{{-- Per-blog sitemap. Published posts only (same guarantee as every public
     surface). Emitting the XML declaration through a string literal keeps
     PHP's short-open-tag from swallowing "<?xml". --}}
{!! '<?xml version="1.0" encoding="UTF-8"?>'."\n" !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>{{ route('blog.home', $author) }}</loc>
    </url>
    <url>
        <loc>{{ route('blog.about', $author) }}</loc>
    </url>
    <url>
        <loc>{{ route('blog.links', $author) }}</loc>
    </url>
@foreach ($posts as $post)
    <url>
        <loc>{{ route('blog.post', [$author, $post->slug]) }}</loc>
        <lastmod>{{ $post->updated_at->toAtomString() }}</lastmod>
    </url>
@endforeach
</urlset>
