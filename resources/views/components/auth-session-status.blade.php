@props(['status'])

@if ($status)
    {{-- green-800, not stock green-600 (3.3:1 on white fails AA for small
         text); role="status" so "reset link sent" etc. reach live regions. --}}
    <div role="status" {{ $attributes->merge(['class' => 'font-medium text-sm text-green-800']) }}>
        {{ $status }}
    </div>
@endif
