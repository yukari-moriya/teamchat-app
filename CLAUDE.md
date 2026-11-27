# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## このリポジトリは何か

社内向けチームチャットアプリ（Laravel 10 / MySQL 8.4 / Sail）を、仕様書と設計書に従って作り切るための課題リポジトリ（Tutorial 16「総仕上げ」）。
機能は、実装単位（`docs/design/acceptance.md` の1〜7）ごとに作って足していく。どこまで作ったかは、マージ済みのプルリクエストで分かる。
実装の前に、まず下の「決めごとの在りか」で該当する設計書を開くこと。

## 決めごとの在りか

| 何が書いてあるか | どこにあるか |
|:--|:--|
| 仕様書（クライアントの要求） | `docs/spec.md`。**変えない** |
| 設計書一式（このアプリの決めごとの正本） | `docs/design/`（構成は `docs/design/README.md`） |
| 画面の見本 | `mockup/`（`index.html` から全画面） |
| 受け入れ条件とテスト観点 | `docs/design/acceptance.md` |

実装と設計書が食い違ったら、**コードではなく `docs/design/` を先に直す**。設計書は提出物の中心で、最後まで実物と一致させる。

## 設計書の扱い

- 仕様書への疑問は、`docs/design/questions.md` の確認事項に足す
- 画面は `mockup/` に、並び・項目・言葉づかい・状態の見せ方を合わせる（ピクセル一致は不要）
- 図は Mermaid で Markdown の中に書く（画像を置かない）。クラス図は作らない（LaravelのMVCに乗る）
- 設計の選択はユーザーが決める。選択肢と推奨を示して待ち、書き始めの合図があるまで設計書を編集しない

## コマンド

PHP はホストに無い。すべて Sail（Docker）経由で実行する。

```bash
./vendor/bin/sail up -d                 # 起動（mysql が healthy になるまで約30秒）
./vendor/bin/sail artisan migrate       # --seed は付けない運用（README）。初期データは機能と一緒に作る
./vendor/bin/sail artisan test          # 全テスト
./vendor/bin/sail artisan test --filter=ExampleTest   # 1テスト（クラス名・メソッド名で絞る）
./vendor/bin/sail pint                  # コード整形
./vendor/bin/sail npm run dev           # Vite（`build` は本番ビルド）
./vendor/bin/sail stop                  # 停止（データ保持）／ `down -v` はDBごと削除
```

80番・3306番ポートを使う別アプリが動いていると `sail up` が失敗する。テストは DB `testing`（Sail の初期化スクリプトが作成）を使う。

## 実装前に必ず読む決定

Laravel の標準や慣習と違う決め方をしているものがある。実装する前に、該当の決定を読むこと（中身はここに写さない）。

- データの持ち方（メッセージ削除・返信・「編集済み」・メンバーの表・文字の比べ方）: `docs/design/data.md` の0章（D-05〜D-12・D-21・D-24・D-25）
- ログインの作り方: `docs/design/questions.md` の8章（D-01・D-28）
- プライベートチャンネルの隠し方と、権限の無い操作の返し方: `docs/design/permissions-api.md` の1章（R-01・D-15・D-16）

## 規約

- 文言・設計書・コミットメッセージは日本語。画面に出るアプリ名は「チームチャットアプリ」で統一
- 日時の表示は日本時間の `2026/08/17 14:32` 形式。並びは基本「古い順」（チャンネル一覧・メッセージとも）
- `.editorconfig`: UTF-8 / LF / インデント4スペース（YAML は2）
- 変更はすべて、ブランチを切ってコミットし、プルリクエスト（`open-pr` スキル）で `main` に入れる。`main` に直接コミットしない（設計書（`docs/design/`）だけの変更も同じ）。ブランチ名は「プルリクエスト」の節の表のとおり
- push は頼まれたときだけ（PR を出すための push も、頼まれてから）

## コードの書き方

- 一覧で関連するデータを出すときは `with()` を付ける。

## 守ってほしい書き方

### 書き方の決まり

- 名前は Laravel の慣例に合わせる（モデルは単数・テーブルは複数・中間テーブルは単数のアルファベット順・コントローラは `<モデル名>Controller`）。
- 同じ判断を2か所に書かない。権限の判定はポリシーに1か所だけ置く。
- ネストを深くしない。当てはまらない場合を先に返してから、本筋を書く。
- 整形は Laravel Pint に任せる（`./vendor/bin/sail pint`）。見た目を手で揃えない。
- **JavaScript は使わない**（`docs/design/questions.md` の D-03、`docs/design/screens.md` の0章）。文字数の表示・その場編集・削除の確認は、画面を開き直す形で作る。

### 決めごとの在りか

実装を始めると破られやすい決めごとの在りか。書く前に、該当の設計書を読んでから書くこと（上の「実装前に必ず読む決定」に加えて。中身はここに写さない）。同じ決めごとを、`/review-diff`（`.claude/skills/review-diff/SKILL.md` の手順4）が差分で確かめる。

- 見られるチャンネルの絞り込み、削除済みメッセージの除外（画面・検索・公開APIの出口）: `docs/design/permissions-api.md` の1-4・1-6、`docs/design/questions.md` の D-05 の補足
- 公開APIの返す項目・エラー・呼び方: `docs/design/permissions-api.md` の3章（Q-24・Q-27・R-11）
- 画面の作り（JavaScript・画面の状態の持ち方・操作のあとの戻り先・フォームの項目名）: `docs/design/screens.md` の0章、`docs/design/permissions-api.md` の2-2・2-3（D-03・D-26）
- 画面の項目と文言の出し方: `docs/design/screens.md` の3章
- 入力チェックの上限と文言: `docs/design/screens.md` の4章
- エラーの扱い（エラー画面・複数の表を書く処理・本番の設定）: `docs/design/behavior.md` の3章（D-14）
- Laravel が最初から用意している物のうち、使わない物: `docs/design/questions.md` の D-10、`docs/design/permissions-api.md` の2-1 の末尾の注
- 作らないもの: `docs/design/features.md` の2章
- 初期データ: `docs/design/features.md` の F-26、`docs/design/data.md` の4章（D-27）
- 漏れの出口ごとの受け入れ条件とテスト観点: `docs/design/acceptance.md` の1-8・2章

## プルリクエスト

`main` に直接コミットしない。イシュー1件に1本のブランチを切り、プルリクエストで受け取る。

| 何の作業か | ブランチ名 | 例 |
|:--|:--|:--|
| イシュー1件 | `feature/issue-<番号>-<英字の短い名前>` | `feature/issue-1-auth` |
| イシューに紐づかない直し（`.claude/` の物差し・設計書だけの修正） | `chore/<英字の短い名前>` | `chore/harness-gaps` |

イシューは実装単位（`docs/design/acceptance.md` の 1 認証／2 チャンネル／3 メンバー管理／4 メッセージ／5 スレッド／6 検索／7 公開API）に当たる。

PR は `open-pr` スキル（`.claude/skills/open-pr/SKILL.md`）で出す。本文の型（テストの実行結果・受け入れ条件それぞれの判定・設計書の変更・確認してほしいこと）と、材料の集め方は、そのスキルにある。
イシューに紐づく PR は、最後に `Closes #<イシュー番号>` を入れる。

## 触らないでほしいもの

- `.env`: 読む必要も書く必要もありません。値が要るときは私に聞いてください。
- `vendor/`・`node_modules/`・`storage/`: 自動で作られるフォルダです。
