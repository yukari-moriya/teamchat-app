@extends('layouts.app')

@section('title', 'チャンネルを作る')

@section('page_class', 'page--narrow')

@section('content')
    <h1 class="h-title" style="margin-bottom:var(--sp-6)">チャンネルを作る</h1>
    <div class="card">
        <div class="card__body">
            <form method="POST" action="{{ route('channels.store') }}" novalidate>
                @csrf
                @include('channels._fields', ['channel' => null, 'nameHelp' => '', 'descriptionHelp' => '何について話す場所かを短く書きます。'])
                @php($isPrivate = old('visibility') === 'private')
                <div class="field @error('visibility') is-error @enderror">
                    <span class="label">公開範囲</span>
                    <div class="radio-set">
                        <label class="radio">
                            <input type="radio" name="visibility" value="public" @checked(! $isPrivate)>
                            <span><span class="rt">公開</span><span class="rd" style="display:block">ログインしている社員なら誰でも読み書きできます。</span></span>
                        </label>
                        <label class="radio">
                            <input type="radio" name="visibility" value="private" @checked($isPrivate)>
                            <span><span class="rt">プライベート</span><span class="rd" style="display:block">追加された人だけが読み書きできます。作成者がメンバーを管理します。</span></span>
                        </label>
                    </div>
                    @error('visibility')<span class="err">{{ $message }}</span>@enderror
                </div>
                <div class="inline-2" style="justify-content:flex-end;margin-top:var(--sp-6)">
                    <a class="btn" href="{{ route('channels.index') }}">キャンセル</a>
                    <button type="submit" class="btn btn--primary">作成する</button>
                </div>
            </form>
        </div>
    </div>
@endsection
