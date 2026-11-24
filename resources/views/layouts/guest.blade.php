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
<div class="auth">
    <div class="auth__card">
        <div class="auth__brand"><span class="brandmark">チ</span>チームチャットアプリ</div>
        @yield('content')
    </div>
</div>
</body>
</html>
