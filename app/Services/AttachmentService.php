<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\User;
use App\Support\JpegOrientation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * 添付ファイルの保存・サムネイル生成・削除を担当する。
 * ファイルは公開ディレクトリではなく local ディスク(storage/app/private)に保存し、権限チェック付きのルートからのみ配信する。
 */
class AttachmentService
{
    /** 1回の投稿で添付できる最大ファイル数 */
    public const MAX_FILES = 10;

    /** 1ファイルの最大サイズ(KB) */
    public const MAX_SIZE_KB = 10240;

    /** 添付を許可する拡張子(SVG・HTMLなどスクリプトを含められる形式は不可) */
    public const ALLOWED_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'webp',
        'pdf', 'txt', 'log', 'csv',
        'xlsx', 'xls', 'docx', 'doc', 'pptx', 'ppt',
        'zip', 'mp4', 'mov',
    ];

    /** ブラウザでインライン表示・サムネイル生成する画像のMIMEタイプ */
    public const IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * 画像の長辺の上限(px)。これを超える画像は縮小して保存する。
     * 不具合報告のスクリーンショットは文字が読めることが重要なので、PC画面(フルHD〜WQHD)はそのまま残る大きさにしている
     */
    public const MAX_IMAGE_EDGE = 2560;

    /**
     * 受け付ける画像の画素数の上限(5000万画素。高解像度のPCで撮った縦長のページ全体のスクリーンショットも収まる大きさ)。
     * PNGなどは数十KBでも2万×2万pxの画像を作れ、表示するブラウザがメモリ不足で固まるため、これを超える画像は受け付けない
     */
    public const MAX_IMAGE_PIXELS = 50_000_000;

    /** サムネイルの最大サイズ(px) */
    private const THUMB_MAX_WIDTH = 480;

    private const THUMB_MAX_HEIGHT = 360;

    /** これを超える画素数の画像はメモリ不足を避けるためサムネイルを作らない(4000万画素≒スマホ写真の最大級) */
    private const THUMB_MAX_PIXELS = 40_000_000;

    private const DISK = 'local';

    /**
     * バリデーションルール(課題・コメントの各フォームで共通)
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $extensions = implode(',', self::ALLOWED_EXTENSIONS);

        return [
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            // extensions: ファイル名の拡張子 / mimes: ファイルの中身から判定した形式 の両方をチェックする
            'attachments.*' => [
                'file',
                'max:'.self::MAX_SIZE_KB,
                'extensions:'.$extensions,
                'mimes:'.$extensions,
                self::imageContentRule(),
            ],
        ];
    }

    /**
     * 画像の拡張子なのに中身が画像でないファイル(例: PHPコードを .png にしたもの)を拒否する。
     * mimes ルールだけだと中身がテキストの場合に「txt」として通ってしまうため
     */
    private static function imageContentRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (! $value instanceof UploadedFile) {
                return;
            }
            // 拡張子が画像の場合に加えて、中身が画像の場合も確認する
            // (中身は巨大な画像なのに拡張子を .txt などにして、画素数の上限をすり抜けられないように。保存・表示は中身で画像と判定するため)
            $isImageExtension = in_array(strtolower($value->getClientOriginalExtension()), ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
            $isImageContent = in_array((string) @(new \finfo(FILEINFO_MIME_TYPE))->file($value->getRealPath()), self::IMAGE_MIME_TYPES, true);
            if (! $isImageExtension && ! $isImageContent) {
                return;
            }
            $size = @getimagesize($value->getRealPath());
            if (! $isImageContent || $size === false) {
                $fail('「'.$value->getClientOriginalName().'」は画像ファイルとして読み込めません。');

                return;
            }
            // 画素数は画像を展開せずにヘッダーから読める(重い処理はしない)
            if ($size[0] * $size[1] > self::MAX_IMAGE_PIXELS) {
                $fail('「'.$value->getClientOriginalName().'」は画像の縦横が大きすぎます（'.number_format(self::MAX_IMAGE_PIXELS / 10_000).'万画素まで）。');
            }
        };
    }

    /**
     * アップロードされたファイルを保存し、画像ならサムネイルも作る
     */
    public function store(UploadedFile $file, Issue $issue, User $user, ?Comment $comment = null): Attachment
    {
        $extension = strtolower($file->getClientOriginalExtension());
        // MIMEタイプはクライアントの申告ではなくファイルの中身から判定した値を使う
        $mimeType = (string) $file->getMimeType();
        $directory = "attachments/{$issue->project_id}/{$issue->id}";
        $basename = (string) Str::uuid();

        $path = $file->storeAs($directory, $basename.'.'.$extension, self::DISK);
        if ($path === false) {
            throw new \RuntimeException('添付ファイルの保存に失敗しました。');
        }

        $width = $height = null;
        $thumbnailPath = null;
        $fileSize = (int) $file->getSize();

        try {
            if (in_array($mimeType, self::IMAGE_MIME_TYPES, true)) {
                $fullPath = Storage::disk(self::DISK)->path($path);

                // 大きすぎる画像(スマホの写真など)は長辺 MAX_IMAGE_EDGE px まで縮小して保存する
                if ($this->shrinkIfTooLarge($fullPath)) {
                    clearstatcache(true, $fullPath);
                    $fileSize = (int) filesize($fullPath);
                }

                $size = @getimagesize($fullPath);
                if ($size !== false) {
                    [$width, $height] = $size;
                    // 縮小していないJPEGはEXIFの向き情報が残っているので、表示上の縦横に合わせる
                    if ($size[2] === IMAGETYPE_JPEG && JpegOrientation::swapsDimensions(JpegOrientation::read($fullPath))) {
                        [$width, $height] = [$height, $width];
                    }
                    $thumbnailPath = $this->makeThumbnail($fullPath, "{$directory}/thumbs/{$basename}.jpg");
                }
            }

            return Attachment::create([
                'issue_id' => $issue->id,
                'comment_id' => $comment?->id,
                'user_id' => $user->id,
                'original_name' => $this->sanitizeName($file->getClientOriginalName()),
                'path' => $path,
                'thumbnail_path' => $thumbnailPath,
                'mime_type' => $mimeType,
                'size' => $fileSize,
                'width' => $width,
                'height' => $height,
            ]);
        } catch (Throwable $e) {
            // 保存済みのファイルが、DBに記録されないまま残らないようにする
            Storage::disk(self::DISK)->delete(array_filter([$path, $thumbnailPath]));

            throw $e;
        }
    }

    /**
     * 添付ファイルを削除する(論理削除。データ・ファイルは残し、画面・配信からは見えなくする)
     */
    public function delete(Attachment $attachment, ?User $actor = null): void
    {
        $attachment->trashBy($actor);
    }

    /**
     * ストレージ上のファイルを削除する。
     * 登録に失敗したときの後片付け(DBに記録されなかったファイルを残さない)専用。削除操作では使わない(削除は論理削除)
     */
    public function deleteFiles(Attachment $attachment): void
    {
        $paths = array_filter([$attachment->path, $attachment->thumbnail_path]);
        Storage::disk(self::DISK)->delete($paths);
    }

    /**
     * 一覧表示用のサムネイル(JPEG)を生成する。失敗しても添付自体は成功扱いにする
     */
    private function makeThumbnail(string $sourcePath, string $thumbnailPath): ?string
    {
        try {
            $info = @getimagesize($sourcePath);
            if ($info === false) {
                return null;
            }
            [$width, $height, $type] = $info;
            if ($width < 1 || $height < 1 || $width * $height > self::THUMB_MAX_PIXELS) {
                return null;
            }

            // サムネイルの大きさは「表示上の向き」(EXIFの向きを反映した縦横)で決める
            $orientation = $type === IMAGETYPE_JPEG ? JpegOrientation::read($sourcePath) : 1;
            [$displayWidth, $displayHeight] = JpegOrientation::swapsDimensions($orientation) ? [$height, $width] : [$width, $height];
            $scale = min(1, self::THUMB_MAX_WIDTH / $displayWidth, self::THUMB_MAX_HEIGHT / $displayHeight);
            // 縮小は回転前の画像に対して行い、回転は小さくなったサムネイルで行う(大きいまま回転するとメモリが倍必要になるため)
            $thumbWidth = max(1, (int) round($width * $scale));
            $thumbHeight = max(1, (int) round($height * $scale));

            $source = $this->loadImage($sourcePath, $type, $width, $height, $thumbWidth * $thumbHeight * 5 * 2);
            if ($source === null) {
                Log::info('サムネイルを作成できませんでした(メモリ不足または読み込み失敗)', ['width' => $width, 'height' => $height]);

                return null;
            }

            $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
            // 透過PNGはJPEG化すると黒くなるため、白で塗ってから描画する
            imagefill($thumb, 0, 0, (int) imagecolorallocate($thumb, 255, 255, 255));
            imagecopyresampled($thumb, $source, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);
            // 元画像のメモリはすぐ解放する
            unset($source);
            $thumb = JpegOrientation::apply($thumb, $orientation);

            ob_start();
            imagejpeg($thumb, null, 82);
            $data = (string) ob_get_clean();

            Storage::disk(self::DISK)->put($thumbnailPath, $data);

            return $thumbnailPath;
        } catch (Throwable $e) {
            Log::warning('サムネイルの生成に失敗しました', ['path' => $sourcePath, 'exception' => $e]);

            return null;
        }
    }

    /**
     * 画像の展開に必要なメモリを確保できるか確認し、足りなければ memory_limit を引き上げる。
     * 画像処理に使ってよいメモリは .env の IMAGE_MEMORY_LIMIT(既定256M)まで。超える画像は縮小・サムネイル作成をせず、そのまま保存する
     */
    private function ensureMemory(int $requiredBytes): bool
    {
        // 現在の使用量 + 画像展開分 + 余裕(32MB)
        $needed = memory_get_usage(true) + $requiredBytes + 32 * 1024 * 1024;

        // 同時に複数人がアップロードしてもサーバーのメモリが足りなくならないよう、1リクエストあたりの上限を設ける
        // (php.ini の memory_limit が無制限でもこの上限は守る)
        $cap = $this->toBytes((string) config('app.image_memory_limit', '256M'));
        if ($cap > 0 && $needed > $cap) {
            return false;
        }

        $limit = $this->toBytes((string) ini_get('memory_limit'));
        if ($limit < 0 || $needed <= $limit) {
            return true;
        }

        return ini_set('memory_limit', (string) $needed) !== false;
    }

    /**
     * php.ini のサイズ表記(128M など)をバイト数にする
     */
    private function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * 長辺が MAX_IMAGE_EDGE を超える画像を、同じ形式のまま縮小して上書き保存する。縮小した場合 true
     * - PNG(スクリーンショット)は文字がにじまないよう可逆圧縮のまま、透過も保つ
     * - JPEGは向き情報(EXIF)を画素に反映してから保存する(再保存するとEXIFが消えるため。位置情報などのメタデータも消える)
     * - GIFはアニメーションが失われるため縮小しない
     */
    private function shrinkIfTooLarge(string $path): bool
    {
        try {
            $info = @getimagesize($path);
            if ($info === false) {
                return false;
            }
            [$width, $height, $type] = $info;
            if (max($width, $height) <= self::MAX_IMAGE_EDGE || $type === IMAGETYPE_GIF) {
                return false;
            }

            // 縮小は回転前の画像に対して行い、回転は縮小後の小さい画像で行う(大きいまま回転するとメモリが倍必要になるため)。
            // 長辺は回転しても変わらないので、縮小率は回転前の大きさで計算してよい
            $orientation = $type === IMAGETYPE_JPEG ? JpegOrientation::read($path) : 1;
            $scale = self::MAX_IMAGE_EDGE / max($width, $height);
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));

            // 縮小後の画像(回転する場合はその回転後の分も)が同時にメモリに載るので、その分も見込んでおく
            $resizedBytes = $newWidth * $newHeight * 5 * ($orientation === 1 ? 1 : 2);
            $source = $this->loadImage($path, $type, $width, $height, $resizedBytes);
            if ($source === null) {
                Log::info('画像を縮小できませんでした(メモリ不足または読み込み失敗)。元のサイズのまま保存します', ['width' => $width, 'height' => $height]);

                return false;
            }

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            if ($type !== IMAGETYPE_JPEG) {
                // 透過を保つ
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagefill($resized, 0, 0, (int) imagecolorallocatealpha($resized, 0, 0, 0, 127));
            }
            imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            unset($source);
            $resized = JpegOrientation::apply($resized, $orientation);

            // 一時ファイルに書いてから置き換える(途中で失敗しても元のファイルを壊さない)
            $temp = $path.'.tmp';
            $written = match ($type) {
                IMAGETYPE_PNG => imagepng($resized, $temp, 6),
                IMAGETYPE_JPEG => imagejpeg($resized, $temp, 88),
                IMAGETYPE_WEBP => imagewebp($resized, $temp, 85),
                default => false,
            };
            unset($resized);

            if (! $written || ! rename($temp, $path)) {
                @unlink($temp);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('画像の縮小に失敗しました。元のサイズのまま保存します', ['path' => $path, 'exception' => $e]);

            return false;
        }
    }

    /**
     * 画像を読み込む(向きの補正はしない。縮小した後の小さい画像で行う)。メモリを確保できない・読めない場合は null
     * $extraBytes: 読み込んだ後に作る画像(縮小後の画像など)の分として追加で確保しておくメモリ
     */
    private function loadImage(string $path, int $type, int $width, int $height, int $extraBytes = 0): ?\GdImage
    {
        // GDは画像を展開するとメモリを大きく使う(1画素あたり約4〜5バイト。2400万画素で約100MB)。
        // メモリ不足のFatal Errorはcatchできず登録自体が失敗するため、足りなければ上限を引き上げ、無理なら諦める
        if (! $this->ensureMemory($width * $height * 5 + $extraBytes)) {
            return null;
        }

        $image = match ($type) {
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };
        if ($image === false) {
            return null;
        }

        return $image;
    }

    /**
     * 表示・ダウンロード用のファイル名から制御文字やパス区切りを取り除く。
     * 文字の向きを変える制御文字(U+202E など)も取り除く(「reportfdp.zip」を「reportpiz.pdf」のように見せかけられないように)
     */
    private function sanitizeName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\\p{Cf}\p{Zl}\p{Zp}]/u', '', $name) ?? '';
        $name = trim($name);

        return $name === '' ? 'file' : Str::limit($name, 200, '');
    }
}
