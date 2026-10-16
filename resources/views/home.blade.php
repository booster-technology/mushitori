@extends('layouts.app')

@section('title', 'ホーム')

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h1 class="h4 fw-bold mb-0">ホーム</h1>
    </div>

    @if (portal() === 'staff')
        {{-- 自分の担当課題(社内メンバーのみ) --}}
        <section class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-body d-flex align-items-center justify-content-between">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-person-check me-1"></i>自分が担当の未完了課題</h2>
                <span class="badge text-bg-secondary rounded-pill">{{ $myIssues->count() }}</span>
            </div>
            <div class="card-body p-0">
                <x-issue-list :issues="$myIssues" :show-project="true" empty="担当している未完了の課題はありません。" />
            </div>
        </section>
    @endif

    {{-- プロジェクト一覧 --}}
    <section class="mb-4">
        <h2 class="h6 fw-bold mb-2"><i class="bi bi-folder2-open me-1"></i>プロジェクト</h2>
        @if ($projects->isEmpty())
            <div class="card shadow-sm border-0">
                <div class="card-body text-center text-muted py-5">
                    @if (portal() === 'staff')
                        プロジェクトがまだありません。
                        <div class="mt-3">
                            <a href="{{ route('staff.projects.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>プロジェクトを作成</a>
                        </div>
                    @else
                        参加しているプロジェクトがありません。担当者にお問い合わせください。
                    @endif
                </div>
            </div>
        @else
            <div class="row g-3">
                @foreach ($projects as $project)
                    <div class="col-12 col-md-6 col-xl-4">
                        <div class="card shadow-sm border-0 h-100 project-card">
                            <div class="card-body">
                                <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                                    <div class="min-w-0">
                                        <div class="small text-muted">{{ $project->key }}</div>
                                        <h3 class="h6 fw-bold mb-0 text-truncate">
                                            <a href="{{ portal_route('issues.index', $project) }}" class="stretched-link text-body text-decoration-none">{{ $project->name }}</a>
                                        </h3>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2 small">
                                    @foreach (\App\Enums\IssueStatus::cases() as $status)
                                        <span class="status-count">
                                            <x-status-badge :status="$status" />
                                            <span class="fw-bold ms-1">{{ $project->status_counts[$status->value] }}</span>
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                            <div class="card-footer bg-body border-0 pt-0 d-flex gap-2 position-relative z-2">
                                <a href="{{ portal_route('issues.index', $project) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-list-ul me-1"></i>課題一覧
                                </a>
                                <a href="{{ portal_route('issues.create', $project) }}" class="btn btn-sm btn-primary">
                                    <i class="bi bi-plus-lg me-1"></i>課題を追加
                                </a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- 最近更新された課題 --}}
    <section class="card shadow-sm border-0">
        <div class="card-header bg-body">
            <h2 class="h6 fw-bold mb-0"><i class="bi bi-clock-history me-1"></i>最近更新された課題</h2>
        </div>
        <div class="card-body p-0">
            <x-issue-list :issues="$recentIssues" :show-project="true" empty="まだ課題はありません。" />
        </div>
    </section>
@endsection
