{{--
    メッセージ（返信を含む）の1行（docs/design/screens.md 3-3 の No 7〜12）。
    削除済みの表示はここにだけ書き、チャンネルとスレッドの両方でこの部品を使う（D-05 の補足のリスク1）。
    slot は本文の下（返信件数）、$actions はマウスを乗せたときの操作、$editForm は編集中に本文の代わりに出す欄。
    本文は書いたままの文字を出す。改行と空白は CSS（pre-wrap）で残すので、msg__body の中に余分な空白を入れない
--}}
@props(['message'])
<article {{ $attributes->class(['msg', 'msg--deleted' => $message->isDeleted()]) }}>
    {{ $actions ?? '' }}
    <x-avatar :user="$message->user" />
    <div>
        <div class="msg__head">
            <span class="msg__name">{{ $message->user->name }}</span>
            <span class="msg__time">{{ $message->created_at->format('Y/m/d H:i') }}</span>
        </div>
        @if ($message->isDeleted())
            <div class="msg__deleted-note">このメッセージは削除されました</div>
        @elseif (isset($editForm))
            {{ $editForm }}
        @else
            <div class="msg__body">{{ $message->body }}@if ($message->is_edited)<span class="msg__edited">編集済み</span>@endif</div>
        @endif
        {{ $slot }}
    </div>
</article>
