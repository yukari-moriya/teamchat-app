@extends('layouts.app')

@section('title', '検索結果')

{{-- docs/design/screens.md 3-8 --}}
@section('content')
    <h1 class="h-title" id="search-heading">メッセージを検索</h1>
    <form method="GET" action="{{ route('search') }}" novalidate>
        <div class="field @error('q') is-error @enderror" style="margin:var(--sp-5) 0 var(--sp-6);max-width:560px">
            <div class="inline-2">
                <input class="input" name="q" type="text" value="{{ \App\Support\OldInput::text('q', $keyword) }}" aria-labelledby="search-heading">
                <button type="submit" class="btn btn--primary">検索</button>
            </div>
            @error('q')<span class="err">{{ $message }}</span>@enderror
        </div>
    </form>
    {{-- キーワードを入れずに開いたときは、結果の欄を出さない --}}
    @if ($results !== null)
        <p class="t-caption result-count" style="margin-bottom:var(--sp-3)">「{{ $keyword }}」の検索結果　{{ $results->total() }}件</p>
        @if ($results->isEmpty())
            <div class="empty result-empty">
                <div class="empty__title">「{{ $keyword }}」に一致するメッセージはありません</div>
                <div class="empty__text">別のキーワードで試すか、キーワードを短くしてください。</div>
            </div>
        @else
            <div class="rows">
                @foreach ($results as $result)
                    {{-- 返信は、元のスレッドを開いて、その返信の位置へ移る（Q-22）。返信でなければ parent_id は NULL --}}
                    <a class="row" href="{{ $result->url($result->parent_id) }}">
                        <div class="row__main">
                            <div class="result__meta"><span class="result__ch"><x-channel-sign :channel="$result->channel" /> {{ $result->channel->name }}</span><span>·</span><span>{{ $result->user->name }}</span><span>·</span><span>{{ $result->created_at->format('Y/m/d H:i') }}</span></div>
                            {{-- 改行と空白は CSS（pre-wrap）で残すので、中に余分な空白を入れない --}}
                            <div class="t-body result__body">{{ \App\Support\KeywordHighlighter::highlight($result->body, $keywords) }}</div>
                        </div>
                    </a>
                @endforeach
            </div>
            @if ($results->hasPages())
                @include('search._pager', ['paginator' => $results])
            @endif
        @endif
    @endif
@endsection
