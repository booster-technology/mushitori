<?php

/*
| ゴミ箱(削除したデータ)の設定
*/
return [
    // 削除したプロジェクト・課題・コメント・添付ファイルを保管する日数。
    // 過ぎたものは毎日の自動処理(php artisan app:purge-trash)で、画像などの添付ファイルの実体も含めて完全に削除する。0なら自動では削除しない
    'retention_days' => (int) env('TRASH_RETENTION_DAYS', 30),
];
