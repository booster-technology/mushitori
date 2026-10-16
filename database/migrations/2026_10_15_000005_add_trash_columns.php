<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ゴミ箱用の列
 * - deleted_by: 削除した人(ゴミ箱の画面に表示する)
 * - comments.purged_at: 完全に削除したコメント(本文・添付は消し、課題の経緯として変更履歴だけ残したもの)
 */
return new class extends Migration
{
    private const TABLES = ['projects', 'issues', 'comments', 'attachments'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                // ユーザーは削除しない(無効にする)ので外部キー制約は付けない
                $table->unsignedBigInteger('deleted_by')->nullable();
            });
        }

        Schema::table('comments', function (Blueprint $table) {
            $table->timestamp('purged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn('purged_at');
        });

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('deleted_by');
            });
        }
    }
};
