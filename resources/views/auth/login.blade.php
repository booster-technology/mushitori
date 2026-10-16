@extends('layouts.guest')

@section('title', 'ログイン')

@section('content')
    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <h2 class="h5 fw-bold mb-3">ログイン</h2>
            <p class="text-muted small">
                登録済みのメールアドレスを入力してください。ログイン用の6桁の認証コードをメールでお送りします。
            </p>
            <form method="POST" action="{{ portal_route('login.send') }}" novalidate>
                @csrf
                <div class="mb-3">
                    <label for="email" class="form-label">メールアドレス</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}"
                           class="form-control form-control-lg @error('email') is-invalid @enderror"
                           autocomplete="email" inputmode="email" required autofocus>
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button type="submit" class="btn btn-primary btn-lg w-100">
                    <i class="bi bi-envelope me-1"></i>認証コードを送信
                </button>
            </form>
        </div>
    </div>
@endsection
