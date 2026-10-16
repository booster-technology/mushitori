@extends('layouts.app')

@section('title', 'プロジェクト管理')

@section('content')
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h1 class="h4 fw-bold mb-0 me-auto">プロジェクト管理</h1>
        <a href="{{ route('staff.projects.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>プロジェクトを作成</a>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            @if ($projects->isEmpty())
                <div class="text-center text-muted py-5">プロジェクトがまだありません。</div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>キー</th>
                                <th>プロジェクト名</th>
                                <th class="text-end d-none d-sm-table-cell">メンバー</th>
                                <th class="text-end d-none d-sm-table-cell">未完了の課題</th>
                                <th class="text-end">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($projects as $project)
                                <tr>
                                    <td class="text-nowrap fw-semibold">{{ $project->key }}</td>
                                    <td>
                                        <a href="{{ route('staff.issues.index', $project) }}">{{ $project->name }}</a>
                                        <div class="d-sm-none small text-muted">メンバー{{ $project->members_count }}人・未完了{{ $project->open_issues_count }}件</div>
                                    </td>
                                    <td class="text-end d-none d-sm-table-cell">{{ $project->members_count }}人</td>
                                    <td class="text-end d-none d-sm-table-cell">{{ $project->open_issues_count }}件</td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('staff.projects.edit', $project) }}" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-gear me-1"></i>設定
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    @if ($projects->hasPages())
        <div class="mt-3 d-flex justify-content-center">{{ $projects->links() }}</div>
    @endif
@endsection
