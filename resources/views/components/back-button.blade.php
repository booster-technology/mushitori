{{-- 一覧・詳細画面の「戻る」ボタン(テキストリンクではなく、ひと目で分かるボタンにする) --}}
@props(['href', 'label' => '戻る'])
<a href="{{ $href }}" {{ $attributes->class(['btn', 'btn-outline-secondary', 'btn-back']) }}>
    <i class="bi bi-arrow-left me-1"></i>{{ $label }}
</a>
