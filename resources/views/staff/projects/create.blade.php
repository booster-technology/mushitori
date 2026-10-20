@extends('layouts.app')

@section('title', 'プロジェクトの作成')
@section('flash-width', 'form-narrow')

@section('content')
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <x-back-button :href="route('staff.projects.index')" label="プロジェクト一覧" class="btn-sm" />
        <h1 class="h4 fw-bold mb-0">プロジェクトの作成</h1>
    </div>

    <form method="POST" action="{{ route('staff.projects.store') }}"
          data-confirm="この内容でプロジェクトを作成しますか？&#10;プロジェクトキーは作成後に変更できません。" data-confirm-ok="作成する">
        @csrf
        <div class="card shadow-sm border-0 form-narrow">
            <div class="card-body">
                <div class="mb-3">
                    <label for="key" class="form-label fw-semibold">プロジェクトキー <span class="badge text-bg-danger">必須</span></label>
                    <input type="text" id="key" name="key" value="{{ old('key') }}" maxlength="10"
                           class="form-control text-uppercase @error('key') is-invalid @enderror" placeholder="例）WEB" required>
                    <div class="form-text">課題キー（例: WEB-12）やURLに使われます。英大文字で始まる2〜10文字（英大文字・数字・_）。作成後は変更できません。</div>
                    @error('key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label for="name" class="form-label fw-semibold">プロジェクト名 <span class="badge text-bg-danger">必須</span></label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" maxlength="100"
                           class="form-control @error('name') is-invalid @enderror" placeholder="例）コーポレートサイト保守" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label for="description" class="form-label fw-semibold">説明</label>
                    <textarea id="description" name="description" rows="3" class="form-control @error('description') is-invalid @enderror">{{ old('description') }}</textarea>
                    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-4">
                    <div class="form-label fw-semibold">メンバー</div>
                    <div class="form-text mt-0 mb-2">
                        お客様はメンバーになっているプロジェクトだけ閲覧できます。社内メンバーを追加すると、そのメンバーに新規課題の通知が届きます（社内メンバーが未設定の場合は社内全員に通知）。
                    </div>
                    @if ($users->isEmpty())
                        <p class="small text-muted">ユーザーがいません。</p>
                    @else
                        <div class="member-checklist border rounded p-2">
                            @foreach ($users as $user)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="members[]" value="{{ $user->id }}" id="member-{{ $user->id }}"
                                           @checked(in_array($user->id, old('members', [auth()->id()])))>
                                    <label class="form-check-label" for="member-{{ $user->id }}">
                                        <span class="badge {{ $user->isStaff() ? 'text-bg-dark' : 'text-bg-success' }} me-1">{{ $user->role->label() }}</span>
                                        {{ $user->displayName() }}
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>作成する</button>
            </div>
        </div>
    </form>
@endsection
