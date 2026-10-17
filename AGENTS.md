# プロジェクト概要(ムシトリ)

お客様との不具合・要望を管理する、必要な機能に絞ったシンプルな課題管理ツール。お客様がスクリーンショットを添えて課題を起票し、社内メンバーが担当者・状態を管理してコメントでやり取りする。仕様(画面・権限・制限値・通知・設定など)は [docs/spec.md](docs/spec.md)、設置方法は [README.md](README.md) を参照。

## 技術構成
- PHP 8.3+(開発機は8.4) / Laravel 13 / MySQL 8.0 / Nginx + PHP-FPM
- 画面はBladeで描画。Bootstrap 5.3.3・Bootstrap Icons 1.11.3 は `public/vendor/` に同梱(CDNは使わない。社内LAN等でも動くように)。npm・Vite等のビルドは使わない(`public/css/app.css`、`public/js/app.js` を直接編集)
- テストはPest。SQLite(メモリ)で実行する

## コーディング規約
- PSR-12(`./vendor/bin/pint` で整形)
- **ビジネスロジックはControllerに書かない。** `app/Services/` のServiceクラスに書き、Controllerはリクエスト受け取り・権限チェック・Service呼び出し・レスポンス返却のみ
  - 一覧・検索のクエリ、画面表示用のデータの読み込み(load)もServiceに書く(例: `DashboardService`、`UserService::search()`、`IssueService::loadForDisplay()`)
  - 入力チェックは FormRequest(`app/Http/Requests/`)に書く。入力値の正規化(大文字・小文字の統一など)は `prepareForValidation()` で行う
- **各処理には日本語でコメントを書く**
- **エラーハンドリングは `try-catch` を使う。** 想定外の例外は `Controller::failed()` でログに残し、利用者には「問題が発生しました…」を表示する(スタックトレース等を見せない)。`ValidationException` は握りつぶさず再スローする
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
- テーマカラー: `.env` の `APP_THEME_COLOR`(標準 `#0D223A`)から `App\Support\ThemeColor` がホバー色・リンク色・文字色(白/黒)を計算し、`partials/head-assets` で CSS変数(`--mt-primary` / `--mt-on-primary` / `--mt-link` 等)として出力する。**CSSで色を足すときは固定値ではなくこれらの変数を使う**(テーマカラーの上の文字は `--mt-on-primary`)。社内用ヘッダーはテーマに関係なくチャコール+アンバー
- 確認ダイアログ: `<form data-confirm="メッセージ" data-confirm-ok="削除する" data-confirm-variant="danger">`(`confirm()` は使わない)
- 二重送信防止: POSTフォームは `public/js/app.js` が送信後にボタンを自動で無効化する(個別対応は不要)
- 添付入力: `<x-attachment-input />`(D&D・Ctrl+V貼り付け・プレビュー)。サーバー側のルールは `AttachmentService::rules()`
- 添付表示: `<x-attachment-grid :attachments="..." />`(サムネイル・拡大表示)
- 戻るボタン: `<x-back-button :href="..." label="..." />`
- 課題一覧: `<x-issue-list :issues="..." />`(PCは表・スマホはカード)
- 本文表示: `format_text()`(エスケープ+改行+URLリンク)。`{!! !!}` で生のユーザー入力を出さない

## 注意事項
- 画像は長辺 `AttachmentService::MAX_IMAGE_EDGE`(2560px)を超える場合だけ同じ形式のまま縮小して保存する(スクリーンショットの文字が読めることを優先。PNGは可逆のまま)。JPEGは縮小時にEXIFが消えるため、向きは `App\Support\JpegOrientation`(exif拡張不要の自前解析)で画素に反映してから保存する。GDのメモリ不足はcatchできないので、画像を展開する前に `ensureMemory()` で必要量(縮小後の画像分も含む)を確保する。1リクエストの上限は `IMAGE_MEMORY_LIMIT`(既定256M)。**回転は必ず縮小した後の小さい画像で行う**(元の大きさで回転するとメモリが倍必要)
- 大きな画像はブラウザ側(`public/js/app.js` の `shrinkImage()`)でも送信前に縮小する(サーバーの負荷・通信量の削減)。縮小は1つずつ順番に行い、縮小中は送信できない。サーバー側の縮小はブラウザで縮小できなかった場合の保険
- 添付ファイルはDBに格納しない(DB肥大化・バックアップ遅延・配信時のメモリ消費を避けるため)。ファイルはストレージ、メタデータのみDB
- バックアップは `php artisan app:backup`(`app/Services/BackupService.php`)。DB(MySQLは mysqldump、SQLiteは VACUUM INTO)+添付+.env+復元手順を1ファイルにまとめる。`update.sh` は更新前に必ず実行する。`BACKUP_DAILY=true` で毎日3時に自動実行
- 添付ファイルは `local` ディスク(`storage/app/private`)に保存し、`AttachmentController` で権限チェックして配信する。`public` ディスクに置かないこと。画像以外は必ずダウンロード(`Content-Disposition: attachment`)で返す
- SVG・HTMLは添付不可。画像拡張子のファイルは中身も画像であることを確認している(`AttachmentService::imageContentRule()`)
- 課題番号は `projects.next_issue_number` を行ロックして採番する(`IssueService::create()`)
- 課題の `{issue}` ルートパラメータは課題キー(`WEB-12`)で、`Issue::resolveRouteBinding()` で解決する。プロジェクトキーは作成後に変更不可(URL・課題キーが変わるため)
- コメントの変更履歴の列名は `change_log`(Eloquentの `$changes` プロパティと衝突するため `changes` にしない)
- 認証コード・メールアドレス等の認証に関わる値をログやエラーメッセージに平文で出さない(開発環境のメールログは除く)
- ローカル開発のMySQL接続情報は `.env`、DB作成SQLは `database/sql/setup-local-mysql.sql`(どちらもGit管理外)

## インストーラ・公開(OSS)
- **インストール・アップデートは SSH 前提(コマンドだけ)。** Webの画面からインストール・更新する機能(ブラウザ版インストーラ・データベースの更新画面)は、レンタルサーバー(SSHなし)向けに作ったが、利用者が自分で設置するのは難しく、サポートの負担も大きいため削除した。**作り直さない**(自分で設置できない人は有償の導入支援へ案内する)。配布用zip(vendor込み)も同じ理由で廃止した(配布は git clone + install.sh、更新は update.sh の1通りだけ。zip の上書き更新は削除したファイルがサーバーに残る問題もある)
- インストールは `install.sh`(依存パッケージ → `php artisan app:install`(`app/Console/Commands/InstallCommand.php`) → 本番サーバーで sudo が使えれば `deploy/setup-server.sh` を続けて実行するか聞く)。全項目をオプションでも指定でき、`--no-interaction` で自動インストールできる。実際の処理(.env書き込み・DB作成・管理者登録・テストメール)は `app/Services/InstallerService.php`
- インストーラは `.env` を `app/Support/Installer/EnvFile.php` で書き換える(記号入りパスワードも安全に引用)。**APP_KEYは未設定のときだけ生成**し、再実行(--force)でも作り直さない(作り直すと既存データが復号できなくなる)
- インストーラに設定項目を足したら `tests/Standalone/InstallCommandTest.php` も更新する(新しい質問を足すと、答えていない既存テストが失敗する)
- 完了時の「次にやること」(`InstallerService::nextSteps()`)は、PHP-FPM の実行ユーザー(`App\Support\Installer\PhpFpmUser` が www.conf から調べる)で権限・cron のコマンドを表示する(コマンド版はSSHのユーザーで動くため。権限の変更はインストールの後に行う)
- **サーバー設定スクリプト** `deploy/setup-server.sh`(VPS用・root で実行。インストール後に使う。.env が無ければ止まる): 書き込み権限・Nginx(`/etc/nginx/conf.d/mushitori.conf`)・cron をまとめて行う。**権限の持ち方**: `storage` は Webサーバーの所有、`bootstrap/cache` は設置したユーザー:Webサーバーのグループ・g+w・setgid(composer の package:discover と Web の両方が書き込めるように)、`.env` は設置したユーザー:Webサーバーのグループ・640。chown は `-h`(シンボリックリンク先を変えない)。SQLite のパスは設置フォルダの中だけ扱う(.env を書き換えられても他の場所の所有者を root で変えさせない)。**最初に内容を表示して確認してから実行し、同じドメインの設定が他のファイルにあれば Nginx は変えない・`nginx -t` が通らなければ元に戻す**(1台に複数のサイトがあるサーバーを壊さないため。この安全策を外さない)。**Nginx の設定はスクリプト自身が deploy/nginx のテンプレートから作る**(PHP を使うと、Webサーバーのユーザーが書き込める bootstrap/cache を読み込んだ出力を root が信用することになるため)。置き換える内容は `InstallerService::nginxConfig()`(コマンド版インストーラ・`app:nginx-config`)と同じにし、一致はテストで確認する。HTTPS の証明書が無ければ HTTP のテンプレートを置き、`certbot --nginx` を案内する(証明書が無いと `nginx -t` が通らないため)。テストは `tests/Standalone/SetupServerScriptTest.php`(chown・nginx・crontab 等を記録するだけの代役に置き換えて実行。Windows では Git の bash を使う)
- `public/.user.ini` は php-fpm が読み込むPHP設定(添付の上限など)。値は `deploy/php/99-mushitori.ini` と揃える(`DeployConfigTest`)
- **アップデート**: `update.sh`(git で設置した場合のみ。更新前のバックアップ(失敗したら中止) → メンテナンスモード → git pull → composer install → migrate → キャッシュの作り直し)。git pull・composer は実行したユーザーで、**artisan は Webサーバーのユーザーで実行する**(`sudo -u`。storage は Webサーバーの所有のため。README の artisan の例も `sudo -u apache php artisan …` にする)
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
