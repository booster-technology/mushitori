<p align="center"><img src="public/images/logo.png" alt="ムシトリ" width="360"></p>

# ムシトリ（Mushitori）

**スクショを貼って、バグをつかまえよう。** ― お客様との不具合・要望のやり取りに特化した、軽量な課題管理ツール

> **English:** **Mushitori** (Japanese for "bug catching") is a simple, self-hosted issue tracker for handling bug reports and requests from your clients.
> Clients file issues by pasting screenshots (Ctrl+V / drag & drop), log in without passwords (one-time email codes), and only see their own projects.
> Built with Laravel (PHP 8.3+), MySQL or SQLite, and installs in minutes with an interactive installer. MIT licensed.
> **The UI and documentation are currently Japanese only.** English support may be added if there is demand — feel free to open an issue in English.

お客様がスクリーンショットを貼り付けて不具合を起票し、社内で担当者・状態を管理しながらコメントでやり取りできます。
名前は「バグ（ムシ）取り」から。不具合だけでなく、要望や質問もまとめてつかまえられます。
受託開発・Web制作・システム保守など「お客様から不具合報告を受ける仕事」向けに、必要な機能だけに絞っています。

- インストールは数分（`install.sh` を実行して質問に答えるだけ。SQLiteならDBの準備も不要）
- パスワード不要のログイン（メールに届く6桁のコード）で、お客様にアカウント管理の手間をかけない
- 日本語・スマホ対応

## 特徴

| | |
|:--|:--|
| 📸 スクショをそのまま起票 | Ctrl+V で貼り付け、ドラッグ＆ドロップ、スマホは写真を選択。画像はサムネイル表示・クリックで拡大。長辺2560pxを超える写真は自動で縮小（スクリーンショットの文字はつぶさない） |
| 📝 書き方に迷わない | 種別（不具合／要望／質問）ごとに「操作手順・期待する動作・実際の動作」などの記入テンプレートを自動入力 |
| 🔐 パスワード不要 | メールアドレスを入力すると6桁の認証コードが届く方式。パスワードの再発行対応が不要 |
| 🏢 お客様ごとに分離 | 社内用とお客様用で画面・ログインが別。お客様は自社のプロジェクトしか見えない |
| 🔄 分かりやすい進捗管理 | 未対応→処理中→処理済み→完了。担当者・優先度・期限日の変更はコメントと一緒に履歴が残る |
| ✉️ メール通知 | 起票・コメント時に関係者へ通知（社内用／お客様用それぞれのURLで届く） |
| 📱 スマホ対応 | 一覧はPCで表、スマホでカード表示 |
| 🎨 テーマカラー | インストール時に会社・サービスの色を選べる（標準は濃紺 #0D223A）。明るい色でも文字色を自動調整 |

## 動作環境

- **SSHでログインしてコマンドを実行できるサーバー**（VPS・クラウド・SSHが使えるレンタルサーバー）
- PHP 8.3 以上（拡張: mbstring, openssl, pdo_mysql または pdo_sqlite, gd, fileinfo, xml, ctype, tokenizer）
- データベース: MySQL 8.0 / MariaDB 10.6 以上、または SQLite 3（少人数向け）
- Webサーバー: Nginx + PHP-FPM（設定を自動で作成）または Apache + PHP-FPM（設定例あり）
- メール送信用のSMTPサーバー（Amazon SES、Gmail、レンタルサーバーのSMTP など）

自分で設置するのが難しい場合は、[導入支援・カスタマイズ](#導入支援カスタマイズ)をご利用ください。

## サーバーのメモリの目安

画像は1つずつ処理し、1リクエストあたりのメモリ使用量は最大でも `IMAGE_MEMORY_LIMIT`（既定256MB）に抑えています。スマホの大きな写真は送信前にブラウザで縮小するため、通常はサーバーで大きな画像を扱いません。

| 画像（サーバーで縮小する場合） | 使用メモリの目安 |
|:--|--:|
| 4Kのスクリーンショット | 約70MB |
| スマホ写真 1200万画素 | 約70MB |
| スマホ写真 2400万画素 | 約120MB |

PHP-FPMの同時処理数（`pm.max_children`）は「(サーバーのメモリ − OS・MySQLの分) ÷ 256MB」程度を目安にしてください（例: メモリ2GB・MySQL同居なら 4）。

## インストール

SSHでサーバーにログインし、コマンドで設置します。VPS なら、次の2つのコマンドで設置からサーバーの設定まで終わります（`install.sh` の最後に、続けてサーバーの設定を行うか聞かれます）。

```bash
bash install.sh                    # 依存パッケージ・.env・データベース・最初の管理者
sudo bash deploy/setup-server.sh   # 書き込み権限・Nginx・cron
```

### 設置場所（重要）

ムシトリ一式は、Webサーバーの公開ディレクトリ（ドキュメントルート）の**外**に置き、**`public/` フォルダだけを公開**してください。一式を公開ディレクトリに置くと、`https://example.com/.env` でデータベースのパスワードや暗号鍵が誰でも見られてしまいます。

```
/var/www/mushitori/          ← 一式（.env・app・vendor・storage…）。公開しない
/var/www/mushitori/public/   ← ここだけを公開する（中身は index.php・CSS・JS・画像のみ）
```

- **Nginx**: `root /var/www/mushitori/public;` にします（インストーラ・`setup-server.sh` が作る設定、[deploy/nginx/mushitori.conf](deploy/nginx/mushitori.conf) はこの形です）。Ubuntuでは `/var/www/html` を公開している既定のサイト設定（`/etc/nginx/sites-enabled/default`）を無効にしてください
- **公開ディレクトリを変えられない場合**（`/var/www/html` やレンタルサーバーの `public_html` が固定など）: 公開ディレクトリを `public/` へのシンボリックリンクにします

  ```bash
  sudo mv /var/www/html /var/www/html.bak
  sudo ln -s /var/www/mushitori/public /var/www/html
  ```

- **やってはいけない置き方**: `/var/www/html` に一式を置いて `https://example.com/public/` で開く、`/var/www/html/mushitori/` に置いて `https://example.com/mushitori/public/` で開く
- **確認**: 設置後に `curl -I https://example.com/.env` を実行し、`404` または `403` になることを確認してください（`200` なら公開されています）

### 本番サーバー（Linux + Nginx）

1. PHP・Nginx・MySQLをインストールする

   ```bash
   # Ubuntu
   sudo apt install nginx mysql-server php8.3-fpm php8.3-cli php8.3-mysql php8.3-sqlite3 php8.3-gd php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip unzip git cron
   # Amazon Linux 2023(MySQL は RDS などを使う場合)
   sudo dnf install nginx php8.3 php8.3-fpm php8.3-mysqlnd php8.3-gd php8.3-mbstring php8.3-xml php8.3-pdo git cronie
   ```

2. 設置してインストーラを実行する

   ```bash
   cd /var/www
   sudo git clone https://github.com/booster-technology/mushitori.git
   sudo chown -R $USER mushitori && cd mushitori
   bash install.sh
   ```

   「本番サーバーで使う」を選ぶと、以下を順に聞かれます。MySQLのデータベースが無ければインストーラが作成します。
   - 公開URL（ロードバランサーの後ろで動かすかどうかも）
   - データベース
   - メール送信（SMTP / Amazon SES / 送信しない）
   - 最初の管理者のメールアドレス

   依存パッケージは Composer で取得します（Composer が無ければ `install.sh` が公式サイトから取得します）。サーバーからインターネットに接続できる必要があります。

3. サーバーを設定する（書き込み権限・Nginx・cron）。`install.sh` の最後に「続けて、サーバーの設定を行いますか？」と聞かれるので、そのまま進めます。あとで行う場合は次のコマンドです（→「[サーバーの設定をまとめて行う](#サーバーの設定をまとめて行うsetup-serversh)」）

   ```bash
   sudo bash deploy/setup-server.sh
   ```

   手で行う場合は、インストーラの完了時に表示される「次にやること」に従います（PHP-FPM の実行ユーザーを調べて、実際のコマンドを表示します）。
4. **HTTPS**: `sudo certbot --nginx` など（ALB でHTTPSを終端する場合は不要）
5. `curl -I https://issues.example.com/.env` が `404` または `403` になることを確認する

### サーバーの設定をまとめて行う（setup-server.sh）

VPS・専用サーバー（Linux）で、インストールの後にWebサーバーまわりの設定をまとめて行うスクリプトです。最初に行う内容（Nginx の設定は今の設定との違いも）を表示し、確認してから実行します。何度実行しても大丈夫です。

```bash
sudo bash deploy/setup-server.sh            # URL などは .env から読み取ります
sudo bash deploy/setup-server.sh --dry-run  # 何をするかを表示するだけ
```

| 手順 | 内容 |
|:--|:--|
| 1. 書き込み権限 | `storage`・`bootstrap/cache` を PHP-FPM の実行ユーザー（`www.conf` から自動で調べる）の所有にする（SQLite の場合はデータベースのファイルも）。SELinux が有効なら書き込み用のラベルも付ける |
| 2. Nginx | このサーバー用の設定（`php artisan app:nginx-config` で作成。ALB の後ろ・HTTPS・HTTPのみを自動で選択）を `/etc/nginx/conf.d/mushitori.conf` に置き、`nginx -t` が通ったときだけ再読み込みする。通らなければ元に戻す |
| 3. cron | PHP-FPM の実行ユーザーで `schedule:run` を毎分実行するよう登録する（登録済みなら何もしない） |

- **1台に複数のサイトがあるサーバー向けの安全策**: 同じドメインの設定が他のファイル（`default.conf` など）にある場合は、Nginx の設定は変更しません。そのまま使い続けるか、そのファイルの該当する `server` ブロックを削除（コメントアウト）してから、もう一度実行してください。既存の `mushitori.conf` を置き換えるときは `mushitori.conf.bak.日時` に残します
- `--url=`（公開URL）、`--behind-proxy`（ALB の後ろ）、`--web-user=apache`（実行ユーザーを指定）、`--nginx-conf=`（Nginx の設定ファイルの場所）、`--no-nginx`・`--no-cron`（その手順を行わない）、`--yes`（確認しない）も使えます。詳しくは `bash deploy/setup-server.sh --help`
- Apache の場合は Nginx の手順を行いません（権限と cron だけ設定します。Apache の設定は「Webサーバー・PHPの設定例」を参照）

### SSHが使えるレンタルサーバー

PHP が自分のユーザーで動くレンタルサーバーでは、書き込み権限の設定は不要です（`setup-server.sh` も使いません）。

1. コントロールパネルで、PHP を **8.3 以上** にし、MySQL のデータベースとユーザーを作成しておく（少人数なら SQLite でも可）
2. SSH でログインし、公開フォルダの外（ホームディレクトリなど）に設置してインストールする

   ```bash
   cd ~
   git clone https://github.com/booster-technology/mushitori.git
   cd mushitori
   bash install.sh
   ```

   「本番サーバーで使う」を選び、データベースはコントロールパネルで作ったものを入力します。
3. ドメイン（サブドメイン）の公開フォルダを `mushitori/public` にする。コントロールパネルで変えられない場合は、公開フォルダを `public` へのシンボリックリンクにします（例: `mv ~/example.com/public_html ~/public_html.bak && ln -s ~/mushitori/public ~/example.com/public_html`）
4. **cron**（ゴミ箱の自動削除・自動バックアップに必要）: コントロールパネルのcron設定で、毎分次のコマンドを実行するよう登録します。PHPのパス（`/usr/bin/php8.3` の部分）はサーバーによって異なるので、コントロールパネルの案内を確認してください

   ```
   cd /home/ユーザー名/mushitori && /usr/bin/php8.3 artisan schedule:run > /dev/null 2>&1
   ```

5. `https://issues.example.com/.env` を開いて、`403` または `404` になることを確認する

- **PHPの設定**（添付ファイルの上限など）: 同梱の `public/.user.ini` で自動的に設定されます。大きな画像が添付できない場合は、コントロールパネルの php.ini 設定で `upload_max_filesize = 12M`・`post_max_size = 110M`・`max_execution_time = 120`・`memory_limit = 256M` にしてください
- **メール**: レンタルサーバーのメールアカウントをSMTPで使えます

### お試し（自分のPCで数分）

```bash
git clone https://github.com/booster-technology/mushitori.git
cd mushitori
bash install.sh
```

質問には「お試し・開発用」「SQLite」「送信しない」を選ぶと、データベースやメールの準備なしで動きます。
完了画面に表示されるコマンドで起動し、表示されたURLを開いてください（認証コードは `storage/logs/laravel-日付.log` に出力されます）。

> Windowsの場合は `bash install.sh` の代わりに `composer install` → `php artisan app:install` を実行してください。

### 質問なしで自動インストール

すべての項目をオプションで指定できます（一覧は `php artisan app:install --help`）。

```bash
bash install.sh --no-interaction --mode=production --name="課題管理" --url=https://issues.example.com \
  --db=mysql --db-host=127.0.0.1 --db-database=mushitori --db-username=mushitori --db-password='****' \
  --mail=smtp --mail-host=email-smtp.ap-northeast-1.amazonaws.com --mail-port=587 --mail-username='****' --mail-password='****' \
  --mail-from=noreply@example.com --admin-name="管理者" --admin-email=you@example.com
```

### Webサーバー・PHPの設定例

| ファイル | 用途 |
|:--|:--|
| [deploy/nginx/mushitori.conf](deploy/nginx/mushitori.conf) | Nginx・HTTPS（Let's Encrypt など） |
| [deploy/nginx/mushitori-http.conf](deploy/nginx/mushitori-http.conf) | Nginx・HTTPのみ（社内LANなど） |
| [deploy/nginx/mushitori-alb.conf](deploy/nginx/mushitori-alb.conf) | Nginx・AWS ALB などの後ろ |
| [deploy/apache/mushitori.conf](deploy/apache/mushitori.conf) | Apache・HTTPS |
| [deploy/apache/mushitori-http.conf](deploy/apache/mushitori-http.conf) | Apache・HTTPのみ |
| [deploy/apache/mushitori-alb.conf](deploy/apache/mushitori-alb.conf) | Apache・AWS ALB などの後ろ |
| [deploy/php/99-mushitori.ini](deploy/php/99-mushitori.ini) | PHP（投稿の上限・処理時間など） |
| [deploy/setup-server.sh](deploy/setup-server.sh) | 権限・Nginx・cron をまとめて設定するスクリプト（「サーバーの設定をまとめて行う」を参照） |

`php artisan app:nginx-config --url=https://issues.example.com` で、このサーバー用（URL・設置場所・PHP-FPM のソケットに合わせたもの）の Nginx 設定を表示できます（`--behind-proxy` で ALB 用）。コマンド版インストーラは、Nginx の設定（URL・設置場所に合わせたもの）を自動で作ります。Apache の場合は、設定例の `issues.example.com`（URL）・`/var/www/mushitori`（設置場所）・PHP-FPM のソケットのパスを書き換えて使ってください（`.htaccess` は使わず、`AllowOverride None` で動きます）。

画像などの添付ファイルの投稿に必要な値は、すべての設定で揃えています。どれか1つでも小さいと、大きな画像を添付したときにエラーになります。

| 項目 | PHP | Nginx | Apache | ALB |
|:--|:--|:--|:--|:--|
| 1回の投稿の上限（10MB × 10ファイル + 本文） | `post_max_size = 110M`（1ファイルは `upload_max_filesize = 12M`） | `client_max_body_size 110M` | `LimitRequestBody 115343360` | 上限なし |
| 処理を待つ時間（画像の縮小・サムネイル作成） | `max_execution_time = 120` | `fastcgi_read_timeout 120s` | `Timeout 120`・`ProxyTimeout 120` | アイドルタイムアウトを120秒に |

- 上限を超えた投稿には、Webサーバーが日本語の案内ページ（`public/errors/413.html`）を表示します
- 設定を変えたら `sudo nginx -t` または `sudo apachectl configtest` で確認してから再読み込みしてください

### メール送信に Amazon SES を使う

- **EC2で動かす場合（おすすめ）**: インストーラでメールの送り方に「Amazon SES（IAMロール）」を選び、リージョンと送信元を入力するだけです。SMTPのユーザー名・パスワードやアクセスキーは不要です（コマンドでは `--mail=ses --ses-region=ap-northeast-1 --mail-from=noreply@example.com`）
  - EC2のインスタンスに、次の権限を持つIAMロールを付けてください

    ```json
    {
      "Version": "2012-10-17",
      "Statement": [
        { "Effect": "Allow", "Action": ["ses:SendEmail", "ses:SendRawEmail"], "Resource": "*" }
      ]
    }
    ```

  - 送信元は、SESで認証済みのドメインかメールアドレスにします。SESのサンドボックス中は、認証済みのアドレスにしか送れません（本番利用の申請が必要です）
  - `.env` は `MAIL_MAILER=ses-v2` と `AWS_DEFAULT_REGION` だけで、アクセスキー（`AWS_ACCESS_KEY_ID`）は書きません。アクセスキーが無い場合、AWS SDK がサーバーのIAMロールの認証情報を自動で使います
- **EC2以外で動かす場合**: 「SMTPサーバーで送信する」を選び、SESのSMTP認証情報（SMTPサーバー `email-smtp.ap-northeast-1.amazonaws.com`・ポート587）を入力します

### ロードバランサー（AWS ALBなど）の後ろで動かす

HTTPSをALBで終端し、サーバーへはHTTPで転送する構成に対応しています。インストール時に「ロードバランサーの後ろで動かす」を選ぶと（質問なしの場合は `--behind-proxy=yes`）、以下が設定されます。

- `.env` の `TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12,192.168.0.0/16`: プライベートIPアドレス（=VPC内のALB）からの接続について、ALBが付ける `X-Forwarded-Proto` / `X-Forwarded-For` を信頼します。フォームの送信先やリンクが `https://` になり、ログインの回数制限が利用者のIPアドレス単位で働きます
- Nginx設定は [deploy/nginx/mushitori-alb.conf](deploy/nginx/mushitori-alb.conf) をもとに作成（80番で待ち受け。ALBにHTTPで届いたアクセスはHTTPSへ転送。`setup-server.sh` も `.env` の `TRUSTED_PROXIES` を見てこの設定を使います）

> ⚠️ `TRUSTED_PROXIES=*` は使わないでください。Laravelでは「すべてのIPアドレスを信頼する」意味になり、利用者が `X-Forwarded-For` を偽装して自分のIPアドレスを自由に変えられます（ログイン試行回数の制限をすり抜けられます）。

あわせて以下を設定してください。

- ALBのターゲットグループのヘルスチェックのパスは `/up`
- ALBのアイドルタイムアウト（既定60秒）は120秒にする（画像をまとめて添付した投稿は、送信と縮小に60秒以上かかることがあるため）
- Apache の場合は [deploy/apache/mushitori-alb.conf](deploy/apache/mushitori-alb.conf) を参考にしてください（`mod_remoteip` は使わない）
- サーバーのセキュリティグループで、80番はALBからのアクセスだけを許可する
- `TRUSTED_PROXIES` はVPCのCIDR（例: `10.0.0.0/16`）に絞るとより安全です
- 本番（`APP_ENV=production`）では、画面のURLは常に `.env` の `APP_URL` をもとに作ります。`APP_URL` は `https://` で始まるURLにしてください

## 使い始めの流れ

1. 社内用のログイン画面（`/staff/login`、URLはインストール時に変更可）から、管理者のメールアドレスでログイン
2. 「プロジェクト管理」でプロジェクト（例: `WEB` = コーポレートサイト保守）を作成
3. 「ユーザー管理」でお客様を登録し、プロジェクトに参加させる（ログイン案内メールが届きます）
4. お客様が `/login` からログインして課題を起票 → 社内に通知 → コメントでやり取り

操作方法は、ログイン後の画面上部の「使い方」にまとめています（社内メンバー向けとお客様向けの2種類。お客様にはお客様向けの説明だけが表示されます）。

## 削除とゴミ箱

プロジェクト・課題・コメント・添付ファイルを削除すると、社内メンバー用の「ゴミ箱」に移ります（論理削除）。

- **保管期間**: 削除してから30日間（`.env` の `TRASH_RETENTION_DAYS`）はゴミ箱から元に戻せます
- **自動で完全に削除**: 保管期間を過ぎたものは、毎日4時に画像などの添付ファイルの実体も含めて完全に削除します（cron で `schedule:run` が動いている必要があります。`TRASH_RETENTION_DAYS=0` にすると自動では削除しません）
- **すぐに完全に削除**: 個人情報が写った画像を誤って添付した場合などは、社内メンバーがゴミ箱の「完全に削除」ですぐに消せます（誰が消したかはログに残ります）
- コメントを完全に削除しても、状態などの変更履歴は課題の経緯として残ります
- 完全に削除したデータも、バックアップには保管世代数（`BACKUP_KEEP`、既定7日分）のあいだ残ります。削除したデータは最長で「保管期間 + バックアップの保管期間」（既定で37日）でサーバーから消えます

## バックアップ

```bash
php artisan app:backup
```

データベース・添付ファイル・`.env`・復元手順（RESTORE.txt）を1つのファイル（`storage/backups/backup-日時.zip`）にまとめます。

- **毎日自動で取る**: `.env` で `BACKUP_DAILY=true`（本番インストール時に選択可）。毎日3時に実行し、7日分を残します（`BACKUP_KEEP`）
- **保存先を変える**: `BACKUP_PATH=/mnt/backup/mushitori` など。サーバーの故障に備えて、別ディスクやS3などへのコピーもおすすめします
- **MySQLの場合**: `mysqldump` を使います（見つからない場合は `MYSQLDUMP_PATH` で場所を指定）
- **復元**: バックアップ内の RESTORE.txt の手順に従ってください（DBの取り込み → 添付ファイルのコピー → `.env` の配置）
- バックアップにはお客様のデータとパスワードが含まれるため、取り扱いに注意してください。`.env` を含めない場合は `--without-env`

## アップデート

設置したフォルダで実行します。更新前に自動でバックアップを取ってから（失敗したら更新しません）、最新版の取得（`git pull`）・依存パッケージの更新・データベースの更新・キャッシュの作り直しを行います。

```bash
./update.sh
```

- git で設置したフォルダでのみ使えます
- VPS では、更新後に `sudo bash deploy/setup-server.sh` で書き込み権限を付け直しておくと安全です（更新中に作られたファイルが、実行したユーザーの所有になることがあるため）

## 困ったとき

| 症状 | 対処 |
|:--|:--|
| 「入力ミスが続いたため、一時的にログインできません」と表示される | 認証コードを1時間に10回間違えるとロックされます（最初の入力ミスから1時間で自動解除）。すぐに解除するには、社内メンバーが「ユーザー管理」の編集画面で「ロックを解除」、またはサーバーで `php artisan app:unlock-login メールアドレス` |
| 管理者が全員ログインできなくなった | `php artisan app:create-staff you@example.com` で社内メンバーを追加・復旧（ロックも解除されます） |
| 認証コードのメールが届かない | `.env` の `MAIL_` の設定を確認。`storage/logs/laravel-日付.log` に送信エラーが出ていないか確認 |
| 大きな画像が添付できない・送信に時間がかかってエラーになる | php.ini の `post_max_size`・`max_execution_time` と、Nginx の `client_max_body_size`・`fastcgi_read_timeout`（Apache は `LimitRequestBody`・`ProxyTimeout`、ALB はアイドルタイムアウト）を確認（「Webサーバー・PHPの設定例」を参照） |
| フォームの送信先や画像・CSSが http:// になる（ALBの後ろ） | `.env` の `APP_URL` を `https://` で始まるURLにし、`TRUSTED_PROXIES` にALBのIPアドレスの範囲を設定（「ロードバランサー（AWS ALBなど）の後ろで動かす」を参照） |
| 「Composer detected issues in your platform」と表示される・画面が真っ白になる | PHPのバージョンが 8.3 より古い可能性があります（`php -v` で確認）。PHP 8.3 以上にしてください（レンタルサーバーはコントロールパネルで変更。コマンドの `php` と Web の PHP のバージョンが違う場合もあります） |
| ログに「Permission denied」が出る・画面がエラーになる | `storage`・`bootstrap/cache` に Webサーバー（PHP-FPM）が書き込めない状態です。`sudo bash deploy/setup-server.sh` で権限を付け直してください（cron を別のユーザーで動かしていると起きやすいので、cron も Webサーバーと同じユーザーで登録します） |
| 設定を変えたい | `.env` を編集（`config:cache` している場合は `php artisan config:cache` を再実行） |
| テーマカラーを変えたい | `.env` の `APP_THEME_COLOR` を `'#1E5AA8'` のように変更（`#` を含むので引用符で囲む） |

## 機能と権限

| 操作 | 社内メンバー | お客様 |
|:--|:--|:--|
| プロジェクト閲覧 | 全プロジェクト | 参加しているプロジェクトのみ |
| 課題の起票・コメント・状態変更 | ○ | ○ |
| 課題の編集（件名・詳細・添付） | ○ | 自分が起票した課題のみ |
| 担当者・優先度・期限日の変更 | ○ | × |
| 課題・他人のコメントの削除 | ○ | × |
| プロジェクト・ユーザー管理 | ○ | × |

詳しい仕様は [docs/spec.md](docs/spec.md) を参照してください。

## 不具合報告・要望

- 不具合の報告・要望・質問は [Issues](https://github.com/booster-technology/mushitori/issues) へお願いします
- セキュリティ上の問題は、公開の Issue には書かず [SECURITY.md](SECURITY.md) の方法で非公開でご連絡ください
- プルリクエスト（コードの修正提案）は当面受け付けていません。修正の提案も Issue でお知らせください（詳しくは [CONTRIBUTING.md](CONTRIBUTING.md)）

## ライセンス

[MIT License](LICENSE)

### 同梱しているライブラリ

インターネットに接続できない社内LANなどでも動くよう、次のライブラリを `public/vendor/` に同梱しています（各フォルダに LICENSE を同梱）。

| ライブラリ | バージョン | 場所 |
|:--|:--|:--|
| [Bootstrap](https://getbootstrap.com/)（MIT） | 5.3.3 | `public/vendor/bootstrap/` |
| [Bootstrap Icons](https://icons.getbootstrap.com/)（MIT） | 1.11.3 | `public/vendor/bootstrap-icons/` |
| [Alpine.js](https://alpinejs.dev/)（CSP対応版 `@alpinejs/csp`。MIT） | 3.17.4 | `public/vendor/alpinejs/` |
| [Noto Sans JP](https://fonts.google.com/noto/specimen/Noto+Sans+JP)（[Fontsource](https://fontsource.org/) の可変フォント版 `@fontsource-variable/noto-sans-jp`。SIL Open Font License 1.1） | 5.3.0 | `public/vendor/noto-sans-jp/` |

更新するときは、npm公式のパッケージ（`npm pack bootstrap@<バージョン>` など）から同じファイル名で置き換え、`resources/views/partials/head-assets.blade.php` と `body-assets.blade.php` の `?v=` を新しいバージョンにしてください。Alpine.js は `npm pack @alpinejs/csp@<バージョン>` の `dist/cdn.min.js` を `alpine-csp.min.js` として置き、LICENSE は [GitHubのリポジトリ](https://github.com/alpinejs/alpine) の同じバージョンのものを置いてください（npmのパッケージには含まれていないため）。Noto Sans JP は `npm pack @fontsource-variable/noto-sans-jp@<バージョン>` の `files/*.woff2`・`LICENSE` と、`index.css` を `noto-sans-jp.css` として置いてください。

### CSP（Content-Security-Policy）と外部参照

- 画面は自分のサイトのファイルだけで動き、外部のサイト（CDN・外部フォント・解析ツールなど）には一切接続しません
- インラインのスクリプト・スタイルや `eval` も使っていないため、`default-src 'self'` の厳しいCSPのまま動きます（`'unsafe-inline'`・`'unsafe-eval'` は不要）。本番（`APP_DEBUG=false`）ではアプリ自身が次のCSPを付けます

  ```
  default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'
  ```

- Webサーバーやロードバランサーで独自のCSPを付ける場合も、これと同じかそれ以上に厳しい内容で動きます。ただし `img-src` には `data:`（Bootstrapのアイコン）と `blob:`（添付画像のプレビュー）が必要です

## 導入支援・カスタマイズ

コミュニティでのサポートはベストエフォートです。設置代行・独自ドメインでの運用・機能追加などのご相談は、開発元の [株式会社ブースターテクノロジー](https://www.booster-technology.com/) までお問い合わせください。
