<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * 「ポータル」= 社内メンバー用画面(staff) と お客様用画面(customer) の区別。
 * 同じコントローラ・ビューを両方のルートグループで使い回すため、
 * 現在どちらのポータルで処理しているかをここで管理する。
 * ポータル名は認証ガード名・ルート名の接頭辞・ユーザーのロール値と一致させている。
 */
class Portal
{
    public const STAFF = 'staff';

    public const CUSTOMER = 'customer';

    /**
     * 現在のポータル名(ミドルウェアで設定済みでなければURLから判定)
     */
    public static function current(): string
    {
        if (app()->bound('portal.current')) {
            return app('portal.current');
        }

        return self::fromRequest(request());
    }

    public static function set(string $portal): void
    {
        app()->instance('portal.current', $portal);
    }

    public static function isStaff(): bool
    {
        return self::current() === self::STAFF;
    }

    /**
     * URLのパスからポータルを判定する(ミドルウェアより前に呼ばれる箇所用)
     */
    public static function fromRequest(Request $request): string
    {
        $prefix = self::staffPrefix();

        return ($request->is($prefix) || $request->is($prefix.'/*')) ? self::STAFF : self::CUSTOMER;
    }

    /**
     * URLが指定ポータルの画面のものか(ログイン後の遷移先チェック用)
     */
    public static function ownsUrl(string $url, string $portal): bool
    {
        $path = '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        $staffPath = '/'.self::staffPrefix();
        $isStaffUrl = $path === $staffPath || str_starts_with($path, $staffPath.'/');

        return $portal === self::STAFF ? $isStaffUrl : ! $isStaffUrl;
    }

    /**
     * 現在のポータルのルートURLを生成する(例: issues.show → staff.issues.show)
     */
    public static function route(string $name, mixed $parameters = [], bool $absolute = true): string
    {
        return route(self::current().'.'.$name, $parameters, $absolute);
    }

    /**
     * 指定ユーザーが使うポータルのURLを生成する(メール本文のリンク用)。
     * アクセスしてきたURL(Hostヘッダー)ではなく、常に .env の APP_URL を基準にする
     * (Hostヘッダーを偽装したリクエストで、通知メールのリンクを偽サイトに向けられないように)
     */
    public static function routeFor(User $user, string $name, mixed $parameters = []): string
    {
        return rtrim((string) config('app.url'), '/').route($user->role->value.'.'.$name, $parameters, false);
    }

    private static function staffPrefix(): string
    {
        return trim((string) config('app.staff_path'), '/');
    }
}
