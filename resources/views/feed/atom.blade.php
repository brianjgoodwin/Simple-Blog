{{-- Hand-rolled Atom 1.0. Blade's {{ }} XML-escapes text nodes and attribute
     values; dates are RFC 3339 via Carbon::toAtomString(). Entry <id>s are
     permalinks, permanent because slugs freeze at first publish, so readers
     never re-show an old post as new. Emitting the XML declaration through a
     string literal keeps PHP's short-open-tag from swallowing "<?xml". --}}
{!! '<?xml version="1.0" encoding="UTF-8"?>'."\n" !!}
<feed xmlns="http://www.w3.org/2005/Atom">
    <title>{{ $author->name }}</title>
@if ($author->description)
    <subtitle>{{ $author->description }}</subtitle>
@endif
    <id>{{ route('blog.home', $author) }}</id>
    <link rel="alternate" type="text/html" href="{{ route('blog.home', $author) }}"/>
    <link rel="self" type="application/atom+xml" href="{{ route('blog.feed', $author) }}"/>
    <updated>{{ $updated->toAtomString() }}</updated>
    <author>
        <name>{{ $author->name }}</name>
    </author>
@foreach ($posts as $post)
    <entry>
        <title>{{ $post->title }}</title>
        <id>{{ route('blog.post', [$author, $post->slug]) }}</id>
        <link rel="alternate" type="text/html" href="{{ route('blog.post', [$author, $post->slug]) }}"/>
        <published>{{ $post->published_at->toAtomString() }}</published>
        <updated>{{ $post->updated_at->toAtomString() }}</updated>
        {{-- type="html": the post HTML is carried as an entity-encoded text
             payload, so escape the cached render rather than emitting it raw. --}}
        <content type="html">{!! e((string) $post->body_html) !!}</content>
    </entry>
@endforeach
</feed>
