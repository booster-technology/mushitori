{{-- ページング(件数表示は各画面で日本語表記しているため、ここではページ番号のみ表示する) --}}
@if ($paginator->hasPages())
    <nav aria-label="ページ切り替え">
        {{-- スマートフォン: 前へ／次へ のみ --}}
        <ul class="pagination d-sm-none mb-0">
            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true"><span class="page-link">&lsaquo; 前へ</span></li>
            @else
                <li class="page-item"><a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">&lsaquo; 前へ</a></li>
            @endif
            <li class="page-item disabled"><span class="page-link">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span></li>
            @if ($paginator->hasMorePages())
                <li class="page-item"><a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">次へ &rsaquo;</a></li>
            @else
                <li class="page-item disabled" aria-disabled="true"><span class="page-link">次へ &rsaquo;</span></li>
            @endif
        </ul>

        {{-- PC・タブレット: ページ番号 --}}
        <ul class="pagination d-none d-sm-flex mb-0">
            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true" aria-label="前へ"><span class="page-link" aria-hidden="true">&lsaquo;</span></li>
            @else
                <li class="page-item"><a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="前へ">&lsaquo;</a></li>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="page-item disabled" aria-disabled="true"><span class="page-link">{{ $element }}</span></li>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li class="page-item active" aria-current="page"><span class="page-link">{{ $page }}</span></li>
                        @else
                            <li class="page-item"><a class="page-link" href="{{ $url }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li class="page-item"><a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="次へ">&rsaquo;</a></li>
            @else
                <li class="page-item disabled" aria-disabled="true" aria-label="次へ"><span class="page-link" aria-hidden="true">&rsaquo;</span></li>
            @endif
        </ul>
    </nav>
@endif
