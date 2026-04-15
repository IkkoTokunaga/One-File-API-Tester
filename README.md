# One-File API Tester

`API.php` 1ファイルで動く、ブラウザUI付きのAPIテストツールです。  
HTTPリクエスト送信、レスポンス確認、リクエスト保存、共有変数の管理ができます。

## 必要環境

- PHP 8.x
- PHP cURL 拡張 (`curl`)
- ブラウザ (Chrome / Edge など)

## 起動方法

プロジェクトルートで以下を実行します。

```bash
php -S 127.0.0.1:8000
```

ブラウザで次にアクセスします。

- <http://127.0.0.1:8000/API.php>

## 基本操作

1. `Method` と `URL` を入力
2. 必要なら `Headers` をJSONで入力
3. 必要なら `Body` / `Body Params` を入力
4. `Send` を押してレスポンスを確認

## Content-Type と Body の使い分け

- `application/json`  
  `Body` にJSON文字列を入力して送信します。
- `application/x-www-form-urlencoded`  
  `Body Params` のキー/値をフォームとして送信します。
- `GET` / `HEAD`  
  Bodyは送信されません。

## 変数置換 (`{{VariableName}}`)

`Body Params` や入力値に `{{Email}}` のようなプレースホルダを使えます。  
値は右側の **Shared Variables** で管理します。

例:

- `email = user@example.com`
- `password = secret`

`Body Params`:

- `email: {{Email}}`
- `password: {{Password}}`

## 保存機能

- リクエスト保存先: `api_tool_saved_requests/`
- 共有変数保存先: `api_tool_shared_variables/variables.json`
- UI上の `Save` で現在のリクエストを保存できます
- Collections パネルの `Run All` で、collection単位に全リクエストを順次実行できます

## インポートJSONフォーマット

インポートは **1ファイルにつき1リクエスト** のJSONを想定しています。  
最低限、`method` と `url` があれば使えます（不足時は既定値にフォールバック）。

### 最小フォーマット

```json
{
  "method": "GET",
  "url": "https://example.com/api/users"
}
```

### 推奨フォーマット（フル）

```json
{
  "id": "local_post_form_login",
  "path": "local/auth",
  "title": "[LOCAL] POST Form Login",
  "method": "POST",
  "url": "https://httpbin.org/post",
  "contentType": "application/x-www-form-urlencoded",
  "headers": {
    "Accept": "application/json"
  },
  "body": "",
  "bodyParams": {
    "email": "{{Email}}",
    "password": "{{Password}}"
  }
}
```

### 使えるキー

- `method` (GET/POST/PUT/PATCH/DELETE。未指定時は GET)
- `url` (`endpoint` でも可)
- `title` (`name` でも可)
- `path` (保存先の論理フォルダ名)
- `headers` (オブジェクト)
- `contentType` (`content-type` でも可)
- `body` (文字列、またはオブジェクト)
- `bodyParams` (フォーム送信用のキー/値オブジェクト)

### 補足

- インポート時の保存ファイル名は、基本的に **インポートしたJSONファイル名** が使われます。
- `contentType` が `application/json` の場合、`body` は保存時にJSONとして正規化されます。
- 置換に使う変数は、常に `api_tool_shared_variables/variables.json` の共有変数です。

## よくあるエラー

- `PHP の cURL 拡張が有効ではありません`  
  -> `php -m | rg curl` で確認し、未導入なら cURL を有効化してください。
- 保存失敗 (ディレクトリ作成失敗 / 書き込み不可)  
  -> 実行ユーザーの書き込み権限を確認してください。

## Git管理について

現在の `.gitignore` は、`API.php` と `README.md` だけを追跡対象にする設定です。  
保存データ (`api_tool_saved_requests/`, `api_tool_shared_variables/`) は追跡されません。
