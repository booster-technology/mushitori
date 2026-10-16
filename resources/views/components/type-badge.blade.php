@props(['type'])
<span {{ $attributes->class(['badge', 'badge-type', $type->badgeClass()]) }}>{{ $type->label() }}</span>
