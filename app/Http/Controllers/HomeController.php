<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ログイン後のホーム画面(プロジェクト一覧・自分の担当課題・最近の更新)
 */
class HomeController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    public function index(Request $request): View
    {
        return view('home', $this->dashboard->forUser($request->user()));
    }
}
