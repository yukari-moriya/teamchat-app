<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=1280">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title')｜チームチャットアプリ</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="app">
    <header class="topbar">
        <div class="topbar__brand"><span class="brandmark">チ</span>チームチャットアプリ</div>
        <div class="topbar__search">
            <form method="GET" action="{{ route('search') }}" role="search">
                <div class="search-input">
                    <svg class="ic" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5 14 14" stroke-linecap="round"/></svg>
                    <input name="q" type="text" placeholder="メッセージを検索" aria-label="メッセージを検索">
                </div>
            </form>
        </div>
        <div class="topbar__me">
            <span class="nm">{{ Auth::user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">ログアウト</button>
            </form>
        </div>
    </header>
    <div @class(['app__body', 'app__body--thread' => View::hasSection('thread')])>
        <nav class="sidebar">
            <div class="side-group">チャンネル</div>
            @foreach ($sidebarChannels as $sidebarChannel)
                <a @class(['side-item', 'is-selected' => $sidebarChannel->id === $currentChannelId]) href="{{ route('channels.show', $sidebarChannel) }}">
                    <x-channel-sign :channel="$sidebarChannel" />
                    <span class="nm">{{ $sidebarChannel->name }}</span>
                </a>
            @endforeach
            <div class="side-foot"><a href="{{ route('channels.index') }}">チャンネル一覧を見る</a></div>
        </nav>
        {{-- チャンネルの画面（chat）は、モックのとおり余白の枠（.page）を使わず、面いっぱいに置く --}}
        @hasSection('chat')
            <main class="main">
                @yield('chat')
            </main>
        @else
            <main class="main main--scroll">
                <div class="page @yield('page_class')">
                    @yield('content')
                </div>
            </main>
        @endif
        {{-- スレッドのパネル（SC-05）。開いているときだけ、本文の右に置く --}}
        @yield('thread')
    </div>
</div>
</body>
</html>
