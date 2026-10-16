<?php

namespace App\Enums;

/**
 * 課題の種別
 */
enum IssueType: string
{
    case Bug = 'bug';
    case Request = 'request';
    case Question = 'question';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Bug => '不具合',
            self::Request => '要望',
            self::Question => '質問',
            self::Other => 'その他',
        };
    }

    public function badgeClass(): string
    {
        return 'badge-type-'.$this->value;
    }

    /**
     * 新規起票時に詳細欄へ入れておく記入テンプレート(お客様が何を書けばよいか迷わないように)
     */
    public function descriptionTemplate(): string
    {
        return match ($this) {
            self::Bug => "【発生した画面・URL】\n\n【操作手順】\n1. \n2. \n\n【期待する動作】\n\n【実際の動作】\n\n【発生日時・頻度】\n\n【端末・ブラウザ】\n",
            self::Request => "【対象の画面・機能】\n\n【ご要望の内容】\n\n【理由・背景】\n\n【希望時期】\n",
            self::Question => "【対象の画面・機能】\n\n【ご質問の内容】\n",
            self::Other => '',
        };
    }
}
