@extends('layouts.app')

@section('title', 'ゴミ箱')

@section('content')
    <div class="mb-3">
        <h1 class="h4 fw-bold mb-1"><i class="bi bi-trash me-1"></i>ゴミ箱</h1>
        <p class="text-muted small mb-0">
            @if ($retentionDays > 0)
                削除したプロジェクト・課題・コメント・添付ファイルは、{{ $retentionDays }}日間ここに保管され、その後自動で完全に削除されます（画像などの添付ファイルもサーバーから削除されます）。
            @else
                削除したデータは自動では削除されません（.env の TRASH_RETENTION_DAYS=0）。
            @endif
            個人情報を誤って添付した場合などは、「完全に削除」ですぐに削除できます。
        </p>
    </div>

    {{-- 種類のタブ --}}
    <ul class="nav nav-pills status-tabs flex-nowrap overflow-auto mb-3">
        @foreach ($types as $key => $label)
            <li class="nav-item">
                <a class="nav-link text-nowrap @if ($type === $key) active @endif" href="{{ route('staff.trash.index', ['type' => $key]) }}">
                    {{ $label }}<span class="badge rounded-pill text-bg-light ms-1">{{ $counts[$key] }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="card shadow-sm border-0">
        @if ($items->isEmpty())
            <div class="card-body text-center text-muted py-5">削除された{{ $types[$type] }}はありません。</div>
        @else
            <ul class="list-group list-group-flush">
                @foreach ($items as $item)
                    @php
                        // 完全に削除するときに一緒に消えるもの(確認ダイアログで知らせる)
                        $purgeNote = match ($type) {
                            'projects' => 'このプロジェクトの課題・コメント・添付ファイルもすべて完全に削除されます。',
                            'issues' => 'この課題のコメント・添付ファイルもすべて完全に削除されます。',
                            'comments' => '本文と添付ファイルを完全に削除します（状態などの変更履歴は残ります）。',
                            'attachments' => 'ファイルの実体もサーバーから削除されます。',
                        };
                    @endphp
                    <li class="list-group-item py-3">
                        <div class="d-flex flex-column flex-md-row gap-2 align-items-md-center">
                            <div class="flex-grow-1 min-w-0">
                                {{-- 名前 --}}
                                <div class="fw-semibold text-break">
                                    @switch($type)
                                        @case('comments')
                                            {{ \Illuminate\Support\Str::limit($item->body ?? '（本文なし・状態などの変更のみ）', 80) }}
                                            @break
                                        @case('attachments')
                                            <i class="bi bi-paperclip me-1"></i>{{ $item->original_name }}
                                            <span class="text-muted small fw-normal">（{{ $item->humanSize() }}）</span>
                                            @break
                                        @default
                                            {{ $item->trash_label }}
                                    @endswitch
                                </div>

                                {{-- どこのデータか --}}
                                <div class="small text-muted">
                                    @switch($type)
                                        @case('issues')
                                            プロジェクト: {{ $item->project?->name }}
                                            @if ($item->project?->trashed())<span class="badge text-bg-secondary ms-1">削除済み</span>@endif
                                            @break
                                        @case('comments')
                                        @case('attachments')
                                            課題: {{ $item->issue ? ($item->issue->project?->key.'-'.$item->issue->number.' '.$item->issue->title) : '' }}
                                            @if ($item->issue?->trashed())<span class="badge text-bg-secondary ms-1">削除済み</span>@endif
                                            ／ {{ $type === 'comments' ? '投稿' : '添付' }}: {{ $item->user?->displayName() }}
                                            @if ($type === 'comments' && $item->attachments_count > 0)／ 添付 {{ $item->attachments_count }}件@endif
                                            @break
                                        @case('projects')
                                            課題 {{ $item->issues_count }}件
                                            @break
                                    @endswitch
                                </div>

                                {{-- 削除した日時・人と、自動で完全に削除される日 --}}
                                <div class="small text-muted">
                                    削除: {{ $item->deleted_at->format('Y/m/d H:i') }}@if ($item->deletedBy)（{{ $item->deletedBy->displayName() }}）@endif
                                    ／
                                    @if ($item->purges_at)
                                        {{ $item->purges_at->format('Y/m/d') }}に完全に削除されます
                                    @else
                                        自動では削除されません
                                    @endif
                                </div>
                            </div>

                            <div class="d-flex gap-2 flex-shrink-0">
                                <form method="POST" action="{{ route('staff.trash.restore', [$type, $item->id]) }}" class="m-0"
                                      data-confirm="「{{ $item->trash_label }}」を元に戻しますか？" data-confirm-ok="元に戻す">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-arrow-counterclockwise me-1"></i>元に戻す</button>
                                </form>
                                <form method="POST" action="{{ route('staff.trash.destroy', [$type, $item->id]) }}" class="m-0"
                                      data-confirm="「{{ $item->trash_label }}」を完全に削除しますか？&#10;{{ $purgeNote }}&#10;この操作は取り消せません。"
                                      data-confirm-ok="完全に削除する" data-confirm-variant="danger">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger text-nowrap"><i class="bi bi-x-octagon me-1"></i>完全に削除</button>
                                </form>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($items->hasPages())
        <div class="mt-3 d-flex justify-content-center">{{ $items->links() }}</div>
    @endif
@endsection
