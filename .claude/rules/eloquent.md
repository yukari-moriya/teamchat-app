---
paths:
  - "app/Models/**"
  - "database/**"
---

# モデルとデータベースの決まり

データの持ち方は、Laravel の標準に任せない。中身と理由は設計書にある。

## 先に読む設計書

- データの持ち方の決定と、テーブルの定義: `docs/design/data.md` の0章・2章
- 削除したメッセージの経路ごとの扱い: `docs/design/questions.md` の「D-05 の補足」
- 初期データ: `docs/design/features.md` の F-26、`docs/design/data.md` の4章

## しないこと

- `SoftDeletes` を使わない（D-05）。
- 返信を別のテーブルにしない（D-06）。
- 公開チャンネルのメンバーの行を作らない（D-07）。
- 「編集済み」の判定に `updated_at` を使わない（D-09）。
- 文字の比較を、DB の照合順序や `LOWER()` に任せない（D-12）。
- migration で `timestamps()` を使わない。日時の列は、`data.md` の2章の定義どおりに書く。

## モデル

- 問い合わせの条件は、スコープや関連にまとめる。コントローラーやビューに書き直さない。
- `$fillable` には、利用者が入力する列だけを入れる。外部キーは、サーバー側で関連を使って設定する。
- 画面や URL から受け取った ID は、数字の文字列かを確かめてから探す。

## migration・factory・seeder

- 実行済みの migration は書き換えず、新しく足す。
- `doctrine/dbal` を入れていないので、既存の列を `->change()` で直さない。
- シーダーは、何度流しても行が増えないようにする。画面に出る日時は固定の値にする。分け方は D-30。
- テストのデータの状態は、factory のメソッド（`deleted()`・`private()`・`replyTo()`）を使う。
