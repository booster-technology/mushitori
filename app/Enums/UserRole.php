<?php

namespace App\Enums;

/**
 * ユーザーの種別。ロールごとにログイン画面・認証ガードが分かれる。
 */
enum UserRole: string
{
    case Staff = 'staff';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Staff => '社内',
            self::Customer => 'お客様',
        };
    }

    /**
     * このロールが使う認証ガード名(ポータル名と同じ)
     */
    public function guard(): string
    {
        return $this->value;
    }
}
