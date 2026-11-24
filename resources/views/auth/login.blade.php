@extends('layouts.guest')

@section('title', 'ログイン')

@section('content')
    <h1 class="h-display" style="text-align:center;margin-bottom:var(--sp-6);font-size:var(--fs-title)">ログイン</h1>
    <form method="POST" action="{{ url('/login') }}" novalidate>
        @csrf
        <div class="field @error('email') is-error @enderror">
            <label class="label" for="email">メールアドレス</label>
            <input class="input" id="email" name="email" type="text" value="{{ old('email') }}" autocomplete="username">
            @error('email')<span class="err">{{ $message }}</span>@enderror
        </div>
        <div class="field @error('password') is-error @enderror">
            <label class="label" for="password">パスワード</label>
            <input class="input" id="password" name="password" type="password" autocomplete="current-password">
            @error('password')<span class="err">{{ $message }}</span>@enderror
        </div>
        <button type="submit" class="btn btn--primary btn--lg btn--block">ログイン</button>
    </form>
    <div class="auth__foot">アカウントをお持ちでない方は <a href="{{ route('register') }}">新規登録</a></div>
@endsection
