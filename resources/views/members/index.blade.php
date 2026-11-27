@extends('layouts.app')

@section('title', 'メンバー管理')

{{-- docs/design/screens.md 3-7 --}}
@section('content')
    <div class="page__head">
        <div>
            <h1 class="h-title" style="display:flex;align-items:center;gap:8px"><x-channel-sign :channel="$channel" />{{ $channel->name }}<x-channel-badge :channel="$channel" /></h1>
            <p class="t-caption" style="margin-top:4px">@if ($channel->description !== null){{ $channel->description }}　·　@endif追加された人だけが読み書きできます。</p>
        </div>
        <a class="btn btn--sm" href="{{ route('channels.show', $channel) }}">チャンネルを開く</a>
    </div>
    <div class="card" style="margin-bottom:var(--sp-6)">
        <div class="card__body">
            <h2 class="h-heading" id="member-heading" style="margin-bottom:var(--sp-3)">メンバーを追加する</h2>
            <form method="POST" action="{{ route('channels.members.store', $channel) }}" novalidate>
                @csrf
                <div class="field @error('member') is-error @enderror" style="max-width:520px;margin-bottom:0">
                    <div class="inline-2">
                        <input class="input" name="member" type="text" value="{{ old('member') }}" placeholder="表示名またはメールアドレスで探す" aria-labelledby="member-heading">
                        <button type="submit" class="btn btn--primary"><svg class="ic" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M8 3.5v9M3.5 8h9"/></svg>追加</button>
                    </div>
                    @error('member')<span class="err">{{ $message }}</span>@enderror
                </div>
            </form>
        </div>
    </div>
    <h2 class="h-heading" style="margin-bottom:var(--sp-3)">メンバー　{{ $members->count() }}人</h2>
    <div class="rows">
        @foreach ($members as $member)
            <div class="row">
                <x-avatar :user="$member" />
                <div class="row__main">
                    <div class="row__title">{{ $member->name }}@if ($member->id === $channel->creator_id)<span class="badge badge--owner">作成者</span>@endif</div>
                    <div class="row__sub">{{ $member->email }}</div>
                </div>
                <div class="row__acts">
                    @can('removeMember', [$channel, $member])
                        <form method="POST" action="{{ route('channels.members.destroy', [$channel, $member]) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn--sm btn--danger">外す</button>
                        </form>
                    @else
                        <span class="t-caption">作成者は外せません</span>
                        <button type="button" class="btn btn--sm" disabled>外す</button>
                    @endcan
                </div>
            </div>
        @endforeach
    </div>
@endsection
