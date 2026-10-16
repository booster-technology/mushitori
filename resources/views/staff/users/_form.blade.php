{{-- ユーザーの作成・編集フォームの共通部分(種別が「お客様」のときだけ会社名を表示する。public/js/app.js の userForm) --}}
@php
    $currentRole = old('role', isset($user) ? $user->role->value : $defaultRole->value);
    $selectedProjects = array_map('intval', old('projects', isset($user) ? $user->projects->pluck('id')->all() : []));
@endphp

<div x-data="userForm">
<div class="mb-3">
    <div class="form-label fw-semibold">種別 <span class="badge text-bg-danger">必須</span></div>
    <div class="d-flex flex-wrap gap-2">
        @foreach (\App\Enums\UserRole::cases() as $r)
            <input type="radio" class="btn-check" name="role" id="role-{{ $r->value }}" value="{{ $r->value }}"
                   @change="chooseRole" @checked($currentRole === $r->value)>
            <label class="btn btn-outline-secondary" for="role-{{ $r->value }}">{{ $r->label() }}</label>
        @endforeach
    </div>
    @error('role')<div class="small text-danger mt-1">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="name" class="form-label fw-semibold">氏名 <span class="badge text-bg-danger">必須</span></label>
    <input type="text" id="name" name="name" value="{{ old('name', $user->name ?? '') }}" maxlength="100"
           class="form-control @error('name') is-invalid @enderror" required>
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="email" class="form-label fw-semibold">メールアドレス <span class="badge text-bg-danger">必須</span></label>
    <input type="email" id="email" name="email" value="{{ old('email', $user->email ?? '') }}" maxlength="255"
           class="form-control @error('email') is-invalid @enderror" required>
    <div class="form-text">ログイン時の認証コードの送り先になります。</div>
    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3" x-show="isCustomer" @if ($currentRole !== 'customer') x-cloak @endif>
    <label for="company_name" class="form-label fw-semibold">会社名</label>
    <input type="text" id="company_name" name="company_name" value="{{ old('company_name', $user->company_name ?? '') }}" maxlength="100"
           class="form-control @error('company_name') is-invalid @enderror" placeholder="例）株式会社サンプル">
    @error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <div class="form-label fw-semibold">参加プロジェクト</div>
    <div class="form-text mt-0 mb-2">お客様は、ここでチェックしたプロジェクトだけ閲覧・起票できます。</div>
    @if ($projects->isEmpty())
        <p class="small text-muted">プロジェクトがまだありません。</p>
    @else
        <div class="member-checklist border rounded p-2">
            @foreach ($projects as $project)
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="projects[]" value="{{ $project->id }}" id="project-{{ $project->id }}"
                           @checked(in_array($project->id, $selectedProjects, true))>
                    <label class="form-check-label" for="project-{{ $project->id }}">
                        <span class="text-muted small me-1">{{ $project->key }}</span>{{ $project->name }}
                    </label>
                </div>
            @endforeach
        </div>
    @endif
</div>
</div>
