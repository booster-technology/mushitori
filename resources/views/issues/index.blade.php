@extends('layouts.app')

@section('title', $project->name.' の課題')

@section('content')
    @php
        $hasFilter = $filters['status'] !== 'unclosed' || $filters['type'] !== '' || $filters['priority'] !== ''
            || $filters['assignee'] !== '' || $filters['q'] !== '' || $filters['sort'] !== 'updated';
    @endphp

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <x-back-button :href="portal_route('home')" label="ホーム" class="btn-sm" />
        <div class="min-w-0 me-auto">
            <div class="small text-muted">{{ $project->key }}</div>
            <h1 class="h4 fw-bold mb-0 text-truncate">{{ $project->name }}</h1>
        </div>
        @if (portal() === 'staff')
            <a href="{{ route('staff.projects.edit', $project) }}" class="btn btn-outline-secondary">
                <i class="bi bi-gear me-1"></i><span class="d-none d-sm-inline">設定</span>
            </a>
        @endif
        <a href="{{ portal_route('issues.create', $project) }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>課題を追加
        </a>
    </div>

    @if ($project->description)
        <p class="text-muted small mb-3">{!! format_text($project->description) !!}</p>
    @endif

    {{-- 状態のタブ(よく使う絞り込み) --}}
    <ul class="nav nav-pills status-tabs flex-nowrap overflow-auto mb-2">
        @foreach (\App\Services\IssueQueryService::STATUS_FILTERS + collect(\App\Enums\IssueStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all() as $value => $label)
            <li class="nav-item">
                <a class="nav-link text-nowrap @if ($filters['status'] === $value) active @endif"
                   href="{{ portal_route('issues.index', ['project' => $project] + array_filter(array_merge($filters, ['status' => $value]))) }}">{{ $label }}</a>
            </li>
        @endforeach
    </ul>

    {{-- 詳細な絞り込み(スマートフォンでは折りたたむ) --}}
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2">
            <button class="btn btn-sm btn-link text-decoration-none px-0 d-md-none" type="button"
                    data-bs-toggle="collapse" data-bs-target="#issueFilters" aria-expanded="{{ $hasFilter ? 'true' : 'false' }}">
                <i class="bi bi-funnel me-1"></i>絞り込み・並び替え
            </button>
            <form method="GET" action="{{ portal_route('issues.index', $project) }}" id="issueFilters"
                  class="collapse d-md-block {{ $hasFilter ? 'show' : '' }}">
                <input type="hidden" name="status" value="{{ $filters['status'] }}">
                <div class="row g-2 align-items-end py-1">
                    <div class="col-12 col-md-4 col-lg-3">
                        <label class="form-label small mb-1" for="q">キーワード</label>
                        <input type="search" id="q" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm"
                               placeholder="件名・詳細・{{ $project->key }}-1">
                    </div>
                    <div class="col-6 col-md-2 col-lg-2">
                        <label class="form-label small mb-1" for="type">種別</label>
                        <select id="type" name="type" class="form-select form-select-sm">
                            <option value="">すべて</option>
                            @foreach (\App\Enums\IssueType::cases() as $type)
                                <option value="{{ $type->value }}" @selected($filters['type'] === $type->value)>{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2 col-lg-2">
                        <label class="form-label small mb-1" for="priority">優先度</label>
                        <select id="priority" name="priority" class="form-select form-select-sm">
                            <option value="">すべて</option>
                            @foreach (\App\Enums\IssuePriority::cases() as $priority)
                                <option value="{{ $priority->value }}" @selected($filters['priority'] === $priority->value)>{{ $priority->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2 col-lg-2">
                        <label class="form-label small mb-1" for="assignee">担当者</label>
                        <select id="assignee" name="assignee" class="form-select form-select-sm">
                            <option value="">すべて</option>
                            @if (portal() === 'staff')
                                <option value="me" @selected($filters['assignee'] === 'me')>自分</option>
                            @endif
                            <option value="none" @selected($filters['assignee'] === 'none')>未設定</option>
                            @foreach ($staffUsers as $staff)
                                <option value="{{ $staff->id }}" @selected($filters['assignee'] === (string) $staff->id)>{{ $staff->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2 col-lg-2">
                        <label class="form-label small mb-1" for="sort">並び順</label>
                        <select id="sort" name="sort" class="form-select form-select-sm">
                            @foreach (\App\Services\IssueQueryService::SORTS as $value => $label)
                                <option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-lg-1 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-secondary flex-fill"><i class="bi bi-search"></i><span class="d-lg-none ms-1">検索</span></button>
                    </div>
                </div>
                @if ($hasFilter)
                    <div class="small pb-1">
                        <a href="{{ portal_route('issues.index', $project) }}" class="link-secondary"><i class="bi bi-x-circle me-1"></i>条件をクリア</a>
                    </div>
                @endif
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-body small text-muted">
            全{{ number_format($issues->total()) }}件
            @if ($issues->total() > 0)
                （{{ $issues->firstItem() }}〜{{ $issues->lastItem() }}件目を表示）
            @endif
        </div>
        <div class="card-body p-0">
            <x-issue-list :issues="$issues" />
        </div>
    </div>

    @if ($issues->hasPages())
        <div class="mt-3 d-flex justify-content-center">
            {{ $issues->onEachSide(1)->links() }}
        </div>
    @endif
@endsection
