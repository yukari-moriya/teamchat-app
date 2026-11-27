{{--
    行にマウスを乗せたときの操作（docs/design/screens.md 3-3 の No 14〜16、3-4 の No 6）。出し分けは CSS。直せる・消せるかは MessagePolicy に聞く。
    返信の行に「返信」は出さない（R-08）。出す操作が1つも無い行は、枠ごと出さない
--}}
@if (! $message->isReply() || Gate::any(['update', 'delete'], $message))
    <div class="msg__acts">
        @if (! $message->isReply())
            <a class="icon-btn" href="{{ $message->url($message->id) }}" title="返信" aria-label="返信"><svg class="ic" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 3.5h11v7h-6l-3 2.5v-2.5h-2z"/></svg></a>
        @endif
        @can('update', $message)
            <a class="icon-btn" href="{{ $message->url($thread?->id, editing: true) }}" title="編集" aria-label="編集"><svg class="ic" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"><path d="M11 2.6 13.4 5 5.4 13H3v-2.4z"/></svg></a>
        @endcan
        @can('delete', $message)
            {{-- 確認は挟まない（D-04） --}}
            <form method="POST" action="{{ route('channels.messages.destroy', [$channel, $message]) }}">
                @csrf
                @method('DELETE')
                @include('channels._thread-field')
                <button type="submit" class="icon-btn" title="削除" aria-label="削除"><svg class="ic" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M3 4.5h10M6.5 4.5V3h3v1.5M4.5 4.5l.6 8.5h5.8l.6-8.5"/></svg></button>
            </form>
        @endcan
    </div>
@endif
