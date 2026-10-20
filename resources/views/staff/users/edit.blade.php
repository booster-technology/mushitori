@extends('layouts.app')

@section('title', $user->name.' の編集')
@section('flash-width', 'form-narrow')

@section('content')
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <x-back-button :href="route('staff.users.index')" label="ユーザー一覧" class="btn-sm" />
        <h1 class="h4 fw-bold mb-0">ユーザーの編集</h1>
    </div>

    {{-- 認証コードの入力ミスでログインがロックされている場合(更新フォームとは別のフォームで解除する) --}}
    @if ($lockedMinutes !== null)
        <div class="alert alert-warning d-flex flex-column flex-sm-row align-items-sm-center gap-2 form-narrow">
            <div class="flex-grow-1">
                <div class="fw-semibold"><i class="bi bi-lock-fill me-1"></i>ログインがロックされています</div>
                <div class="small">
                    認証コードの入力ミスが続いたため、一時的にログインできない状態です（約{{ $lockedMinutes }}分後に自動で解除されます）。
                    本人から問い合わせがあった場合は、ロックを解除してください。
                </div>
            </div>
            <form method="POST" action="{{ route('staff.users.unlock', $user) }}" class="flex-shrink-0"
                  data-confirm="「{{ $user->name }}」のログインのロックを解除しますか？&#10;本人以外の誰かがログインを試みていた可能性もあります。本人からの問い合わせであることを確認してから解除してください。"
                  data-confirm-ok="ロックを解除する">
                @csrf
                <button type="submit" class="btn btn-warning btn-sm text-nowrap"><i class="bi bi-unlock me-1"></i>ロックを解除</button>
            </form>
        </div>
    @endif

    <form method="POST" action="{{ route('staff.users.update', $user) }}"
          data-confirm="ユーザー情報を更新しますか？&#10;「無効」にすると、このユーザーはログインできなくなります。" data-confirm-ok="更新する">
        @csrf
        @method('PUT')
        <div class="card shadow-sm border-0 form-narrow">
            <div class="card-body">
                @include('staff.users._form')

                <div class="mb-4">
                    <div class="form-label fw-semibold">状態</div>
                    <input type="hidden" name="is_active" value="0">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="is_active" value="1" id="is_active"
                               @checked(old('is_active', $user->is_active))>
                        <label class="form-check-label" for="is_active">有効（ログインできる）</label>
                    </div>
                    @error('is_active')<div class="small text-danger mt-1">{{ $message }}</div>@enderror
                </div>

                <div class="small text-muted mb-3">
                    最終ログイン: {{ $user->last_login_at?->format('Y/m/d H:i') ?? '未ログイン' }}
                    ／ 作成日: {{ $user->created_at->format('Y/m/d') }}
                </div>

                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>更新する</button>
            </div>
        </div>
    </form>

    {{-- ログイン案内メールの再送(更新フォームとは別のフォームで送る) --}}
    <div class="card shadow-sm border-0 form-narrow mt-3">
        <div class="card-body d-flex flex-column flex-sm-row align-items-sm-center gap-2">
            <div class="flex-grow-1">
                <div class="fw-semibold"><i class="bi bi-envelope me-1"></i>ログイン案内メール</div>
                <div class="small text-muted">
                    ログイン画面のURLとログイン方法を、{{ $user->email }} 宛てに送ります。案内メールを削除してしまった場合などに使います（1時間に{{ \App\Services\UserService::LOGIN_GUIDE_RESENDS_PER_HOUR }}回まで）。
                </div>
            </div>
            @if ($user->is_active)
                <form method="POST" action="{{ route('staff.users.login-guide', $user) }}" class="flex-shrink-0"
                      data-confirm="「{{ $user->name }}」（{{ $user->email }}）にログイン案内メールを送りますか？" data-confirm-ok="送信する">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-sm text-nowrap"><i class="bi bi-send me-1"></i>ログイン案内メールを再送する</button>
                </form>
            @else
                <div class="small text-muted flex-shrink-0">無効なユーザーには送れません</div>
            @endif
        </div>
    </div>
@endsection
