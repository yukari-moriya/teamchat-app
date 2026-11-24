@extends('layouts.guest')

@section('title', '新規登録')

@section('content')
    <h1 class="h-display" style="text-align:center;margin-bottom:var(--sp-6);font-size:var(--fs-title)">新規登録</h1>
    <form method="POST" action="{{ url('/register') }}" novalidate>
        @csrf
        <div class="field @error('name') is-error @enderror">
            <label class="label" for="name">表示名<span class="req">*</span></label>
            <input class="input" id="name" name="name" type="text" value="{{ old('name') }}" autocomplete="name">
            @error('name')<span class="err">{{ $message }}</span>@enderror
            <div class="field-foot"><span class="help">メッセージに表示される名前です。</span><span class="count">30文字まで</span></div>
        </div>
        <div class="field @error('email') is-error @enderror">
            <label class="label" for="email">メールアドレス<span class="req">*</span></label>
            <input class="input" id="email" name="email" type="text" value="{{ old('email') }}" autocomplete="email">
            @error('email')<span class="err">{{ $message }}</span>@enderror
        </div>
        <div class="field @error('password') is-error @enderror">
            <label class="label" for="password">パスワード<span class="req">*</span></label>
            <input class="input" id="password" name="password" type="password" autocomplete="new-password">
            @error('password')<span class="err">{{ $message }}</span>@else<span class="help">8文字以上で入力してください。</span>@enderror
        </div>
        <div class="field @error('password_confirmation') is-error @enderror">
            <label class="label" for="password_confirmation">パスワード（確認用）<span class="req">*</span></label>
            <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">
            @error('password_confirmation')<span class="err">{{ $message }}</span>@enderror
        </div>
        <button type="submit" class="btn btn--primary btn--lg btn--block">登録する</button>
    </form>
    <div class="auth__foot">すでにアカウントをお持ちの方は <a href="{{ route('login') }}">ログイン</a></div>
@endsection
