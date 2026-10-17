#!/usr/bin/env bash
#
# ムシトリ サーバー設定スクリプト(Linux の VPS・専用サーバー用。root 権限で実行する)
#
#   sudo bash deploy/setup-server.sh            … インストール(bash install.sh)の後に、書き込み権限・Nginx・cron をまとめて設定する
#   sudo bash deploy/setup-server.sh --dry-run  … 何をするかを表示するだけ(何も変更しない)
#
# 行うこと(最初に内容を表示し、確認してから実行する):
#   1. 書き込み権限 … storage を Webサーバー(PHP-FPM)の実行ユーザーの所有にする。
#                     bootstrap/cache は設置したユーザーと Webサーバーの両方が書き込めるようにする(update.sh が動くように)。
#                     .env は Webサーバーだけが読めるようにする(640。他のユーザーからパスワード等を読めないように)。
#                     SQLite の場合は、設置フォルダの中にあるデータベースのファイルとそのフォルダも Webサーバーの所有にする
#   2. Nginx       … deploy/nginx の設定例をこのサーバー(URL・設置場所・PHP-FPM のソケット)に合わせて
#                     /etc/nginx/conf.d/mushitori.conf に置き、nginx -t が通ったときだけ再読み込みする(通らなければ元に戻す)。
#                     HTTPS の証明書がまだ無い場合は、いったん HTTP の設定を置く(その後 certbot --nginx で HTTPS にする)。
#                     同じドメインの設定が他のファイルにある場合は、何も変えずにこの手順だけ止める
#                     (1台に複数のサイトがあるサーバーで、他のサイトの設定を壊さないように)
#   3. cron         … Webサーバーの実行ユーザーで schedule:run を毎分実行するよう登録する(登録済みなら何もしない)
#
# オプション:
#   --url=URL            公開URL(省略時は .env の APP_URL)
#   --behind-proxy[=yes|no]  ロードバランサー(AWS ALB等)の後ろで動かすか(省略時は .env に TRUSTED_PROXIES があれば yes)
#   --web-user=USER      Webサーバー(PHP-FPM)の実行ユーザー(省略時は PHP-FPM の www.conf から調べる)
#   --nginx-conf=PATH    Nginx の設定ファイルの置き場所(既定: /etc/nginx/conf.d/mushitori.conf)
#   --no-nginx           Nginx の設定を行わない
#   --no-cron            cron の登録を行わない
#   --yes                確認せずに実行する
#   --dry-run            何をするかを表示するだけ
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
URL=""
PROXY_OPTION=""
WEB_USER=""
NGINX_CONF="/etc/nginx/conf.d/mushitori.conf"
DO_NGINX=1
DO_CRON=1
ASSUME_YES=0
DRY_RUN=0
# Let's Encrypt の証明書の置き場所(テストのために環境変数で変えられる)
CERT_DIR="${LETSENCRYPT_LIVE_DIR:-/etc/letsencrypt/live}"

bold() { printf '\033[1m%s\033[0m\n' "$1"; }
warn() { printf '\033[33m%s\033[0m\n' "$1"; }
fail() { printf '\033[31m%s\033[0m\n' "$1" >&2; exit 1; }
# 先頭のコメント(使い方)だけを表示する
usage() { awk 'NR > 2 { if (/^#/) { sub(/^# ?/, ""); print } else exit }' "${BASH_SOURCE[0]}"; }

for arg in "$@"; do
  case "$arg" in
    --url=*) URL="${arg#*=}" ;;
    --behind-proxy|--behind-proxy=yes|--behind-proxy=true|--behind-proxy=1) PROXY_OPTION="yes" ;;
    --behind-proxy=no|--behind-proxy=false|--behind-proxy=0) PROXY_OPTION="no" ;;
    --web-user=*) WEB_USER="${arg#*=}" ;;
    --nginx-conf=*) NGINX_CONF="${arg#*=}" ;;
    --no-nginx) DO_NGINX=0 ;;
    --no-cron) DO_CRON=0 ;;
    --yes|-y) ASSUME_YES=1 ;;
    --dry-run) DRY_RUN=1 ;;
    -h|--help) usage; exit 0 ;;
    *) fail "不明なオプションです: $arg(--help で使い方を表示します)" ;;
  esac
done

[ -f "$APP_DIR/artisan" ] || fail "ムシトリのフォルダが見つかりません($APP_DIR)。ムシトリのフォルダの中の deploy/setup-server.sh を実行してください。"
[ -f "$APP_DIR/.env" ] || fail "まだインストールされていません(.env がありません)。先に bash install.sh でインストールしてから実行してください。"
IS_ROOT=0
[ "$(id -u)" -eq 0 ] && IS_ROOT=1
if [ "$IS_ROOT" -eq 0 ] && [ "$DRY_RUN" -eq 0 ]; then
  fail "root 権限が必要です。sudo bash $APP_DIR/deploy/setup-server.sh のように実行してください(--dry-run なら不要)。"
fi
PHP_BIN="$(command -v php || true)"
[ -n "$PHP_BIN" ] || fail "php が見つかりません。"

# コマンドを表示して実行する(--dry-run では表示だけ)
run() {
  printf '  $ %s\n' "$*"
  if [ "$DRY_RUN" -eq 0 ]; then "$@"; fi
}

# .env の値(前後の引用符は外す)。書かれていなければ空
env_value() {
  sed -n "s/^$1=//p" "$APP_DIR/.env" | tail -n 1 | sed -e "s/^['\"]//" -e "s/['\"]\$//"
}

# sed の置き換え後の文字列として使えるよう、特別な意味の文字を無効にする
sed_escape() {
  printf '%s' "$1" | sed -e 's/[\\|&]/\\&/g'
}

# ---------------------------------------------------------------------------
# 現在の状態を調べる
# ---------------------------------------------------------------------------

# Webサーバー(PHP-FPM)の実行ユーザー
if [ -z "$WEB_USER" ]; then
  for f in /etc/php-fpm.d/www.conf /etc/php/*/fpm/pool.d/www.conf /usr/local/etc/php-fpm.d/www.conf; do
    [ -f "$f" ] || continue
    WEB_USER="$(sed -n 's/^[[:space:]]*user[[:space:]]*=[[:space:]]*\([A-Za-z0-9._-]*\).*/\1/p' "$f" | head -n 1)"
    [ -n "$WEB_USER" ] && break
  done
fi
[ -n "$WEB_USER" ] || fail "Webサーバー(PHP-FPM)の実行ユーザーが分かりませんでした。--web-user=apache のように指定してください。"
[[ "$WEB_USER" =~ ^[A-Za-z0-9._-]+$ ]] || fail "ユーザー名が正しくありません: $WEB_USER"
id "$WEB_USER" >/dev/null 2>&1 || fail "ユーザー $WEB_USER がこのサーバーにありません。--web-user で指定してください。"
WEB_GROUP="$(id -gn "$WEB_USER" 2>/dev/null || printf '%s' "$WEB_USER")"

# 一式を設置したユーザー(artisan の所有者)。bootstrap/cache・.env の所有者にする
APP_OWNER="$(stat -c '%U' "$APP_DIR/artisan")"

SELINUX=0
if command -v getenforce >/dev/null 2>&1 && [ "$(getenforce 2>/dev/null)" = "Enforcing" ]; then SELINUX=1; fi

# 1. 書き込み権限。SQLite のファイルは、設置フォルダの中にある場合だけ扱う
# (.env の値をそのまま root で chown すると、.env を書き換えられた場合に他の場所の所有者を変えられてしまうため)
SQLITE_FILE=""
SQLITE_NOTE=""
if [ "$(env_value DB_CONNECTION)" = "sqlite" ]; then
  SQLITE_FILE="$(env_value DB_DATABASE)"
  [ -n "$SQLITE_FILE" ] || SQLITE_FILE="$APP_DIR/database/database.sqlite"
  case "$SQLITE_FILE" in /*) ;; *) SQLITE_FILE="$APP_DIR/$SQLITE_FILE" ;; esac
  SQLITE_DIR_REAL="$(readlink -f "$(dirname "$SQLITE_FILE")" 2>/dev/null || true)"
  APP_DIR_REAL="$(readlink -f "$APP_DIR")"
  case "$SQLITE_DIR_REAL/" in
    "$APP_DIR_REAL"/?*) ;;
    *) SQLITE_NOTE="SQLite のデータベース($SQLITE_FILE)が設置フォルダの外にあるため、権限は変えません(必要なら手で設定してください)"; SQLITE_FILE="" ;;
  esac
  if [ -n "$SQLITE_FILE" ] && [ -L "$SQLITE_FILE" ]; then
    SQLITE_NOTE="SQLite のデータベース($SQLITE_FILE)がシンボリックリンクのため、権限は変えません"
    SQLITE_FILE=""
  fi
fi
ENV_NOTE=""
[ -L "$APP_DIR/.env" ] && ENV_NOTE=".env がシンボリックリンクのため、権限は変えません"

# 2. Nginx
NGINX_NOTE=""
NGINX_TEMPLATE=""
TEMPLATE_LABEL=""
CERT_PENDING=0
NEW_CONF=""
if [ "$DO_NGINX" -eq 1 ] && ! command -v nginx >/dev/null 2>&1; then
  DO_NGINX=0
  NGINX_NOTE="Nginx が見つからないため行いません(Apache の場合は deploy/apache の設定例を参照してください)"
fi
if [ "$DO_NGINX" -eq 1 ]; then
  [ -n "$URL" ] || URL="$(env_value APP_URL)"
  if [ -z "$URL" ]; then
    DO_NGINX=0
    NGINX_NOTE="公開URLが分からないため行いません(.env の APP_URL を設定するか、--url=https://issues.example.com のように指定してください)"
  fi
fi
if [ "$DO_NGINX" -eq 1 ]; then
  DOMAIN="$(printf '%s' "$URL" | sed -E 's#^[A-Za-z]+://##; s#[/:].*$##')"
  # ドメイン・設置場所は Nginx の設定にそのまま入るため、別の設定を紛れ込ませられる文字は受け付けない
  [[ "$DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$ ]] || fail "公開URLのドメインが正しくありません: $DOMAIN"
  [[ "$APP_DIR" =~ ^[A-Za-z0-9._/-]+$ ]] || fail "設置フォルダの場所に使えない文字が含まれています(英数字と . _ - / だけにしてください): $APP_DIR"

  case "$PROXY_OPTION" in
    yes) BEHIND_PROXY=1 ;;
    no) BEHIND_PROXY=0 ;;
    *) BEHIND_PROXY=0; [ -n "$(env_value TRUSTED_PROXIES)" ] && BEHIND_PROXY=1 ;;
  esac
  if [ "$BEHIND_PROXY" -eq 1 ]; then
    NGINX_TEMPLATE="mushitori-alb.conf"; TEMPLATE_LABEL="ロードバランサーの後ろ"
  elif [[ "$URL" == https://* ]] && [ -f "$CERT_DIR/$DOMAIN/fullchain.pem" ]; then
    NGINX_TEMPLATE="mushitori.conf"; TEMPLATE_LABEL="HTTPS(証明書は $CERT_DIR/$DOMAIN/)"
  elif [[ "$URL" == https://* ]]; then
    # 証明書が無いまま HTTPS の設定を置くと nginx -t が通らないため、いったん HTTP で置き、certbot --nginx で HTTPS にしてもらう
    NGINX_TEMPLATE="mushitori-http.conf"; TEMPLATE_LABEL="HTTP(HTTPS の証明書がまだ無いため。この後 certbot --nginx で HTTPS にします)"
    CERT_PENDING=1
  else
    NGINX_TEMPLATE="mushitori-http.conf"; TEMPLATE_LABEL="HTTPのみ"
  fi
  [ -f "$APP_DIR/deploy/nginx/$NGINX_TEMPLATE" ] || fail "Nginx の設定例(deploy/nginx/$NGINX_TEMPLATE)が見つかりません。"

  # このサーバー用の設定を作る。PHP(Webサーバーのユーザーが書き込めるファイルを読み込む)は使わず、このスクリプトで作る
  # (root が Web 側の出力をそのまま Nginx の設定にしないように)。置き換える内容は InstallerService::nginxConfig() と同じ
  SOCKET=""
  for s in /run/php/php8.4-fpm.sock /run/php/php8.3-fpm.sock /run/php-fpm/www.sock /var/run/php-fpm/www.sock; do
    if [ -e "$s" ]; then SOCKET="$s"; break; fi
  done
  SED_ARGS=(-e "s|issues\\.example\\.com|$(sed_escape "$DOMAIN")|g" -e "s|/var/www/mushitori|$(sed_escape "$APP_DIR")|g")
  [ -n "$SOCKET" ] && SED_ARGS+=(-e "s|unix:/run/php-fpm/www\\.sock|unix:$(sed_escape "$SOCKET")|g")
  NEW_CONF="$(sed "${SED_ARGS[@]}" "$APP_DIR/deploy/nginx/$NGINX_TEMPLATE")"

  # 同じドメインの設定が他のファイルにあれば、何も変えない(他のサイトの設定を壊さないように)
  if NGINX_DUMP="$(nginx -T 2>/dev/null)"; then
    CONFLICTS="$(awk -v domain="$DOMAIN" -v self="$NGINX_CONF" '
      /^# configuration file / { file = $4; sub(/:$/, "", file); next }
      file != self && $0 !~ /^[[:space:]]*#/ && /server_name/ {
        line = $0; sub(/#.*/, "", line); n = split(line, words, /[[:space:];]+/)
        for (i = 1; i <= n; i++) if (words[i] == domain) { print file; break }
      }' <<<"$NGINX_DUMP" | sort -u | tr '\n' ' ')"
    if [ -n "$CONFLICTS" ]; then
      DO_NGINX=0
      NGINX_NOTE="同じドメイン($DOMAIN)の設定が既に次のファイルにあるため、変更しません: $CONFLICTS
       そのまま使い続けるか、そのファイルの該当する server ブロックを削除(コメントアウト)してから、もう一度実行してください"
    fi
  elif [ "$IS_ROOT" -eq 1 ]; then
    DO_NGINX=0
    NGINX_NOTE="今の Nginx の設定にエラーがあるため行いません(sudo nginx -t で確認してください)"
  else
    NGINX_NOTE="root でないため、同じドメインの設定が他のファイルにあるかは確認していません"
  fi

  if [ "$DO_NGINX" -eq 1 ] && [ -f "$NGINX_CONF" ] && [ "$(cat "$NGINX_CONF")" = "$NEW_CONF" ]; then
    DO_NGINX=0
    NGINX_NOTE="$NGINX_CONF は既に同じ内容です(変更なし)"
  fi
fi

# 3. cron
CRON_LINE="* * * * * cd $APP_DIR && $PHP_BIN artisan schedule:run >> /dev/null 2>&1"
CRON_NOTE=""
CURRENT_CRON=""
if [ "$DO_CRON" -eq 1 ] && ! command -v crontab >/dev/null 2>&1; then
  DO_CRON=0
  CRON_NOTE="cron(crontab コマンド)が無いため行いません。Amazon Linux 2023 は sudo dnf install cronie && sudo systemctl enable --now crond、Ubuntu は sudo apt install cron で入れてから、もう一度実行してください"
fi
if [ "$DO_CRON" -eq 1 ]; then
  CURRENT_CRON="$(crontab -u "$WEB_USER" -l 2>/dev/null || true)"
  case "$CURRENT_CRON" in
    *"cd $APP_DIR "*schedule:run*) DO_CRON=0; CRON_NOTE="$WEB_USER の crontab に登録済みです(変更なし)" ;;
  esac
fi

# ---------------------------------------------------------------------------
# 行う内容を表示して確認する
# ---------------------------------------------------------------------------

bold "== ムシトリのサーバー設定 =="
echo "  設置フォルダ      : $APP_DIR(設置したユーザー: $APP_OWNER)"
echo "  Webサーバーの実行ユーザー: $WEB_USER:$WEB_GROUP"
[ "$DRY_RUN" -eq 1 ] && warn "  ※ --dry-run: 表示するだけで、何も変更しません"
echo
echo "1. 書き込み権限"
echo "   - storage を $WEB_USER:$WEB_GROUP の所有にする"
echo "   - bootstrap/cache を $APP_OWNER:$WEB_GROUP の所有にし、グループでも書き込めるようにする"
if [ -n "$ENV_NOTE" ]; then warn "   ※ $ENV_NOTE"; else echo "   - .env を $APP_OWNER:$WEB_GROUP の所有・640 にする(他のユーザーから読めないように)"; fi
[ -n "$SQLITE_FILE" ] && echo "   - SQLite のデータベース($SQLITE_FILE)とそのフォルダを $WEB_USER の所有にする"
[ -n "$SQLITE_NOTE" ] && warn "   ※ $SQLITE_NOTE"
[ "$SELINUX" -eq 1 ] && echo "   - SELinux が有効なため、書き込むフォルダに httpd_sys_rw_content_t を付ける"
echo "2. Nginx"
if [ "$DO_NGINX" -eq 1 ]; then
  echo "   - $NGINX_CONF に設定を置き、nginx -t が通ったら再読み込みする(通らなければ元に戻す)"
  echo "     ドメイン: $DOMAIN / 種類: $TEMPLATE_LABEL(deploy/nginx/$NGINX_TEMPLATE)"
  if [ -f "$NGINX_CONF" ]; then
    echo "     今の設定との違い(今の設定は $NGINX_CONF.bak.日時 に残します):"
    diff -u "$NGINX_CONF" <(printf '%s\n' "$NEW_CONF") | sed 's/^/       /' | head -n 80 || true
  else
    echo "     (新しく作成します。内容は php artisan app:nginx-config --url=$URL$([ "$BEHIND_PROXY" -eq 1 ] && printf ' --behind-proxy') で確認できます)"
  fi
  [ -n "$NGINX_NOTE" ] && warn "     ※ $NGINX_NOTE"
else
  echo "   - ${NGINX_NOTE:-行いません(--no-nginx)}"
fi
echo "3. cron"
if [ "$DO_CRON" -eq 1 ]; then
  echo "   - $WEB_USER の crontab に追加する: $CRON_LINE"
  [ "$IS_ROOT" -eq 0 ] && warn "     ※ root でないため、登録済みかどうかは確認していません"
else
  echo "   - ${CRON_NOTE:-行いません(--no-cron)}"
fi
echo

if [ "$DRY_RUN" -eq 0 ] && [ "$ASSUME_YES" -eq 0 ]; then
  [ -r /dev/tty ] || fail "確認のための入力ができません。内容を確認したうえで --yes を付けて実行してください。"
  read -r -p "実行しますか？ [y/N] " answer < /dev/tty
  case "$answer" in
    y|Y|yes|YES) ;;
    *) echo "中止しました(何も変更していません)。"; exit 0 ;;
  esac
fi

# ---------------------------------------------------------------------------
# 実行する
# ---------------------------------------------------------------------------

bold "== 1. 書き込み権限 =="
# -h: シンボリックリンク自体の所有者だけを変える(リンク先の所有者を変えないように)
run chown -R -h "$WEB_USER:$WEB_GROUP" "$APP_DIR/storage"
run chown -R -h "$APP_OWNER:$WEB_GROUP" "$APP_DIR/bootstrap/cache"
run chmod -R g+rwX "$APP_DIR/bootstrap/cache"
run chmod g+s "$APP_DIR/bootstrap/cache"
if [ -z "$ENV_NOTE" ]; then
  run chown -h "$APP_OWNER:$WEB_GROUP" "$APP_DIR/.env"
  run chmod 640 "$APP_DIR/.env"
fi
if [ -n "$SQLITE_FILE" ]; then
  run chown -h "$WEB_USER:$WEB_GROUP" "$(dirname "$SQLITE_FILE")"
  [ -f "$SQLITE_FILE" ] && run chown -h "$WEB_USER:$WEB_GROUP" "$SQLITE_FILE"
fi
if [ "$SELINUX" -eq 1 ]; then
  run chcon -R -h -t httpd_sys_rw_content_t "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
  [ -n "$SQLITE_FILE" ] && run chcon -R -h -t httpd_sys_rw_content_t "$(dirname "$SQLITE_FILE")"
fi

NGINX_FAILED=0
if [ "$DO_NGINX" -eq 1 ]; then
  bold "== 2. Nginx =="
  BACKUP=""
  if [ -f "$NGINX_CONF" ]; then
    BACKUP="$NGINX_CONF.bak.$(date +%Y%m%d%H%M%S)"
    run cp -p "$NGINX_CONF" "$BACKUP"
  fi
  printf '  $ (このサーバー用の設定を %s に書き込む)\n' "$NGINX_CONF"
  if [ "$DRY_RUN" -eq 1 ]; then
    printf '  $ nginx -t && systemctl reload nginx\n'
  else
    printf '%s\n' "$NEW_CONF" > "$NGINX_CONF"
    chmod 644 "$NGINX_CONF"
    if nginx -t; then
      run systemctl reload nginx
    else
      # 通らなければ元に戻す(他のサイトに影響しないよう、再読み込みはしない)
      if [ -n "$BACKUP" ]; then cp -p "$BACKUP" "$NGINX_CONF"; else rm -f "$NGINX_CONF"; fi
      NGINX_FAILED=1
      warn "nginx -t でエラーになったため、設定を元に戻しました(Nginx は再読み込みしていません)。"
    fi
  fi
fi

if [ "$DO_CRON" -eq 1 ]; then
  bold "== 3. cron =="
  printf '  $ (%s の crontab に追加) %s\n' "$WEB_USER" "$CRON_LINE"
  if [ "$DRY_RUN" -eq 0 ]; then
    { if [ -n "$CURRENT_CRON" ]; then printf '%s\n' "$CURRENT_CRON"; fi; printf '%s\n' "$CRON_LINE"; } | crontab -u "$WEB_USER" -
  fi
  if ! systemctl is-active --quiet crond 2>/dev/null && ! systemctl is-active --quiet cron 2>/dev/null; then
    warn "cron のサービスが動いていないようです(Amazon Linux: sudo systemctl enable --now crond / Ubuntu: sudo systemctl enable --now cron)。"
  fi
fi

echo
if [ "$DRY_RUN" -eq 1 ]; then
  bold "== 表示のみ(--dry-run)。何も変更していません =="
elif [ "$NGINX_FAILED" -eq 1 ]; then
  fail "Nginx の設定に失敗しました(上のメッセージを確認してください)。権限・cron の設定は完了しています。"
else
  bold "== サーバーの設定が完了しました =="
  if [ "$DO_NGINX" -eq 1 ] && [ "$CERT_PENDING" -eq 1 ]; then
    echo "  HTTPS にするには、続けて次を実行してください(証明書を取得し、この設定に HTTPS を追加します):"
    echo "    sudo certbot --nginx -d $DOMAIN"
  fi
  echo "  artisan コマンドは Webサーバーのユーザーで実行してください(例: sudo -u $WEB_USER php artisan app:backup)。"
fi
