{{-- エラー画面の共通レイアウト(技術的な詳細は表示しない) --}}
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') | {{ config('app.name') }}</title>
    @include('partials.head-assets')
</head>
<body class="guest-body">
    @php
        // 「前の画面に戻る」は同じサイト内の前の画面(わからなければホーム)。CSPに合わせて、スクリプトで前の画面に戻るリンク(history.back)は使わない
        $home = \App\Support\Portal::route('home');
        $previous = url()->previous($home);
        $backUrl = str_starts_with($previous, url('/')) && $previous !== url()->current() ? $previous : $home;
    @endphp
    <main class="container py-5">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-6 text-center">
                <div class="mb-4"><x-brand size="md" /></div>
                <div class="display-4 fw-bold text-secondary mb-2">@yield('code')</div>
                <h1 class="h4 fw-bold mb-3">@yield('title')</h1>
                <p class="text-muted mb-4">@yield('message')</p>
                <div class="d-flex flex-wrap justify-content-center gap-2">
                    <a href="{{ $backUrl }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>前の画面に戻る</a>
                    <a href="{{ \App\Support\Portal::route('home') }}" class="btn btn-primary"><i class="bi bi-house-door me-1"></i>ホームへ</a>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
