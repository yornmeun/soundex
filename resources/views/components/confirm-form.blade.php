{{-- A form that asks for confirmation in a modal before submitting. --}}
@props(['action', 'method' => 'POST', 'title', 'message', 'confirmLabel' => 'Confirm', 'danger' => false])
<form method="POST" action="{{ $action }}" {{ $attributes }}
      x-data
      @submit.prevent="$store.confirm.ask({ title: @js($title), message: @js($message), confirmLabel: @js($confirmLabel), danger: @js($danger) }, () => $el.submit())">
    @csrf
    @if (strtoupper($method) !== 'POST')
        @method($method)
    @endif
    {{ $slot }}
</form>
