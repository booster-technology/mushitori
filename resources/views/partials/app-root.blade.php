{{--
    <body> に付ける Alpine.js の設定(全画面共通。public/js/app.js の app)。
    確認ダイアログ・二重送信の防止(全フォームの送信を capture で受け取る)、「戻る」で表示した画面のボタンの復帰、
    ドロップ領域の外にファイルを落としたときにブラウザがファイルを開いてしまうのを防ぐ
--}}
x-data="app" @submit.capture="onSubmit" @pageshow.window="restoreForms" @dragover.window="ignoreDragOver" @drop.window="preventStrayDrop"
