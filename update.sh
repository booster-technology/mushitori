#!/usr/bin/env bash
#
# ムシトリ アップデートスクリプト(Linux / macOS)
#   ./update.sh   … 最新版を取得して、依存パッケージとデータベースを更新する
#
# 更新の前に自動でバックアップ(php artisan app:backup)を取る。失敗した場合は更新しない
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

echo "== 更新前のバックアップを作成します =="
php artisan app:backup

echo "== メンテナンスモードにします =="
php artisan down || true
# 失敗しても必ずメンテナンスモードを解除する
trap 'php artisan up' EXIT

echo "== 最新版を取得します =="
git pull --ff-only

echo "== 依存パッケージを更新します =="
"${COMPOSER[@]}" install --no-dev --optimize-autoloader --no-interaction

echo "== データベースを更新します =="
php artisan migrate --force

echo "== キャッシュを作り直します =="
php artisan optimize:clear
if [ "$(php artisan env 2>/dev/null | grep -c production)" -gt 0 ]; then
  php artisan config:cache && php artisan route:cache && php artisan view:cache
fi

echo "== アップデートが完了しました =="
