@props(['messages', 'field' => null])

@if ($messages)
    {{-- id="{field}-error" lets the matching input reference this via
         aria-describedby, so the error is announced when the field is focused. --}}
    <ul @if ($field) id="{{ $field }}-error" @endif {{ $attributes->merge(['class' => 'text-sm text-red-600 space-y-1']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
