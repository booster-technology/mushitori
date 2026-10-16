<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * 添付ファイルの配信。課題を閲覧できる人にだけ返す
 */
class AttachmentController extends Controller
{
    /**
     * サムネイルが無い画像(作成に失敗したもの)の代わりに元画像を返すのは、この画素数まで。
     * これより大きい画像を一覧に並べると、表示するブラウザが重くなる・固まるため
     */
    private const ORIGINAL_AS_THUMBNAIL_MAX_PIXELS = 16_000_000;

    /** 画像の表示時に付けるCSP(画像を直接開いた場合も、スクリプト等は一切動かさない) */
    private const IMAGE_CSP = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox";

    /**
     * 画像はブラウザで表示し、それ以外はダウンロードさせる
     * (HTMLなどをブラウザに解釈させてスクリプトが動くのを防ぐため)
     */
    public function show(Request $request, Attachment $attachment): Response
    {
        Gate::authorize('view', $attachment);

        $disk = Storage::disk('local');
        if (! $disk->exists($attachment->path)) {
            $this->logMissingFile($attachment, $attachment->path);
            abort(404);
        }

        if ($notModified = $this->notModified($request, $attachment->path)) {
            return $notModified;
        }

        if ($attachment->isImage()) {
            return $disk->response($attachment->path, $attachment->original_name, $this->headers($attachment->path) + [
                'Content-Type' => $attachment->mime_type,
                'Content-Security-Policy' => self::IMAGE_CSP,
            ]);
        }

        return $disk->download($attachment->path, $attachment->original_name, $this->headers($attachment->path) + [
            'Content-Type' => 'application/octet-stream',
        ]);
    }

    /**
     * 一覧表示用のサムネイル(サムネイルが無い場合は、画素数が多すぎなければ元画像)
     */
    public function thumbnail(Request $request, Attachment $attachment): Response
    {
        Gate::authorize('view', $attachment);
        abort_unless($attachment->isImage(), 404);

        $disk = Storage::disk('local');
        if ($attachment->thumbnail_path && $disk->exists($attachment->thumbnail_path)) {
            $path = $attachment->thumbnail_path;
        } else {
            abort_if((int) $attachment->width * (int) $attachment->height > self::ORIGINAL_AS_THUMBNAIL_MAX_PIXELS, 404);
            $path = $attachment->path;
        }

        if (! $disk->exists($path)) {
            $this->logMissingFile($attachment, $path);
            abort(404);
        }

        if ($notModified = $this->notModified($request, $path)) {
            return $notModified;
        }

        return $disk->response($path, null, $this->headers($path) + [
            'Content-Type' => $path === $attachment->path ? $attachment->mime_type : 'image/jpeg',
            'Content-Security-Policy' => self::IMAGE_CSP,
        ]);
    }

    /**
     * 共通のヘッダー。
     * キャッシュは「保存してよいが、表示のたびにサーバーへ確認する」(no-cache)にする。
     * ログアウト後やプロジェクトから外された後に、ブラウザのキャッシュから表示され続けないように(確認時に権限チェックが行われる)。
     * 添付ファイルは後から中身が変わらないので、確認時は変更なし(304)を返し、ファイル自体は再送しない
     *
     * @return array<string, string>
     */
    private function headers(string $path): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-cache',
            'ETag' => $this->etag($path),
        ];
    }

    /**
     * ブラウザのキャッシュが最新なら 304(Not Modified)を返す
     */
    private function notModified(Request $request, string $path): ?Response
    {
        $response = new Response;
        $response->headers->add($this->headers($path));

        return $response->isNotModified($request) ? $response : null;
    }

    /**
     * ファイルの保存先はアップロードごとに異なり、中身も変わらないため、保存先からETagを作る
     */
    private function etag(string $path): string
    {
        return '"'.sha1($path).'"';
    }

    /**
     * DBにはあるのにファイルが無い(バックアップからの復元漏れ・手動削除など)のは異常なので、気付けるようにログに残す
     */
    private function logMissingFile(Attachment $attachment, string $path): void
    {
        Log::warning('添付ファイルの実体が見つかりません', [
            'attachment_id' => $attachment->id,
            'issue_id' => $attachment->issue_id,
            'path' => $path,
        ]);
    }
}
