<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ログイン用の6桁認証コード。コードは平文で保存せずHMACのハッシュ値のみ保持する。
     */
    public function up(): void
    {
        Schema::create('login_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // どのポータル(staff/customer)向けに発行したか
            $table->string('guard', 20);
            $table->string('code_hash', 64);
            $table->timestamp('expires_at');
            // 入力ミスの回数(上限を超えたら無効化)
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'guard']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_codes');
    }
};
