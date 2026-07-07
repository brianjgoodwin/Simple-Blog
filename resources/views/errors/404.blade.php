{{--
    Custom 404. Drafts, unknown authors, and unknown slugs all land here (never
    a 403), so this page must not hint at what might have existed. Deliberately
    says nothing beyond "not found" and shares the public reading aesthetic.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Not found') }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-white text-gray-900 antialiased">
    <div class="max-w-xl mx-auto px-4 py-24">
        <h1 class="text-3xl font-bold">{{ __('Not found') }}</h1>
        <p class="mt-4 text-gray-600">
            {{ __("There's nothing at this address.") }}
        </p>
        <p class="mt-8 text-sm text-gray-600">
            <a href="{{ url('/') }}" class="hover:underline">{{ __('Back to home') }}</a>
        </p>
    </div>
</body>
</html>
