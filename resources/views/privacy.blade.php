{{--
    The privacy policy. Host-level and static, a sibling of acceptable-use —
    it describes how this instance handles data, so it credits the operator,
    not any one author.

    Self-contained like welcome/404/acceptable-use (not x-public-layout, which
    needs an $author). Kept deliberately short: the honest posture is that
    there is very little to disclose.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Privacy') }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-white text-gray-900 antialiased">
    <div class="max-w-xl mx-auto px-4 py-24">
        <main>
            <h1 class="text-3xl font-bold">{{ __('Privacy') }}</h1>

            <p class="mt-4 text-gray-600">
                {{ config('app.name') }} is a small, invite-only place for writing.
                The short version: no analytics, no tracking, no ads, and nothing
                sold or shared. What follows is just the detail.
            </p>

            <h2 class="mt-8 text-xl font-bold">{{ __('What we store') }}</h2>
            <p class="mt-2 text-gray-600">
                To run your account: your name, username, email address, and a
                hashed password. Your writing: the posts and pages you create,
                kept as the plain Markdown you typed. Ordinary web-server logs
                (such as IP addresses) may be retained briefly to keep the server
                secure and to deal with abuse — never to profile you or your
                readers.
            </p>

            <h2 class="mt-8 text-xl font-bold">{{ __('What your readers get') }}</h2>
            <p class="mt-2 text-gray-600">
                Public blog pages load nothing but the page and its stylesheet —
                no analytics scripts, no third-party fonts, no remote images, no
                embeds. A reader's browser never makes a request to anyone but
                this server, so nobody — including us — is watching people read.
            </p>

            <h2 class="mt-8 text-xl font-bold">{{ __('Your control') }}</h2>
            <p class="mt-2 text-gray-600">
                You can export everything you've written, as Markdown, at any
                time. Deleting your account removes your account and its content.
                Because your posts are plain Markdown you can take with you,
                leaving is always an option.
            </p>

            <p class="mt-8">
                <a href="{{ url('/') }}" class="underline decoration-gray-300 hover:decoration-current">
                    {{ __('Back to the front page') }}
                </a>
            </p>
        </main>

        <x-site-footer />
    </div>
</body>
</html>
