<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchRequest;
use App\Models\Message;
use App\Support\TextNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\Paginator;
use Illuminate\View\View;

class SearchController extends Controller
{
    private const PER_PAGE = 20;

    public function index(SearchRequest $request): View|RedirectResponse
    {
        if (! $request->has('q')) {
            return view('search.index', ['keyword' => null, 'results' => null]);
        }

        $keyword = $request->validated('q');
        $keywords = TextNormalizer::keywords($keyword);
        // 大きすぎるページ番号は、読み飛ばす件数が整数に収まらず 500 になるので、収まる値で頭打ちにする
        $page = min(Paginator::resolveCurrentPage(), intdiv(PHP_INT_MAX, self::PER_PAGE));

        // 見られる範囲と削除済みの条件は、問い合わせそのものに入れる。取ってきたあとに間引くと、
        // 件数とページ送りから、見えない結果があることが分かる（docs/design/permissions-api.md 1-4）
        $results = Message::query()
            ->whereHas('channel', fn ($query) => $query->visibleTo($request->user()))
            ->notDeleted()
            ->containingAll($keywords)
            ->inSearchOrder()
            ->with(['channel', 'user'])
            ->paginate(self::PER_PAGE, page: $page)
            ->appends(['q' => $keyword]);

        // 最後より先のページは、最後のページへ移す。0件なら、最後のページは1ページ目（D-39）
        if ($results->currentPage() > $results->lastPage()) {
            return to_route('search', ['q' => $keyword, 'page' => $results->lastPage()]);
        }

        return view('search.index', ['keyword' => $keyword, 'keywords' => $keywords, 'results' => $results]);
    }
}
