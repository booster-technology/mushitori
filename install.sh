#!/usr/bin/env bash
#
# ムシトリ インストールスクリプト(Linux / macOS)
#
#   bash install.sh                 … 対話形式でインストール(本番・お試しどちらも)
#   bash install.sh --dev           … 開発用(テストツールも含めて)インストール(--dev は最初に書く)
#   bash install.sh --no-interaction --mode=local --db=sqlite ...
#                                … 質問せずに自動インストール(指定できる項目は php artisan app:install --help)
#
# 本番サーバーでは、最後にサーバーの設定(書き込み権限・Nginx・cron。deploy/setup-server.sh)も続けて行えます
#
set -euo pipefail
cd "$(dirname "$0")"

bold() { printf '\033[1m%s\033[0m\n' "$1"; }
fail() { printf '\033[31m%s\033[0m\n' "$1" >&2; exit 1; }

bold "== ムシトリのインストールを始めます =="

# --- 1. PHP の確認 ---------------------------------------------------------
if ! command -v php >/dev/null 2>&1; then
  fail "PHP が見つかりません。PHP 8.3 以上をインストールしてください。
  例) Ubuntu:  sudo apt install php8.3-cli php8.3-fpm php8.3-mysql php8.3-sqlite3 php8.3-gd php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip unzip
  例) Amazon Linux 2023:  sudo dnf install php8.3 php8.3-fpm php8.3-mysqlnd php8.3-gd php8.3-mbstring php8.3-xml php8.3-pdo"
fi
if ! php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);'; then
  fail "PHP 8.3 以上が必要です（現在: $(php -r 'echo PHP_VERSION;')）"
fi

# --- 2. Composer の確認(無ければ公式インストーラを署名検証してから取得) ------------
COMPOSER=(composer)
if ! command -v composer >/dev/null 2>&1; then
  if [ ! -f composer.phar ]; then
    bold "Composer が見つからないため、公式サイトから取得します…"
    EXPECTED="$(php -r 'echo trim(file_get_contents("https://composer.github.io/installer.sig"));')"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
    if [ "$EXPECTED" != "$ACTUAL" ]; then
      rm -f composer-setup.php
      fail "Composer のインストーラの検証に失敗しました。時間をおいて再度お試しください。"
    fi
    php composer-setup.php --quiet
    rm -f composer-setup.php
  fi
  COMPOSER=(php composer.phar)
fi

# --- 3. 依存パッケージのインストール ----------------------------------------
if [ "${1:-}" = "--dev" ]; then
  shift
  bold "依存パッケージをインストールしています（開発用）…"
  "${COMPOSER[@]}" install --no-interaction
else
  bold "依存パッケージをインストールしています…"
  "${COMPOSER[@]}" install --no-dev --optimize-autoloader --no-interaction
fi

# --- 4. インストーラ本体(設定の聞き取り・DB作成・管理者登録) ---------------------
php artisan app:install "$@"

# --- 5. サーバーの設定(書き込み権限・Nginx・cron) ----------------------------
# 本番サーバーで sudo が使える場合は、続けて deploy/setup-server.sh を実行するか聞く
# (スクリプト側でも、行う内容を表示して確認してから実行する)。質問なしのインストール(--no-interaction)では聞かない
case " $* " in
  *" --no-interaction "*|*" -n "*) interactive=0 ;;
  *) interactive=1 ;;
esac
if [ "$interactive" -eq 1 ] && [ -t 0 ] && [ -f .env ] && grep -q '^APP_ENV=production' .env && command -v sudo >/dev/null 2>&1; then
  echo
  read -r -p "続けて、サーバーの設定(書き込み権限・Nginx・cron)を行いますか？ [Y/n] " answer
  case "${answer:-y}" in
    y|Y|yes|YES)
      sudo bash deploy/setup-server.sh || printf '\033[33m%s\033[0m\n' "サーバーの設定を完了できませんでした。上のメッセージを確認し、sudo bash $(pwd)/deploy/setup-server.sh で再実行してください。"
      ;;
    *) echo "あとで行う場合は sudo bash $(pwd)/deploy/setup-server.sh を実行してください。" ;;
  esac
fi
