<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ユーザー(社内メンバー・お客様)とセッションのテーブルを作成する。
     * ログインはメール+6桁コード方式のため、パスワード列は持たない。
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email')->unique();
            // staff=社内メンバー / customer=お客様
            $table->string('role', 20)->index();
            // お客様の会社名(社内メンバーは空)
            $table->string('company_name', 100)->nullable();
            // 無効化されたユーザーはログイン不可・ログイン中でも強制ログアウト
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            // Laravel標準のDatabaseSessionHandlerが書き込むため残す(ガードに関わらずIDが入る)
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
