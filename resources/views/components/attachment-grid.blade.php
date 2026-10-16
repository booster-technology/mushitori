{{--
    添付ファイルの表示。画像はサムネイル(クリックで拡大)、それ以外はアイコン+ダウンロードリンク
--}}
@props(['attachments'])
@if ($attachments->isNotEmpty())
    <div {{ $attributes->class(['row g-2 attachment-grid']) }}>
        @foreach ($attachments as $attachment)
            <div class="col-6 col-sm-4 col-lg-3">
                @if ($attachment->isImage())
                    <a href="{{ portal_route('attachments.show', $attachment) }}" class="attachment-thumb"
                       @click.prevent="showImage" data-title="{{ $attachment->original_name }}"
                       title="{{ $attachment->original_name }}">
                        <img src="{{ portal_route('attachments.thumbnail', $attachment) }}" alt="{{ $attachment->original_name }}" loading="lazy">
                    </a>
                @else
                    <a href="{{ portal_route('attachments.show', $attachment) }}" class="attachment-file" title="{{ $attachment->original_name }}">
                        <i class="bi {{ $attachment->icon() }}"></i>
                    </a>
                @endif
                <div class="small text-truncate mt-1" title="{{ $attachment->original_name }}">
                    <a href="{{ portal_route('attachments.show', $attachment) }}" class="link-secondary text-decoration-none" @if (! $attachment->isImage()) download @endif>
                        {{ $attachment->original_name }}
                    </a>
                </div>
                <div class="text-muted fs-xs">{{ $attachment->humanSize() }}</div>
            </div>
        @endforeach
    </div>
@endif
