{{-- 利用者の入力(件名・本文・名前)は mail_escape() でMarkdown記号を無効化してから埋め込む --}}
<x-mail::message>
# 新しい課題が登録されました

{{ mail_escape($recipient->name) }} 様

{{ mail_escape($actor->displayName()) }} さんが課題を登録しました。

<x-mail::table>
| 項目 | 内容 |
|:--|:--|
| 課題 | {{ $issue->issueKey() }} {{ mail_escape($issue->title) }} |
| プロジェクト | {{ mail_escape($issue->project->name) }} |
| 種別 | {{ $issue->type->label() }} |
| 優先度 | {{ $issue->priority->label() }} |
| 担当者 | {{ mail_escape($issue->assignee?->name ?? '未設定') }} |
</x-mail::table>

@if ($issue->description)
<x-mail::panel>
{!! nl2br(e(mail_escape(\Illuminate\Support\Str::limit($issue->description, 500)))) !!}
</x-mail::panel>
@endif

<x-mail::button :url="$url">
課題を確認する
</x-mail::button>
</x-mail::message>
