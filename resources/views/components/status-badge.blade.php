@props(['status'])
<span {{ $attributes->class(['badge', 'badge-status', $status->badgeClass()]) }}>{{ $status->label() }}</span>
