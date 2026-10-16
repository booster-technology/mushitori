@props(['priority'])
<span {{ $attributes->class(['text-nowrap']) }} title="優先度: {{ $priority->label() }}">
    <i class="bi {{ $priority->icon() }}"></i><span class="ms-1">{{ $priority->label() }}</span>
</span>
