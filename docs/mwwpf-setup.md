# File Upload for MW WP Form 環境準備指示書

対象: Claude Code
作成: 2026-10-19
更新: 2026-10-19（初回適用で判明した wp-env 11 の注意点を反映）

この指示書は、プラグインの実装を始める前の開発環境・GitHub・CI を準備するためのもの。プラグイン本体の実装は `docs/mwwpf-instructions.md`（プラグイン実装指示書）で行う。

---

## 0. 作業ルール

- Step 1 から順に進める。各 Step の「確認」を満たしてから次へ進む。
- §2 の要確認事項は、該当する Step の前に質問する。回答がない項目は、指定された既定の扱いに従う。
- ツールのインストール、リポジトリ外のファイル（Traefik の設定、`/etc/hosts`、シェルの設定など）の変更は、実行前に内容を提示して承認を得る。
- GitHub への操作（リポジトリ作成、設定変更、push）は、§2 S1 の回答後に行う。
- 公開リポジトリになるため、パスワード・API キー・トークン・実在する顧客サイトの情報をコミットしない。
- この段階ではプラグインの機能を実装しない。作るのは空の雛形とテストの土台まで。

### 起動場所

1. 最初は `/Users/kkazkeke/Developer/wp-plugins/` で Claude Code を起動し、この指示書を渡す（フォルダがなければ Step 2 で作る）。
2. Step 2 でプラグインのフォルダを作った後は、そのフォルダ内で作業する。
3. 以後の開発は、プラグインのフォルダで Claude Code を起動する。

---

## 1. 決定済みの値

今後ほかのプラグインでこの指示書を流用するときは、この表の値だけを差し替える。

| 変数 | 値 | 備考 |
|---|---|---|
| `DEV_ROOT` | `/Users/kkazkeke/Developer/wp-plugins` | 自作プラグインの置き場所。直下に共通の設定ファイルやリポジトリは作らない |
| `SLUG` | `mwwpf-file-upload`（確定） | フォルダ名・GitHub のリポジトリ名・配布 zip の最上位フォルダ名を全て一致させる |
| `PLUGIN_DIR` | `${DEV_ROOT}/${SLUG}` | リポジトリのルート = プラグインのフォルダ |
| `DEV_HOST` | `plugins.localhost` | 手元の開発用 WordPress のホスト名 |
| `DEV_PORT` / `TESTS_PORT` | `8888` / `8889` | wp-env の既定。別プラグインと同時に起動する場合はずらす（§6.2） |
| `MWF_VERSION` | `5.1.8` | テストに使う MW WP Form のバージョン |
| リポジトリの公開範囲 | 公開（public） | 更新配信を GitHub Releases から認証なしで行うため |

---

## 2. 要確認事項

| # | 項目 | 回答がない場合 |
|---|---|---|
| S1 | GitHub のアカウント（個人か Organization か）とリポジトリ名 | Step 10 以降を行わない。リポジトリ名の既定は `SLUG` |
| S2 | プラグイン名（表示名） | 「File Upload for MW WP Form」。"MW WP Form" を名前の先頭に置かない（公式品と誤認されないため）。**スラッグ `mwwpf-file-upload` と接頭辞 `mwwpf_` / `MWWPF_` は確定済み**（本体の接頭辞 `mwf_` / `MWF_` / `mwform_` とは重ならない） |
| S3 | 導入予定サイトのうち最も古い WordPress と PHP のバージョン | WordPress 6.0 / PHP 8.0 を下限として扱う |
| S4 | 既存の Traefik の設定ファイルの場所と entryPoint 名 | Step 4 を行わず、`http://localhost:8888` で進める |

S2 はプラグインヘッダに書くため、Step 2 の前に確認する。

---

## 3. Step 1: Mac のツール

`which` やバージョン確認で、次のツールの有無を調べる。足りないものは一覧にして提示し、承認後にインストールする。

| ツール | 推奨の入れ方 | 用途 |
|---|---|---|
| Docker | OrbStack（Docker Desktop でも可） | wp-env の実行 |
| Node.js（LTS） | mise などのバージョン管理ツール | wp-env、Playwright |
| PHP・Composer | Homebrew | Composer、PHPCS をホスト側で実行 |
| Git | macOS 付属または Homebrew | — |
| GitHub CLI（`gh`） | Homebrew | リポジトリ作成、Actions・Release の確認 |

**確認:** `docker info`、`node -v`、`php -v`、`composer -V`、`git --version`、`gh --version` が全て成功する。

---

## 4. Step 2: フォルダと Git の初期化

1. `DEV_ROOT` がなければ作成する。
2. `PLUGIN_DIR` を作成し、`git init -b main` する。
3. `docs/` を作り、次の2つを置く。
   - `docs/mwwpf-setup.md`（この指示書）
   - `docs/mwwpf-instructions.md`（プラグイン実装指示書）
4. `CLAUDE.md` を作る。
   ```markdown
   # File Upload for MW WP Form

   - 環境準備: 未完了（docs/mwwpf-setup.md）
   - プラグイン実装: docs/mwwpf-instructions.md

   環境準備が「未完了」の間は docs/mwwpf-setup.md に従う。
   「完了」になったら docs/mwwpf-instructions.md に従う。
   ```
5. `.gitignore` を作る。
   ```
   /vendor/
   /node_modules/
   /dist/
   /tests/fixtures/mw-wp-form/
   /test-results/
   /playwright-report/
   .wp-env.override.json
   .DS_Store
   ```
6. 最小限のプラグインファイル `${SLUG}.php` を作る（プラグインヘッダのみ。`Version: 0.1.0`、`Requires at least` と `Requires PHP` は S3 の値、`Text Domain` は `SLUG`）。中身は実装指示書で書く。
7. 初回コミット。

**確認:** `git status` がクリーン。`git log` に初回コミットがある。

---

## 5. Step 3: wp-env

### 5.1 `package.json`

開発用の依存として `@wordpress/env` と `@playwright/test` を入れる。

### 5.2 `.wp-env.json`

```json
{
  "core": "WordPress/WordPress#6.8",
  "phpVersion": "8.3",
  "plugins": [ "." ],
  "config": {
    "WP_DEBUG": true,
    "WP_DEBUG_LOG": true,
    "AUTOMATIC_UPDATER_DISABLED": true,
    "WP_AUTO_UPDATE_CORE": false,
    "MWWPF_TEST_MAIL_CAPTURE": true
  },
  "mappings": {
    "wp-content/plugins/mw-wp-form": "./tests/fixtures/mw-wp-form",
    "wp-content/mu-plugins": "./tests/mu-plugins"
  }
}
```

- `core` は S3 の最も古いバージョンにする（上の値は仮置き）。最新版での確認は §5.4 のとおり切り替える。
- 自動更新は必ず止める。テスト環境のコアは `.git` なしでコピーされるため、止めないと WordPress の自動更新で最新版に上がり、下限バージョンでのテストにならない（実例: 6.0 → 7.1.3）。上がってしまった場合は、古いファイルが残るため `npx wp-env destroy` で作り直す。
- wp-env 11 以降では、テスト環境の同時起動（`testsEnvironment` の既定動作）と `env` 設定が非推奨になり、起動時に警告が出る。現行版では動作する。次のメジャー版で削除された場合は、`--config` で別の設定ファイルを使う方式へ移行する。
- MW WP Form は zip を直接指定せず、`tests/fixtures/mw-wp-form/` をマッピングする。通常版と模擬 5.2 を Step 7 のスクリプトで差し替えるため。
- マッピングしたフォルダは有効化されないため、起動後に `wp-env run cli wp plugin activate mw-wp-form` と `tests-cli` 側の同じコマンドを実行する（npm スクリプトに含める）。

### 5.3 起動

```
bin/mwf-fixture.sh 5.1.8      # Step 7 で作るスクリプト
npx wp-env start
```

### 5.4 バージョンの切り替え

- PHP: `WP_ENV_PHP_VERSION=8.0 npx wp-env start --update`
- WordPress: `WP_ENV_CORE=WordPress/WordPress#<版> npx wp-env start --update`

これらの環境変数の名前は、使用する wp-env のバージョンのドキュメントで確認してから使う。

**確認:** `http://localhost:8888/wp-admin/`（admin / password）でログインでき、MW WP Form 5.1.8 と本プラグインが有効になっている。`http://localhost:8889` のテスト環境も起動している。

---

## 6. Step 4: 手元のホスト名 `http://plugins.localhost`

S4 の回答がない場合、この Step は行わない。

### 6.1 WordPress 側

`.wp-env.override.json`（コミットしない）を作る。開発環境だけを変え、テスト環境と CI は `localhost` のままにする（CI には Traefik がないため）。

`WP_HOME` / `WP_SITEURL` は使わない。wp-env は `WP_SITEURL` に必ずポート番号を付け直すため、`http://plugins.localhost:8888` になってしまう。代わりに独自の定数を定義する。

```json
{
  "env": {
    "development": {
      "config": {
        "MWWPF_DEV_HOME": "http://plugins.localhost"
      }
    }
  }
}
```

この定数があるときだけ URL を差し替える mu-plugin `tests/mu-plugins/mwwpf-dev-host.php` を作る（コミットする。定数がなければ何もしないため、テスト環境と CI には影響しない）。

```php
if ( ! defined( 'MWWPF_DEV_HOME' ) || ! MWWPF_DEV_HOME ) {
	return;
}

add_filter( 'option_home', 'mwwpf_dev_home_url', 20 );
add_filter( 'option_siteurl', 'mwwpf_dev_home_url', 20 );

function mwwpf_dev_home_url() {
	return untrailingslashit( MWWPF_DEV_HOME );
}
```

### 6.2 ポート

`DEV_ROOT` 配下の別プラグインと wp-env を同時に起動する場合は、同じ `.wp-env.override.json` に `"port"` と `"testsPort"` を追加し、重ならない値（例: 8890 / 8891）にする。その場合は Traefik の転送先ポートとテストの `baseURL` も合わせて変える。

### 6.3 Traefik

既存の Traefik（ローカル Docker 共通のリバースプロキシ）の file provider に追加する。設定ファイルを読み、追加する差分を提示して承認を得てから書き込む。entryPoint 名は既存の設定に合わせる。

- Traefik の場所: `/Users/kkazkeke/gdrive/@github/traefik`（entryPoint は `web`）。file provider は `dynamic/` を監視しているので、`dynamic/${SLUG}.yml` を追加するだけで反映される（コンテナの再起動は不要）。
- file provider がない Traefik の場合は、compose の command に `--providers.file.directory=/etc/traefik/dynamic` と `--providers.file.watch=true` を、volumes に `./dynamic:/etc/traefik/dynamic:ro` を追加して、`docker compose up -d` で作り直す。

```yaml
http:
  routers:
    mwwpf:
      rule: "Host(`plugins.localhost`)"
      entryPoints: [web]
      service: mwwpf
  services:
    mwwpf:
      loadBalancer:
        servers:
          - url: "http://host.docker.internal:8888"
```

### 6.4 反映

`npx wp-env start --update`

Safari やコマンドラインで `plugins.localhost` が開けない場合は、`/etc/hosts` への `127.0.0.1 plugins.localhost` の追加を提案する（承認後に実施）。

**確認:** `http://plugins.localhost/wp-admin/` でログインでき、管理画面のリンクが全て `plugins.localhost` になっている。`http://localhost:8889` のテスト環境は従来どおり開ける。

---

## 7. Step 5: Composer、PHPCS、PHPUnit

### 7.1 `composer.json`

- 開発用: `wp-coding-standards/wpcs`、`phpcompatibility/phpcompatibility-wp`、`dealerdirect/phpcodesniffer-composer-installer`、`phpunit/phpunit`、`yoast/phpunit-polyfills`
- 本番用の依存（plugin-update-checker）はここでは入れない。実装指示書の Phase 8 で入れる。
- `config.platform.php` を S3 の PHP 下限に合わせる（古い PHP で動かない依存を入れないため）。

### 7.2 PHPCS

`.phpcs.xml.dist` を作る。

- ルールセット: `WordPress`、`PHPCompatibilityWP`
- `testVersion`: S3 の PHP 下限以上
- テキストドメイン: `SLUG`、接頭辞: `mwwpf` / `MWWPF`
- 対象外: `vendor/`、`node_modules/`、`tests/fixtures/`

### 7.3 PHPUnit

- wp-env のテスト環境に含まれる WordPress のテストライブラリを使い、`tests-cli` コンテナ内で実行する。テストライブラリのパスは使用する wp-env のドキュメントで確認する（wp-env 11 では環境変数 `WP_TESTS_DIR` = `/wordpress-phpunit`）。
- PHPUnit は必ず `WORDPRESS_TABLE_PREFIX=wptests_` を付けて実行する（例: `wp-env run tests-cli --env-cwd=wp-content/plugins/${SLUG} env WORDPRESS_TABLE_PREFIX=wptests_ vendor/bin/phpunit`）。テストライブラリはテスト用サイトと同じデータベース・接頭辞を使うため、付けないと Playwright 用のサイト（8889）が初期化されて E2E が壊れる。
- `.phpunit.result.cache` は `.gitignore` と `.distignore` に追加する。
- `tests/phpunit/bootstrap.php` で MW WP Form と本プラグインを読み込む。
- 空のテストを1件作る（`MW_WP_Form_Abstract_Form_Field` クラスが存在することを確認するだけのもの）。

**確認:** `composer lint`（または npm スクリプト）と PHPUnit の空テストが通る。

---

## 8. Step 6: Playwright

- `playwright.config.ts` を作る。`baseURL` は `http://localhost:8889`（テスト環境）。ブラウザは Chromium のみ。
- ログイン状態の保存（storageState）を用意し、管理画面の操作をテストで使えるようにする。
- 空のテストを1件作る（フロントのトップページが 200 で開くことを確認するだけのもの）。

**確認:** `npx playwright test` が通る。

---

## 9. Step 7: テスト用の補助ファイル

### 9.1 MW WP Form の差し替えスクリプト `bin/mwf-fixture.sh`

| 引数 | 動作 |
|---|---|
| `5.1.8` など | wordpress.org から指定バージョンの zip を取得し、`tests/fixtures/mw-wp-form/` に展開する |
| `sim52` | 5.1.8 を展開した後、次のファイルを削除して「模擬 5.2」にする: `classes/form-fields/class.file.php`、`classes/form-fields/class.image.php`、`classes/validation-rules/class.filetype.php`、`class.filesize.php`、`class.maximagesize.php`、`class.minimagesize.php` |

- 展開前に `tests/fixtures/mw-wp-form/` の中身を空にする。
- 取得した zip のバージョンを `tests/fixtures/mw-wp-form/.fixture-version` に書いておく（どちらの状態かを確認できるように）。

### 9.2 メール捕捉 mu-plugin `tests/mu-plugins/mwwpf-mail-capture.php`

- 定数 `MWWPF_TEST_MAIL_CAPTURE` が true のときだけ動く。
- `pre_wp_mail` で送信を止め、宛先・件名・本文・ヘッダ・添付ファイル名を JSON で `wp-content/mwwpf-mail-log/` に保存する。添付ファイル自体もコピーしておく。
- 本番に入らないよう、配布 zip から `tests/` を除外する（§12）。

**確認:** テスト環境で `wp_mail()` を呼ぶと、ログに JSON が1件できる。

---

## 10. Step 8: npm スクリプト

| スクリプト | 内容 |
|---|---|
| `env:start` | `bin/mwf-fixture.sh 5.1.8` → `wp-env start` → MW WP Form の有効化 |
| `env:sim52` | `bin/mwf-fixture.sh sim52` → `wp-env start --update` → 有効化 |
| `env:stop` | `wp-env stop` |
| `lint` | PHPCS |
| `test:php` | テスト環境で PHPUnit |
| `test:e2e` | Playwright |
| `test` | lint → test:php → test:e2e |
| `build` | 配布 zip を `dist/${SLUG}.zip` に作る（§12.3 と同じ手順） |

**確認:** `npm run env:start` → `npm test` が通る。`npm run env:sim52` → `npm test` も通る（この時点では空テストのみ）。

---

## 11. Step 9: GitHub リポジトリ

S1 の回答後に行う。

1. GitHub アカウントで二段階認証が有効になっていることをユーザーに確認してもらう。
2. `gh repo create <owner>/${SLUG} --public --source . --push` で作成して push する。
3. 設定:
   - main ブランチの保護: PR 経由のマージのみ、`ci.yml` の成功を必須にする
   - タグ `v*` の作成を管理者のみに制限する（タグの保護ルール）
   - Actions の権限: 既定を読み取りのみにする
4. リポジトリの説明に「MW WP Form の公式品ではない」旨を入れる。

**確認:** GitHub 上にリポジトリがあり、ブランチ保護が有効。

---

## 12. Step 10: GitHub Actions と配布 zip

### 12.1 `.github/workflows/ci.yml`

- トリガー: push、pull_request
- 権限: `contents: read`
- matrix: PHP 8.0 と 8.3（S3 の下限が上がればそれに合わせる）× MW WP Form `5.1.8` と `sim52`
- 手順: checkout → Node・PHP のセットアップ → `composer install` → `npm ci` → lint → `bin/mwf-fixture.sh <matrix>` → wp-env 起動 → PHPUnit → Playwright（Chromium のインストールを含む）
- 失敗時は Playwright のレポートを artifact として保存する
- 外部の Action は公式または実績のあるものに限り、メジャーバージョン以上で固定する

### 12.2 `.github/workflows/release.yml`

- トリガー: `v*` のタグ push
- 権限: `contents: write`（このワークフローだけ）
- 手順:
  1. タグ（先頭の `v` と `-rc.1` などの接尾辞を除く）とプラグインヘッダの `Version:` が一致しなければ失敗させる（例: `v0.1.0-rc.1` はヘッダ `0.1.0` と照合する）
  2. `ci.yml` と同じテストを実行する（reusable workflow にしてもよい）
  3. `composer install --no-dev --optimize-autoloader`
  4. §12.3 の手順で `${SLUG}.zip` を作る
  5. Release を作成して zip を添付する。タグ名に `-rc` などを含む場合はプレリリースにする

### 12.3 配布 zip の作り方と `.distignore`

- **zip の最上位フォルダ名は必ず `${SLUG}/`。** 違うと、更新時にフォルダ名が変わってプラグインが無効化される。
- `.distignore` で次を除外する:
  ```
  /.git
  /.github
  /bin
  /docs
  /tests
  /node_modules
  /dist
  /test-results
  /playwright-report
  /.wp-env.json
  /.wp-env.override.json
  /.phpcs.xml.dist
  /phpunit.xml.dist
  /playwright.config.ts
  /composer.json
  /composer.lock
  /package.json
  /package-lock.json
  /CLAUDE.md
  /.gitignore
  /.distignore
  ```
- `vendor/` は含める（`--no-dev` でインストールしたもの）。
- `npm run build` でも同じ手順で作れるようにする。

**確認:**
- PR を作ると `ci.yml` が全 matrix で通る
- テスト用タグ `v0.1.0-rc.1` を push すると、プレリリースが作られ `${SLUG}.zip` が添付される
- zip を展開すると最上位が `${SLUG}/` で、除外対象が入っていない
- 確認後、テスト用のプレリリースとタグは削除してよいかユーザーに確認する

---

## 13. 完了確認

全て満たしたら、`CLAUDE.md` の「環境準備」を「完了（日付）」に書き換えてコミットし、ユーザーに報告する。

- [ ] Step 1〜10 の「確認」を全て満たした
- [ ] `http://plugins.localhost`（または S4 未回答なら `http://localhost:8888`）で管理画面が開く
- [ ] `npm test` が手元で通る（5.1.8 と模擬 5.2 の両方）
- [ ] GitHub Actions の `ci.yml` が通る
- [ ] テスト用プレリリースで zip の作成と添付ができた
- [ ] コミットされたファイルに秘密情報や `.wp-env.override.json` が含まれていない

---

## 14. リリース手順（実装後の運用。人が行う作業）

1. 作業ブランチで実装し、PR で `ci.yml` が通ることを確認して main にマージする
2. プラグインヘッダの `Version:` と readme の変更履歴を更新し、PR でマージする
3. プレリリースのタグ（例: `v1.1.0-rc.1`）を push し、ステージングサイトで更新を確認する（実装指示書 §9.5）
4. 問題がなければ正式なタグ（例: `v1.1.0`）を push する
