/**
 * ムシトリ 共通スクリプト(Alpine.js のコンポーネント。ビルド不要)
 *
 * - Alpine.js は CSP対応版(@alpinejs/csp)を public/vendor/alpinejs に同梱している(CDNは使わない)。
 *   eval を使わないため、Content-Security-Policy を script-src 'self' のまま緩めずに動く。
 *   その代わり、HTMLの x-○○ / @○○ に書けるのは簡単な式(プロパティ・メソッドの呼び出し・比較など)だけで、
 *   window などのグローバル変数は参照できない。処理はこのファイルのコンポーネントに書き、HTMLからはメソッド名で呼ぶ。
 * - 読み込み順: bootstrap.bundle.min.js → このファイル → alpine-csp.min.js(defer)。コンポーネントは alpine:init で登録する。
 * - モーダルの表示・フォーカスの管理は Bootstrap に任せ、Alpine からは表示する内容と開閉だけを操作する。
 *
 * コンポーネント
 * - app             … <body x-data="app">(全画面)。確認ダイアログ・二重送信の防止・画像の拡大表示・一覧の行クリック
 * - attachmentInput … 添付ファイル入力(ドラッグ＆ドロップ・貼り付け・プレビュー・送信前の画像縮小)
 * - issueTemplates  … 課題の登録: 種別に応じて詳細のテンプレートを切り替える
 * - userForm        … ユーザーの作成・編集: 種別が「お客様」のときだけ会社名を表示する
 */
(() => {
    'use strict';

    /* ------------------------------------------------------------
     * 共通の小さな関数
     * ------------------------------------------------------------ */

    function formatSize(bytes) {
        if (bytes < 1024) {
            return bytes + ' B';
        }
        if (bytes < 1024 * 1024) {
            return (bytes / 1024).toFixed(1) + ' KB';
        }
        return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    }

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    // 貼り付けた画像は「image.png」という名前になるため、日時入りの分かりやすい名前に変える
    function screenshotName(file, index) {
        const d = new Date();
        const ext = (file.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
        return 'screenshot_' + d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + '_'
            + pad(d.getHours()) + pad(d.getMinutes()) + pad(d.getSeconds())
            + (index > 0 ? '_' + (index + 1) : '') + '.' + ext;
    }

    /**
     * 長辺が maxEdge を超える画像を、送信前にブラウザで縮小する(サーバーのメモリ・通信量を節約するため)。
     * 縮小できない・小さくならない場合は元のファイルのまま返す(その場合はサーバー側で縮小する)。
     * 画像の向き(スマホ写真のEXIF)は、ブラウザが表示どおりの向きで描画するので、そのまま正しい向きになる
     */
    function shrinkImage(file, maxEdge) {
        return new Promise((resolve) => {
            const canvas = document.createElement('canvas');
            if (!/^image\/(jpeg|png|webp)$/.test(file.type) || typeof canvas.toBlob !== 'function') {
                resolve(file); // GIF(アニメーション)やその他のファイルはそのまま
                return;
            }

            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => {
                const longest = Math.max(img.naturalWidth, img.naturalHeight);
                if (longest <= maxEdge) {
                    URL.revokeObjectURL(url);
                    resolve(file);
                    return;
                }

                const scale = maxEdge / longest;
                canvas.width = Math.round(img.naturalWidth * scale);
                canvas.height = Math.round(img.naturalHeight * scale);
                const ctx = canvas.getContext('2d');
                ctx.imageSmoothingEnabled = true;
                ctx.imageSmoothingQuality = 'high';
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                URL.revokeObjectURL(url);

                canvas.toBlob((blob) => {
                    canvas.width = canvas.height = 0; // スマホのメモリをすぐ解放する
                    // 同じ形式で保存できなかった(SafariのWebP等)・小さくならなかった場合は元のまま
                    if (!blob || blob.type !== file.type || blob.size >= file.size) {
                        resolve(file);
                        return;
                    }
                    resolve(new File([blob], file.name, {type: file.type, lastModified: Date.now()}));
                }, file.type, 0.88);
            };
            img.onerror = () => {
                URL.revokeObjectURL(url);
                resolve(file);
            };
            img.src = url;
        });
    }

    // Bootstrap のモーダル(読み込めていない環境では null)
    function bootstrapModal(el) {
        return el && window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(el) : null;
    }

    // 送信ボタンを押せないようにして「送信中」表示にする(連打・ダブルクリックによる二重登録を防ぐ)
    function lockSubmitButtons(form) {
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
            button.disabled = true;
            if (button.tagName === 'BUTTON') {
                button.dataset.originalHtml = button.innerHTML;
                button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>送信中…';
            }
        });
    }

    function unlockSubmitButtons(form) {
        delete form.dataset.submitting;
        delete form.dataset.confirmed;
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
            button.disabled = false;
            if (button.dataset.originalHtml) {
                button.innerHTML = button.dataset.originalHtml;
                delete button.dataset.originalHtml;
            }
        });
    }

    document.addEventListener('alpine:init', () => {
        const Alpine = window.Alpine;

        /* ------------------------------------------------------------
         * app: 全画面共通(<body x-data="app">。属性は partials/app-root.blade.php)
         * ------------------------------------------------------------ */
        Alpine.data('app', () => ({
            // 確認ダイアログ(partials/confirm-modal.blade.php)
            confirmMessage: '',
            confirmOk: 'OK',
            confirmDanger: false,
            pendingForm: null,

            // 画像の拡大表示
            imageSrc: null,
            imageTitle: '',

            get confirmButtonClass() {
                return this.confirmDanger ? 'btn btn-danger' : 'btn btn-primary';
            },

            // すべてのフォームの送信をここで受け取る(<body @submit.capture="onSubmit">)
            onSubmit(event) {
                const form = event.target;
                if (!(form instanceof HTMLFormElement)) {
                    return;
                }

                // 添付画像の縮小中は送信しない(縮小前の大きな画像が送られるのを防ぐ)
                const busyInput = form.querySelector('.attachment-input[data-busy]');
                if (busyInput) {
                    event.preventDefault();
                    delete form.dataset.confirmed;
                    busyInput.dispatchEvent(new CustomEvent('attachment-busy'));
                    return;
                }

                // data-confirm があるフォームは、確認ダイアログで「OK」を押してから送信する
                if (form.dataset.confirm && !form.dataset.confirmed) {
                    event.preventDefault();
                    this.openConfirm(form);
                    return;
                }

                // 検索フォームなどGETは対象外
                if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') {
                    return;
                }

                // 二重送信の防止: 1回目の送信以降は止める
                if (form.dataset.submitting) {
                    event.preventDefault();
                    return;
                }
                form.dataset.submitting = '1';
                // submitイベントの処理が終わってからボタンを無効にする(先に無効にすると送信自体が止まるブラウザがあるため)
                setTimeout(() => lockSubmitButtons(form), 0);
            },

            openConfirm(form) {
                const dialog = bootstrapModal(this.$refs.confirmModal);
                if (!dialog) {
                    // モーダルが使えない環境では確認なしで送信する
                    this.submitConfirmed(form);
                    return;
                }
                this.pendingForm = form;
                this.confirmMessage = form.dataset.confirm;
                this.confirmOk = form.dataset.confirmOk || 'OK';
                this.confirmDanger = form.dataset.confirmVariant === 'danger';
                dialog.show();
            },

            // 確認ダイアログの「OK」(連打しても送信は1回だけ)
            acceptConfirm() {
                const form = this.pendingForm;
                if (!form) {
                    return;
                }
                this.pendingForm = null;
                bootstrapModal(this.$refs.confirmModal).hide();
                this.submitConfirmed(form);
            },

            // 確認ダイアログを閉じた(キャンセル)
            cancelConfirm() {
                this.pendingForm = null;
            },

            // 確認後の送信。requestSubmit が無い古いブラウザでは submit() で送り、二重送信の防止も自前で行う
            submitConfirmed(form) {
                form.dataset.confirmed = '1';
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                    return;
                }
                if (form.dataset.submitting) {
                    return;
                }
                form.dataset.submitting = '1';
                lockSubmitButtons(form);
                form.submit();
            },

            // ブラウザの「戻る」でキャッシュから表示された場合は、送信中のボタンを元に戻す
            restoreForms(event) {
                if (event.persisted) {
                    document.querySelectorAll('form[data-submitting]').forEach(unlockSubmitButtons);
                }
            },

            // ファイルをドロップ領域の外に落としたときに、ブラウザがそのファイルを開いてしまうのを防ぐ
            ignoreDragOver(event) {
                event.preventDefault();
            },

            preventStrayDrop(event) {
                if (!event.target.closest || !event.target.closest('.dropzone')) {
                    event.preventDefault();
                }
            },

            // 添付画像の拡大表示(<a @click.prevent="showImage" data-title="ファイル名">)
            showImage(event) {
                const link = event.currentTarget;
                const dialog = bootstrapModal(this.$refs.imageModal);
                if (!dialog) {
                    window.location.href = link.href;
                    return;
                }
                this.imageSrc = link.href;
                this.imageTitle = link.dataset.title || '';
                dialog.show();
            },

            clearImage() {
                this.imageSrc = null;
            },

            // 一覧の行全体をクリックで詳細へ(<tr data-href="URL" @click="openRow">)。行内のリンク・ボタンはそのまま
            openRow(event) {
                if (event.target.closest('a, button, input, select, label')) {
                    return;
                }
                const url = event.currentTarget.dataset.href;
                if (event.ctrlKey || event.metaKey) {
                    window.open(url, '_blank');
                } else {
                    window.location.href = url;
                }
            },
        }));

        /* ------------------------------------------------------------
         * attachmentInput: 添付ファイル入力(components/attachment-input.blade.php)
         * ------------------------------------------------------------ */
        Alpine.data('attachmentInput', () => {
            // 画像の縮小は1つずつ順番に行う(端末のメモリを使いすぎないように)
            let queue = Promise.resolve();
            let nextId = 1;

            return {
                items: [],      // 選択中のファイル { id, file, name, size, isImage, url, removeLabel }
                errors: [],
                processing: 0,  // 縮小中の件数
                notice: '',
                dragging: false,
                maxFiles: 10,
                maxSize: 10 * 1024 * 1024,
                maxImageEdge: 2560,
                extensions: [],
                canAssignFiles: typeof DataTransfer !== 'undefined',

                init() {
                    const data = this.$el.dataset;
                    this.maxFiles = parseInt(data.maxFiles, 10) || this.maxFiles;
                    this.maxSize = parseInt(data.maxSize, 10) || this.maxSize;
                    this.maxImageEdge = parseInt(data.maxImageEdge, 10) || this.maxImageEdge;
                    this.extensions = (data.extensions || '').split(',');

                    // フォーム内のどこで貼り付けても(本文を入力中に Ctrl+V しても)添付できるようにする
                    const form = this.$el.closest('form');
                    if (form && this.canAssignFiles) {
                        form.addEventListener('paste', (event) => this.onPaste(event));
                    }
                },

                // 縮小中は data-busy を付け、送信を止める(app の onSubmit で確認する)
                get busy() {
                    return this.processing > 0 ? '1' : null;
                },

                get statusText() {
                    if (this.notice) {
                        return this.notice;
                    }
                    return this.processing > 0 ? '画像を縮小しています…（' + this.processing + '件）' : '';
                },

                get errorText() {
                    return [...new Set(this.errors)].join(' ');
                },

                get dropzoneClass() {
                    return this.dragging ? 'is-dragover' : '';
                },

                // クリック・キーボードでファイル選択ダイアログを開く
                openPicker() {
                    this.$refs.input.click();
                },

                startDrag() {
                    this.dragging = true;
                },

                endDrag() {
                    this.dragging = false;
                },

                onDrop(event) {
                    this.dragging = false;
                    if (event.dataTransfer && event.dataTransfer.files.length) {
                        this.addFiles(event.dataTransfer.files);
                    }
                },

                // ファイル選択ダイアログで選んだ
                onSelect() {
                    if (!this.canAssignFiles) {
                        return; // 古いブラウザでは通常のファイル選択として動かす
                    }
                    // input.files は sync() で上書きするので、追加分だけを取り込む
                    const known = this.items.map((item) => item.file);
                    this.addFiles(Array.from(this.$refs.input.files).filter((file) => !known.includes(file)));
                },

                // クリップボードからの貼り付け(スクリーンショットを Ctrl+V で添付)
                onPaste(event) {
                    const clipboard = event.clipboardData;
                    if (!clipboard || !clipboard.files || clipboard.files.length === 0) {
                        return; // 文字の貼り付けは通常どおり
                    }
                    // Excel・Wordからのコピーは文字と一緒に画像も入っているため、入力欄への貼り付けなら文字を優先する
                    const types = Array.from(clipboard.types || []);
                    const target = event.target;
                    const isTextField = target && (target.tagName === 'TEXTAREA'
                        || (target.tagName === 'INPUT' && /^(text|search|email|url|tel|number)$/i.test(target.type))
                        || target.isContentEditable);
                    if (isTextField && types.includes('text/plain')) {
                        return;
                    }
                    event.preventDefault();
                    this.addFiles(Array.from(clipboard.files).map((file, i) => (
                        file.type.startsWith('image/') && /^image\.\w+$/i.test(file.name)
                            ? new File([file], screenshotName(file, i), {type: file.type})
                            : file
                    )));
                    this.dragging = true;
                    setTimeout(() => {
                        this.dragging = false;
                    }, 400);
                },

                // 縮小中に送信しようとした
                waitForShrink() {
                    this.notice = '画像の縮小が終わるまでお待ちください…';
                },

                // ファイルを追加する。大きな画像は1つずつ順番に縮小してから追加する
                addFiles(list) {
                    this.errors = [];
                    let accepted = 0;
                    Array.from(list).forEach((file) => {
                        const ext = (file.name.split('.').pop() || '').toLowerCase();
                        if (!this.extensions.includes(ext)) {
                            this.errors.push('「' + file.name + '」は添付できない形式です。');
                            return;
                        }
                        if (this.items.length + this.processing + accepted >= this.maxFiles) {
                            this.errors.push('添付できるのは' + this.maxFiles + 'ファイルまでです。');
                            return;
                        }
                        accepted++;
                        this.processing++;
                        queue = queue
                            .then(() => shrinkImage(file, this.maxImageEdge))
                            .then((result) => {
                                // サイズの上限は縮小した後で判定する(大きな写真も縮小すれば添付できる)
                                if (result.size > this.maxSize) {
                                    this.errors.push('「' + file.name + '」は' + formatSize(this.maxSize) + 'を超えているため添付できません。');
                                } else {
                                    this.items.push(this.toItem(result));
                                }
                            })
                            .catch(() => {
                                this.errors.push('「' + file.name + '」を読み込めませんでした。');
                            })
                            .then(() => {
                                this.processing--;
                                if (this.processing === 0) {
                                    this.notice = '';
                                }
                                this.sync();
                            });
                    });
                    if (accepted === 0) {
                        this.sync();
                    }
                },

                toItem(file) {
                    const isImage = file.type.startsWith('image/');
                    return {
                        id: nextId++,
                        file: file,
                        name: file.name,
                        size: formatSize(file.size),
                        isImage: isImage,
                        url: isImage ? URL.createObjectURL(file) : null,
                        removeLabel: file.name + ' を取り消す',
                    };
                },

                // プレビューの「×」で取り消す
                remove(index) {
                    const [removed] = this.items.splice(index, 1);
                    if (removed && removed.url) {
                        URL.revokeObjectURL(removed.url);
                    }
                    this.errors = [];
                    this.sync();
                },

                // 選択中のファイル一覧を input に反映する(この内容が送信される)
                sync() {
                    if (!this.canAssignFiles) {
                        return;
                    }
                    const transfer = new DataTransfer();
                    this.items.forEach((item) => transfer.items.add(item.file));
                    this.$refs.input.files = transfer.files;
                },
            };
        });

        /* ------------------------------------------------------------
         * issueTemplates: 課題の登録(issues/create.blade.php)
         * 種別を切り替えたら詳細のテンプレートも切り替える(未編集の場合のみ)
         * ------------------------------------------------------------ */
        Alpine.data('issueTemplates', () => ({
            applyTemplate(event) {
                const description = this.$refs.description;
                // 種別ごとのテンプレート(サーバー側で data-templates に JSON で埋め込んでいる)
                const templates = JSON.parse(description.dataset.templates || '{}');
                const current = description.value.trim();
                const untouched = current === '' || Object.values(templates).some((t) => t.trim() === current);
                if (event.target.checked && untouched) {
                    description.value = templates[event.target.value] || '';
                }
            },
        }));

        /* ------------------------------------------------------------
         * userForm: ユーザーの作成・編集(staff/users/_form.blade.php)
         * ------------------------------------------------------------ */
        Alpine.data('userForm', () => ({
            role: '',

            init() {
                const checked = this.$el.querySelector('input[name="role"]:checked');
                this.role = checked ? checked.value : '';
            },

            get isCustomer() {
                return this.role === 'customer';
            },

            chooseRole(event) {
                this.role = event.target.value;
            },
        }));
    });
})();
