# プロジェクト概要(ムシトリ)

お客様との不具合・要望を管理する、必要な機能に絞ったシンプルな課題管理ツール。お客様がスクリーンショットを添えて課題を起票し、社内メンバーが担当者・状態を管理してコメントでやり取りする。仕様(画面・権限・制限値・通知・設定など)は [docs/spec.md](docs/spec.md)、設置方法は [README.md](README.md) を参照。

## 技術構成
- PHP 8.3+(開発機は8.4) / Laravel 13 / MySQL 8.0 / Nginx + PHP-FPM
- **依存パッケージは PHP 8.3 で動く版に固定する。** `composer.json` の `config.platform.php`(8.3.0)を消さない(消して `composer update` すると、開発機のPHP 8.4に合わせて Symfony 8 など8.4専用の版が composer.lock に入り、PHP 8.3 のサーバーで composer install できなくなる)
- 画面はBladeで描画。Bootstrap 5.3.3・Bootstrap Icons 1.11.3・Alpine.js 3.17.4(CSP対応版 `@alpinejs/csp`)・フォントの Noto Sans JP(`@fontsource-variable/noto-sans-jp` 5.3.0。文字の範囲ごとに分割され、使う文字の分だけ読み込まれる)は `public/vendor/` に同梱(CDNは使わない。社内LAN等でも動くように)。npm・Vite等のビルドは使わない(`public/css/app.css`、`public/js/app.js` を直接編集)
- **JavaScriptは Alpine.js で書く。** 処理は `public/js/app.js` の `Alpine.data()` コンポーネントに書き、Bladeからは `x-data="コンポーネント名"` とメソッド名・プロパティ名で呼ぶ(全画面共通は `<body x-data="app">`、属性は `partials/app-root.blade.php`)。CSP対応版のため、HTMLの式は簡単なもの(プロパティ・メソッド呼び出し・比較・代入)だけで、`window` 等のグローバル変数・`x-html` は使えない
- **CSPを緩めない(外部参照なし)。** `<script>…</script>`・`<style>`・`style="…"` 属性・`javascript:`・`onclick` 等をHTMLに書かない(CSSのクラスや app.js に書く)。外部のURLからスクリプト・CSS・画像・フォントを読み込まない。テーマカラーは `/theme.css`(`ThemeController`)で配信する。本番は `SecurityHeaders` が `default-src 'self'` のCSPを付ける(APP_DEBUG=true の時はエラー詳細画面のため付けない)。違反は `tests/Feature/ContentSecurityPolicyTest.php` で検出する
- `x-show` の表示切り替えは次の描画のタイミング(requestAnimationFrame)で行われるため、画面が描画されていない自動操作のブラウザでは切り替わらないように見えることがある(実際のブラウザでは問題ない)
- テストはPest。SQLite(メモリ)で実行する
- メールの送り方は SMTP・Amazon SES(`ses-v2`。EC2のIAMロールで送信し、アクセスキーは .env に書かない)・log(送信しない)。SES用に `aws/aws-sdk-php` を使う(サイズを抑えるため、composer.json の `extra.aws/aws-sdk-php` で SES 以外のサービスは削除している。別のAWSサービスを使うときはここに追加する)

## コーディング規約
- PSR-12(`./vendor/bin/pint` で整形)
- **ビジネスロジックはControllerに書かない。** `app/Services/` のServiceクラスに書き、Controllerはリクエスト受け取り・権限チェック・Service呼び出し・レスポンス返却のみ
  - 一覧・検索のクエリ、画面表示用のデータの読み込み(load)もServiceに書く(例: `DashboardService`、`UserService::search()`、`IssueService::loadForDisplay()`)
  - 入力チェックは FormRequest(`app/Http/Requests/`)に書く。入力値の正規化(大文字・小文字の統一など)は `prepareForValidation()` で行う
- **各処理には日本語でコメントを書く**
- **エラーハンドリングは `try-catch` を使う。** 想定外の例外は `Controller::failed()` でログに残し、利用者には「問題が発生しました…」を表示する(スタックトレース等を見せない)。`ValidationException` は握りつぶさず再スローする
- **例外・異常は必ずログに残す。** コントローラは `failed()`(例外・URL・ルート名・ユーザーIDを記録)、サービスで例外を受け止めて処理を続ける場合(メール送信失敗など)は `Log::error` / `Log::warning` を出す。try-catch していない例外はLaravel標準の仕組みで記録される
  - ログにはパスワード・認証コード・リクエストの入力値を出さない(ユーザーはIDで記録する)
  - インストール前は storage/logs に書けないことがあるので、`InstallerService` では `logSafely()` を使う
- 権限チェックはPolicy(`app/Policies/`)で行う。お客様が見られないプロジェクト・課題は `Response::denyAsNotFound()` で404にして存在自体を知らせない
- 新しい画面・フォームは、ユーザー共通方針(二重送信防止・確認ダイアログ・戻るボタン・D&D・ページング・サムネイル・レスポンシブ・ロール別ログアウト)に従う。仕組みは下記「共通部品」を使う
- **画面・権限・制限値・通知・設定などを変えたら [docs/spec.md](docs/spec.md) も更新する**(仕様書と実装を食い違わせない)

## 2つのポータル(重要)
- 社内用(`/staff/...`、ルート名 `staff.*`、ガード `staff`)とお客様用(`/...`、ルート名 `customer.*`、ガード `customer`)がある。ユーザーは1テーブル(`users.role`)で、**ポータル名 = ガード名 = ロール値** に揃えている
- 課題・コメント・添付・ホームは**同じコントローラを両方のルートグループに登録**している(`routes/web.php` の `$sharedRoutes`)
- **ビューでは `route()` ではなく `portal_route('issues.show', $issue)` を使う**(現在のポータルのURLになる)。メールのリンクは受信者のロールで `Portal::routeFor()` を使う
- `UsePortal` ミドルウェアが `Auth::shouldUse()` するので、`$request->user()` / `@can` は現在のポータルのユーザーで判定される
- **ログアウトは `Auth::guard(portal())->logout()` のみ。`session()->invalidate()` は使わない**(同じブラウザの別ポータルまでログアウトされるため)。強制ログアウト(`EnsureActiveUser`)も同様
- ログアウト時は `session()->migrate(true)`(IDだけ変更・CSRFトークンは維持)。ログイン時はセッション固定攻撃対策で `regenerate()`(CSRFトークンも再発行)しているため、別タブで開いていた別ポータルのフォームは一度再読み込みが必要になる(ログイン状態は維持される)
- `UsePortal` は `bootstrap/app.php` で `AuthenticatesRequests` より前に実行されるよう優先度リストに割り込ませている。消すと未ログイン時のリダイレクト先などがずれる

## 共通部品
- ロゴ: `<x-brand tone="white|color" size="sm|md|lg" />`。サイト名が「ムシトリ」ならロゴ画像、独自のサイト名なら網のアイコン+サイト名。濃い色の背景には `tone="white"`(文字だけ白いロゴ)。画像は元画像 `resources/brand/logo-original.png` から `php -d memory_limit=1G scripts/make-logo-assets.php` で作り直せる(public/images の画像を直接編集しない)
- テーマカラー: `.env` の `APP_THEME_COLOR`(標準 `#0D223A`)から `App\Support\ThemeColor` がホバー色・リンク色・文字色(白/黒)を計算し、`partials/head-assets` で CSS変数(`--mt-primary` / `--mt-on-primary` / `--mt-link` 等)として出力する。**CSSで色を足すときは固定値ではなくこれらの変数を使う**(テーマカラーの上の文字は `--mt-on-primary`)。社内用ヘッダーはテーマに関係なくチャコール+アンバー
- 使い方(ヘルプ): `resources/views/help/staff.blade.php`(社内向け)・`help/customer.blade.php`(お客様向け)。枠組みは `<x-help-page :sections="[...]">`、手順は `<ol class="help-steps">`、画面のボタンの見本は `<span class="help-ui btn ...">`。**画面のボタン名・手順・制限値を変えたら、使い方も更新する**(制限値は定数を参照して書く)。お客様向けには社内用のURL・管理機能を書かない(`HelpTest` で確認)
- 確認ダイアログ: `<form data-confirm="メッセージ" data-confirm-ok="削除する" data-confirm-variant="danger">`(`confirm()` は使わない)
- 二重送信防止: POSTフォームは `public/js/app.js` の `app`(body で全フォームの送信を受け取る)が送信後にボタンを自動で無効化する(個別対応は不要)
- 添付入力: `<x-attachment-input />`(D&D・Ctrl+V貼り付け・プレビュー)。サーバー側のルールは `AttachmentService::rules()`
- 添付表示: `<x-attachment-grid :attachments="..." />`(サムネイル・拡大表示)
- 戻るボタン: `<x-back-button :href="..." label="..." />`
- 課題一覧: `<x-issue-list :issues="..." />`(PCは表・スマホはカード)
- 本文表示: `format_text()`(エスケープ+改行+URLリンク)。`{!! !!}` で生のユーザー入力を出さない

## 注意事項
- 画像は長辺 `AttachmentService::MAX_IMAGE_EDGE`(2560px)を超える場合だけ同じ形式のまま縮小して保存する(スクリーンショットの文字が読めることを優先。PNGは可逆のまま)。JPEGは縮小時にEXIFが消えるため、向きは `App\Support\JpegOrientation`(exif拡張不要の自前解析)で画素に反映してから保存する。GDのメモリ不足はcatchできないので、画像を展開する前に `ensureMemory()` で必要量(縮小後の画像分も含む)を確保する。1リクエストの上限は `IMAGE_MEMORY_LIMIT`(既定256M)。**回転は必ず縮小した後の小さい画像で行う**(元の大きさで回転するとメモリが倍必要)
- 大きな画像はブラウザ側(`public/js/app.js` の `shrinkImage()`)でも送信前に縮小する(サーバーの負荷・通信量の削減)。縮小は1つずつ順番に行い、縮小中は送信できない。サーバー側の縮小はブラウザで縮小できなかった場合の保険
- Webサーバー・PHPの設定例は `deploy/nginx/`・`deploy/apache/`(それぞれHTTPS・HTTPのみ・ALBの3種類)・`deploy/php/`。添付の投稿のため、投稿の上限(110MB)と処理時間(120秒)を全設定で揃え、上限超えはWebサーバーが `public/errors/413.html`(静的な日本語ページ)を表示する。**添付の上限(`AttachmentService`)を変えたら、設定例と 413.html も合わせて変える**(`tests/Feature/DeployConfigTest.php` で確認)。コマンド版インストーラは deploy/nginx を元に Nginx の設定を生成する
- **削除は論理削除(ゴミ箱)。** プロジェクト・課題・コメント・添付ファイルは `App\Models\Concerns\Trashable` の `trashBy($actor)` で削除する(削除日時と削除した人を記録するだけで、データも添付ファイルの実体も消さない)。コメントを削除したら、その添付も一緒に論理削除する。ユーザーは削除せず「無効」にする
- **物理削除は `App\Services\TrashService` だけ。** ゴミ箱で社内メンバーが「完全に削除」したとき、と保管期間(`TRASH_RETENTION_DAYS`、既定30日)を過ぎたものを毎日4時に自動で消すとき(`app:purge-trash`)に限り、添付ファイルの実体も含めて消す。完全に削除したコメントは、課題の経緯として変更履歴だけを残す(`purged_at`)。それ以外の場所で `forceDelete`・ファイルの削除・本文を空にする等をしない(`tests/Feature/SoftDeleteTest.php`・`TrashTest.php` で確認)。例外は、登録に失敗したときに保存済みのファイルを片付ける処理(`AttachmentService::deleteFiles()`。DBに記録されていないファイル)だけ
- 添付ファイルはDBに格納しない(DB肥大化・バックアップ遅延・配信時のメモリ消費を避けるため)。ファイルはストレージ、メタデータのみDB
- バックアップは `php artisan app:backup`(`app/Services/BackupService.php`)。DB(MySQLは mysqldump、SQLiteは VACUUM INTO)+添付+.env+復元手順を1ファイルにまとめる。`update.sh` は更新前に必ず実行する。`BACKUP_DAILY=true` で毎日3時に自動実行
- 添付ファイルは `local` ディスク(`storage/app/private`)に保存し、`AttachmentController` で権限チェックして配信する。`public` ディスクに置かないこと。画像以外は必ずダウンロード(`Content-Disposition: attachment`)で返す
- SVG・HTMLは添付不可。画像拡張子のファイルは中身も画像であることを確認している(`AttachmentService::imageContentRule()`)
- 課題番号は `projects.next_issue_number` を行ロックして採番する(`IssueService::create()`)
- 課題の `{issue}` ルートパラメータは課題キー(`WEB-12`)で、`Issue::resolveRouteBinding()` で解決する。プロジェクトキーは作成後に変更不可(URL・課題キーが変わるため)
- コメントの変更履歴の列名は `change_log`(Eloquentの `$changes` プロパティと衝突するため `changes` にしない)
- 認証コード・メールアドレス等の認証に関わる値をログやエラーメッセージに平文で出さない(開発環境のメールログは除く)
- 利用者に見えるメッセージはすべて日本語にする: 入力チェックは `lang/ja/validation.php`(Laravelの全ルールを翻訳済み。項目名は各FormRequestの `attributes()`)、Laravel本体の文言(メールのフッター等)は `lang/ja.json`、エラー画面は `resources/views/errors/`(個別の画面が無いステータスは `4xx` / `5xx`)。コマンドの質問は `required: '入力してください。'`、`confirm(..., yes: 'はい', no: 'いいえ')` とする。漏れは `tests/Feature/JapaneseMessagesTest.php` で確認する(Laravelの更新でルールが増えると失敗するので、翻訳を足す)
- ロードバランサー(ALB)対応: `bootstrap/app.php` の `trustProxies()` で信頼するのは `HEADER_X_FORWARDED_AWS_ELB`(Proto・Port・For)のみ。**X-Forwarded-Host は信頼しない**(Hostを偽装してメール内リンク等を書き換えられないように)。信頼する接続元は `.env` の `TRUSTED_PROXIES`(config `trustedproxy.proxies`。インストーラはプライベートIPの範囲 `InstallerService::PRIVATE_NETWORKS` を設定する)。**`TRUSTED_PROXIES=*` は使わない・案内しない**(Laravel 13 では 0.0.0.0/0 を信頼する意味になり、X-Forwarded-For の偽装で `$request->ip()` を変えられ、ログインの回数制限をすり抜けられる)。ALB用Nginx設定は `fastcgi_param REMOTE_ADDR $realip_remote_addr` でALBのIPをPHPに渡す(real_ip で書き換えた利用者IPを渡さない)。本番では `AppServiceProvider::configureUrls()` が `APP_URL` で `URL::forceRootUrl()`/`forceScheme()` するので、TRUSTED_PROXIES が無くてもURLは APP_URL 基準。テストは `tests/Feature/BehindLoadBalancerTest.php`
- ローカル開発のMySQL接続情報は `.env`、DB作成SQLは `database/sql/setup-local-mysql.sql`(どちらもGit管理外)

## セキュリティ上の約束事(再発防止テストは `tests/Feature/SecurityTest.php`)
- ログイン: 入力ミスの回数はユーザーID単位で数える(`LoginCodeService::failureKey()`)。メールアドレスの照合はDBの `=` だけに頼らず、PHPで完全一致も確認する(MySQLの `utf8mb4_unicode_ci` は「à」と「a」、全角と半角を同一視するため)。未登録のメールアドレスも同じように入力ミスを数える(登録の有無を推測させない)。宛先ごとの送信回数の上限(1時間10回)はIPに関係なく効かせる(`LoginCodeService::send()` 内で数える。ミドルウェアではなくサービスで数えるのは、ロック解除でリセットできるようにするため)。ロックの解除は `LoginCodeService::unlock()`(入力ミスの回数と送信回数をリセット)。社内メンバーはユーザー編集画面から、サーバーでは `app:unlock-login` で解除する
- 見られないプロジェクトのもの(課題・コメント・添付)は、閲覧以外の操作(削除など)でも 403 ではなく 404 を返す(Policyで `canAccessProject()` を先に確認して `Response::denyAsNotFound()`)
- クエリ文字列は `(string) $request->query()` で文字列化しない(`?q[]=x` で500になる)。`Controller::queryString()` を使う
- メール本文のリンクは `Portal::routeFor()`(常に APP_URL 基準)で作る。`route()` をそのまま使うとHostヘッダーの偽装でリンクを書き換えられる
- 添付画像は5000万画素まで(`AttachmentService::MAX_IMAGE_PIXELS`。小さいファイルで巨大な画像を作れるため)。添付の配信は `Cache-Control: private, no-cache` + ETag(権限を毎回確認させ、変更が無ければ304)
- セキュリティ関連のヘッダー(X-Frame-Options 等)は `SecurityHeaders` ミドルウェアで全応答に付ける(Nginxの設定例には書かない)
- インストーラ: 改行などの制御文字・不正なホスト名は受け付けない(`InstallerService::assertSafeSettings()`)。Nginx の設定に入るドメインも英数字・ドット・ハイフンに限る(`app:nginx-config`)

## インストーラ・公開(OSS)
- **インストール・アップデートは SSH 前提(コマンドだけ)。** Webの画面からインストール・更新する機能(ブラウザ版インストーラ・データベースの更新画面)は、レンタルサーバー(SSHなし)向けに作ったが、利用者が自分で設置するのは難しく、サポートの負担も大きいため削除した。**作り直さない**(自分で設置できない人は有償の導入支援へ案内する)。配布用zip(vendor込み)も同じ理由で廃止した(配布は git clone + install.sh、更新は update.sh の1通りだけ。zip の上書き更新は削除したファイルがサーバーに残る問題もある)
- インストールは `install.sh`(依存パッケージ → `php artisan app:install`(`app/Console/Commands/InstallCommand.php`) → 本番サーバーで sudo が使えれば `deploy/setup-server.sh` を続けて実行するか聞く)。全項目をオプションでも指定でき、`--no-interaction` で自動インストールできる。実際の処理(.env書き込み・DB作成・管理者登録・テストメール)は `app/Services/InstallerService.php`
- インストーラは `.env` を `app/Support/Installer/EnvFile.php` で書き換える(記号入りパスワードも安全に引用)。**APP_KEYは未設定のときだけ生成**し、再実行(--force)でも作り直さない(作り直すと既存データが復号できなくなる)
- インストーラに設定項目を足したら `tests/Standalone/InstallCommandTest.php` も更新する(新しい質問を足すと、答えていない既存テストが失敗する)
- 完了時の「次にやること」(`InstallerService::nextSteps()`)は、PHP-FPM の実行ユーザー(`App\Support\Installer\PhpFpmUser` が www.conf から調べる)で権限・cron のコマンドを表示する(コマンド版はSSHのユーザーで動くため。権限の変更はインストールの後に行う)
- **サーバー設定スクリプト** `deploy/setup-server.sh`(VPS用・root で実行。インストール後に使う。.env が無ければ止まる): 書き込み権限・Nginx(`/etc/nginx/conf.d/mushitori.conf`)・cron をまとめて行う。**最初に内容を表示して確認してから実行し、同じドメインの設定が他のファイルにあれば Nginx は変えない・`nginx -t` が通らなければ元に戻す**(1台に複数のサイトがあるサーバーを壊さないため。この安全策を外さない)。Nginx の設定の中身は `php artisan app:nginx-config`(`InstallerService::nginxConfig()`。コマンド版インストーラと共通)で作り、Webサーバーの実行ユーザーで動かす(root で artisan を動かすと storage に root 所有のファイルができるため)。テストは `tests/Standalone/SetupServerScriptTest.php`(chown・nginx・crontab 等を記録するだけの代役に置き換えて実行。Windows では Git の bash を使う)
- `public/.user.ini` は php-fpm が読み込むPHP設定(添付の上限など)。値は `deploy/php/99-mushitori.ini` と揃える(`DeployConfigTest`)
- **アップデート**: `update.sh`(git で設置した場合のみ。更新前のバックアップ(失敗したら中止) → メンテナンスモード → git pull → composer install → migrate → キャッシュの作り直し)
  - **テーブルの変更は必ず新しいマイグレーションファイルで行う**(適用済みのファイルを書き換えても、既存の環境には反映されない)。既存データの変換もマイグレーションに書く
  - 1つのマイグレーションには1つの変更だけにする(MySQLではテーブルの変更をトランザクションで戻せないため、途中で失敗したときに、どこまで反映されたか分かるように)
  - .env に新しい設定を足すときは、未設定でも動く既定値を config に書く(アップデートでは .env を書き換えないため)
- **外部からのプルリクエストは当面受け付けない**(不具合報告・要望は Issues のみ。方針は CONTRIBUTING.md・`.github/` のテンプレート)
- GitHubでMITライセンスとして公開する前提。**実在の取引先名・案件名・個人のメールアドレスをコード・テスト・プレースホルダに書かない**(例は「株式会社サンプル」「WEB」「example.com」)
- `tests/Standalone/` のテスト(インストーラ・バックアップ・サーバー設定)は RefreshDatabase を使わず、一時ディレクトリに .env・SQLiteファイルを作る。RefreshDatabase はテストをトランザクションで囲むため、SQLiteの `VACUUM INTO`(バックアップ)が実行できない
- 管理者の復旧は `php artisan app:create-staff メールアドレス`(お客様を指定した場合は確認してから社内メンバーに変更)、ログインのロック解除だけなら `php artisan app:unlock-login メールアドレス`(ロール等は変えない)

## 開発コマンド
```bash
php artisan migrate --seed                       # 初期データ(最初の社内メンバー)
php artisan db:seed --class=DemoSeeder           # サンプルデータ
php -d upload_max_filesize=20M -d post_max_size=120M -S 127.0.0.1:8000 -t public scripts/dev-router.php
php artisan test
./vendor/bin/pint
```
