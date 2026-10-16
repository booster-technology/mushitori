<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@hasSection('title')@yield('title') | @endif{{ config('app.name') }}</title>
    @include('partials.head-assets')
</head>
<body class="portal-{{ portal() }} guest-body" @include('partials.app-root')>
    <main class="container py-5">
        <div class="row justify-content-center">
            <div class="col-12 col-sm-10 col-md-7 col-lg-5">
                <div class="text-center mb-4">
                    <h1 class="h4 fw-bold mb-2"><x-brand size="lg" /></h1>
                    <div class="text-muted small">
                        {{ portal() === 'staff' ? '社内メンバー用' : 'お客様用' }}
                    </div>
                </div>
                @include('partials.flash')
                @yield('content')
            </div>
        </div>
    </main>
    @include('partials.confirm-modal')
    @include('partials.body-assets')
</body>
</html>
