{{-- 編集中の行で、本文の代わりに出す欄（docs/design/screens.md 3-3 の No 17〜19）。返信の行でも同じ（3-4 の No 6） --}}
<form class="msg-edit" method="POST" action="{{ route('channels.messages.update', [$channel, $message]) }}" novalidate>
    @csrf
    @method('PUT')
    @include('channels._thread-field')
    <div class="field @error('edit_body') is-error @enderror">
        <textarea class="textarea" name="edit_body" aria-label="メッセージの編集">{{ \App\Support\OldInput::text('edit_body', $message->body) }}</textarea>
        <div class="field-foot">
            {{-- @error と @else の組は使わない。@else の側を通ると、同じ名前の $message（直すメッセージ）が消える --}}
            @if ($errors->has('edit_body'))<span class="err">{{ $errors->first('edit_body') }}</span>@else<span></span>@endif
            <span class="count">1,000文字まで</span>
        </div>
    </div>
    <div class="inline-2">
        <button type="submit" class="btn btn--primary btn--sm">保存</button>
        <a class="btn btn--sm" href="{{ $message->url($thread?->id) }}">やめる</a>
    </div>
</form>
