<?php

namespace App\Enums;

/**
 * 課題の状態(未対応・処理中・処理済み・完了の4段階)
 */
enum IssueStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => '未対応',
            self::InProgress => '処理中',
            self::Resolved => '処理済み',
            self::Closed => '完了',
        };
    }

    /**
     * バッジ表示用のCSSクラス(public/css/app.css で色を定義)
     */
    public function badgeClass(): string
    {
        return 'badge-status-'.$this->value;
    }

    /**
     * 完了以外(=対応が必要な状態)の一覧
     *
     * @return list<self>
     */
    public static function unclosed(): array
    {
        return [self::Open, self::InProgress, self::Resolved];
    }
}
