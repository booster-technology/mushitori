<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * プロジェクトとプロジェクトメンバーのテーブルを作成する。
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            // 課題キー(例: WEB-12)の接頭辞。URLにも使うため作成後は変更不可
            $table->string('key', 10)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            // 次に採番する課題番号(プロジェクト単位の連番)
            $table->unsignedInteger('next_issue_number')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        // お客様はメンバーのプロジェクトのみ閲覧可。社内メンバーは全プロジェクト閲覧可で、メンバーになると新規課題の通知を受け取る
        Schema::create('project_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_user');
        Schema::dropIfExists('projects');
    }
};
