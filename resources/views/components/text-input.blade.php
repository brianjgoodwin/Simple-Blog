@props(['disabled' => false])

@php
    // Associate validation errors with this field for assistive tech: when the
    // input's name has an error in the default bag, mark it invalid and point
    // at the matching <x-input-error field="..."> element (id "{name}-error").
    // Inputs on a named error bag (e.g. updatePassword, userDeletion) pass
    // aria-invalid/aria-describedby explicitly; the guard keeps this from
    // clobbering them.
    $errorField = $attributes->get('name');
    $describesError = $errorField && ! $attributes->has('aria-describedby') && $errors->has($errorField);
@endphp

<input @disabled($disabled)
    @if ($describesError) aria-invalid="true" aria-describedby="{{ $errorField }}-error" @endif
    {{ $attributes->merge(['class' => 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm']) }}>
