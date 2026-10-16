{{-- 処理結果のメッセージ表示 --}}
@if (session('success'))
    <div class="alert alert-success alert-dismissible fade show d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-check-circle-fill"></i>
        <div>{{ session('success') }}</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="閉じる"></button>
    </div>
@endif
@if (session('status'))
    <div class="alert alert-info alert-dismissible fade show d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-info-circle-fill"></i>
        <div>{{ session('status') }}</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="閉じる"></button>
    </div>
@endif
@if (session('error'))
    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>{{ session('error') }}</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="閉じる"></button>
    </div>
@endif
@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>入力内容を確認してください</div>
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
