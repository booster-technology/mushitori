<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 課題・コメント・添付ファイルのテーブルを作成する。
     */
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained();
            // プロジェクト内の連番(キーは「プロジェクトキー-番号」)
            $table->unsignedInteger('number');
            $table->string('title', 200);
            $table->text('description')->nullable();
            // 値の定義は app/Enums 配下を参照
            $table->string('type', 20);
            $table->string('priority', 10);
            $table->string('status', 20);
            $table->foreignId('reporter_id')->constrained('users');
            $table->foreignId('assignee_id')->nullable()->constrained('users');
            $table->date('due_date')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'status']);
            $table->index(['assignee_id', 'status']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->text('body')->nullable();
            // 状態・担当者などの変更履歴 [{field, from, to}, ...]
            $table->json('change_log')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            // コメントに添付した場合のみ値が入る(課題本文の添付はnull)
            $table->foreignId('comment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('original_name');
            $table->string('path');
            $table->string('thumbnail_path')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('issues');
    }
};
