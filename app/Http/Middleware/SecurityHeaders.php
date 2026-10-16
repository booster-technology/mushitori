<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * すべての応答にセキュリティ関連のヘッダーを付ける。
 * Webサーバー(Nginx・Apache)の設定に頼らず、どの環境に設置しても同じ対策が効くようにアプリ側で付与する
 * (Nginxの設定例では付けていない。二重に付くのを避けるため)
 */
class SecurityHeaders
{
    private const HEADERS = [
        // 他のサイトの画面に埋め込ませない(クリックジャッキング対策)
        'X-Frame-Options' => 'SAMEORIGIN',
        // 宣言した種類以外のファイルとして解釈させない(画像に見せかけたスクリプトの実行などを防ぐ)
        'X-Content-Type-Options' => 'nosniff',
        // 外部サイトへのリンクを開いたとき、課題のURL(課題キーなど)を相手に送らない
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    /**
     * Content-Security-Policy: 自分のサイト以外からは何も読み込まない(外部のスクリプト・CSS・画像・フォントは使わない)。
     * インラインのスクリプト・スタイル(<script>…</script>・<style>・style属性・javascript:)も使わないので、'unsafe-inline' も不要。
     * - img-src の data: は BootstrapのCSSに埋め込まれたアイコン(SVG)、blob: は添付ファイルのプレビュー用
     * - Alpine.js は CSP対応版(eval を使わない)なので 'unsafe-eval' も不要
     */
    public const CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; "
        ."font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        // 開発時のエラー詳細画面(APP_DEBUG=true)はインラインのスクリプトを使うため、CSPを付けない。
        // 添付ファイルの配信など、個別にCSPを付けている応答はそのまま
        if (! config('app.debug') && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
        }

        return $response;
    }
}
