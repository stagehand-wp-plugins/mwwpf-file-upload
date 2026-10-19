# File Upload for MW WP Form プラグイン実装指示書

対象: Claude Code
作成: 2026-10-19 / 調査対象: MW WP Form 5.1.8（GitHub `web-soudan/mw-wp-form` master, 2026-09-27）
改訂: 2026-10-20 — §10 の回答を反映。DB保存（メディア登録）をやめ、送信済みファイルを非公開の保管場所に一定期間保管し、管理画面「添付ファイル」で一覧・削除する方式に変更（F6・F7・F10・F11、§6.4・§6.9〜§6.14 ほか）

**前提:** 環境準備指示書 `docs/mwwpf-setup.md` が完了していること（`CLAUDE.md` の「環境準備」が「完了」）。開発フォルダ・wp-env・テストの土台・GitHub・CI・配布 zip の作り方は、全て環境準備指示書で用意済みとする。本書で「環境準備 §n」「環境準備 Sn」と書いた箇所は環境準備指示書を指す。

---

## 0. 作業ルール

- Phase 単位で実装する（§8）。各 Phase の終わりにテストを通してからコミットする。
- **MW WP Form 本体のファイルは一切編集しない。** 本体の挙動は、公開されているフックだけで変える。
- 着手前に MW WP Form 5.1.8 のソースを取得し、§3 の根拠箇所を自分で読んで確認する。本書と本体ソースが食い違う場合はソースを優先し、差異を報告して作業を止める。
- §10 の「要確認事項」は、該当箇所の実装前に質問する。既定値がある項目は、回答がなければ既定値で進めてよい。
- 本体の内部クラス（`MW_WP_Form_File`、`MW_WP_Form_Directory`、`MWF_Functions::save_attachments_in_media` など）は呼ばない。5.2 で削除される可能性がある。例外は §6.3 と §6.7 に明記した基底クラスのみ。
- 環境準備が未完了なら作業を始めず、環境準備指示書に従うようユーザーに伝える。
- 公開リポジトリのため、パスワード・API キー・トークン・実在する顧客サイトの情報をコミットしない。
- 作業は作業ブランチで行い、Phase ごとに PR を作る。main への直接 push はしない。
- 環境・CI・配布 zip の設定を変える必要が出た場合は、変更内容を提示して承認を得る。

---

## 1. 目的

MW WP Form 5.2（2026年中にリリース予定）で `[mwform_file]` と `[mwform_image]` が削除される。

本プラグインを有効化するだけで、**既存フォームの本文を一切変更せずに**、同名ショートコードを自前実装へ差し替える。5.1.8 と 5.2 で挙動を同じにする。

---

## 2. 要件

### 2.1 機能要件

| # | 要件 |
|---|---|
| F1 | 既存の `[mwform_file name="x" id="" class="" show_error="false"]` と `[mwform_image ...]` が、記述を変えずに動く |
| F2 | 入力 → 確認 → 完了、確認画面なし、確認画面からの「戻る」、バリデーションエラー時の再表示に対応する |
| F3 | 管理画面のバリデーション設定（filetype / filesize / maxfilesize / minfilesize / noempty / required）がそのまま効く |
| F4 | 管理者宛メールに添付する。添付ファイル名は元のファイル名。自動返信メールには添付しない（本体と同じ） |
| F5 | メール本文の `{x}` は元のファイル名に置換される |
| F6 | 送信済みのファイルは、全フォームで（本体の「問い合わせデータをデータベースに保存」の設定に関係なく）非公開の保管場所に保管する。メディアライブラリには登録しない（§6.9） |
| F7 | 保管ファイルは保存期間（既定 30日、管理画面で変更可）を過ぎたら自動で削除する（§6.10） |
| F8 | 管理画面のフォーム編集画面にあるタグ生成ダイアログから、従来どおり挿入できる |
| F9 | `[mwform_image]` は入力画面と確認画面でサムネイルを表示する |
| F10 | MW WP Form のメニューに「添付ファイル」画面を追加し、保管ファイルを一覧・ダウンロード・プレビュー・削除（個別・一括）できる。閲覧・操作は管理者のみ（§6.14） |
| F11 | アップロードされたファイルは、一時ファイル・保管ファイルとも外部に公開しない。Nginx などで保管場所が公開ディレクトリ内にある場合は、管理画面で警告する（§6.4） |

### 2.2 非機能要件

- 対応する PHP・WordPress の下限は環境準備 S3 で確定した値（既定: PHP 8.0 / WordPress 6.0）
- MW WP Form 5.1.8 で動作確認する。5.2 はリリース後に再検証する（§9.3）
- Apache（Xserver）と Nginx（KUSANAGI 9）の両方で動く
- フロントの JavaScript は jQuery に依存しない素の JS で書く
- JavaScript が必須。無効時は `<noscript>` で案内する
- WordPress Coding Standards（PHPCS `WordPress` ルールセット）に準拠する
- 更新は GitHub Releases から配信し、各サイトの管理画面に通常のプラグインと同じ更新通知を出す（§6.13）

### 2.3 スコープ外

- 1項目で複数ファイルを受け付けること（本体と同じく1項目1ファイル）
- 本体で過去に保存された問い合わせデータ・添付の移行（本体の形式のまま残るので不要）
- 本体の削除予告（admin notice）を消すこと
- 添付ファイルのメディアライブラリへの登録と、本体の問い合わせデータ画面（一覧・詳細・CSV）での添付表示（2026-10-20 の決定でスコープ外。問い合わせデータにはファイル名だけが残る）
- 本体フック `mwform_upload_dir_{form_key}` / `mwform_upload_filename_{form_key}` の適用（本体では DB保存時のメディア登録にだけ使われるフックのため）

---

## 3. 調査済みの事実（5.1.8 の根拠箇所）

| # | 箇所 | 内容 | 設計への影響 |
|---|---|---|---|
| 1 | `classes/models/class.form-fields.php:36` | 項目一覧を `apply_filters( 'mwform_form_fields', ... )` で生成。キーはショートコード名 | 同じキー `mwform_file` に自作オブジェクトを入れれば置き換わる |
| 2 | `classes/abstract/class.form-field.php:79` | 基底クラスのコンストラクタが優先度 10 で自分自身を登録する | 本体の項目と同じ優先度のため後勝ちが不定。**上書きは優先度 100 の別フィルターで行う** |
| 3 | `classes/abstract/class.form-field.php:102,105` | `initialize()` 内で入力画面・確認画面用に `add_shortcode` する | 自作クラスも基底クラスを継承すれば同じ流れで描画される |
| 4 | `classes/services/class.exec-shortcode.php:118` | 描画時に全項目の `initialize()` を呼ぶ | — |
| 5 | `classes/services/class.exec-shortcode.php:122` | フォーム本文に `wp_kses_post` をかけてから `do_shortcode` する | ショートコードの出力（input タグ）は kses の対象外 |
| 6 | `classes/controllers/class.admin.php:38-40` | 管理画面でも同じ一覧から `add_tag_generator()` する | 上書きすればタグ生成ダイアログも自作クラスのものになる |
| 7 | `mw-wp-form.php:35` | 本体クラスの読み込みは `plugins_loaded` 優先度 9 | 基底クラスを継承するファイルは `plugins_loaded` 優先度 20 以降で require する |
| 8 | `classes/controllers/class.main.php:141,153` | POST 時に `$_FILES` を取り込み、確認・完了時に `_file_upload()` を実行。本文中の `[mwform_file name=""]` を正規表現で拾い、アップロード可能なキーを決める | **自作の file input には name 属性を付けない。** POST に `$_FILES` が載らないので、本体のアップロード処理は空振りする |
| 9 | `classes/controllers/class.main.php:244` | 添付は `_get_attachments()` が `mwf_upload_files`（UPLOAD_FILE_KEYS）から作る | 自作分は含まれない。自前で添付する |
| 10 | `classes/services/class.mail.php:83-84,87` | 管理者メールは `mwform_mail_{key}` → `mwform_admin_mail_{key}` の順にフィルター後に送信。DB保存用は raw のクローン | 添付は `mwform_admin_mail_{key}` で足す。DB保存は別途行う |
| 11 | `classes/models/class.mail.php:109` | `wp_mail()` に `array_values( $attachments )` を渡す | 配列キーで添付名を指定できない。**元ファイル名でコピーした実ファイルを添付する** |
| 12 | `classes/models/class.parser.php:311-325` | メールタグの値は `mwform_custom_mail_tag_{key}`（`$value, $name, $saved_mail_id`）を通る。DB保存時の値も同じ経路 | `{x}` を元ファイル名に置換できる |
| 13 | `classes/models/class.validation-rules.php:91-96` | ルール一覧は `apply_filters( 'mwform_validation_rules', ... )`。キーはルール名（filetype / filesize / maxfilesize / minfilesize / noempty / required） | 同名キーで代理ルールに置き換えられる |
| 14 | `classes/validation-rules/class.filesize.php` | `mwf_files` と `mwf_upload_files` に依存して検証する | 自作分には効かない。代理ルールが必要 |
| 15 | `classes/functions.php:179-208` | DB保存時、添付をメディア登録し、問い合わせデータのメタ `{key}` に添付ID、`_mwf_upload_files` にキー配列を保存 | 本プラグインはメディア登録をしない（2026-10-20 の決定）。問い合わせデータのその項目にはメールタグと同じ元ファイル名が保存される（#12） |
| 16 | `classes/controllers/class.main.php:138`、`classes/services/class.exec-shortcode.php:72` | `mwform_start_main_process( $form_key )` は POST 処理時と描画時の両方で発火 | フォーム別フック（`*_{form_key}`）の登録点にする |
| 17 | `classes/controllers/class.main.php:167` | 送信成功後に `mwform_after_send_{key}`（`clone $Data`）。保存IDは `MW_WP_Form_Data::get_saved_mail_id()`（`class.data.php:703`） | 保管場所への移動はここで行う。DB保存オンなら保存IDを保管ファイルのメタデータに記録する |
| 18 | `classes/models/class.form.php:235` | form タグは `enctype="multipart/form-data"` を固定出力 | 本方式は非同期アップロードなので不要。5.2 で消えても影響なし |
| 19 | `js/form.js:29` | 本体 JS が `.mw_wp_form input[type="file"]` の change を監視（name 属性前提） | name なしでも実害はない見込み。Phase 3 で動作確認する |
| 20 | `classes/abstract/class.validation-rule.php` | 基底のコンストラクタも優先度 10 で自己登録。`rule( $name, $options )` と `admin( $key, $value )` が抽象メソッド | 代理ルールは基底を継承し、元のルールに処理を委譲する |

---

## 4. 全体設計

ファイルは選択した時点で非同期にサーバへ送り、フォームの POST にはファイル本体を載せない。POST に載るのは「元ファイル名」と「トークン」の hidden 項目だけ。

```
[入力画面]
  <input type="file">（name 属性なし）
     │ change
     ▼
  JS → POST admin-ajax.php?action=mwwpf_upload（FormData）
     サーバ: 検証 → 一時保存 {token}.bin + {token}.json
     ← { token, name, size, mime, preview_url }
  JS: hidden name="{field}"               = 元ファイル名
      hidden name="mwwpf_token[{field}]"   = トークン

[フォーム送信 POST]（ファイル本体は含まない）
  MW WP Form の通常処理
  └ バリデーション: 代理ルールがトークンを解決して検証

[確認画面]
  ファイル名（画像はプレビュー）＋ hidden 2種を再出力

[完了処理]
  mwform_custom_mail_tag_{key}  → {field} を元ファイル名に置換
  mwform_admin_mail_{key}       → 元ファイル名でコピーして $Mail->attachments に追加
  mwform_after_send_{key}       → 一時ファイルを保管場所（files/）へ移動。トークンを消費済みにする

[WP-Cron（毎時）]
  期限切れの一時ファイルと、保存期間を過ぎた保管ファイルを削除

[管理画面 MW WP Form → 添付ファイル]（管理者のみ）
  保管ファイルの一覧・ダウンロード・画像プレビュー・個別削除・一括削除・保存期間の設定
```

**トークンを正とする。** hidden の「元ファイル名」は表示用にすぎず、改ざんされうる。メール本文・添付名・保存名は必ずトークンのメタデータから取る。

---

## 5. ディレクトリ構成

スラッグ `mwwpf-file-upload`、関数・オプション接頭辞 `mwwpf_`、クラス接頭辞 `MWWPF_` は確定済み。プラグインの表示名は環境準備 S2 の値を使う。

本書で作るのはプラグイン本体のファイルのみ。`.wp-env.json`、`composer.json`、`package.json`、`bin/`、`.github/`、`tests/mu-plugins/`、`.distignore` などは環境準備で作成済み。

```
mwwpf-file-upload/
├── mwwpf-file-upload.php          # プラグインヘッダ、定数、ブートストラップ、更新チェック
├── uninstall.php
├── includes/
│   ├── class-plugin.php          # 依存チェック、フック登録
│   ├── class-form-scanner.php    # フォームID → 対象項目の一覧
│   ├── class-storage.php         # 一時保存、トークン、メタデータ
│   ├── class-ajax.php            # upload / delete / preview
│   ├── class-mail.php            # メールタグ、添付
│   ├── class-archive.php         # 送信済みファイルの保管（移動・一覧・削除）
│   ├── class-admin-page.php      # 管理画面「添付ファイル」（一覧・ダウンロード・削除・設定）
│   ├── class-admin-notice.php    # 保管場所が公開されている恐れがある場合の警告
│   ├── class-cleanup.php         # WP-Cron
│   ├── fields/
│   │   ├── class-field-file.php  # [mwform_file] 置き換え
│   │   └── class-field-image.php # [mwform_image] 置き換え（file を継承）
│   └── validation/
│       ├── class-rule-proxy.php  # 代理ルールの基底
│       ├── class-rule-filetype.php
│       ├── class-rule-filesize.php
│       ├── class-rule-imagesize.php   # maxfilesize / minfilesize
│       └── class-rule-noempty.php     # noempty / required
├── assets/
│   ├── js/mwwpf.js
│   └── css/mwwpf.css
├── languages/
└── tests/
    ├── phpunit/                  # 本書のテストを追加する
    └── e2e/                      # 本書のテストを追加する（Playwright）
```

---

## 6. コンポーネント仕様

### 6.1 ブートストラップ（class-plugin.php）

- `plugins_loaded` 優先度 20 で起動する。
- `class_exists( 'MW_WP_Form_Abstract_Form_Field' )` と `class_exists( 'MW_WP_Form_Abstract_Validation_Rule' )` が false なら、管理画面に「MW WP Form が必要です」と通知して何もしない。
- 基底クラスを継承するファイル（fields/、validation/）は、この時点で初めて require する。
- 登録するフック:
  - `mwform_form_fields`（優先度 100）: `mwform_file` と `mwform_image` のキーを自作オブジェクトで上書きする。本体の項目がない場合（5.2）も追加する。
  - `mwform_validation_rules`（優先度 100）: §6.7 の代理ルールで上書きする。上書き前の元オブジェクトを代理ルールに渡す。
  - `mwform_start_main_process`: 受け取った `$form_key` で §6.8・§6.9 のフォーム別フックを登録する。同じキーで二重登録しない。
  - `wp_ajax_*` / `wp_ajax_nopriv_*`: §6.5
  - `wp_enqueue_scripts`: 対象項目を描画したときだけ JS と CSS を読み込む（項目の描画時にフラグを立て、`wp_footer` で enqueue）。
- 項目オブジェクトとルールオブジェクトは1リクエストに1つずつ（使い回し）。

### 6.2 フォームスキャナ（class-form-scanner.php）

- `get_fields( int $form_id ): array` → `[ 'attach' => [ 'type' => 'file', 'atts' => [...] ], 'photo' => [ 'type' => 'image', ... ] ]`
- フォーム（投稿タイプ `mw-wp-form`）の `post_content` を `get_shortcode_regex( [ 'mwform_file', 'mwform_image' ] )` と `shortcode_parse_atts()` で解析する。
- name 属性が `^[A-Za-z0-9_\-\[\]]+$` に一致しないものは無視する。
- 結果はリクエスト内でキャッシュする。
- `apply_filters( 'mwwpf_form_fields', $fields, $form_id )` で追加・除外できるようにする（テーマ側で本文をフィルター生成しているフォーム向け）。
- `form_key`（`mw-wp-form-{ID}`）からフォームIDへの変換は自前で行う（`MWF_Functions` に依存しない）。
- **アップロード・検証・添付のすべてで、この一覧にない項目名は拒否する。**

### 6.3 項目クラス（fields/）

- `MWWPF_Field_File extends MW_WP_Form_Abstract_Form_Field`
  - `set_names()`: `shortcode_name` = `mwform_file`、`display_name` = 本体と同じ「ファイル」（翻訳は本体のテキストドメイン `mw-wp-form` を使う）
  - `set_defaults()`: 本体と同じ `name / id / class / show_error`。追加で `accept`（既定 null）
  - `$type = 'input'`
- `MWWPF_Field_Image extends MWWPF_Field_File`
  - `shortcode_name` = `mwform_image`、`accept` の既定は `image/png,image/jpeg,image/gif,image/webp`
- `input_page()` の出力:
  - ラッパー `<div class="mwwpf" data-mwwpf-form="{form_id}" data-mwwpf-field="{name}" data-mwwpf-type="file|image" data-mwwpf-max="{bytes}" data-mwwpf-ext="pdf,jpg">`
  - `<input type="file">`（**name 属性なし**。id・class・accept は属性どおり）
  - 状態表示領域（ファイル名、サイズ、削除ボタン、進捗、エラー）。`aria-live="polite"`
  - hidden `name="{name}"` と hidden `name="mwwpf_token[{name}]"`
  - `<noscript>` の案内文
  - `show_error` が `false` 以外なら `$this->get_error( $name )`
- 戻る・エラー再表示時: Data に有効なトークンがあれば、アップロード済みの状態（ファイル名・削除ボタン・画像はサムネイル）で描画する。トークンが無効なら空の状態で描画する。
- `confirm_page()`: 有効なトークンがあれば、ファイル名（画像はサムネイル）と hidden 2種を出力する。なければ何も出さない。
- `mwform_tag_generator_dialog()`: 本体と同じ項目（name / id / class / show_error）に accept を追加する。
- data 属性の上限サイズ・拡張子は §6.4 の「項目ごとの制限」を使う（クライアント側の事前チェック用。サーバ側でも必ず再検証する）。
- 出力は全て `esc_attr` / `esc_html` でエスケープする。

### 6.4 ストレージ（class-storage.php）

- 保管場所のルート: 定数 `MWWPF_STORAGE_DIR` があればそれを使い、なければ `wp_upload_dir()['basedir'] . '/mwwpf'`。配下に次の2つを作る。
  - `tmp/`: アップロード直後の一時ファイル（未送信）と、メール添付用のコピー（`send-*`）
  - `files/{form_id}/`: 送信済みの保管ファイル（§6.9）
  - ルートと各ディレクトリの作成時に `.htaccess`（`Require all denied` と旧書式 `Deny from all` を併記）と空の `index.php` を置く。
  - Nginx では `.htaccess` が効かない。README に KUSANAGI 用の設定例を載せ、**公開ディレクトリ外の `MWWPF_STORAGE_DIR` 指定を推奨**と明記する:
    ```nginx
    location ^~ /wp-content/uploads/mwwpf/ { deny all; return 404; }
    ```
- 公開の警告（class-admin-notice.php）: 次の両方を満たすとき、管理者に管理画面の通知（全画面）と「添付ファイル」画面上部の警告を出す。
  - サーバが Nginx（`$_SERVER['SERVER_SOFTWARE']` に `nginx` を含む）、または `.htaccess` を解釈しないサーバ（`apply_filters( 'mwwpf_server_ignores_htaccess', ... )` で判定を上書きできる）
  - 保管場所のルートの `realpath` が `ABSPATH` の `realpath` の配下にある
  - 文言: 「添付ファイルの保管場所が公開ディレクトリ内にあり、このサーバでは .htaccess による保護が効きません。wp-config.php で MWWPF_STORAGE_DIR にドキュメントルート外のパスを指定するか、Nginx の設定で保管場所へのアクセスを拒否してください。」README の該当節へのリンクを付ける。
  - Nginx 側で拒否設定済みのサイト向けに、定数 `MWWPF_STORAGE_PROTECTED`（true）で警告を消せる。
- ファイル名は全てトークン由来（`{token}.bin`、拡張子なし）で、元のファイル名・拡張子をパスに使わない。保管場所が万一公開されても、名前の推測と実行はできない。
- トークン: `bin2hex( random_bytes( 16 ) )`（32桁の16進）。検証は `^[a-f0-9]{32}$`。
- ファイル: `tmp/{token}.bin`（拡張子なし）。メタデータ: `tmp/{token}.json`
  ```json
  {
    "form_id": 123, "field": "attach", "type": "file",
    "name": "見積依頼書.pdf", "ext": "pdf", "mime": "application/pdf",
    "size": 123456, "width": null, "height": null,
    "sid_hash": "sha256(...)", "created": 1760000000, "consumed": false
  }
  ```
- 訪問者の識別: アップロード時に Cookie `mwwpf_sid`（ランダム32桁、HttpOnly、SameSite=Lax、HTTPS 時 Secure、パス `COOKIEPATH`、有効期限はブラウザ終了まで）を発行する。既にあれば再利用する。メタデータには `hash_hmac( 'sha256', $sid, wp_salt( 'auth' ) )` を保存する。
- `resolve( $token, $form_id, $field ): ?array` — 次の全てを満たすときだけメタデータを返す:
  トークンの形式が正しい / `.bin` と `.json` が存在する / form_id と field が一致する / sid_hash が現在の Cookie と一致する（`hash_equals`）/ 期限内 / 未消費
- `delete( $token )`、`mark_consumed( $token )`、`copy_for_mail( $token ): string`（§6.8）を持つ。
- 項目ごとの制限 `get_limits( $form_id, $field ): array{ max_bytes, exts, min_w, min_h, max_w, max_h }`:
  - フォーム設定（投稿メタ `mw-wp-form` の `validation`）から、その項目の filetype / filesize / maxfilesize / minfilesize の値を読む。設定の読み方は `MW_WP_Form_Setting` を参考に自前で実装する。
  - 設定がなければ既定値（§6.11）。
  - **filetype の指定があっても、§7 の拒否リストの拡張子は常に拒否する。**

### 6.5 Ajax エンドポイント（class-ajax.php）

REST API ではなく `admin-ajax.php` を使う。未ログインの REST を遮断するセキュリティプラグインやサーバ設定の影響を避けるため。

| action | メソッド | 入力 | 処理 |
|---|---|---|---|
| `mwwpf_upload` | POST（multipart） | `nonce`, `form_id`, `field`, `file` | 検証 → 保存 → `{ token, name, size, mime, preview_url }` |
| `mwwpf_delete` | POST | `nonce`, `form_id`, `field`, `token` | 本人のトークンなら削除 |
| `mwwpf_preview` | GET | `token`, `form_id`, `field` | 本人のトークンで画像ならストリーム出力 |

`mwwpf_upload` の検証順:
1. nonce（`wp_create_nonce( 'mwwpf_upload' )` を `wp_localize_script` で渡す）
2. `form_id` の投稿が投稿タイプ `mw-wp-form` で公開状態
3. `field` が §6.2 の一覧にある
4. レート制限（IP ごと、既定 10分で30回。transient）
5. 一時保存先（`tmp/`）の合計サイズ上限（既定 500MB）
6. `$_FILES['file']['error'] === UPLOAD_ERR_OK` かつ `is_uploaded_file()`
7. サイズ ≤ `max_bytes`
8. 拡張子が許可リストにあり、拒否リストにない
9. `wp_check_filetype_and_ext()` と `finfo` で実際の MIME を確認し、拡張子と整合する
10. image 項目は `getimagesize()` が成功し、幅・高さが min/max の範囲内
11. `move_uploaded_file()` で `{token}.bin` に保存し、`.json` を書く

- 元ファイル名は `sanitize_file_name()` 相当の処理をするが、日本語は残す。パス区切り・制御文字・先頭のドットは除去する。長さは 200 バイトまで。
- エラー時は `wp_send_json_error( [ 'code' => ..., 'message' => ... ], 400 )`。メッセージは日本語で、本体のバリデーションメッセージに文言を合わせる。
- 一定確率（既定 2%）で §6.10 の掃除を実行する（WP-Cron が動かない環境向け）。
- `mwwpf_preview` のレスポンスヘッダ: 正しい `Content-Type`、`X-Content-Type-Options: nosniff`、`Content-Disposition: inline`、`Cache-Control: no-store`。画像（png / jpeg / gif / webp）以外は 404 を返す。

### 6.6 フロント JS（assets/js/mwwpf.js）

- `.mwwpf` ごとに初期化する。1ページに複数フォーム・複数項目があってもよい。
- 選択時: data 属性で拡張子・サイズを事前チェック → `XMLHttpRequest` で送信して進捗を表示 → 成功したら hidden 2種を設定し、ファイル名・サイズ・削除ボタンを表示（画像はサムネイル）→ file input の値をクリアする。
- アップロード中は、同じフォームの送信系ボタン（`input[type=submit]`、`button[type=submit]`、`button:not([type])`）を無効化する。
- 削除: `mwwpf_delete` を呼び、hidden 2種を空にして初期状態に戻す。
- 失敗時: エラーを表示し、hidden は変更しない。
- 本体 `form.js` と干渉しないこと（§3 #19）。

### 6.7 代理バリデーションルール（validation/）

- `MWWPF_Rule_Proxy extends MW_WP_Form_Abstract_Validation_Rule`
  - コンストラクタで元のルールオブジェクトを受け取る。
  - `rule( $name, $options )`: `$this->Data` の form_key から form_id を求め、`$name` が §6.2 の一覧にあれば自前で検証する。なければ元のルールに委譲する。
  - `admin( $key, $value )`: 常に元のルールに委譲する（管理画面の設定 UI を本体と同一に保つ）。
  - `get_name()` は元のルールと同じ名前を返す。
  - 元のルールがない場合（5.2 で削除された場合）も動くようにする。そのときは `admin()` で本体互換の設定欄を自前で出す（本体 5.1.8 の各ルールの `admin()` と同じ name 属性）。
- 自前検証の内容（トークンは `$this->Data` の `mwwpf_token[{name}]` から取る）:
  - `noempty` / `required`: トークンが `resolve()` できなければエラー
  - `filetype`: メタデータの `ext` が `types` に含まれなければエラー
  - `filesize`: メタデータの `size` が `bytes` を超えたらエラー
  - `maxfilesize` / `minfilesize`: メタデータの `width` / `height` で判定
  - トークンが空の場合、`noempty` / `required` 以外は通す（本体と同じ挙動）
- エラーメッセージは本体のルールと同じ文言・同じ `message` オプションに従う。

### 6.8 メール（class-mail.php）

`mwform_start_main_process` で受け取った `$form_key` ごとに登録する。

- `mwform_custom_mail_tag_{form_key}`（`$value, $name, $saved_mail_id`）
  - `$name` が対象項目なら、`resolve()` できたときは元ファイル名、できなければ空文字を返す。
  - 対象外なら `$value` をそのまま返す。
  - DB保存の値もこの経路を通る（§3 #12）。
- `mwform_admin_mail_{form_key}`（`$Mail, $values, $Data`）
  - 対象項目ごとに `copy_for_mail( $token )` を呼ぶ。`{tmp}/send-{random}/` に元ファイル名でコピーし、そのパスを `$Mail->attachments[ $name ]` に追加する。
  - 複数項目で同名ファイルがあれば ` (2)` などを付けて衝突を避ける。
  - コピー用ディレクトリは `register_shutdown_function` で必ず削除する。
- 自動返信メールには添付しない。
- PHPMailer で日本語ファイル名の添付が文字化けしないことを確認する（§9）。

### 6.9 送信済みファイルの保管（class-archive.php）

`mwform_after_send_{form_key}`（`$Data` のクローン）で実行する。全フォームが対象（本体の DB保存の設定に関係しない）。メディアライブラリには登録しない。

- 対象項目ごとに、`resolve()` できたトークンについて次を行う。
  1. 一時ファイル `tmp/{token}.bin` を `files/{form_id}/{token}.bin` へ `rename` する（同じファイルシステム内の移動。失敗したら `copy` → 元を削除）。
  2. メタデータ `files/{form_id}/{token}.json` を書く。一時ファイルのメタデータに次を加える（`sid_hash` は保管しない）:
     `submission`（同じ送信の項目で共通のランダム16桁）、`archived`（保管した時刻）、`saved_mail_id`（`$Data->get_saved_mail_id()`。DB保存オフなら null）
  3. 一時ファイルの `.bin` / `.json` を削除し、トークンを消費済みにする。
- 保管ファイルの読み込み・削除はトークンからだけパスを組み立てる（`files/{form_id}/{token}.bin`。form_id は整数、トークンは `^[a-f0-9]{32}$` で検証）。
- 一覧用の API: `list( array $args ): array{ items, total }`（引数: form_id、ページ、件数、並び順）。`files/*/` の `.json` を読み、`archived` の降順で返す。
- `delete( $form_id, $token )`、`get_path( $form_id, $token )`（存在確認つき）を持つ。
- メール送信が失敗した場合はこのフックが呼ばれない。一時ファイルは §6.10 の掃除で消える。

### 6.10 掃除（class-cleanup.php）

- 有効化時に WP-Cron（毎時）を登録し、無効化時に解除する。
- `tmp/`: 作成から `mwwpf_tmp_ttl`（既定 24時間）を過ぎた `.bin` / `.json`、消費済みのもの、ペアが欠けたもの、1時間以上前の `send-*` ディレクトリを削除する。
- `files/`: 保管から保存期間（オプション `mwwpf_retention_days`、既定 30日）を過ぎた `.bin` / `.json` と、ペアが欠けたもの（1時間以上経過）を削除する。保存期間が 0 のときは自動削除しない。
- 削除は保管場所のルート配下に限定する（`realpath` で確認してから `unlink`）。

### 6.11 設定（管理画面で変えられるのは保存期間のみ。他は定数とフィルターで変更する）

| 名前 | 種類 | 既定 | 内容 |
|---|---|---|---|
| `MWWPF_STORAGE_DIR` | 定数 | `uploads/mwwpf` | 保管場所のルート（`tmp/` と `files/`）。公開ディレクトリ外を推奨 |
| `MWWPF_STORAGE_PROTECTED` | 定数 | なし | true でサーバ側の保護設定済みとみなし、§6.4 の警告を出さない |
| `mwwpf_retention_days` | オプション | 30 | 保管ファイルの保存期間（日）。0 で自動削除しない。「添付ファイル」画面で変更する |
| `mwwpf_server_ignores_htaccess` | フィルター | Nginx なら true | §6.4 の警告の判定 |
| `mwwpf_default_max_bytes` | フィルター | 10MB | filesize 未設定時の上限（§10 Q1 の回答） |
| `mwwpf_default_exts` | フィルター | file: `pdf,jpg,jpeg,png,gif,webp,heic,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip` / image: `jpg,jpeg,png,gif,webp` | filetype 未設定時の許可拡張子 |
| `mwwpf_tmp_ttl` | フィルター | 86400 秒 | 一時ファイルの保持時間 |
| `mwwpf_rate_limit` | フィルター | `[ 30, 600 ]` | 回数と秒数 |
| `mwwpf_tmp_quota_bytes` | フィルター | 500MB | 一時保存先の合計上限 |
| `mwwpf_form_fields` | フィルター | — | §6.2 |

### 6.12 アンインストール（uninstall.php）

- 保管場所のルート（`tmp/` と `files/` を含む）を削除し、Cron とオプション・transient を消す。保管ファイルは本プラグイン専用のデータのため一緒に消える。README に「アンインストール前に必要なファイルはダウンロードしておく」と明記する。
- 本体の問い合わせデータは**消さない**。
- 無効化だけでは保管ファイルを消さない（Cron の解除のみ）。

### 6.13 更新配信（Plugin Update Checker）

- `composer require yahnis-elsts/plugin-update-checker`（v5 系）で導入する。
- メインファイルで次のように初期化する。`{owner}` は環境準備 S1 で確定したアカウント。
  ```php
  require __DIR__ . '/vendor/autoload.php';
  use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

  $mwwpf_update_checker = PucFactory::buildUpdateChecker(
  	'https://github.com/{owner}/mwwpf-file-upload/',
  	__FILE__,
  	'mwwpf-file-upload'
  );
  $mwwpf_update_checker->getVcsApi()->enableReleaseAssets( '/mwwpf-file-upload\.zip/' );
  ```
- 更新の判定は最新の GitHub Release を使う。GitHub が自動生成するソース zip ではなく、`release.yml`（環境準備 §12）が添付する `mwwpf-file-upload.zip` を配布物にする。
- 自動更新はプラグイン側で強制しない。各サイトのプラグイン一覧の「自動更新を有効化」で切り替える（WordPress 標準の機能）。
- 非公開リポジトリ用のトークンは設定しない（公開リポジトリが前提）。
- `vendor/autoload.php` がない場合（開発中に `composer install` 前など）は、更新チェックだけを無効にしてプラグイン本体は動かす。

### 6.14 管理画面「添付ファイル」（class-admin-page.php）

- MW WP Form のメニュー（`edit.php?post_type=mw-wp-form`）の下に、サブメニュー「添付ファイル」を追加する。権限は `manage_options`（`apply_filters( 'mwwpf_admin_capability', 'manage_options' )`）。
- 一覧は `WP_List_Table` を継承して作る。
  - 列: チェックボックス、プレビュー（画像のみ。サムネイル）、元のファイル名、フォーム名（フォーム編集画面へのリンク）、項目名、サイズ、種類（MIME）、受信日時（サイトのタイムゾーン）、削除予定日（保存期間 0 なら「自動削除なし」）、問い合わせデータ（`saved_mail_id` があれば詳細画面へのリンク）
  - 同じ送信（`submission`）のファイルが分かるよう、受信日時で並べる（既定は新しい順）。
  - フォームでの絞り込み（セレクトボックス）、ページ送り（1ページ 20件、画面オプションで変更可）。
  - 行の操作: 「ダウンロード」「削除」。一括操作: 「削除」。
- ダウンロード: `admin-post.php?action=mwwpf_download&form_id=&token=&_wpnonce=`。権限・nonce・トークン形式を確認し、元のファイル名で `Content-Disposition: attachment` を返す（ファイル名は RFC 5987 の `filename*=UTF-8''...` で日本語に対応）。`X-Content-Type-Options: nosniff`、`Cache-Control: no-store` を付ける。
- 画像プレビュー: `admin-post.php?action=mwwpf_thumb&...`。画像（png / jpeg / gif / webp）だけを `Content-Disposition: inline` で返す。それ以外は 404。サムネイル画像は生成せず、原寸を CSS で縮小表示する。
- 削除（個別・一括）: `admin-post.php` への POST。権限・nonce を確認し、`delete()` を呼ぶ。完了後に件数を通知（`?deleted=n`）して一覧へ戻る。個別削除は確認ダイアログを出す。
- 保存期間の設定: 一覧の上に「保存期間 [30] 日（0 で自動削除しない）[保存]」のフォーム。0〜3650 の整数。nonce を確認して `mwwpf_retention_days` を更新する。
- 画面上部に、保管ファイルの件数と合計サイズを表示する。§6.4 の警告条件を満たす場合は、ここにも警告を出す。
- 全ての出力をエスケープし、パスは画面に出さない。

---

## 7. セキュリティ要件

実装後に1項目ずつ確認し、§9.2 のテストで裏付けること。

- [ ] file input に name 属性がない（本体のアップロード処理に `$_FILES` を渡さない）
- [ ] サーバは、クライアントから受け取ったパスやファイル名を、保存・読み込みのパスに使わない。パスはトークンからのみ組み立てる
- [ ] トークンは `random_bytes` 由来で、形式を正規表現で検証する
- [ ] トークンは Cookie（`mwwpf_sid`）に紐付き、他人のトークンは使えない
- [ ] フォームIDと項目名の組み合わせを、フォーム本文から作った一覧で検証する
- [ ] 拡張子の許可リストと拒否リストを両方適用する。拒否リストは常に優先する: `php, php3, php4, php5, php7, php8, phtml, phar, phps, pht, cgi, pl, py, rb, sh, asp, aspx, jsp, exe, com, bat, cmd, js, mjs, html, htm, shtml, xhtml, svg, svgz, xml, htaccess, htpasswd, ini, user.ini`
- [ ] 二重拡張子（`a.php.pdf`）は最後の拡張子で判定し、保存名に元の拡張子を使わない（`.bin` 固定）
- [ ] `wp_check_filetype_and_ext()` と `finfo` で実際の内容を確認する
- [ ] 保管場所（`tmp/` と `files/`）に直接アクセスできない（Apache は `.htaccess`、Nginx は設定または公開ディレクトリ外）。保護が効かない恐れがあるときは管理画面で警告する（§6.4）
- [ ] 「添付ファイル」画面・ダウンロード・プレビュー・削除・設定の全てで、権限（既定 `manage_options`）と nonce を確認する
- [ ] ダウンロード・プレビュー・削除のパスは、整数の form_id と形式を検証したトークンからだけ組み立てる
- [ ] 全ての Ajax に nonce、レート制限、容量上限をかける
- [ ] プレビューは画像のみ。`nosniff` と `no-store` を付ける
- [ ] 送信後にトークンを消費済みにし、再利用できない
- [ ] `unlink` は保管場所のルート配下に限定する（`realpath` で確認）
- [ ] 出力は全てエスケープする
- [ ] エラーメッセージにサーバのパスを含めない
- [ ] 配布 zip と CI の要件（除外ファイル、ワークフローの権限）は環境準備 §12 を満たしたまま変えない

---

## 8. 実装フェーズ

| Phase | 内容 | 完了条件 |
|---|---|---|
| 0 | 環境準備の完了を確認する。§9.1 のテスト用フォームとバリデーション設定を、テスト環境に自動で作る仕組み（WP-CLI のスクリプト、またはテストの前処理）を用意する | `npm test` が通り、テスト環境にテスト用フォームが作られる |
| 1 | ブートストラップ、フォームスキャナ、項目クラスの上書き（描画のみ。アップロードなし） | 既存フォームの `[mwform_file]` が自作の HTML で描画される。管理画面のタグ生成ダイアログが自作のものになる |
| 2 | ストレージ、Ajax（upload / delete / preview）、掃除 | §9.2 のアップロード系テストが通る |
| 3 | フロント JS、入力・確認・戻る・エラー再表示 | §9.1 の画面遷移テストが通る |
| 4 | 代理バリデーションルール | §9.1 のバリデーションテストが通る |
| 5 | メール（メールタグ、添付） | 添付と本文が §9.1 のとおり |
| 6 | 送信済みファイルの保管（§6.9）、保存期間による削除（§6.10）、管理画面「添付ファイル」（§6.14）、公開の警告（§6.4） | §9.1 の T10〜T12・T17〜T20 が通る |
| 7 | 5.2 模擬テスト（§9.3）、README、多言語化、PHPCS 修正 | 全テストが通り、PHPCS の警告がゼロ |
| 8 | 更新配信（§6.13）: Plugin Update Checker の組み込み。`release.yml` と `.distignore` は環境準備で作成済み | §9.5 の更新テストが通る |

---

## 9. テスト計画と受け入れ基準

### 9.1 機能テスト（Playwright E2E）

テスト用フォーム（本文は下記をそのまま使う。**本プラグインのためにフォームを書き換えないことが前提のため**）:

```
<p>お名前 [mwform_text name="your-name"]</p>
<p>資料 [mwform_file name="attach"]</p>
<p>写真 [mwform_image name="photo"]</p>
<p>[mwform_bback]戻る[/mwform_bback] [mwform_bconfirm]確認[/mwform_bconfirm] [mwform_bsubmit]送信[/mwform_bsubmit]</p>
```

ボタン系ショートコード名は 5.1.8 のソースで確認済み（`mwform_bback` / `mwform_bconfirm` / `mwform_bsubmit`）。button 要素版で、§6.6 の送信ボタン無効化の対象になる。

バリデーション設定: attach = noempty、filetype `pdf`、filesize `2097152` / photo = filetype `jpg,png`、maxfilesize 幅 2000・高さ 2000

| # | シナリオ | 期待結果 |
|---|---|---|
| T1 | PDF と JPG を選んで確認 → 送信 | 管理者メールに2ファイル添付。添付名は元のファイル名。本文の `{attach}` `{photo}` は元のファイル名 |
| T2 | 日本語ファイル名（`見積依頼書（最新版）.pdf`） | 添付名・本文とも文字化けしない |
| T3 | 確認画面から「戻る」 | 入力画面にアップロード済みの状態が残る。そのまま再送信できる |
| T4 | 他の項目のエラーで再表示 | アップロード済みの状態が残る |
| T5 | 確認画面なしの設定 | 直接送信でき、添付される |
| T6 | 削除ボタン → 未選択で送信 | noempty のエラーが出る |
| T7 | .docx を attach に選ぶ | filetype のエラー（クライアント側とサーバ側の両方） |
| T8 | 3MB の PDF | filesize のエラー |
| T9 | 3000×3000 の PNG を photo に選ぶ | maxfilesize のエラー |
| T10 | 送信（DB保存オン・オフの両方） | 保管場所 `files/{form_id}/` に保管され、「添付ファイル」画面に表示される。メディアライブラリには登録されない。DB保存オンなら問い合わせデータへのリンクが出る |
| T11 | 送信後 | `tmp/` に一時ファイルが残らない |
| T12 | 保存期間を 1日にして、保管から1日を過ぎたファイル（テストではメタデータの時刻を書き換える）で掃除を実行 | 削除される。期間内のものは残る。保存期間 0 なら削除されない |
| T17 | 「添付ファイル」画面でダウンロード・画像プレビュー | 元のファイル名（日本語含む）でダウンロードできる。画像はプレビューされる |
| T18 | 個別削除、チェックしての一括削除 | 選んだファイルだけが削除され、件数が通知される |
| T19 | 管理者以外（編集者）・未ログインで画面・ダウンロード URL にアクセス | 拒否される |
| T20 | Nginx 相当（`mwwpf_server_ignores_htaccess` を true）で保管場所が公開ディレクトリ内 | 管理画面に警告が出る。`MWWPF_STORAGE_PROTECTED` で消える |
| T13 | 自動返信メール | 添付されない |
| T14 | 1ページに2フォーム | 互いに干渉しない |
| T15 | アップロード中に送信ボタン | 押せない |
| T16 | JavaScript 無効 | noscript の案内が表示される |

### 9.2 セキュリティテスト（PHPUnit と直接リクエスト）

| # | 攻撃 | 期待結果 |
|---|---|---|
| S1 | PHP ファイルを `.pdf` に改名してアップロード | 拒否（MIME 不一致） |
| S2 | `a.php.pdf`、`a.pdf.php`、`a.svg`、`.htaccess` | 拒否、または `.bin` で保存され実行不可 |
| S3 | 別ブラウザ（別 Cookie）で取得したトークンを POST に入れる | 解決されず、添付も本文の値も入らない |
| S4 | フォームにない項目名・存在しないフォームID・別の投稿タイプのID | 拒否 |
| S5 | 項目名に `../` やヌルバイト | 拒否 |
| S6 | hidden のファイル名だけ改ざん | メール本文・添付名はトークン側の元ファイル名 |
| S7 | 送信済みトークンの再送信 | 解決されない |
| S8 | 保管場所（`tmp/` と `files/`）の URL に直接アクセス | 403 または 404（Apache と Nginx の両方） |
| S9 | nonce なし、またはレート制限超過 | 拒否 |
| S10 | プレビューに PDF のトークンを指定 | 404 |
| S11 | 管理画面のダウンロード・削除に、形式外のトークンや `../` を含む form_id、nonce なし | 拒否 |

### 9.3 5.2 互換テスト

- `npm run env:sim52`（環境準備 §9.1、§10）で本体のファイル系クラスを削除した「模擬 5.2」に切り替え、§9.1 と §9.2 を全て再実行する。CI でも matrix で自動実行される。
- 5.2 がリリースされたら、実物で再実行する。差分が出たら修正方針を報告する。

### 9.4 実環境での確認

- Xserver（Apache、PHP 8.x）と KUSANAGI 9（Nginx、PHP-FPM）のステージングで T1・T2・T10・T20・S8 を手動確認する。
- ページキャッシュ（KUSANAGI のページキャッシュ、キャッシュ系プラグイン）が有効でも、フォームページで nonce が古くならないこと。

### 9.5 更新配信テスト

テスト用のプレリリース（例: `v0.9.0` → `v0.9.1`）で、ステージングサイトを使って確認する。

| # | 確認内容 | 期待結果 |
|---|---|---|
| U1 | 旧版を入れたサイトで「更新を確認」 | 新版の更新通知が表示され、変更履歴が見られる |
| U2 | 管理画面から更新 | プラグインが有効のまま更新され、フォルダ名が `mwwpf-file-upload` のまま変わらない |
| U3 | 自動更新を有効にして待つ（または WP-Cron を手動実行） | 自動で更新される |
| U4 | 更新後にフォームを送信 | T1 と同じ結果 |
| U5 | 配布 zip の中身 | §7 の除外対象が入っていない。`vendor/` は入っている |

---

## 10. 要確認事項（実装前に質問する）

| # | 項目 | 回答（2026-10-20） |
|---|---|---|
| Q1 | filetype 未設定時の許可拡張子と上限サイズ | 拡張子は §6.11 の値。上限は 10MB |
| Q2 | 一時保存先を公開ディレクトリ外にできるか（サイトごと） | 既定は `uploads/mwwpf` + `.htaccess`。Nginx などで公開ディレクトリ内なら管理画面で警告し、ドキュメントルート外の `MWWPF_STORAGE_DIR` 指定を促す。README にも記載 |
| Q3 | DB保存時に本体と同じくメディアライブラリ（公開URL）へ置いてよいか | メディア登録（DB保存）は不要。送信済みファイルは全フォームで非公開の保管場所に 30日（変更可）保管し、管理画面「添付ファイル」で管理者だけが一覧・ダウンロード・プレビュー・削除できる |

プラグイン名・スラッグ、対応バージョンの下限、GitHub のアカウントは環境準備 S1〜S3 で確定済み。

---

## 11. README に必ず書くこと

- 本プラグインは MW WP Form の公式品ではないこと
- 有効化するだけで既存の `[mwform_file]` `[mwform_image]` が置き換わること
- JavaScript が必須であること
- Nginx 環境の設定例（§6.4）と、`MWWPF_STORAGE_DIR` を公開ディレクトリ外に置く推奨。警告を消す `MWWPF_STORAGE_PROTECTED`
- 送信済みファイルは「MW WP Form → 添付ファイル」で管理者だけが確認できること。保存期間（既定 30日）を過ぎると自動で削除されること
- 本体の問い合わせデータ（DB保存）にはファイル名だけが残り、メディアライブラリには登録されないこと
- アンインストールすると保管ファイルも削除されること
- MW WP Form の削除予告（管理画面の通知）は 5.1.x の間表示され続けるが、動作には影響しないこと
- 確認済みの MW WP Form バージョン
- 更新は管理画面に通知されること。自動更新はサイトごとに有効化すること
- 導入方法: Releases から `mwwpf-file-upload.zip` を取得し、管理画面の「プラグインのアップロード」で入れる（GitHub の「Source code」の zip は使わない）
