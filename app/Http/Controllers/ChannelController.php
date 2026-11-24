<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class ChannelController extends Controller
{
    // 実装単位 2 で、見られるチャンネルの一覧に作り替える。いまはログイン後の移動先を確かめるための最小の画面
    public function index(): View
    {
        return view('channels.index');
    }
}
