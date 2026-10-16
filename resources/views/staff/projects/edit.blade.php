@extends('layouts.app')

@section('title', $project->name.' の設定')

@section('content')
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <x-back-button :href="route('staff.projects.index')" label="プロジェクト一覧" class="btn-sm" />
        <div class="min-w-0 me-auto">
            <div class="small text-muted">{{ $project->key }}</div>
            <h1 class="h4 fw-bold mb-0 text-truncate">{{ $project->name }} の設定</h1>
        </div>
        <a href="{{ route('staff.issues.index', $project) }}" class="btn btn-outline-secondary"><i class="bi bi-list-ul me-1"></i>課題一覧</a>
    </div>

    <div class="row g-3">
        {{-- 基本情報 --}}
        <div class="col-12 col-lg-6">
            <form method="POST" action="{{ route('staff.projects.update', $project) }}"
                  data-confirm="プロジェクトの情報を更新しますか？" data-confirm-ok="更新する">
                @csrf
                @method('PUT')
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-body fw-bold">基本情報</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="key">プロジェクトキー</label>
                            <input type="text" id="key" class="form-control" value="{{ $project->key }}" disabled>
                            <div class="form-text">プロジェクトキーは変更できません。</div>
                        </div>
                        <div class="mb-3">
                            <label for="name" class="form-label fw-semibold">プロジェクト名 <span class="badge text-bg-danger">必須</span></label>
                            <input type="text" id="name" name="name" value="{{ old('name', $project->name) }}" maxlength="100"
                                   class="form-control @error('name') is-invalid @enderror" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label fw-semibold">説明</label>
                            <textarea id="description" name="description" rows="3" class="form-control">{{ old('description', $project->description) }}</textarea>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>更新する</button>
                    </div>
                </div>
            </form>

            {{-- 削除 --}}
            <div class="card border-danger-subtle shadow-sm mt-3">
                <div class="card-body">
                    <h2 class="h6 fw-bold text-danger">プロジェクトの削除</h2>
                    <p class="small text-muted">削除すると、このプロジェクトと課題は画面に表示されなくなります。</p>
                    <form method="POST" action="{{ route('staff.projects.destroy', $project) }}" class="m-0"
                          data-confirm="プロジェクト「{{ $project->name }}」を削除しますか？&#10;課題もすべて表示されなくなります。"
                          data-confirm-ok="削除する" data-confirm-variant="danger">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>このプロジェクトを削除</button>
                    </form>
                </div>
            </div>
        </div>

        {{-- メンバー --}}
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-body fw-bold d-flex justify-content-between align-items-center">
                    <span>メンバー</span>
                    <span class="badge text-bg-secondary rounded-pill">{{ $project->members->count() }}</span>
                </div>
                <div class="card-body">
                    <p class="small text-muted">
                        お客様はメンバーになっているプロジェクトだけ閲覧・起票できます。社内メンバーを追加すると新規課題の通知が届きます（社内メンバーが未設定の場合は社内全員に通知）。
                    </p>

                    @if ($candidates->isNotEmpty())
                        <form method="POST" action="{{ route('staff.projects.members.store', $project) }}" class="d-flex gap-2 mb-3">
                            @csrf
                            <label for="user_id" class="visually-hidden">追加するユーザー</label>
                            <select id="user_id" name="user_id" class="form-select form-select-sm" required>
                                <option value="">ユーザーを選択…</option>
                                @foreach ($candidates->groupBy(fn ($u) => $u->role->label()) as $label => $group)
                                    <optgroup label="{{ $label }}">
                                        @foreach ($group as $candidate)
                                            <option value="{{ $candidate->id }}">{{ $candidate->displayName() }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-person-plus me-1"></i>追加</button>
                        </form>
                    @endif
                    <div class="small mb-3">
                        <a href="{{ route('staff.users.create') }}"><i class="bi bi-plus-circle me-1"></i>新しいユーザー（お客様など）を作成する</a>
                    </div>

                    @if ($project->members->isEmpty())
                        <p class="text-muted small mb-0">メンバーはまだいません。</p>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach ($project->members as $member)
                                <li class="list-group-item d-flex align-items-center gap-2 px-0">
                                    <span class="avatar avatar-sm {{ $member->isStaff() ? 'avatar-staff' : 'avatar-customer' }}">{{ $member->initial() }}</span>
                                    <div class="min-w-0 me-auto">
                                        <div class="small fw-semibold text-truncate">
                                            {{ $member->displayName() }}
                                            @unless ($member->is_active)<span class="badge text-bg-secondary ms-1">無効</span>@endunless
                                        </div>
                                        <div class="text-muted text-truncate fs-xs">{{ $member->email }}</div>
                                    </div>
                                    <span class="badge {{ $member->isStaff() ? 'text-bg-dark' : 'text-bg-success' }}">{{ $member->role->label() }}</span>
                                    <form method="POST" action="{{ route('staff.projects.members.destroy', [$project, $member]) }}" class="m-0"
                                          data-confirm="{{ $member->displayName() }} をメンバーから外しますか？"
                                          data-confirm-ok="外す" data-confirm-variant="danger">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="メンバーから外す">
                                            <i class="bi bi-person-dash"></i><span class="visually-hidden">メンバーから外す</span>
                                        </button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
