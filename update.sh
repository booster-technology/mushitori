#!/usr/bin/env bash
#
# ムシトリ アップデートスクリプト(Linux / macOS)
#   ./update.sh   … 最新版を取得して、依存パッケージとデータベースを更新する(設置したユーザーで実行する)
#
# - 更新の前に自動でバックアップ(php artisan app:backup)を取る。失敗した場合は更新しない
# - git pull・composer install は実行したユーザー(設置したユーザー)で行う
# - storage に書き込む artisan のコマンド(バックアップ・メンテナンスモード・データベースの更新・キャッシュ)は、
#   Webサーバー(PHP-FPM)の実行ユーザーで行う(sudo -u を使う)。storage は Webサーバーの所有になっているため
#   (Webサーバーの実行ユーザーは PHP-FPM の www.conf から調べる。WEB_USER=apache ./update.sh のように指定もできる)
#
set -euo pipefail
cd "$(dirname "$0")"

# git で設置していない場合は更新できないため、何もせずに案内する
if [ ! -d .git ]; then
  echo "このフォルダは git で取得したものではないため、更新できません。git clone で設置し直してください(README の「アップデート」を参照)。" >&2
  exit 1
fi

COMPOSER=(composer)
if ! command -v composer >/dev/null 2>&1; then
  COMPOSER=(php composer.phar)
fi

# Webサーバー(PHP-FPM)の実行ユーザー
WEB_USER="${WEB_USER:-}"
if [ -z "$WEB_USER" ]; then
  for f in /etc/php-fpm.d/www.conf /etc/php/*/fpm/pool.d/www.conf /usr/local/etc/php-fpm.d/www.conf; do
    [ -f "$f" ] || continue
    WEB_USER="$(sed -n 's/^[[:space:]]*user[[:space:]]*=[[:space:]]*\([A-Za-z0-9._-]*\).*/\1/p' "$f" | head -n 1)"
    [ -n "$WEB_USER" ] && break
  done
fi

# artisan を Webサーバーの実行ユーザーで実行する(実行したユーザーと同じ・分からない場合は、そのまま実行する)
artisan() {
  if [ -n "$WEB_USER" ] && [ "$WEB_USER" != "$(id -un)" ]; then
    sudo -u "$WEB_USER" php artisan "$@"
  else
    php artisan "$@"
  fi
}
if [ -n "$WEB_USER" ] && [ "$WEB_USER" != "$(id -un)" ]; then
  echo "※ artisan のコマンドは Webサーバーのユーザー($WEB_USER)で実行します(sudo のパスワードを聞かれることがあります)"
fi

echo "== 更新前のバックアップを作成します =="
artisan app:backup

echo "== メンテナンスモードにします =="
artisan down || true
# 失敗しても必ずメンテナンスモードを解除する
trap 'artisan up' EXIT

echo "== 最新版を取得します =="
git pull --ff-only

echo "== 依存パッケージを更新します =="
"${COMPOSER[@]}" install --no-dev --optimize-autoloader --no-interaction

echo "== データベースを更新します =="
artisan migrate --force

echo "== キャッシュを作り直します =="
artisan optimize:clear
if [ "$(php artisan env 2>/dev/null | grep -c production)" -gt 0 ]; then
  artisan config:cache && artisan route:cache && artisan view:cache
fi

echo "== アップデートが完了しました =="
if [ -n "$WEB_USER" ] && [ "$WEB_USER" != "$(id -un)" ]; then
  echo "本番(config:cache 済み)では、.env を変えたら sudo -u $WEB_USER php artisan config:cache を実行してください。"
fi
