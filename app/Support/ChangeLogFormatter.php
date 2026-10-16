<?php

namespace App\Support;

use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use Illuminate\Support\Carbon;

/**
 * コメントに記録した変更履歴(change_log)を「状態: 未対応 → 処理中」のような表示用の文字列にする
 */
class ChangeLogFormatter
{
    private const FIELD_LABELS = [
        'status' => '状態',
        'assignee' => '担当者',
        'priority' => '優先度',
        'due_date' => '期限日',
    ];

    /**
     * @param  list<array{field: string, from: mixed, to: mixed}>  $changeLog
     * @return list<array{label: string, from: string, to: string}>
     */
    public static function items(array $changeLog): array
    {
        $items = [];
        foreach ($changeLog as $change) {
            $field = $change['field'] ?? '';
            $items[] = [
                'label' => self::FIELD_LABELS[$field] ?? $field,
                'from' => self::value($field, $change['from'] ?? null),
                'to' => self::value($field, $change['to'] ?? null),
            ];
        }

        return $items;
    }

    /**
     * メール本文用の1行表記
     *
     * @param  list<array{field: string, from: mixed, to: mixed}>  $changeLog
     * @return list<string>
     */
    public static function lines(array $changeLog): array
    {
        return array_map(
            fn (array $item) => "{$item['label']}: {$item['from']} → {$item['to']}",
            self::items($changeLog)
        );
    }

    private static function value(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '未設定';
        }

        return match ($field) {
            'status' => IssueStatus::tryFrom((string) $value)?->label() ?? (string) $value,
            'priority' => IssuePriority::tryFrom((string) $value)?->label() ?? (string) $value,
            'due_date' => Carbon::parse((string) $value)->format('Y/m/d'),
            default => (string) $value,
        };
    }
}
