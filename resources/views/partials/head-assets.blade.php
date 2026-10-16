{{-- ブラウザのタブ・スマホのホーム画面のアイコン(元画像は resources/brand/logo-original.png) --}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/icon-192.png') }}">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
{{--
    Bootstrap・Bootstrap Icons・Noto Sans JP は public/vendor に同梱している(CDNに接続できない社内LAN等でも動くように。ビルド不要)。
    更新するときは npm の公式パッケージから同じ場所・同じファイル名で置き換える(README の「同梱ライブラリ」参照)
--}}
<link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}?v=5.3.3">
<link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}?v=1.11.3">
{{-- 文字のフォント(Noto Sans JP)。文字の範囲ごとに分かれていて、画面で使っている文字の分だけ読み込まれる --}}
<link rel="stylesheet" href="{{ asset('vendor/noto-sans-jp/noto-sans-jp.css') }}?v=5.3.0">
<link rel="stylesheet" href="{{ asset_v('css/app.css') }}">
{{-- テーマカラー(.env の APP_THEME_COLOR から計算)。CSPに合わせて <style> ではなくCSSファイルとして読み込む(app/Http/Controllers/ThemeController.php) --}}
<link rel="stylesheet" href="{{ route('theme.css', ['v' => \App\Support\ThemeColor::fromConfig()->version()]) }}">
