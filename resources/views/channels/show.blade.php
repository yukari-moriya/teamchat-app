@extends('layouts.app')

@section('title', $channel->name)

{{-- いまは見出しまで（docs/design/screens.md 3-3 の No 1〜5）。メッセージ一覧と投稿欄は実装単位 4・5 --}}
@section('chat')
    <div class="ch-head">
        <div class="ch-head__main">
            <div class="ch-head__name"><x-channel-sign :channel="$channel" />{{ $channel->name }}</div>
            <x-channel-badge :channel="$channel" />
            @if ($channel->description !== null)
                <span class="ch-head__desc">{{ $channel->description }}</span>
            @endif
        </div>
        <div class="ch-head__acts">
            @can('update', $channel)
                <a class="btn btn--sm" href="{{ route('channels.edit', $channel) }}"><svg class="ic" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"><path d="M11 2.6 13.4 5 5.4 13H3v-2.4z"/></svg>編集</a>
            @endcan
            @can('delete', $channel)
                <a class="btn btn--sm btn--danger" href="{{ route('channels.edit', ['channel' => $channel, 'confirm_delete' => 1]) }}"><svg class="ic" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M3 4.5h10M6.5 4.5V3h3v1.5M4.5 4.5l.6 8.5h5.8l.6-8.5"/></svg>削除</a>
            @endcan
        </div>
    </div>
@endsection
