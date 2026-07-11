{{--
    The acceptable-use page. One paragraph of rules, one of promises.

    Its real function (PLAN.md Phase 14): it makes suspending an account a
    policy action instead of a personal argument. Phase 11's register page
    must link here before the first invite goes out.

    Self-contained like welcome/404 (not x-public-layout, which needs an
    $author).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Acceptable use') }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-white text-gray-900 antialiased">
    <div class="max-w-xl mx-auto px-4 py-24">
        <main>
            <h1 class="text-3xl font-bold">{{ __('Acceptable use') }}</h1>

            <p class="mt-4 text-gray-600">
                {{ config('app.name') }} hosts personal writing. Don't use it to
                harass people, impersonate people, post spam or link schemes, or
                publish content that is illegal where this server lives (the US).
                Accounts that do get suspended — and since your posts are plain
                Markdown you can export at any time, suspension never takes your
                words away from you.
            </p>

            <p class="mt-4 text-gray-600">
                In return: no analytics, no tracking, no ads. We don't watch you
                write, and we don't watch anyone read.
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
