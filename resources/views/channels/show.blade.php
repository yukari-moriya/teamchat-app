@extends('layouts.app')

@section('title', $channel->name)

@if ($thread !== null)
    @section('thread')
        @include('channels._thread')
    @endsection
@endif

{{-- docs/design/screens.md 3-3。スレッドのパネル（SC-05）は _thread --}}
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

    <div class="msg-list">
        @forelse ($messages->groupBy(fn ($message) => $message->created_at->format('Y-m-d')) as $messagesOfDay)
            <div class="day-sep"><span>{{ $messagesOfDay->first()->created_at->isoFormat('YYYY年M月D日（ddd）') }}</span></div>
            @foreach ($messagesOfDay as $message)
                <x-message :message="$message" :id="$message->anchor()">
                    <x-slot:actions>
                        @include('channels._message-actions')
                    </x-slot:actions>

                    @if ($message->id === $editingMessageId)
                        <x-slot:editForm>
                            @include('channels._message-edit-form')
                        </x-slot:editForm>
                    @endif

                    {{-- 削除されたメッセージでも出す。件数は削除された返信も数える --}}
                    @if ($message->replies_count > 0)
                        <a class="thread-link" href="{{ $message->url($message->id) }}"><svg class="ic" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 3.5h11v7h-6l-3 2.5v-2.5h-2z"/></svg>返信{{ $message->replies_count }}件</a>
                    @endif
                </x-message>
            @endforeach
        @empty
            <div class="empty">
                <div class="empty__title">まだメッセージはありません</div>
                <div class="empty__text">下の欄から最初のメッセージを送ってください</div>
            </div>
        @endforelse
    </div>

    <form @class(['composer', 'is-error' => $errors->has('body')]) method="POST" action="{{ route('channels.messages.store', $channel) }}" novalidate>
        @csrf
        @include('channels._thread-field')
        <div class="composer__box">
            <textarea class="composer__ta" name="body" placeholder="#{{ $channel->name }} にメッセージを送る" aria-label="メッセージ">{{ old('body') }}</textarea>
            <div class="composer__foot">
                <span class="count">1,000文字まで</span>
                <button type="submit" class="btn btn--primary btn--sm">送信</button>
            </div>
        </div>
        @error('body')<span class="err">{{ $message }}</span>@enderror
    </form>
@endsection
