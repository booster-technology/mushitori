<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 代理起票(電話・メールで受けた内容を、社内メンバーがお客様の課題として登録する)用の列
 * - registered_by: 代わりに登録した社内メンバー(起票者本人が登録した場合は null)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            // ユーザーは削除しない(無効にする)ので外部キー制約は付けない(deleted_by と同じ)
            $table->unsignedBigInteger('registered_by')->nullable()->after('reporter_id');
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropColumn('registered_by');
        });
    }
};
