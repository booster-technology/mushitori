{{-- 利用者の入力(件名・本文・名前)は mail_escape() でMarkdown記号を無効化してから埋め込む --}}
<x-mail::message>
# 課題が更新されました

{{ mail_escape($recipient->name) }} 様

{{ mail_escape($actor->displayName()) }} さんが課題 **{{ $issue->issueKey() }} {{ mail_escape($issue->title) }}** を更新しました。

@if ($changes)
@foreach ($changes as $line)
- {{ mail_escape($line) }}
@endforeach

@endif
@if ($comment->body)
<x-mail::panel>
{!! nl2br(e(mail_escape(\Illuminate\Support\Str::limit($comment->body, 1000)))) !!}
</x-mail::panel>
@endif

現在の状態: **{{ $issue->status->label() }}**　／　担当者: {{ mail_escape($issue->assignee?->name ?? '未設定') }}

<x-mail::button :url="$url">
課題を確認する
</x-mail::button>
</x-mail::message>
