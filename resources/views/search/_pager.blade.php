{{-- 検索結果のページ送り。両方を出し、行けない側はリンクにせず、押せない表示にする（D-40） --}}
<nav class="pager" aria-label="ページ送り">
    @if ($paginator->onFirstPage())
        <span class="btn btn--sm is-disabled" aria-disabled="true">前のページ</span>
    @else
        <a class="btn btn--sm" href="{{ $paginator->previousPageUrl() }}">前のページ</a>
    @endif
    @if ($paginator->hasMorePages())
        <a class="btn btn--sm" href="{{ $paginator->nextPageUrl() }}">次のページ</a>
    @else
        <span class="btn btn--sm is-disabled" aria-disabled="true">次のページ</span>
    @endif
</nav>
