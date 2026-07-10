{{--
    Root landing page for the blog host itself.

    Invite-only: there is no public directory of authors and no sign-up. This
    page just names the host and points invited authors at the login form.
    Readers reach a specific blog directly at /@{username}.

    Self-contained (not the x-public-layout, which needs an $author) but shares
    its narrow-column, light aesthetic.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-white text-gray-900 antialiased">
    <div class="max-w-xl mx-auto px-4 py-24">
        <h1 class="text-3xl font-bold">{{ config('app.name') }}</h1>

        <p class="mt-4 text-gray-600">
            {{ __('A small, invite-only home for writing. Each author keeps one blog.') }}
        </p>

        <p class="mt-8 text-sm text-gray-600">
            @auth
                <a href="{{ route('dashboard') }}" class="hover:underline">{{ __('Go to your dashboard') }}</a>
            @else
                <a href="{{ route('login') }}" class="hover:underline">{{ __('Author sign in') }}</a>
            @endauth
        </p>

        <x-site-footer />
    </div>
</body>
</html>
