{{--
    ロゴ表示。サイト名が「ムシトリ」のときはロゴ画像、独自のサイト名にしている場合は「網のアイコン + サイト名」を表示する。
    tone: white = 濃い色の背景用(文字が白いロゴ) / color = 明るい背景用
    size: sm(ヘッダー) / md / lg(ログイン画面など)
--}}
@props(['tone' => 'color', 'size' => 'md'])
@if (config('app.name') === 'ムシトリ')
    <img src="{{ asset_v($tone === 'white' ? 'images/logo-white.png' : 'images/logo.png') }}" alt="ムシトリ"
         {{ $attributes->class(['brand-logo', 'brand-logo-'.$size]) }}>
@else
    <span {{ $attributes->class(['brand-text', 'brand-text-'.$size]) }}>
        <img src="{{ asset_v('images/icon-192.png') }}" alt="" class="brand-icon">
        <span>{{ config('app.name') }}</span>
    </span>
@endif
