<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class Controller
{
    /**
     * 想定外のエラー時の共通処理。詳細(例外・スタックトレース・どの画面で誰が操作したか)はログに残し、
     * 利用者には分かりやすいメッセージだけを表示して元の画面に戻す。
     * 入力値はパスワード等を含む場合があるのでログには出さない
     */
    protected function failed(Throwable $e, string $context): RedirectResponse
    {
        $request = request();

        Log::error($context, [
            'exception' => $e,
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'route' => $request->route()?->getName(),
            'user_id' => $request->user()?->id,
            'portal' => app()->bound('portal.current') ? app('portal.current') : null,
            'ip' => $request->ip(),
        ]);

        return back()
            ->withInput()
            ->with('error', '処理中に問題が発生しました。時間をおいて再度お試しください。');
    }

    /**
     * URLのクエリ文字列(?key=値)を文字列で取り出す。?key[]=x のような配列は未指定として扱う(文字列への変換でエラーにしない)
     */
    protected function queryString(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }
}
