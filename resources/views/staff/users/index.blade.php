@extends('layouts.app')

@section('title', 'ユーザー管理')

@section('content')
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h1 class="h4 fw-bold mb-0 me-auto">ユーザー管理</h1>
        <a href="{{ route('staff.users.create') }}" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>ユーザーを作成</a>
    </div>

    <form method="GET" action="{{ route('staff.users.index') }}" class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-5">
                    <label for="q" class="form-label small mb-1">キーワード</label>
                    <input type="search" id="q" name="q" value="{{ $keyword }}" class="form-control form-control-sm" placeholder="氏名・メールアドレス・会社名">
                </div>
                <div class="col-8 col-md-3">
                    <label for="role" class="form-label small mb-1">種別</label>
                    <select id="role" name="role" class="form-select form-select-sm">
                        <option value="">すべて</option>
                        @foreach (\App\Enums\UserRole::cases() as $r)
                            <option value="{{ $r->value }}" @selected($role === $r->value)>{{ $r->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-4 col-md-2">
                    <button type="submit" class="btn btn-sm btn-secondary w-100"><i class="bi bi-search me-1"></i>検索</button>
                </div>
            </div>
        </div>
    </form>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            @if ($users->isEmpty())
                <div class="text-center text-muted py-5">該当するユーザーはいません。</div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>氏名</th>
                                <th class="d-none d-md-table-cell">メールアドレス</th>
                                <th>種別</th>
                                <th class="d-none d-lg-table-cell text-end">参加プロジェクト</th>
                                <th class="d-none d-lg-table-cell">最終ログイン</th>
                                <th class="text-end">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($users as $user)
                                <tr class="@unless ($user->is_active) table-secondary text-muted @endunless">
                                    <td>
                                        <div class="fw-semibold">
                                            {{ $user->name }}
                                            @unless ($user->is_active)<span class="badge text-bg-secondary ms-1">無効</span>@endunless
                                            @if (in_array($user->id, $lockedIds, true))<span class="badge text-bg-warning ms-1" title="認証コードの入力ミスが続いたため、ログインが一時的にロックされています"><i class="bi bi-lock-fill"></i> ロック中</span>@endif
                                        </div>
                                        @if ($user->company_name)<div class="small text-muted">{{ $user->company_name }}</div>@endif
                                        <div class="small text-muted d-md-none text-break">{{ $user->email }}</div>
                                    </td>
                                    <td class="d-none d-md-table-cell small text-break">{{ $user->email }}</td>
                                    <td><span class="badge {{ $user->isStaff() ? 'text-bg-dark' : 'text-bg-success' }}">{{ $user->role->label() }}</span></td>
                                    <td class="d-none d-lg-table-cell text-end">{{ $user->projects_count }}</td>
                                    <td class="d-none d-lg-table-cell small text-nowrap">{{ $user->last_login_at?->format('Y/m/d H:i') ?? '未ログイン' }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('staff.users.edit', $user) }}" class="btn btn-sm btn-outline-secondary text-nowrap"><i class="bi bi-pencil me-1"></i>編集</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    @if ($users->hasPages())
        <div class="mt-3 d-flex justify-content-center">{{ $users->links() }}</div>
    @endif
@endsection
