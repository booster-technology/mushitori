@extends('layouts.guest')

@section('title', '認証コードの入力')

@section('content')
    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <h2 class="h5 fw-bold mb-3">認証コードの入力</h2>
            <p class="text-muted small mb-3">
                <span class="fw-bold text-body">{{ $email }}</span> 宛にお送りした6桁のコードを入力してください。
                コードの有効期限は{{ \App\Services\LoginCodeService::EXPIRES_MINUTES }}分です。
            </p>
            <form method="POST" action="{{ portal_route('login.verify') }}" novalidate>
                @csrf
                <div class="mb-3">
                    <label for="code" class="form-label">認証コード</label>
                    <input type="text" id="code" name="code"
                           class="form-control form-control-lg text-center code-input @error('code') is-invalid @enderror"
                           inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"
                           placeholder="000000" required autofocus>
                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
                    <label class="form-check-label small" for="remember">このブラウザでログイン状態を保持する（30日間）</label>
                </div>
                <button type="submit" class="btn btn-primary btn-lg w-100">
                    <i class="bi bi-box-arrow-in-right me-1"></i>ログイン
                </button>
            </form>

            <hr class="my-4">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <a href="{{ portal_route('login') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>メールアドレスを入力し直す
                </a>
                <form method="POST" action="{{ portal_route('login.resend') }}" class="m-0">
                    @csrf
                    <button type="submit" class="btn btn-link btn-sm p-0">コードを再送する</button>
                </form>
            </div>
        </div>
    </div>
@endsection
