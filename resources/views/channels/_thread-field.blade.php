{{-- 開いているスレッドを一緒に送り、操作のあとも開いたままにする（docs/design/permissions-api.md 2-2・2-3） --}}
@if ($thread !== null)
    <input type="hidden" name="thread" value="{{ $thread->id }}">
@endif
