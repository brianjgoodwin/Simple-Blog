<x-guest-layout>
    <x-slot name="title">{{ __('Register') }}</x-slot>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <!-- Invite code (pre-filled when the register link carried one) -->
        <div>
            <x-input-label for="code" :value="__('Invite code')" />
            <x-text-input id="code" class="block mt-1 w-full" type="text" name="code" :value="old('code', $code)" required autofocus autocomplete="off" />
            <x-input-error field="code" :messages="$errors->get('code')" class="mt-2" />
        </div>

        <!-- Display name -->
        <div class="mt-4">
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autocomplete="name" />
            <x-input-error field="name" :messages="$errors->get('name')" class="mt-2" />
            <p class="mt-1 text-sm text-gray-500">{{ __('Your byline — shown on everything you publish.') }}</p>
        </div>

        <!-- Username -->
        <div class="mt-4">
            <x-input-label for="username" :value="__('Username')" />
            <x-text-input id="username" class="block mt-1 w-full" type="text" name="username" :value="old('username')" required autocomplete="off" />
            <x-input-error field="username" :messages="$errors->get('username')" class="mt-2" />
            <p class="mt-1 text-sm text-gray-500">
                {{ __('Lowercase letters, numbers, and underscores. Your blog will live at /@username — this can never be changed.') }}
            </p>
        </div>

        <!-- Email Address -->
        <div class="mt-4">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error field="email" :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="new-password" />
            <x-input-error field="password" :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error field="password_confirmation" :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        {{-- Phase 14's condition for opening registration: the rules are
             one click away before an account exists. --}}
        <p class="mt-4 text-sm text-gray-600">
            {{ __('By creating an account you agree to') }}
            <a href="{{ route('acceptable-use') }}" class="underline decoration-gray-300 hover:decoration-current">{{ __('how this place works') }}</a>.
        </p>

        <div class="flex items-center justify-end mt-4">
            <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" href="{{ route('login') }}">
                {{ __('Already registered?') }}
            </a>

            <x-primary-button class="ms-3">
                {{ __('Register') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
