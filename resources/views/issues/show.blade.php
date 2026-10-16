@extends('layouts.app')

@section('title', $issue->issueKey().' '.$issue->title)

@section('content')
    <div class="issue-detail mx-auto">
        {{-- 上部の操作ボタン --}}
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <x-back-button :href="$backUrl" label="課題一覧に戻る" class="btn-sm" />
            <span class="small text-muted text-truncate">{{ $issue->project->name }}</span>
            <div class="ms-auto d-flex gap-2">
                @can('update', $issue)
                    <a href="{{ portal_route('issues.edit', $issue) }}" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-pencil me-1"></i>編集
                    </a>
                @endcan
                @can('delete', $issue)
                    <form method="POST" action="{{ portal_route('issues.destroy', $issue) }}" class="m-0"
                          data-confirm="課題「{{ $issue->issueKey() }} {{ $issue->title }}」を削除しますか？&#10;削除した課題は一覧に表示されなくなります。"
                          data-confirm-ok="削除する" data-confirm-variant="danger">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>削除</button>
                    </form>
                @endcan
            </div>
        </div>

        {{-- 課題の本体 --}}
        <article class="card shadow-sm border-0 mb-4">
            <div class="card-body p-3 p-md-4">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <x-type-badge :type="$issue->type" />
                    <span class="issue-key">{{ $issue->issueKey() }}</span>
                    <x-status-badge :status="$issue->status" class="ms-auto fs-6" />
                </div>
                <h1 class="h4 fw-bold mb-3 text-break">{{ $issue->title }}</h1>

                <div class="d-flex flex-wrap align-items-center gap-2 small text-muted mb-3">
                    <x-user-chip :user="$issue->reporter" :company="true" />
                    <span>が {{ $issue->created_at->format('Y/m/d H:i') }} に登録</span>
                </div>

                {{-- 属性 --}}
                <dl class="row issue-props g-0 mb-4">
                    <div class="col-6 col-md-3">
                        <dt>優先度</dt>
                        <dd><x-priority :priority="$issue->priority" /></dd>
                    </div>
                    <div class="col-6 col-md-3">
                        <dt>担当者</dt>
                        <dd><x-user-chip :user="$issue->assignee" /></dd>
                    </div>
                    <div class="col-6 col-md-3">
                        <dt>期限日</dt>
                        <dd class="@if ($issue->isOverdue()) text-danger fw-bold @endif">
                            {{ $issue->due_date?->format('Y/m/d') ?? '未設定' }}
                            @if ($issue->isOverdue())<i class="bi bi-exclamation-circle ms-1" title="期限切れ"></i>@endif
                        </dd>
                    </div>
                    <div class="col-6 col-md-3">
                        <dt>最終更新</dt>
                        <dd>{{ $issue->updated_at->format('Y/m/d H:i') }}</dd>
                    </div>
                </dl>

                <div class="issue-body text-break">
                    @if ($issue->description)
                        {!! format_text($issue->description) !!}
                    @else
                        <span class="text-muted">（詳細の記載はありません）</span>
                    @endif
                </div>

                @if ($issue->attachments->isNotEmpty())
                    <h2 class="h6 fw-bold mt-4 mb-2"><i class="bi bi-paperclip me-1"></i>添付ファイル（{{ $issue->attachments->count() }}）</h2>
                    <x-attachment-grid :attachments="$issue->attachments" />
                @endif
            </div>
        </article>

        {{-- コメント・変更履歴 --}}
        <section class="mb-4">
            <h2 class="h6 fw-bold mb-3"><i class="bi bi-chat-left-text me-1"></i>コメント（{{ $issue->comments->whereNull('deleted_at')->count() }}）</h2>

            @forelse ($issue->comments as $comment)
                <div class="comment card border-0 shadow-sm mb-3 @if ($comment->trashed()) comment-deleted @endif" id="comment-{{ $comment->id }}">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="avatar {{ $comment->user->isStaff() ? 'avatar-staff' : 'avatar-customer' }}">{{ $comment->user->initial() }}</span>
                            <div class="min-w-0">
                                <div class="fw-semibold small text-truncate">
                                    {{ $comment->user->displayName() }}
                                    @if ($comment->user->isStaff())
                                        <span class="badge rounded-pill text-bg-dark ms-1">社内</span>
                                    @endif
                                </div>
                                <div class="text-muted fs-xs">{{ $comment->created_at->format('Y/m/d H:i') }}</div>
                            </div>
                            @if (! $comment->trashed())
                                @can('delete', $comment)
                                    <form method="POST" action="{{ portal_route('comments.destroy', $comment) }}" class="ms-auto m-0"
                                          data-confirm="このコメントを削除しますか？&#10;本文と添付ファイルは表示されなくなります（状態などの変更履歴は残ります）。"
                                          data-confirm-ok="削除する" data-confirm-variant="danger">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-link link-secondary p-0" title="コメントを削除">
                                            <i class="bi bi-trash"></i><span class="visually-hidden">削除</span>
                                        </button>
                                    </form>
                                @endcan
                            @endif
                        </div>

                        @if ($comment->change_log)
                            <ul class="change-log list-unstyled small mb-2">
                                @foreach (\App\Support\ChangeLogFormatter::items($comment->change_log) as $item)
                                    <li>
                                        <span class="change-label">{{ $item['label'] }}</span>
                                        <span class="text-muted">{{ $item['from'] }}</span>
                                        <i class="bi bi-arrow-right mx-1"></i>
                                        <span class="fw-semibold">{{ $item['to'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($comment->trashed())
                            <div class="small text-muted fst-italic">（このコメントは削除されました）</div>
                        @elseif ($comment->body)
                            <div class="comment-body text-break">{!! format_text($comment->body) !!}</div>
                        @endif

                        @if (! $comment->trashed() && $comment->attachments->isNotEmpty())
                            <x-attachment-grid :attachments="$comment->attachments" class="mt-2" />
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-muted small">まだコメントはありません。</p>
            @endforelse
        </section>

        {{-- コメント投稿・状態変更 --}}
        <section class="card shadow-sm border-0 mb-4" id="comment-form">
            <div class="card-body p-3 p-md-4">
                <h2 class="h6 fw-bold mb-3"><i class="bi bi-reply me-1"></i>コメント・状態の変更</h2>
                <form method="POST" action="{{ portal_route('comments.store', $issue) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label for="body" class="visually-hidden">コメント</label>
                        <textarea id="body" name="body" rows="5" class="form-control @error('body') is-invalid @enderror"
                                  placeholder="コメントを入力（確認結果、追加の情報、ご質問など）">{{ old('body') }}</textarea>
                        @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <x-attachment-input id="comment-attachments" />
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6 col-md-3">
                            <label for="status" class="form-label small mb-1">状態</label>
                            <select id="status" name="status" class="form-select form-select-sm">
                                @foreach (\App\Enums\IssueStatus::cases() as $status)
                                    <option value="{{ $status->value }}" @selected(old('status', $issue->status->value) === $status->value)>{{ $status->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        @can('manage', $issue)
                            <div class="col-6 col-md-3">
                                <label for="assignee_id" class="form-label small mb-1">担当者</label>
                                <select id="assignee_id" name="assignee_id" class="form-select form-select-sm">
                                    <option value="">未設定</option>
                                    @foreach ($staffUsers as $staff)
                                        <option value="{{ $staff->id }}" @selected((string) old('assignee_id', $issue->assignee_id) === (string) $staff->id)>{{ $staff->name }}</option>
                                    @endforeach
                                    {{-- 現在の担当者が無効化された場合も選択肢に残す(コメントしただけで担当者が外れないように) --}}
                                    @if ($issue->assignee && ! $staffUsers->contains('id', $issue->assignee_id))
                                        <option value="{{ $issue->assignee_id }}" @selected((string) old('assignee_id', $issue->assignee_id) === (string) $issue->assignee_id)>{{ $issue->assignee->name }}（無効）</option>
                                    @endif
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="priority" class="form-label small mb-1">優先度</label>
                                <select id="priority" name="priority" class="form-select form-select-sm">
                                    @foreach (\App\Enums\IssuePriority::cases() as $priority)
                                        <option value="{{ $priority->value }}" @selected(old('priority', $issue->priority->value) === $priority->value)>{{ $priority->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="due_date" class="form-label small mb-1">期限日</label>
                                <input type="date" id="due_date" name="due_date" class="form-control form-control-sm"
                                       value="{{ old('due_date', $issue->due_date?->format('Y-m-d')) }}">
                            </div>
                        @endcan
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>コメントする</button>
                        <span class="small text-muted">起票者・担当者・コメントした方にメールで通知されます。</span>
                    </div>
                </form>
            </div>
        </section>

        <div class="mb-4">
            <x-back-button :href="$backUrl" label="課題一覧に戻る" />
        </div>
    </div>
@endsection
