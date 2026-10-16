<x-mail::message>
# ログイン認証コード

{{ mail_escape($user->name) }} 様

{{ config('app.name') }} へのログイン認証コードをお送りします。
ログイン画面に以下の6桁のコードを入力してください。

<x-mail::panel>
<div style="font-size: 28px; font-weight: bold; letter-spacing: 8px; text-align: center;">{{ $code }}</div>
</x-mail::panel>

このコードの有効期限は **{{ $expiresMinutes }}分** です。

このメールが複数届いた場合は、最も新しいメールのコードを入力してください（コードを再送すると、以前のコードは使えなくなります）。

お心当たりのない場合は、このメールを破棄してください。第三者がメールアドレスを誤って入力した可能性があります。
</x-mail::message>
