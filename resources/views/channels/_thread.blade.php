{{--
    スレッドのパネル（SC-05。docs/design/screens.md 3-4、mockup/thread.html）。$thread は元のメッセージ。
    元のメッセージには、返信件数のリンク・操作・編集欄を渡さない。行の位置の名前（id）も付けない（左の流れに同じ行があるため）
--}}
<aside class="thread">
    <div class="thread__head">
        <div>
            <div class="t-label">スレッド</div>
            <div class="t-caption"><x-channel-sign :channel="$channel" />{{ $channel->name }}</div>
        </div>
        <a class="icon-btn" href="{{ $thread->url() }}" title="閉じる" aria-label="閉じる"><svg class="ic" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg></a>
    </div>

    <div class="thread__body">
        <div class="thread__origin">
            <x-message :message="$thread" />
        </div>
        {{-- 0件でも出す。件数は削除された返信も数える（Q-18） --}}
        <div class="thread__count">返信{{ $thread->replies->count() }}件</div>
        @foreach ($thread->replies as $message)
            <x-message :message="$message" :id="$message->anchor()">
                <x-slot:actions>
                    @include('channels._message-actions')
                </x-slot:actions>

                @if ($message->id === $editingMessageId)
                    <x-slot:editForm>
                        @include('channels._message-edit-form')
                    </x-slot:editForm>
                @endif
            </x-message>
        @endforeach
    </div>

    <form @class(['composer', 'is-error' => $errors->has('reply_body')]) method="POST" action="{{ route('channels.messages.replies.store', [$channel, $thread]) }}" novalidate>
        @csrf
        <div class="composer__box">
            {{-- 前回の入力値は、文字列のときだけ戻す。画面を通さずに配列を送られても、この画面が開くように --}}
            <textarea class="composer__ta" name="reply_body" placeholder="返信を送る" aria-label="返信">{{ is_string(old('reply_body')) ? old('reply_body') : '' }}</textarea>
            <div class="composer__foot">
                <span class="count">1,000文字まで</span>
                <button type="submit" class="btn btn--primary btn--sm">送信</button>
            </div>
        </div>
        @error('reply_body')<span class="err">{{ $message }}</span>@enderror
    </form>
</aside>
