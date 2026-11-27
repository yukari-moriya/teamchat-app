---
paths:
  - "resources/views/**"
  - "public/css/**"
  - "app/View/**"
---

# 画面の決まり

文言と色は、自分で決めない。

## 先に読む設計書

- 画面の状態の持ち方と戻り先: `docs/design/screens.md` の0章、`docs/design/permissions-api.md` の2-2・2-3
- 画面の項目と文言: `docs/design/screens.md` の3章・4章
- 見た目: `mockup/` と `mockup/design-guide.md`

## しないこと

- 画面の文言を、自分で考えない。設計書にも見本にも無ければ聞く。
- 新しく足すスタイルの色を、直値で書かない。`:root` の変数を使う（`design-guide.md` の1章）。見本にある直値は、そのまま写してよい。
- 入力欄に戻す値を、`old()` のまま出さない。`\App\Support\OldInput::text()` を通す（値を比べるだけならよい）。
- URL のクエリの値を、そのまま出さない。配列でも届くので、文字列だと確かめてから出す。
- `{!! !!}` を使わない。

## 部品と出し分け

- メッセージと返信の行は `<x-message>` を使う。削除済みの表示を、ほかの場所に書かない（D-05 の補足）。
- `msg__body` の中に、余分な空白を入れない。空白もそのまま表示される。
- 操作の出し分けは、`@can` でポリシーに聞く。`is_private` を直接見るのは、記号・バッジ・文言の出し分けだけ。
- `$message` という変数を使っている範囲では、`@error` と `@else` を組にしない。`@else` の側を通ると、`$message` が消える。

## CSS

- 直すのは `public/css/app.css`。見本に無いスタイルは、末尾の「アプリで足した分」に理由を添えて足す。
- `resources/css`・`resources/js`・Vite は使っていない。
