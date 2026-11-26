<?php

namespace App\View\Composers;

use App\Models\Channel;
use Illuminate\View\View;

/**
 * 共通レイアウトのサイドバー（F-05）。見られるチャンネルを渡し、開いているチャンネルを選択中にする。
 */
class SidebarComposer
{
    public function compose(View $view): void
    {
        $current = request()->route('channel');

        $view->with([
            'sidebarChannels' => Channel::visibleTo(auth()->user())->inListOrder()->get(),
            'currentChannelId' => $current instanceof Channel ? $current->id : null,
        ]);
    }
}
