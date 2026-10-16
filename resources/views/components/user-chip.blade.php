@props(['user', 'empty' => '未設定', 'company' => false])
@if ($user)
    <span {{ $attributes->class(['user-chip']) }}>
        <span class="avatar avatar-xs {{ $user->isStaff() ? 'avatar-staff' : 'avatar-customer' }}">{{ $user->initial() }}</span>
        <span class="text-truncate">{{ $company ? $user->displayName() : $user->name }}</span>
    </span>
@else
    <span {{ $attributes->class(['text-muted']) }}>{{ $empty }}</span>
@endif
