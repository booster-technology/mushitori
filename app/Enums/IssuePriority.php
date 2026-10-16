<?php

namespace App\Enums;

/**
 * 課題の優先度
 */
enum IssuePriority: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::High => '高',
            self::Normal => '中',
            self::Low => '低',
        };
    }

    /**
     * 一覧表示用のアイコン(Bootstrap Icons)
     */
    public function icon(): string
    {
        return match ($this) {
            self::High => 'bi-arrow-up-circle-fill text-danger',
            self::Normal => 'bi-dash-circle text-secondary',
            self::Low => 'bi-arrow-down-circle text-info',
        };
    }

    /**
     * 「優先度が高い順」の並び替えに使うSQL(MySQL/SQLiteの両方で動く書き方)
     */
    public static function orderSql(string $column = 'priority'): string
    {
        return "CASE {$column} WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END";
    }
}
