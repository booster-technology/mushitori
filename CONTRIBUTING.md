# ムシトリへの協力について

ムシトリに関心を持っていただき、ありがとうございます。

## 受け付けているもの

| | |
|:--|:--|
| 不具合の報告・要望・質問 | **歓迎します。** [Issues](https://github.com/booster-technology/mushitori/issues) からお送りください |
| セキュリティ上の問題 | 公開の Issue には書かず、[SECURITY.md](SECURITY.md) の方法で非公開でご連絡ください |
| プルリクエスト(コードの修正提案) | **当面は受け付けていません。** 修正の提案も Issue でお知らせください(内容を確認し、開発元で対応します) |

設置代行・機能追加などのご相談は、開発元の [株式会社ブースターテクノロジー](https://www.booster-technology.com/) までお問い合わせください。

## Issue を書くときのお願い

- **不具合**: ムシトリのバージョン(またはコミット)、PHP・データベースのバージョン、再現手順、期待する動作と実際の動作を書いてください。エラーの場合は `storage/logs/laravel-日付.log` の該当部分があると助かります(パスワード・メールアドレス・URLなどの個人情報や設定値は伏せてください)
- **要望**: どんな場面で困っているか(使いたい理由)を書いてください
- 日本語・英語のどちらでも構いません

## 開発の進め方(参考)

改修やカスタマイズのために手元で動かす場合の手順です。

```bash
bash install.sh --dev                 # テストツールも含めてインストール（お試し・SQLiteを選択）
php -d upload_max_filesize=20M -d post_max_size=120M -S 127.0.0.1:8000 -t public scripts/dev-router.php
php artisan db:seed --class=DemoSeeder  # サンプルデータ
php artisan test                      # テスト（Pest）
./vendor/bin/pint                     # コード整形
```

- 技術構成: Laravel 13 / Blade / Bootstrap 5・Alpine.js（どちらも同梱・npm やビルドは不要）/ Pest
- 依存パッケージは PHP 8.3 でも動く版に固定しています（`composer.json` の `config.platform.php`）。PHP 8.4 の開発機で `composer update` しても、PHP 8.4 専用の版（Symfony 8 など）は入りません
- 仕様は [docs/spec.md](docs/spec.md)、設計方針・注意点は [CLAUDE.md](CLAUDE.md) にまとめています
- `main` ブランチへの push のたびに、GitHub Actions で PHP 8.3・8.4 のテストとコード整形のチェックが動きます
