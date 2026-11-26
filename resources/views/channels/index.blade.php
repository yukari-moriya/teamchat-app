@extends('layouts.app')

@section('title', 'チャンネル一覧')

@section('content')
    <div class="page__head">
        <div>
            <h1 class="h-title">チャンネル一覧</h1>
            <p class="t-caption" style="margin-top:4px">公開チャンネルは、ログインしている社員なら誰でも読み書きできます。</p>
        </div>
        <a class="btn btn--primary" href="{{ route('channels.create') }}"><svg class="ic" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M8 3.5v9M3.5 8h9"/></svg>チャンネルを作る</a>
    </div>
    @if ($channels->isNotEmpty())
        <div class="rows">
            @foreach ($channels as $channel)
                <div class="row">
                    <div class="row__main">
                        <div class="row__title"><x-channel-sign :channel="$channel" />{{ $channel->name }}<x-channel-badge :channel="$channel" /></div>
                        <div class="row__sub">@if ($channel->description !== null){{ $channel->description }}　·　@endif作成者：{{ $channel->creator->name }}</div>
                    </div>
                    <div class="row__acts">
                        @can('manageMembers', $channel)
                            {{-- 行き先の画面とルートは実装単位 3 で作る --}}
                            <a class="btn btn--sm" href="{{ url("/channels/{$channel->id}/members") }}">メンバー</a>
                        @endcan
                        @can('update', $channel)
                            <a class="btn btn--sm" href="{{ route('channels.edit', $channel) }}">編集</a>
                        @endcan
                        <a class="btn btn--sm" href="{{ route('channels.show', $channel) }}">開く</a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
