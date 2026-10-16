@extends('layouts.app')

@section('title', '課題の追加')

@section('content')
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <x-back-button :href="portal_route('issues.index', $project)" label="課題一覧" class="btn-sm" />
        <div class="min-w-0">
            <div class="small text-muted">{{ $project->name }}</div>
            <h1 class="h4 fw-bold mb-0">課題の追加</h1>
        </div>
    </div>

    {{-- 種別を切り替えたら、詳細のテンプレートも切り替える(public/js/app.js の issueTemplates) --}}
    <form method="POST" action="{{ portal_route('issues.store', $project) }}" enctype="multipart/form-data" x-data="issueTemplates"
          data-confirm="この内容で課題を登録しますか？&#10;登録すると関係者にメールで通知されます。" data-confirm-ok="登録する">
        @csrf
        <div class="row g-3">
            <div class="col-12 col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">種別 <span class="badge text-bg-danger">必須</span></label>
                            <div class="d-flex flex-wrap gap-2" role="radiogroup">
                                @foreach (\App\Enums\IssueType::cases() as $type)
                                    <input type="radio" class="btn-check" name="type" id="type-{{ $type->value }}" value="{{ $type->value }}"
                                           @change="applyTemplate" @checked(old('type', $defaultType->value) === $type->value)>
                                    <label class="btn btn-outline-secondary" for="type-{{ $type->value }}">{{ $type->label() }}</label>
                                @endforeach
                            </div>
                            @error('type')<div class="small text-danger mt-1">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="title" class="form-label fw-semibold">件名 <span class="badge text-bg-danger">必須</span></label>
                            <input type="text" id="title" name="title" value="{{ old('title') }}" maxlength="200"
                                   class="form-control @error('title') is-invalid @enderror"
                                   placeholder="例）ログイン後にトップページが真っ白になる" required>
                            @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label fw-semibold">詳細</label>
                            <div class="form-text mt-0 mb-1">どの画面で・どんな操作をしたら・どうなったかを書いていただけると、早く対応できます。</div>
                            <textarea id="description" name="description" rows="12"
                                      class="form-control @error('description') is-invalid @enderror"
                                      x-ref="description" data-templates='@json($templates)'>{{ old('description', $defaultType->descriptionTemplate()) }}</textarea>
                            @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div>
                            <label class="form-label fw-semibold" for="attachments">スクリーンショット・添付ファイル</label>
                            <x-attachment-input />
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-4">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="priority" class="form-label fw-semibold">優先度</label>
                            <select id="priority" name="priority" class="form-select">
                                @foreach (\App\Enums\IssuePriority::cases() as $priority)
                                    <option value="{{ $priority->value }}" @selected(old('priority', $defaultPriority->value) === $priority->value)>{{ $priority->label() }}</option>
                                @endforeach
                            </select>
                        </div>

                        @if (portal() === 'staff')
                            <div class="mb-3">
                                <label for="assignee_id" class="form-label fw-semibold">担当者</label>
                                <select id="assignee_id" name="assignee_id" class="form-select @error('assignee_id') is-invalid @enderror">
                                    <option value="">未設定</option>
                                    @foreach ($staffUsers as $staff)
                                        <option value="{{ $staff->id }}" @selected((string) old('assignee_id') === (string) $staff->id)>{{ $staff->name }}</option>
                                    @endforeach
                                </select>
                                @error('assignee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-3">
                                <label for="due_date" class="form-label fw-semibold">期限日</label>
                                <input type="date" id="due_date" name="due_date" value="{{ old('due_date') }}"
                                       class="form-control @error('due_date') is-invalid @enderror">
                                @error('due_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        @else
                            <p class="small text-muted mb-3">担当者・期限日は弊社にて設定します。</p>
                        @endif

                        <button type="submit" class="btn btn-primary btn-lg w-100">
                            <i class="bi bi-send me-1"></i>登録する
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection
