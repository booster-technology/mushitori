{{--
    共通の確認ダイアログ。フォームに data-confirm="メッセージ" を付けると、送信前にこのダイアログを表示する。
    data-confirm-ok="ボタン名" / data-confirm-variant="danger" でボタンの文言・色を変えられる。
    表示する内容は <body x-data="app">(public/js/app.js)が設定し、開閉は Bootstrap のモーダルで行う
--}}
<div class="modal fade" id="confirmModal" x-ref="confirmModal" @hidden-bs-modal.dot="cancelConfirm"
     tabindex="-1" aria-labelledby="confirmModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-6 fw-bold" id="confirmModalTitle">確認</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0 text-pre-line" x-text="confirmMessage"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">キャンセル</button>
                <button type="button" :class="confirmButtonClass" @click="acceptConfirm" x-text="confirmOk">OK</button>
            </div>
        </div>
    </div>
</div>

{{-- 添付画像の拡大表示(添付一覧の画像をクリックすると開く) --}}
<div class="modal fade" id="imageModal" x-ref="imageModal" @hidden-bs-modal.dot="clearImage"
     tabindex="-1" aria-label="画像の拡大表示" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <span class="modal-title small text-truncate" x-text="imageTitle"></span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body text-center bg-body-tertiary p-2">
                <img :src="imageSrc" alt="" class="img-fluid image-modal-img">
            </div>
            <div class="modal-footer py-2">
                <a :href="imageSrc" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-box-arrow-up-right me-1"></i>元のサイズで開く
                </a>
            </div>
        </div>
    </div>
</div>
