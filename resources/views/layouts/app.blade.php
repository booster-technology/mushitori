<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('title')@yield('title') | @endif{{ config('app.name') }}</title>
    @include('partials.head-assets')
</head>
<body class="portal-{{ portal() }}" @include('partials.app-root')>
    @php
        $currentUser = auth()->user();
        // ヘッダーの文字色: 社内用は常に濃色ヘッダー、お客様用はテーマカラーの明るさに合わせて白/黒を切り替える
        $navDark = portal() === 'staff' || \App\Support\ThemeColor::fromConfig()->isDark();
    @endphp

    {{-- ヘッダー(社内用はチャコール・お客様用はテーマカラーで、同じブラウザで両方開いていても区別できるようにする) --}}
    <nav class="navbar navbar-expand-lg app-navbar" data-bs-theme="{{ $navDark ? 'dark' : 'light' }}">
        <div class="container-xl">
            <a class="navbar-brand d-flex align-items-center gap-2" href="{{ portal_route('home') }}">
                <x-brand :tone="$navDark ? 'white' : 'color'" size="sm" />
                @if (portal() === 'staff')
                    <span class="badge rounded-pill text-bg-light portal-badge">社内</span>
                @endif
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                    aria-controls="mainNav" aria-expanded="false" aria-label="メニューを開く">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link @if (request()->routeIs('*.home')) active @endif" href="{{ portal_route('home') }}">
                            <i class="bi bi-house-door me-1"></i>ホーム
                        </a>
                    </li>
                    @if (portal() === 'staff')
                        <li class="nav-item">
                            <a class="nav-link @if (request()->routeIs('staff.projects.*')) active @endif" href="{{ route('staff.projects.index') }}">
                                <i class="bi bi-folder2 me-1"></i>プロジェクト管理
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link @if (request()->routeIs('staff.users.*')) active @endif" href="{{ route('staff.users.index') }}">
                                <i class="bi bi-people me-1"></i>ユーザー管理
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link @if (request()->routeIs('staff.trash.*')) active @endif" href="{{ route('staff.trash.index') }}">
                                <i class="bi bi-trash me-1"></i>ゴミ箱
                            </a>
                        </li>
                    @endif
                    <li class="nav-item">
                        <a class="nav-link @if (request()->routeIs('*.help*')) active @endif" href="{{ portal_route('help') }}">
                            <i class="bi bi-question-circle me-1"></i>使い方
                        </a>
                    </li>
                </ul>
                @if ($currentUser)
                    <div class="d-flex align-items-center gap-2 py-2 py-lg-0">
                        <span class="avatar avatar-sm">{{ $currentUser->initial() }}</span>
                        <span class="navbar-text small me-2 py-0">{{ $currentUser->displayName() }}</span>
                        <form method="POST" action="{{ portal_route('logout') }}" class="m-0">
                            @csrf
                            <button type="submit" class="btn btn-sm {{ $navDark ? 'btn-outline-light' : 'btn-outline-dark' }}">
                                <i class="bi bi-box-arrow-right me-1"></i>ログアウト
                            </button>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    </nav>

    <main class="container-xl py-3 py-md-4">
        {{-- メッセージ欄の幅は、本体の幅を絞っている画面では本体に揃える(各画面の @section('flash-width', 'クラス名')) --}}
        <div class="@yield('flash-width')">
            @include('partials.flash')
        </div>
        @yield('content')
    </main>

    <footer class="app-footer text-center text-muted small py-4">
        &copy; {{ date('Y') }} {{ config('app.name') }}
    </footer>

    @include('partials.confirm-modal')
    @include('partials.body-assets')
</body>
</html>
