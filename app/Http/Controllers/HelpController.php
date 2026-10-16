<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * 使い方(ヘルプ)の画面。社内メンバーには社内向け、お客様にはお客様向けの説明を表示する
 */
class HelpController extends Controller
{
    /**
     * 使い方(画面上部の「使い方」から開く)
     */
    public function show(): View
    {
        return view(portal() === 'staff' ? 'help.staff' : 'help.customer');
    }

    /**
     * お客様向けの使い方(社内メンバーが、お客様への案内のために内容を確認する用)
     */
    public function customer(): View
    {
        return view('help.customer');
    }
}
