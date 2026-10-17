@extends('layouts.app')

@section('title', '使い方')

@use('App\Enums\IssuePriority')
@use('App\Enums\IssueStatus')
@use('App\Enums\IssueType')
@use('App\Services\AttachmentService')
@use('App\Services\LoginCodeService')

@section('content')
    @php
        $appName = config('app.name');
        // 認証コードのメールの送信元(迷惑メール対策で受信を許可してもらう用。メールを送信しない設定では表示しない)
        $mailFrom = config('mail.default') !== 'log' ? config('mail.from.address') : null;
        // 削除したデータを完全に削除するまでの日数(0なら自動では削除しない)
        $trashDays = app(\App\Services\TrashService::class)->retentionDays();
    @endphp

    <x-help-page title="使い方"
                 lead="不具合のご報告・ご要望・ご質問の登録方法と、担当者とのやり取りの方法をご案内します。"
                 :sections="[
                     'start' => 'はじめに',
                     'login' => 'ログイン',
                     'create' => '課題を登録する',
                     'screenshot' => 'スクリーンショットの撮り方',
                     'comment' => '担当者とやり取りする',
                     'find' => '課題を探す',
                     'edit' => '登録した課題の修正',
                     'trouble' => '困ったとき',
                 ]">
        @if (portal() === 'staff')
            <x-slot:before>
                <div class="alert alert-info d-flex flex-column flex-sm-row align-items-sm-center gap-2">
                    <div class="flex-grow-1 small">
                        <i class="bi bi-info-circle me-1"></i>
                        これは<strong>お客様向けの使い方</strong>です。お客様は、ログイン後の画面上部の「使い方」からこのページを見られます。
                    </div>
                    <x-back-button :href="route('staff.help')" label="社内メンバー向けの使い方" class="btn-sm text-nowrap" />
                </div>
            </x-slot:before>
        @endif

        {{-- 1. はじめに --}}
        <section id="start" class="help-section card shadow-sm border-0">
            <div class="card-body">
                <h2>1. はじめに</h2>
                <p>{{ $appName }}は、システムやWebサイトの不具合のご報告、ご要望・ご質問をお送りいただくための窓口です。</p>
                <ul class="mb-0">
                    <li>画面のスクリーンショットを添えて登録できるので、状況が正確に伝わります。</li>
                    <li>登録した内容（「課題」と呼びます）ごとに、担当者とのやり取りや対応の状況を確認できます。</li>
                    <li>担当者から返信があると、メールでお知らせします。</li>
                </ul>
            </div>
        </section>

        {{-- 2. ログイン --}}
        <section id="login" class="help-section card shadow-sm border-0">
            <div class="card-body">
                <h2>2. ログイン</h2>
                <p>パスワードは不要です。登録されたメールアドレスに届く6桁の認証コードでログインします。</p>
                <ol class="help-steps">
                    <li>
                        <div class="fw-semibold">ログイン画面を開き、メールアドレスを入力します</div>
                        <div class="text-break">ログイン画面: <code>{{ route('customer.login') }}</code>（ブックマークしておくと便利です）</div>
                    </li>
                    <li>
                        <div class="fw-semibold">メールに届いた6桁の認証コードを入力します</div>
                        <div>
                            コードの有効期限は{{ LoginCodeService::EXPIRES_MINUTES }}分です。
                            「このブラウザでログイン状態を保持する（30日間）」にチェックすると、しばらくはログインし直す必要がありません（共用のパソコンではチェックしないでください）。
                        </div>
                    </li>
                </ol>
                <ul class="small mb-0">
                    <li>メールが届かないときは、コード入力画面の「コードを再送する」を押してください。<strong>再送すると、以前のコードは使えなくなります。</strong>最新のメールのコードを入力してください。</li>
                    <li>コードを1時間に{{ LoginCodeService::MAX_FAILURES_PER_ACCOUNT }}回間違えると、安全のため一時的にログインできなくなります（1時間ほどで解除されます。1日に{{ LoginCodeService::MAX_FAILURES_PER_DAY }}回間違えた場合は24時間）。お急ぎの場合は担当者にご連絡ください。</li>
                </ul>
            </div>
        </section>

        {{-- 3. 課題を登録する --}}
        <section id="create" class="help-section card shadow-sm border-0">
            <div class="card-body">
                <h2>3. 課題を登録する</h2>
                <ol class="help-steps">
                    <li>
                        <div class="fw-semibold">ホームでプロジェクトを選び、<span class="help-ui btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>課題を追加</span> を押します</div>
                    </li>
                    <li>
                        <div class="fw-semibold">内容を入力します</div>
                        <ul class="small mt-1">
                            <li>
                                <strong>種別</strong>:
                                @foreach (IssueType::cases() as $type)
                                    <x-type-badge :type="$type" />
                                @endforeach
                                から選びます。
                            </li>
                            <li><strong>件名</strong>: ひと目で内容が分かる短い文章（例: ログイン後にトップページが真っ白になる）</li>
                            <li><strong>詳細</strong>: 下の「書き方のコツ」を参考にしてください。</li>
                            <li><strong>スクリーンショット・添付ファイル</strong>: 画面の画像を添えていただくと、早く正確に対応できます（<a href="#screenshot">撮り方</a>）。</li>
                            <li>
                                <strong>優先度</strong>:
                                @foreach (IssuePriority::cases() as $priority)
                                    <x-priority :priority="$priority" class="me-2" />
                                @endforeach
                                （業務が止まっている場合は「高」を選んでください）
                            </li>
                        </ul>
                    </li>
                    <li>
                        <div class="fw-semibold">登録すると、担当者に通知されます</div>
                        <div>課題には「WEB-12」のような番号（課題キー）が付きます。お問い合わせの際にお伝えいただくとスムーズです。</div>
                    </li>
                </ol>

                <div class="alert alert-light border small mb-0">
                    <div class="fw-semibold mb-1"><i class="bi bi-lightbulb me-1"></i>詳細の書き方のコツ（不具合の場合）</div>
                    <ul class="mb-0">
                        <li>どの画面で（画面の名前やURL）</li>
                        <li>どんな操作をしたら（押したボタン、入力した内容）</li>
                        <li>どうなったか（表示されたメッセージ。本来はどうなるはずだったか）</li>
                        <li>いつ起きたか・毎回起きるか</li>
                        <li>使っている端末やブラウザ（例: Windows の Chrome、iPhone の Safari）</li>
                    </ul>
                </div>
            </div>
        </section>

        {{-- 4. スクリーンショットの撮り方 --}}
        <section id="screenshot" class="help-section card shadow-sm border-0">
            <div class="card-body">
                <h2>4. スクリーンショットの撮り方・添付のしかた</h2>
                {{-- 端末ごとのカード(スマートフォンでは縦に並ぶ) --}}
                <div class="row g-2 mb-3">
                    @foreach ([
                        ['bi-windows', 'Windows', '<kbd>Windows</kbd>+<kbd>Shift</kbd>+<kbd>S</kbd> を押して、写したい範囲を選びます。', '添付欄をクリックしてから <kbd>Ctrl</kbd>+<kbd>V</kbd> で貼り付けます。'],
                        ['bi-apple', 'Mac', '<kbd>⌘</kbd>+<kbd>Control</kbd>+<kbd>Shift</kbd>+<kbd>4</kbd> を押して、写したい範囲を選びます。', '添付欄をクリックしてから <kbd>⌘</kbd>+<kbd>V</kbd> で貼り付けます。'],
                        ['bi-phone', 'iPhone', 'サイドボタンと音量を上げるボタンを同時に押します（ホームボタンのある機種は、ホームボタンとサイドボタン）。', '添付欄の「タップしてファイル・写真を選択」から写真を選びます。'],
                        ['bi-phone', 'Android', '電源ボタンと音量を下げるボタンを同時に押します（機種により異なります）。', '添付欄の「タップしてファイル・写真を選択」から写真を選びます。'],
                    ] as [$icon, $device, $howToTake, $howToAttach])
                        <div class="col-12 col-md-6">
                            <div class="border rounded p-2 h-100 small">
                                <div class="fw-bold mb-1"><i class="bi {{ $icon }} me-1"></i>{{ $device }}</div>
                                {{-- 説明文は固定の文章(利用者の入力は含まない)なので、キーの表示(kbd)のためそのまま出力する --}}
                                <div><span class="text-muted">撮り方:</span> {!! $howToTake !!}</div>
                                <div><span class="text-muted">添付:</span> {!! $howToAttach !!}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <ul class="small mb-0">
                    <li>保存済みの画像やファイルは、添付欄にドラッグ＆ドロップするか、添付欄をクリックして選べます。</li>
                    <li>1回に{{ AttachmentService::MAX_FILES }}ファイルまで、1ファイル{{ intdiv(AttachmentService::MAX_SIZE_KB, 1024) }}MBまで添付できます（画像・PDF・Excel・Word・PowerPoint・テキスト・ZIP・動画）。</li>
                    <li>スマホの写真など大きな画像は、自動で縮小してから送信します。</li>
                    <li>パスワードなどの大切な情報が写っていないか、送信前にご確認ください。</li>
                </ul>
            </div>
        </section>

        {{-- 5. 担当者とやり取りする --}}
        <section id="comment" class="help-section card shadow-sm border-0">
            <div class="card-body">
                <h2>5. 担当者とやり取りする</h2>
                <p>担当者からコメントがあると、メールでお知らせします。メールのボタンから課題を開き、画面の下の「コメント・状態の変更」から返信してください。画像やファイルも添付できます。</p>

                <h3>課題の状態</h3>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <tbody>
                            <tr><td class="text-nowrap"><x-status-badge :status="IssueStatus::Open" /></td><td>登録されたばかりで、これから確認します</td></tr>
                            <tr><td class="text-nowrap"><x-status-badge :status="IssueStatus::InProgress" /></td><td>担当者が調査・対応しています</td></tr>
                            <tr><td class="text-nowrap"><x-status-badge :status="IssueStatus::Resolved" /></td><td>対応が終わりました。<strong>動作をご確認ください</strong></td></tr>
                            <tr><td class="text-nowrap"><x-status-badge :status="IssueStatus::Closed" /></td><td>確認が取れ、終了しました</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="mb-0">
                    「処理済み」になったら、動作をご確認ください。問題がなければ、コメント欄の「状態」を「完了」にして <span class="help-ui btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>コメントする</span> を押してください。
                    解決していない場合は、その内容をコメントでお知らせください。
                </p>
            </div>
        </section>

        {{-- 6. 課題を探す --}}
        <section id="find" class="help-section card shadow-sm border-0">
            <div class="card-body">
                <h2>6. 課題を探す</h2>
                <ul class="mb-0">
                    <li>ホームには、最近更新された課題が表示されます。</li>
                    <li>プロジェクトの課題一覧では、状態のタブ（初期状態では完了以外を表示）、キーワード・課題キー（例: WEB-12）での検索、並び替えができます。</li>
                    <li>課題一覧の上にある「完了」タブで、終了した課題も確認できます。</li>
                </ul>
            </div>
        </section>

        {{-- 7. 登録した課題の修正 --}}
        <section id="edit" class="help-section card shadow-sm border-0">
            <div class="card-body">
                <h2>7. 登録した課題の修正</h2>
                <ul class="mb-0">
                    <li>ご自身が登録した課題は、課題の画面の <span class="help-ui btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>編集</span> から件名・詳細・添付ファイルを修正できます。</li>
                    <li>ご自身のコメントは、コメントの右上のごみ箱アイコンから削除できます。</li>
                    @if ($trashDays > 0)
                        <li>削除したコメント・添付ファイルは、{{ $trashDays }}日後にサーバーからも完全に削除されます。</li>
                    @endif
                    <li><strong>個人情報などが写った画像を誤って添付した場合</strong>は、削除したうえで担当者にご連絡ください。すぐにサーバーから完全に削除します。</li>
                    <li>課題そのものを取り消したい場合は、コメントでその旨をお知らせください（課題の削除は担当者が行います）。</li>
                </ul>
            </div>
        </section>

        {{-- 8. 困ったとき --}}
        <section id="trouble" class="help-section card shadow-sm border-0">
            <div class="card-body">
                <h2>8. 困ったとき</h2>
                <dl class="mb-0">
                    <dt>認証コードのメールが届かない</dt>
                    <dd>
                        迷惑メールフォルダに入っていないかご確認ください。
                        @if ($mailFrom)
                            ドメイン指定受信などを設定している場合は、<code>{{ $mailFrom }}</code> からのメールを受信できるようにしてください。
                        @endif
                        それでも届かない場合は、担当者にご連絡ください。
                    </dd>
                    <dt>プロジェクトが表示されない</dt>
                    <dd>ご利用いただけるプロジェクトの設定がまだの可能性があります。担当者にご連絡ください。</dd>
                    <dt>メールアドレスが変わった・利用者を追加したい</dt>
                    <dd class="mb-0">担当者にご連絡ください。こちらで変更・追加します。</dd>
                </dl>
            </div>
        </section>
    </x-help-page>
@endsection
