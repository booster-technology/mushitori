{{-- 利用者の入力(件名・本文・名前)は mail_escape() でMarkdown記号を無効化してから埋め込む --}}
<x-mail::message>
# 新しい課題が登録されました

{{ mail_escape($recipient->name) }} 様

@if ($issue->registered_by && $recipient->id === $issue->reporter_id)
{{-- 代理起票: お客様には、お問い合わせの内容を課題として登録したことを知らせる --}}
お問い合わせいただいた内容を、{{ mail_escape($actor->displayName()) }} が課題として登録しました。今後の対応状況は、この課題でお知らせします。
@elseif ($issue->registered_by)
{{ mail_escape($actor->displayName()) }} さんが、{{ mail_escape($issue->reporter->displayName()) }} さんの代わりに課題を登録しました。
@else
{{ mail_escape($actor->displayName()) }} さんが課題を登録しました。
@endif

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
