<?php

namespace App\Models;

use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 課題・コメントの添付ファイル。ファイル本体は非公開ディスクに保存し、権限チェック付きのルート経由で配信する。
 * 削除は論理削除(データ・ファイルは残し、画面・配信からは見えなくする)
 */
class Attachment extends Model
{
    use Trashable;

    protected $fillable = [
        'issue_id',
        'comment_id',
        'user_id',
        'original_name',
        'path',
        'thumbnail_path',
        'mime_type',
        'size',
        'width',
        'height',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ブラウザでそのまま表示する画像か(それ以外はダウンロードさせる)
     */
    public function isImage(): bool
    {
        return in_array($this->mime_type, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);
    }

    /**
     * 表示用のファイルサイズ(例: 1.2 MB)
     */
    public function humanSize(): string
    {
        $size = $this->size;
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($size < 1024 || $unit === 'GB') {
                return ($unit === 'B' ? $size : number_format($size, 1)).' '.$unit;
            }
            $size /= 1024;
        }

        return '';
    }

    /**
     * ファイル種別に応じたアイコン(Bootstrap Icons)
     */
    public function icon(): string
    {
        $ext = strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));

        return match (true) {
            $this->isImage() => 'bi-file-earmark-image',
            $ext === 'pdf' => 'bi-file-earmark-pdf',
            in_array($ext, ['xls', 'xlsx', 'csv'], true) => 'bi-file-earmark-spreadsheet',
            in_array($ext, ['doc', 'docx'], true) => 'bi-file-earmark-word',
            in_array($ext, ['ppt', 'pptx'], true) => 'bi-file-earmark-slides',
            in_array($ext, ['zip'], true) => 'bi-file-earmark-zip',
            in_array($ext, ['mp4', 'mov'], true) => 'bi-file-earmark-play',
            default => 'bi-file-earmark-text',
        };
    }
}
