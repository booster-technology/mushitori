{{--
    添付ファイルの入力欄。クリックでの選択に加えて、ドラッグ＆ドロップ・クリップボードからの貼り付け(Ctrl+V)に対応する。
    大きな画像は送信前にブラウザで縮小する。動作は public/js/app.js の attachmentInput を参照
--}}
@props(['name' => 'attachments[]', 'id' => 'attachments'])
@php
    $maxFiles = \App\Services\AttachmentService::MAX_FILES;
    $maxSizeKb = \App\Services\AttachmentService::MAX_SIZE_KB;
    $extensions = \App\Services\AttachmentService::ALLOWED_EXTENSIONS;
@endphp
<div class="attachment-input" x-data="attachmentInput" :data-busy="busy" @attachment-busy="waitForShrink"
     data-max-files="{{ $maxFiles }}"
     data-max-size="{{ $maxSizeKb * 1024 }}"
     data-extensions="{{ implode(',', $extensions) }}"
     data-max-image-edge="{{ \App\Services\AttachmentService::MAX_IMAGE_EDGE }}">
    <input type="file" id="{{ $id }}" name="{{ $name }}" multiple class="visually-hidden" x-ref="input" @change="onSelect"
           accept="image/*,{{ collect($extensions)->map(fn ($e) => '.'.$e)->implode(',') }}">
    <div class="dropzone" :class="dropzoneClass" tabindex="0" role="button" aria-controls="{{ $id }}"
         aria-label="添付ファイルを選択、またはドラッグ＆ドロップ"
         @click="openPicker" @keydown.enter.prevent="openPicker" @keydown.space.prevent="openPicker"
         @dragenter.prevent="startDrag" @dragover.prevent="startDrag" @dragleave="endDrag" @dragend="endDrag"
         @drop.prevent="onDrop">
        <i class="bi bi-cloud-arrow-up fs-2 text-secondary"></i>
        <div class="fw-semibold d-none d-md-block">ファイルをドラッグ＆ドロップ、または<span class="text-primary">クリックして選択</span></div>
        <div class="fw-semibold d-md-none text-primary">タップしてファイル・写真を選択</div>
        <div class="small text-muted d-none d-md-block">
            スクリーンショットは <kbd>Ctrl</kbd>+<kbd>V</kbd>（Macは <kbd>⌘</kbd>+<kbd>V</kbd>）でそのまま貼り付けできます
        </div>
        <div class="small text-muted">
            最大{{ $maxFiles }}ファイル・1ファイル{{ intdiv($maxSizeKb, 1024) }}MBまで（画像・PDF・Office・テキスト・ZIP・動画）
        </div>
        <div class="small text-muted">
            長辺{{ \App\Services\AttachmentService::MAX_IMAGE_EDGE }}pxを超える画像（スマホの写真など）は自動で縮小します（10MBを超える写真も縮小できれば添付できます）
        </div>
    </div>
    <div class="attachment-status small text-muted mt-1" aria-live="polite" x-text="statusText"></div>
    <div class="attachment-error small text-danger mt-1" role="alert" x-text="errorText"></div>

    {{-- 選択中のファイルのプレビュー --}}
    <div class="attachment-previews row g-2 mt-1">
        <template x-for="(item, index) in items" :key="item.id">
            <div class="col-6 col-sm-4 col-lg-3">
                <div class="attachment-preview">
                    <template x-if="item.isImage">
                        <img class="preview-image" alt="" :src="item.url">
                    </template>
                    <template x-if="!item.isImage">
                        <div class="preview-icon"><i class="bi bi-file-earmark"></i></div>
                    </template>
                    <div class="small text-truncate mt-1" :title="item.name" x-text="item.name"></div>
                    <div class="text-muted fs-xs" x-text="item.size"></div>
                    <button type="button" class="preview-remove" :aria-label="item.removeLabel" @click="remove(index)">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>
        </template>
    </div>
    @error('attachments')<div class="small text-danger mt-1">{{ $message }}</div>@enderror
    @error('attachments.*')<div class="small text-danger mt-1">{{ $message }}</div>@enderror
</div>
