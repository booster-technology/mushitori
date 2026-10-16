{{--
    使い方(ヘルプ)の画面の枠組み。左(スマホでは上)に目次、右に本文を表示する
    $sections: 目次 [アンカー名 => 見出し]
--}}
@props(['title', 'lead' => null, 'sections' => []])
<div class="mb-3">
    <h1 class="h4 fw-bold mb-1"><i class="bi bi-question-circle me-1"></i>{{ $title }}</h1>
    @if ($lead)<p class="text-muted mb-0">{{ $lead }}</p>@endif
</div>

{{ $before ?? '' }}

<div class="row g-3 g-lg-4">
    <div class="col-12 col-lg-3">
        <nav class="help-toc card shadow-sm border-0" aria-label="目次">
            <div class="card-body py-2">
                <div class="small fw-bold text-muted mb-1">目次</div>
                <ol class="list-unstyled mb-0 small">
                    @foreach ($sections as $anchor => $heading)
                        <li><a class="d-block py-1 text-decoration-none" href="#{{ $anchor }}">{{ $loop->iteration }}. {{ $heading }}</a></li>
                    @endforeach
                </ol>
            </div>
        </nav>
    </div>
    <div class="col-12 col-lg-9 help-body">
        {{ $slot }}
    </div>
</div>
