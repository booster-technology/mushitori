{{--
    課題の一覧表示。PC(幅992px以上)は表形式、タブレット・スマートフォンはカード形式で表示する(8列の表はタブレット幅に収まらないため)。
    $showProject: プロジェクト名を表示するか(ホーム画面など複数プロジェクトをまたぐ一覧用)
--}}
@props(['issues', 'showProject' => false, 'empty' => '該当する課題はありません。'])

@if ($issues->isEmpty())
    <div class="text-center text-muted py-5">
        <i class="bi bi-inbox fs-1 d-block mb-2"></i>{{ $empty }}
    </div>
@else
    {{-- PC: 表形式(更新日時の列は幅1200px以上のみ) --}}
    <div class="table-responsive d-none d-lg-block">
        <table class="table table-hover align-middle issue-table mb-0">
            <thead>
                <tr>
                    <th class="col-type">種別</th>
                    <th class="col-key">キー</th>
                    <th>件名</th>
                    <th class="col-assignee">担当者</th>
                    <th class="col-status">状態</th>
                    <th class="col-priority">優先度</th>
                    <th class="col-date">期限日</th>
                    <th class="col-date d-none d-xl-table-cell">更新日時</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($issues as $issue)
                    @php($url = portal_route('issues.show', $issue))
                    <tr class="clickable-row" data-href="{{ $url }}" @click="openRow">
                        <td><x-type-badge :type="$issue->type" /></td>
                        <td class="text-nowrap"><a href="{{ $url }}" class="issue-key">{{ $issue->issueKey() }}</a></td>
                        <td class="issue-title-cell">
                            @if ($showProject)
                                <div class="small text-muted text-truncate">{{ $issue->project->name }}</div>
                            @endif
                            <a href="{{ $url }}" class="issue-title-link">{{ $issue->title }}</a>
                            @if (($issue->all_attachments_count ?? 0) > 0)
                                <span class="text-muted small ms-1 text-nowrap" title="添付ファイル"><i class="bi bi-paperclip"></i>{{ $issue->all_attachments_count }}</span>
                            @endif
                            @if (($issue->comments_count ?? 0) > 0)
                                <span class="text-muted small ms-1 text-nowrap" title="コメント"><i class="bi bi-chat-left-text"></i>{{ $issue->comments_count }}</span>
                            @endif
                        </td>
                        <td><x-user-chip :user="$issue->assignee" class="small" /></td>
                        <td><x-status-badge :status="$issue->status" /></td>
                        <td class="small"><x-priority :priority="$issue->priority" /></td>
                        <td class="small text-nowrap @if ($issue->isOverdue()) text-danger fw-bold @endif">
                            {{ $issue->due_date?->format('Y/m/d') ?? '-' }}
                        </td>
                        <td class="small text-nowrap text-muted d-none d-xl-table-cell">{{ $issue->updated_at->format('Y/m/d H:i') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- タブレット・スマートフォン: カード形式 --}}
    <div class="list-group list-group-flush d-lg-none issue-cards">
        @foreach ($issues as $issue)
            <a href="{{ portal_route('issues.show', $issue) }}" class="list-group-item list-group-item-action py-3">
                <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
                    <x-type-badge :type="$issue->type" />
                    <x-status-badge :status="$issue->status" />
                    <span class="issue-key small ms-1">{{ $issue->issueKey() }}</span>
                    <x-priority :priority="$issue->priority" class="small ms-auto" />
                </div>
                @if ($showProject)
                    <div class="small text-muted">{{ $issue->project->name }}</div>
                @endif
                <div class="fw-semibold text-body">{{ $issue->title }}</div>
                <div class="d-flex flex-wrap gap-3 small text-muted mt-1">
                    <span><i class="bi bi-person me-1"></i>{{ $issue->assignee?->name ?? '未設定' }}</span>
                    @if ($issue->due_date)
                        <span class="@if ($issue->isOverdue()) text-danger fw-bold @endif"><i class="bi bi-calendar-event me-1"></i>{{ $issue->due_date->format('Y/m/d') }}</span>
                    @endif
                    <span><i class="bi bi-clock me-1"></i>{{ $issue->updated_at->format('m/d H:i') }}</span>
                    @if (($issue->all_attachments_count ?? 0) > 0)
                        <span><i class="bi bi-paperclip"></i>{{ $issue->all_attachments_count }}</span>
                    @endif
                </div>
            </a>
        @endforeach
    </div>
@endif
