@extends('layouts.app')

@section('title', '使い方（社内メンバー向け）')

@use('App\Enums\IssueStatus')
@use('App\Services\AttachmentService')
@use('App\Services\LoginCodeService')

@section('content')
    @php($appName = config('app.name'))
    @php($trashDays = app(\App\Services\TrashService::class)->retentionDays())

    <x-help-page title="使い方（社内メンバー向け）"
                 lead="プロジェクトの準備から、お客様の課題への対応、ユーザーの管理までをまとめています。"
                 :sections="[
                     'start' => 'はじめに',
                     'setup' => '最初の準備',
                     'flow' => '課題への対応',
                     'status' => '状態・優先度・期限日',
                     'notify' => '通知メール',
                     'users' => 'ユーザーの管理',
                     'trash' => 'ゴミ箱（削除したデータ）',
                     'files' => '添付ファイル',
                     'trouble' => '困ったとき',
                 ]">

        {{-- 1. はじめに --}}
        <section id="start" class="help-section card shadow-sm border-0">
            <div class="card-body">
                <h2>1. はじめに</h2>
                <p>{{ $appName }}は、お客様から届く不具合・要望・質問を「課題」として管理するツールです。お客様が画面のスクリーンショットを添えて課題を登録し、社内メンバーが担当者や状態を管理しながら、コメントでやり取りします。</p>

                <h3>社内用とお客様用の2つの画面があります</h3>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-2">
                        <thead>
                            <tr><th>画面</th><th>ログイン画面のURL</th><th>使う人</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="text-nowrap"><span class="badge rounded-pill text-bg-dark">社内</span> 社内用</td>
                                <td class="text-break"><code>{{ route('staff.login') }}</code></td>
                                <td>社内メンバー（全プロジェクトを見られます）</td>
                            </tr>
                            <tr>
                                <td class="text-nowrap">お客様用</td>
                                <td class="text-break"><code>{{ route('customer.login') }}</code></td>
                                <td>お客様（参加しているプロジェクトだけ見られます）</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="mb-0">
                    <li><strong>社内用のURLはお客様に伝えないでください。</strong>ブックマークしておくと便利です。</li>
                    <li>社内用の画面はヘッダーが濃いグレーで「社内」の印が付きます。同じブラウザで両方にログインしていても見分けられます。</li>
                    <li>ログインはパスワード不要です。メールアドレスを入力すると、6桁の認証コードがメールで届きます。</li>
                </ul>
            </div>
        </section>

        {{-- 2. 最初の準備 --}}
        <section id="setup" class="help-section card shadow-sm border-0">
            <div class="card-body">
                <h2>2. 最初の準備（プロジェクトを作って、お客様を招待する）</h2>
                <p>お客様ごと・案件ごとに「プロジェクト」を作り、そこにお客様を参加させます。お客様は参加しているプロジェクトの課題だけを見られます。</p>

                <ol class="help-steps">
                    <li>
                        <div class="fw-semibold">プロジェクトを作る</div>
                        <div>
                            画面上部の <a href="{{ route('staff.projects.index') }}"><i class="bi bi-folder2 me-1"></i>プロジェクト管理</a> を開き、
                            <span class="help-ui btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>プロジェクトを作成</span> を押します。
                        </div>
                        <ul class="small mt-1">
                            <li><strong>プロジェクトキー</strong>: 英大文字の短い名前（例: <code>WEB</code>）。課題に「WEB-1」「WEB-2」…と番号が振られます。<strong>作成後は変更できません。</strong></li>
                            <li><strong>プロジェクト名</strong>: 例）コーポレートサイト保守</li>
                            <li><strong>メンバー</strong>: 担当する社内メンバーにチェックします。チェックした社内メンバーに、新しい課題の通知メールが届きます。</li>
                        </ul>
                    </li>
                    <li>
                        <div class="fw-semibold">お客様を登録する</div>
                        <div>
                            <a href="{{ route('staff.users.index') }}"><i class="bi bi-people me-1"></i>ユーザー管理</a> を開き、
                            <span class="help-ui btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i>ユーザーを作成</span> を押します。
                        </div>
                        <ul class="small mt-1">
                            <li><strong>種別</strong>: 「お客様」を選びます。</li>
                            <li><strong>氏名・メールアドレス・会社名</strong>: メールアドレスは、ログイン用の認証コードの送り先になります。</li>
                            <li><strong>参加プロジェクト</strong>: 手順1で作ったプロジェクトにチェックします。</li>
                            <li><strong>ログイン案内メールを送る</strong>: チェックしたままにすると、ログイン画面のURLとログイン方法がお客様に届きます。</li>
                        </ul>
                    </li>
                    <li>
                        <div class="fw-semibold">お客様に使い方をお知らせする</div>
                        <div>
                            ログイン案内メールが届いたことと、不具合などはここから登録してほしいことをお客様にお伝えください。
                            お客様はログイン後、画面上部の「使い方」から操作方法を見られます。
                        </div>
                        <div class="mt-2">
                            <a href="{{ route('staff.help.customer') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-book me-1"></i>お客様向けの使い方を見る</a>
                        </div>
                    </li>
                    <li>
                        <div class="fw-semibold">（必要なら）社内メンバーを追加する</div>
                        <div>ユーザー管理で、種別「社内」を選んで作成します。社内メンバーは全プロジェクトの課題を見られ、プロジェクト・ユーザーの管理もできます。</div>
                    </li>
                </ol>

                <div class="alert alert-light border small mb-0">
                    <i class="bi bi-lightbulb me-1"></i>
                    登録済みのユーザーを別のプロジェクトに参加させるときは、プロジェクト管理でプロジェクトの <span class="help-ui btn btn-outline-secondary btn-sm"><i class="bi bi-gear me-1"></i>設定</span> を開き、「メンバー」から追加します（ユーザー管理の「参加プロジェクト」からでも変更できます）。
                </div>
            </div>
        </section>

        {{-- 3. 課題への対応 --}}
        <section id="flow" class="help-section card shadow-sm border-0">
            <div class="card-body">
                <h2>3. 課題への対応</h2>
                <ol class="help-steps">
                    <li>
                        <div class="fw-semibold">お客様が課題を登録すると、メールで通知されます</div>
                        <div>プロジェクトの社内メンバー（社内メンバーが1人もいないプロジェクトでは社内全員）に届きます。メールのボタンから課題を開けます。</div>
                    </li>
                    <li>
                        <div class="fw-semibold">担当者を決めて、状態を「処理中」にします</div>
                        <div>課題の画面の下にある「コメント・状態の変更」で、担当者・状態・優先度・期限日を変更して <span class="help-ui btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>コメントする</span> を押します。変更した内容はコメント欄に履歴として残り、お客様にも通知されます（コメントの本文は空でもかまいません）。</div>
                    </li>
                    <li>
                        <div class="fw-semibold">コメントでお客様とやり取りします</div>
                        <div>確認したいことや対応内容をコメントします。画像やファイルも添付できます。</div>
                    </li>
                    <li>
                        <div class="fw-semibold">対応が終わったら「処理済み」にします</div>
                        <div>お客様に確認をお願いし、問題がないと確認できたら「完了」にします（お客様自身が「完了」にすることもできます）。</div>
                    </li>
                </ol>

                <div class="alert alert-warning small">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>コメントはお客様にもすべて表示されます。</strong>社内だけで共有したいメモは書かないでください。
                </div>

                <h3>電話やメールで受けた内容を登録する</h3>
                <p class="mb-2">社内メンバーも課題一覧の <span class="help-ui btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>課題を追加</span> から登録できます。登録時に担当者・期限日も指定できます。</p>
                <ul class="mb-3">
                    <li><strong>「起票者」でお客様を選ぶと、お客様の課題として登録されます</strong>（代理起票）。お客様に登録のお知らせが届き、その後のコメントや状態の変更もお客様にメールで届きます。お客様は、自分が起票した課題と同じように内容を編集できます。</li>
                    <li>起票者に選べるのは、そのプロジェクトに参加している有効なお客様です。課題の詳細には、代わりに登録した社内メンバーの名前も表示されます。</li>
                    <li>起票者が「自分」のままだと、お客様には通知が届きません（社内で見つけた不具合の記録などに使います）。</li>
                </ul>

                <h3>自分の課題を探す</h3>
                <ul class="mb-0">
                    <li><a href="{{ route('staff.home') }}">ホーム</a>の「自分が担当の未完了課題」に、自分が担当している課題が表示されます。</li>
                    <li>課題一覧では、状態のタブ、キーワード・課題キー（例: WEB-12）での検索、担当者「自分」での絞り込み、並び替えができます。</li>
                </ul>
            </div>
        </section>

        {{-- 4. 状態・優先度・期限日 --}}
        <section id="status" class="help-section card shadow-sm border-0">
            <div class="card-body">
                <h2>4. 状態・優先度・期限日</h2>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>状態</th><th>意味（運用の目安）</th></tr></thead>
                        <tbody>
                            <tr><td><x-status-badge :status="IssueStatus::Open" /></td><td>登録されたばかりで、まだ対応を始めていない</td></tr>
                            <tr><td><x-status-badge :status="IssueStatus::InProgress" /></td><td>社内で調査・対応している</td></tr>
                            <tr><td><x-status-badge :status="IssueStatus::Resolved" /></td><td>対応が終わり、お客様の確認を待っている</td></tr>
                            <tr><td><x-status-badge :status="IssueStatus::Closed" /></td><td>お客様の確認が取れ、終了した（一覧では初期状態で非表示）</td></tr>
                        </tbody>
                    </table>
                </div>
                <ul class="mb-0">
                    <li>状態は、どの状態からでも変更できます（完了から戻すこともできます）。</li>
                    <li><strong>優先度</strong>（高・中・低）、<strong>担当者</strong>、<strong>期限日</strong>を変更できるのは社内メンバーだけです。</li>
                    <li>期限日を過ぎた未完了の課題は、一覧で赤く表示されます。</li>
                </ul>
            </div>
        </section>

        {{-- 5. 通知メール --}}
        <section id="notify" class="help-section card shadow-sm border-0">
            <div class="card-body">
                <h2>5. 通知メール</h2>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-2">
                        <thead><tr><th>きっかけ</th><th>届く人</th></tr></thead>
                        <tbody>
                            <tr><td class="text-nowrap">課題の登録</td><td>プロジェクトの社内メンバー（いなければ社内全員）、担当者。社内メンバーがお客様の代わりに登録した場合は、起票者のお客様にも</td></tr>
                            <tr><td class="text-nowrap">コメント・状態の変更</td><td>起票した人、担当者、その課題にコメントしたことがある人。お客様が投稿した場合は、プロジェクトの社内メンバー（いなければ社内全員）にも</td></tr>
                            <tr><td class="text-nowrap">ユーザーの作成</td><td>作成したユーザー本人（「ログイン案内メールを送る」にチェックした場合）</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="small text-muted mb-0">操作した本人と、無効なユーザーには届きません。</p>
            </div>
        </section>

        {{-- 6. ユーザーの管理 --}}
        <section id="users" class="help-section card shadow-sm border-0">
            <div class="card-body">
                <h2>6. ユーザーの管理</h2>

                <h3 class="mt-0">ログインできないようにする（退職・取引終了など）</h3>
                <p>ユーザー管理でユーザーの <span class="help-ui btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>編集</span> を開き、「状態」の「有効（ログインできる）」をオフにして更新します。ログイン中の場合も、次の操作でログアウトされます。登録した課題やコメントは残ります。</p>

                <h3>ログインがロックされた場合</h3>
                <p>
                    認証コードを1時間に{{ LoginCodeService::MAX_FAILURES_PER_ACCOUNT }}回間違えると、そのユーザーは一時的にログインできなくなります（最初の入力ミスから1時間で自動的に解除されます。1日に{{ LoginCodeService::MAX_FAILURES_PER_DAY }}回間違えた場合は24時間）。
                    ユーザー一覧では <span class="badge text-bg-warning"><i class="bi bi-lock-fill"></i> ロック中</span> と表示されます。
                </p>
                <p>
                    本人から問い合わせがあった場合は、ユーザーの編集画面で <span class="help-ui btn btn-warning btn-sm"><i class="bi bi-unlock me-1"></i>ロックを解除</span> を押すと、すぐにログインできるようになります。
                    <strong>本人以外の誰かがログインを試みていた可能性もあるため、本人からの問い合わせであることを確認してから解除してください。</strong>
                </p>

                <h3>社内メンバー全員がログインできなくなった場合</h3>
                <p class="mb-0">サーバーの管理者に、サーバー上で次のコマンドを実行してもらいます。</p>
                <ul class="mb-0">
                    <li>ロックの解除だけ: <code>php artisan app:unlock-login メールアドレス</code></li>
                    <li>社内メンバーの追加・無効にした社内メンバーの復旧: <code>php artisan app:create-staff メールアドレス</code></li>
                </ul>
            </div>
        </section>

        {{-- 7. ゴミ箱 --}}
        <section id="trash" class="help-section card shadow-sm border-0">
            <div class="card-body">
                <h2>7. ゴミ箱（削除したデータ）</h2>
                <p>プロジェクト・課題・コメント・添付ファイルを削除すると、画面上部の <a href="{{ route('staff.trash.index') }}"><i class="bi bi-trash me-1"></i>ゴミ箱</a> に移ります。お客様が削除したものも、ここに入ります。</p>
                <ul>
                    @if ($trashDays > 0)
                        <li>削除してから<strong>{{ $trashDays }}日間</strong>はゴミ箱に保管され、<span class="help-ui btn btn-outline-primary btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>元に戻す</span> で元に戻せます。{{ $trashDays }}日を過ぎると、画像などの添付ファイルの実体も含めて自動で完全に削除されます。</li>
                    @else
                        <li>削除したデータは自動では削除されません（.env の TRASH_RETENTION_DAYS=0）。<span class="help-ui btn btn-outline-primary btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>元に戻す</span> で元に戻せます。</li>
                    @endif
                    <li><strong>個人情報や機密情報が写った画像を誤って添付した場合</strong>は、削除したうえで、ゴミ箱の <span class="help-ui btn btn-outline-danger btn-sm"><i class="bi bi-x-octagon me-1"></i>完全に削除</span> ですぐにサーバーから消してください（元に戻せません）。</li>
                    <li>課題やプロジェクトを完全に削除すると、その中のコメント・添付ファイルもすべて消えます。コメントを完全に削除しても、状態などの変更履歴は課題の経緯として残ります。</li>
                    <li>サーバーのバックアップには、完全に削除した後もバックアップの保管期間のあいだ残ります。</li>
                </ul>
                <p class="small text-muted mb-0">プロジェクトや課題が削除されたままのコメント・添付ファイルは元に戻せません。先にプロジェクト・課題を元に戻してください。</p>
            </div>
        </section>

        {{-- 8. 添付ファイル --}}
        <section id="files" class="help-section card shadow-sm border-0">
            <div class="card-body">
                <h2>8. 添付ファイル</h2>
                <ul class="mb-0">
                    <li>1回の投稿で{{ AttachmentService::MAX_FILES }}ファイルまで、1ファイル{{ intdiv(AttachmentService::MAX_SIZE_KB, 1024) }}MBまで添付できます（画像・PDF・Office・テキスト・ZIP・動画）。</li>
                    <li>スクリーンショットは <kbd>Ctrl</kbd>+<kbd>V</kbd>（Macは <kbd>⌘</kbd>+<kbd>V</kbd>）で貼り付けたり、ドラッグ＆ドロップで追加したりできます。</li>
                    <li>長辺{{ AttachmentService::MAX_IMAGE_EDGE }}pxを超える画像（スマホの写真など）は自動で縮小されます。</li>
                    <li>画像はその場で表示され、クリックすると拡大できます。それ以外のファイルはダウンロードになります。</li>
                    <li>添付ファイルを見られるのは、その課題を見られる人（社内メンバーと、プロジェクトに参加しているお客様）だけです。</li>
                </ul>
            </div>
        </section>

        {{-- 9. 困ったとき --}}
        <section id="trouble" class="help-section card shadow-sm border-0">
            <div class="card-body">
                <h2>9. 困ったとき</h2>
                <dl class="mb-0">
                    <dt>お客様が課題を見られない・登録できない</dt>
                    <dd>そのお客様がプロジェクトのメンバーになっているか、ユーザーが「有効」になっているかを確認してください。</dd>
                    <dt>お客様に認証コードのメールが届かない</dt>
                    <dd>迷惑メールフォルダに入っていないか、ドメイン指定受信などで受信を拒否していないかを確認してもらってください。それでも届かない場合は、サーバーの管理者にメール送信の設定を確認してもらってください。</dd>
                    <dt>間違えて登録した課題を消したい</dt>
                    <dd>課題の画面の <span class="help-ui btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>削除</span> から削除できます（社内メンバーのみ）。</dd>
                    <dt>コメントを消したい</dt>
                    <dd>コメントの右上のごみ箱アイコンから削除できます。本文と添付ファイルは表示されなくなりますが、状態などの変更履歴は残ります。</dd>
                    <dt>削除したデータはどうなる？</dt>
                    <dd class="mb-0">いったん<a href="#trash">ゴミ箱</a>に移り、そこから元に戻せます（保管期間を過ぎると自動で完全に削除されます）。</dd>
                </dl>
            </div>
        </section>
    </x-help-page>
@endsection
