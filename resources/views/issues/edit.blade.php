@extends('layouts.app')

@section('title', $issue->issueKey().' の編集')

@section('content')
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <x-back-button :href="portal_route('issues.show', $issue)" label="課題に戻る" class="btn-sm" />
        <div class="min-w-0">
            <div class="small text-muted">{{ $issue->project->name }} / {{ $issue->issueKey() }}</div>
            <h1 class="h4 fw-bold mb-0">課題の編集</h1>
        </div>
    </div>

    <form method="POST" action="{{ portal_route('issues.update', $issue) }}" enctype="multipart/form-data"
          data-confirm="課題の内容を更新しますか？" data-confirm-ok="更新する">
        @csrf
        @method('PUT')
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">種別 <span class="badge text-bg-danger">必須</span></label>
                    <div class="d-flex flex-wrap gap-2" role="radiogroup">
                        @foreach (\App\Enums\IssueType::cases() as $type)
                            <input type="radio" class="btn-check" name="type" id="type-{{ $type->value }}" value="{{ $type->value }}"
                                   @checked(old('type', $issue->type->value) === $type->value)>
                            <label class="btn btn-outline-secondary" for="type-{{ $type->value }}">{{ $type->label() }}</label>
                        @endforeach
                    </div>
                </div>

                <div class="mb-3">
                    <label for="title" class="form-label fw-semibold">件名 <span class="badge text-bg-danger">必須</span></label>
                    <input type="text" id="title" name="title" value="{{ old('title', $issue->title) }}" maxlength="200"
                           class="form-control @error('title') is-invalid @enderror" required>
                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label for="description" class="form-label fw-semibold">詳細</label>
                    <textarea id="description" name="description" rows="12"
                              class="form-control @error('description') is-invalid @enderror">{{ old('description', $issue->description) }}</textarea>
                    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                @if ($issue->attachments->isNotEmpty())
                    <div class="mb-3">
                        <div class="form-label fw-semibold">添付済みのファイル</div>
                        <div class="row g-2">
                            @foreach ($issue->attachments as $attachment)
                                @php($canRemove = portal() === 'staff' || $attachment->user_id === auth()->id())
                                <div class="col-6 col-sm-4 col-lg-3">
                                    <div class="border rounded p-2 h-100">
                                        @if ($attachment->isImage())
                                            <img src="{{ portal_route('attachments.thumbnail', $attachment) }}" alt="" class="img-fluid rounded mb-1 edit-thumb">
                                        @else
                                            <div class="text-center fs-2 text-secondary"><i class="bi {{ $attachment->icon() }}"></i></div>
                                        @endif
                                        <div class="small text-truncate" title="{{ $attachment->original_name }}">{{ $attachment->original_name }}</div>
                                        @if ($canRemove)
                                            <div class="form-check small mt-1">
                                                <input class="form-check-input" type="checkbox" name="remove_attachments[]"
                                                       value="{{ $attachment->id }}" id="remove-{{ $attachment->id }}">
                                                <label class="form-check-label text-danger" for="remove-{{ $attachment->id }}">削除する</label>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="mb-4">
                    <label class="form-label fw-semibold" for="attachments">ファイルを追加</label>
                    <x-attachment-input />
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>更新する</button>
                    <a href="{{ portal_route('issues.show', $issue) }}" class="btn btn-outline-secondary">キャンセル</a>
                </div>
                <p class="small text-muted mt-3 mb-0">
                    状態・担当者・優先度・期限日は、課題画面のコメント欄から変更できます（変更履歴が残ります）。
                </p>
            </div>
        </div>
    </form>
@endsection
