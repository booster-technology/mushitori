@extends('layouts.app')

@section('title', 'ユーザーの作成')
@section('flash-width', 'form-narrow')

@section('content')
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <x-back-button :href="route('staff.users.index')" label="ユーザー一覧" class="btn-sm" />
        <h1 class="h4 fw-bold mb-0">ユーザーの作成</h1>
    </div>

    <form method="POST" action="{{ route('staff.users.store') }}"
          data-confirm="この内容でユーザーを作成しますか？" data-confirm-ok="作成する">
        @csrf
        <div class="card shadow-sm border-0 form-narrow">
            <div class="card-body">
                @include('staff.users._form')

                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" name="send_welcome_mail" value="1" id="send_welcome_mail"
                           @checked(session()->hasOldInput() ? old('send_welcome_mail') : true)>
                    <label class="form-check-label" for="send_welcome_mail">ログイン案内メールを送る</label>
                    <div class="form-text">ログイン画面のURLとログイン方法（パスワード不要・メールの認証コードでログイン）をお知らせします。</div>
                </div>

                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>作成する</button>
            </div>
        </div>
    </form>
@endsection
