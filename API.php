<?php
declare(strict_types=1);

/**
 * @param array<string,mixed> $payload
 */
function api_tool_send_json(array $payload): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function api_tool_execute_proxy(array $input): array
{
    $url = trim((string)$input['url']);
    $method = strtoupper(trim((string)$input['method']));
    $allowed = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];
    if (!in_array($method, $allowed, true)) {
        return [
            'ok' => false,
            'status' => 0,
            'duration_ms' => 0,
            'responseContentType' => '',
            'responseHeaders' => '',
            'body' => '',
            'error' => 'サポートされていないメソッドです',
        ];
    }
    if ($url === '') {
        return [
            'ok' => false,
            'status' => 0,
            'duration_ms' => 0,
            'responseContentType' => '',
            'responseHeaders' => '',
            'body' => '',
            'error' => 'URLが空です',
        ];
    }
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['scheme'])) {
        return [
            'ok' => false,
            'status' => 0,
            'duration_ms' => 0,
            'responseContentType' => '',
            'responseHeaders' => '',
            'body' => '',
            'error' => 'URLの形式が不正です',
        ];
    }
    $scheme = strtolower((string)$parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return [
            'ok' => false,
            'status' => 0,
            'duration_ms' => 0,
            'responseContentType' => '',
            'responseHeaders' => '',
            'body' => '',
            'error' => 'http / https のみ許可されています',
        ];
    }

    $headersIn = $input['headers'] ?? [];
    if (!is_array($headersIn)) {
        $headersIn = [];
    }
    $headerLines = [];
    foreach ($headersIn as $k => $v) {
        $name = trim((string)$k);
        if ($name === '') {
            continue;
        }
        $val = (string)$v;
        if (strpbrk($name, "\r\n") !== false || strpbrk($val, "\r\n") !== false) {
            continue;
        }
        $headerLines[] = $name . ': ' . $val;
    }

    $shouldSendBody = !in_array($method, ['GET', 'HEAD'], true);
    $body = $shouldSendBody ? (isset($input['body']) ? (string)$input['body'] : '') : '';

    $ch = curl_init($url);
    if ($ch === false) {
        return [
            'ok' => false,
            'status' => 0,
            'duration_ms' => 0,
            'responseContentType' => '',
            'responseHeaders' => '',
            'body' => '',
            'error' => 'cURL の初期化に失敗しました',
        ];
    }
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
    if ($shouldSendBody) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $t0 = microtime(true);
    $response = curl_exec($ch);
    $durationMs = (int)round((microtime(true) - $t0) * 1000);

    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [
            'ok' => false,
            'status' => 0,
            'duration_ms' => $durationMs,
            'responseContentType' => '',
            'responseHeaders' => '',
            'body' => '',
            'error' => $err !== '' ? $err : '通信に失敗しました',
        ];
    }

    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr($response, 0, $headerSize);
    $respBody = substr($response, $headerSize);
    $responseHeaders = trim((string)$rawHeaders);
    $headerBlocks = preg_split("/\r\n\r\n+/", $responseHeaders) ?: [];
    $lastHeaderBlock = '';
    foreach ($headerBlocks as $block) {
        $trimmed = trim((string)$block);
        if ($trimmed !== '') {
            $lastHeaderBlock = $trimmed;
        }
    }
    if ($lastHeaderBlock !== '') {
        $responseHeaders = $lastHeaderBlock;
    }

    $respCt = '';
    foreach (explode("\r\n", $responseHeaders) as $line) {
        if (stripos($line, 'Content-Type:') === 0) {
            $respCt = trim(substr($line, 14));
            break;
        }
    }

    $ok = $status >= 200 && $status < 300;

    return [
        'ok' => $ok,
        'status' => $status,
        'duration_ms' => $durationMs,
        'responseContentType' => $respCt,
        'responseHeaders' => $responseHeaders,
        'body' => $respBody,
        'error' => null,
    ];
}

/** このスクリプトと同じディレクトリに作成される保存用フォルダ名 */
const API_TOOL_STORAGE_DIR_NAME = 'api_tool_saved_requests';
const API_TOOL_SHARED_VARIABLES_DIR_NAME = 'api_tool_shared_variables';
const API_TOOL_SHARED_VARIABLES_FILE_NAME = 'variables.json';

function api_tool_storage_absolute_dir(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . API_TOOL_STORAGE_DIR_NAME;
}

function api_tool_shared_variables_absolute_dir(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . API_TOOL_SHARED_VARIABLES_DIR_NAME;
}

function api_tool_shared_variables_absolute_path(): string
{
    return api_tool_shared_variables_absolute_dir() . DIRECTORY_SEPARATOR . API_TOOL_SHARED_VARIABLES_FILE_NAME;
}

/**
 * @return array{ok:true}|array{ok:false,error:string}
 */
function api_tool_storage_ensure_dir(): array
{
    $dir = api_tool_storage_absolute_dir();
    if (is_dir($dir)) {
        if (!is_writable($dir)) {
            return ['ok' => false, 'error' => '保存ディレクトリに書き込めません: ' . API_TOOL_STORAGE_DIR_NAME];
        }

        return ['ok' => true];
    }
    if (!@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'error' => '保存ディレクトリの作成に失敗しました（書き込み権限を確認してください）'];
    }

    return ['ok' => true];
}

/**
 * @return array{ok:true}|array{ok:false,error:string}
 */
function api_tool_shared_variables_ensure_dir(): array
{
    $dir = api_tool_shared_variables_absolute_dir();
    if (is_dir($dir)) {
        if (!is_writable($dir)) {
            return ['ok' => false, 'error' => '変数保存ディレクトリに書き込めません: ' . API_TOOL_SHARED_VARIABLES_DIR_NAME];
        }

        return ['ok' => true];
    }
    if (!@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'error' => '変数保存ディレクトリの作成に失敗しました（書き込み権限を確認してください）'];
    }

    return ['ok' => true];
}

/**
 * @param mixed $variablesIn
 * @return array<string,string>
 */
function api_tool_shared_variables_normalize($variablesIn): array
{
    if (!is_array($variablesIn)) {
        return [];
    }
    $normalized = [];
    foreach ($variablesIn as $k => $v) {
        $key = trim((string)$k);
        if ($key === '') {
            continue;
        }
        $normalized[$key] = (string)$v;
    }

    return $normalized;
}

/** 保存ファイル名（拡張子なし）に使えるよう、危険文字のみ除去（日本語ファイル名可） */
function api_tool_storage_sanitize_file_base(string $name): string
{
    $name = trim($name);
    if ($name === '.' || $name === '..') {
        $name = '_';
    }
    $name = preg_replace('/[\\\\\\/:*?"<>|\x00-\x1f]/u', '_', $name) ?? '';
    $name = rtrim($name, '. ');
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 120, 'UTF-8');
    } else {
        $name = substr($name, 0, 120);
    }

    return $name !== '' ? $name : 'request';
}

function api_tool_storage_sanitize_relative_key(string $path): string
{
    $raw = trim(str_replace('\\', '/', $path));
    $raw = preg_replace('#/+#', '/', $raw) ?? '';
    $parts = array_values(array_filter(explode('/', $raw), static function (string $p): bool {
        return $p !== '' && $p !== '.' && $p !== '..';
    }));
    $safeParts = [];
    foreach ($parts as $part) {
        $safeParts[] = api_tool_storage_sanitize_file_base($part);
    }

    return $safeParts !== [] ? implode('/', $safeParts) : 'request';
}

function api_tool_storage_path_for_base(string $base): string
{
    $leaf = api_tool_storage_sanitize_file_base(basename(str_replace('\\', '/', $base)));

    return api_tool_storage_absolute_dir() . DIRECTORY_SEPARATOR . $leaf . '.json';
}

/**
 * @param array<string,mixed> $config
 */
function api_tool_storage_normalize_json_body_for_config(array &$config): void
{
    $contentType = '';
    if (isset($config['contentType']) && is_string($config['contentType'])) {
        $contentType = strtolower(trim($config['contentType']));
    }
    if ($contentType === '' && isset($config['headers']) && is_array($config['headers'])) {
        foreach ($config['headers'] as $k => $v) {
            if (strtolower(trim((string)$k)) === 'content-type') {
                $contentType = strtolower(trim((string)$v));
                break;
            }
        }
    }
    if ($contentType === '' || strpos($contentType, 'application/json') === false) {
        return;
    }
    if (!isset($config['body']) || !is_string($config['body'])) {
        return;
    }
    $raw = trim($config['body']);
    if ($raw === '') {
        $config['body'] = '';
        return;
    }
    /** @var mixed $decoded */
    $decoded = json_decode($raw, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        // 不正JSONの場合は既存挙動互換のため変更しない
        return;
    }
    $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded !== false) {
        $config['body'] = $encoded;
    }
}

/**
 * @return list<array{path:string,fileName:string,mtime:int,item:array<string,mixed>}>
 */
function api_tool_storage_read_index_rows(): array
{
    $dir = api_tool_storage_absolute_dir();
    if (!is_dir($dir)) {
        return [];
    }
    $paths = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $fileInfo) {
        /** @var SplFileInfo $fileInfo */
        if (!$fileInfo->isFile()) {
            continue;
        }
        if (strtolower($fileInfo->getExtension()) !== 'json') {
            continue;
        }
        $paths[] = $fileInfo->getPathname();
    }
    $rows = [];
    foreach ($paths as $path) {
        $relativePath = substr($path, strlen($dir) + 1);
        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        if (!str_ends_with(strtolower($relativePath), '.json')) {
            continue;
        }
        $base = substr($relativePath, 0, -5);
        $base = api_tool_storage_sanitize_relative_key($base);
        if ($base === '') {
            continue;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            continue;
        }
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            continue;
        }
        $normalized = api_tool_storage_normalize_saved_item($decoded, $base);
        $modified = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            !== json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($modified) {
            $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json !== false) {
                $tmp = $path . '.tmp';
                if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
                    @rename($tmp, $path);
                }
            }
        }
        $leafBase = api_tool_storage_sanitize_file_base(basename(str_replace('\\', '/', $base)));
        $itemForResponse = $normalized;
        $itemForResponse['id'] = api_tool_storage_sanitize_file_base((string)($normalized['id'] ?? $leafBase));
        $itemForResponse['path'] = isset($normalized['path']) && is_string($normalized['path']) ? $normalized['path'] : '';
        $itemForResponse['fileName'] = $leafBase;
        $rows[] = [
            'path' => $path,
            'fileName' => $leafBase,
            'mtime' => (int)@filemtime($path),
            'item' => $itemForResponse,
        ];
    }

    return $rows;
}

/**
 * @param array<string,mixed> $item
 * @return array<string,mixed>
 */
function api_tool_storage_normalize_saved_item(array $item, string $fileBase): array
{
    $base = api_tool_storage_sanitize_relative_key($fileBase);
    $src = $item;
    $path = '';
    $id = '';
    if (isset($src['path']) && is_string($src['path'])) {
        $path = trim($src['path']);
    }
    if (isset($src['id']) && is_string($src['id'])) {
        $id = trim($src['id']);
    }
    if ($id === '') {
        $id = basename($base);
    }
    $id = api_tool_storage_sanitize_file_base($id);
    $path = $path !== '' ? api_tool_storage_sanitize_relative_key($path) : '';

    $headersIn = $src['headers'] ?? [];
    $headers = is_array($headersIn) ? $headersIn : [];
    $bodyRaw = $src['body'] ?? '';
    if (is_array($bodyRaw)) {
        $bodyRaw = json_encode($bodyRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (!is_string($bodyRaw)) {
        $bodyRaw = (string)$bodyRaw;
    }

    $flat = [
        'id' => $id,
        'path' => $path,
        'title' => isset($src['title']) ? (string)$src['title'] : (isset($item['name']) ? (string)$item['name'] : ''),
        'method' => isset($src['method']) ? strtoupper((string)$src['method']) : 'GET',
        'url' => isset($src['url']) ? (string)$src['url'] : (isset($src['endpoint']) ? (string)$src['endpoint'] : ''),
        'contentType' => isset($src['contentType']) ? (string)$src['contentType'] : '',
        'headers' => $headers,
        'body' => $bodyRaw,
    ];

    api_tool_storage_normalize_json_body_for_config($flat);

    if (isset($src['bodyParams']) && is_array($src['bodyParams']) && $src['bodyParams'] !== []) {
        $flat['bodyParams'] = $src['bodyParams'];
    }

    return $flat;
}

/**
 * @param array<string,mixed> $itemIn
 * @return non-empty-string
 */
function api_tool_storage_resolve_file_base(array $itemIn): string
{
    $fileIn = $itemIn['fileName'] ?? null;
    if (is_string($fileIn) && trim($fileIn) !== '') {
        return api_tool_storage_sanitize_file_base(basename(str_replace('\\', '/', trim($fileIn))));
    }
    $pathIn = $itemIn['path'] ?? null;
    $idIn = $itemIn['id'] ?? null;
    if (is_string($pathIn) && trim($pathIn) !== '' && is_string($idIn) && trim($idIn) !== '') {
        $idPart = api_tool_storage_sanitize_file_base(trim($idIn));
        return $idPart;
    }
    if (is_string($idIn) && trim($idIn) !== '') {
        return api_tool_storage_sanitize_file_base(trim($idIn));
    }

    return 'req_' . bin2hex(random_bytes(8));
}

/**
 * @param array<string,mixed> $decoded
 */
function api_tool_handle_storage_action(array $decoded): void
{
    $action = isset($decoded['action']) && is_string($decoded['action']) ? $decoded['action'] : '';

    if ($action === 'storage_list') {
        if (!is_dir(api_tool_storage_absolute_dir())) {
            api_tool_send_json(['ok' => true, 'items' => []]);
            return;
        }
        $rows = api_tool_storage_read_index_rows();
        $items = [];
        foreach (array_slice($rows, 0, 30) as $row) {
            $items[] = $row['item'];
        }
        api_tool_send_json(['ok' => true, 'items' => $items]);
        return;
    }

    if ($action === 'storage_save') {
        $ensure = api_tool_storage_ensure_dir();
        if (!$ensure['ok']) {
            api_tool_send_json(['ok' => false, 'error' => $ensure['error']]);
            return;
        }
        $itemIn = $decoded['item'] ?? null;
        if (!is_array($itemIn)) {
            api_tool_send_json(['ok' => false, 'error' => 'item が不正です。']);
            return;
        }
        $fileBase = api_tool_storage_resolve_file_base($itemIn);
        $normalized = api_tool_storage_normalize_saved_item($itemIn, $fileBase);
        $path = api_tool_storage_path_for_base($fileBase);
        $parent = dirname($path);
        if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
            api_tool_send_json(['ok' => false, 'error' => '保存先ディレクトリの作成に失敗しました。']);
            return;
        }
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            api_tool_send_json(['ok' => false, 'error' => 'JSON のエンコードに失敗しました。']);
            return;
        }
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            api_tool_send_json(['ok' => false, 'error' => 'ファイルの保存に失敗しました。']);
            return;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            api_tool_send_json(['ok' => false, 'error' => 'ファイルの確定に失敗しました。']);
            return;
        }
        $itemForResponse = $normalized;
        $itemForResponse['id'] = api_tool_storage_sanitize_file_base((string)($normalized['id'] ?? $fileBase));
        $itemForResponse['path'] = isset($normalized['path']) && is_string($normalized['path']) ? $normalized['path'] : '';
        $itemForResponse['fileName'] = api_tool_storage_sanitize_file_base($fileBase);
        api_tool_send_json(['ok' => true, 'item' => $itemForResponse]);
        return;
    }

    if ($action === 'storage_delete') {
        $fn = isset($decoded['fileName']) && is_string($decoded['fileName']) ? trim($decoded['fileName']) : '';
        $id = isset($decoded['id']) && is_string($decoded['id']) ? trim($decoded['id']) : '';
        $raw = $fn !== '' ? $fn : $id;
        if ($raw === '') {
            api_tool_send_json(['ok' => false, 'error' => 'fileName（または id）が必要です。']);
            return;
        }
        $base = api_tool_storage_sanitize_file_base(basename(str_replace('\\', '/', $raw)));
        $path = api_tool_storage_path_for_base($base);
        if (is_file($path)) {
            @unlink($path);
        }
        api_tool_send_json(['ok' => true]);
        return;
    }

    if ($action === 'variables_get') {
        $path = api_tool_shared_variables_absolute_path();
        if (!is_file($path)) {
            api_tool_send_json(['ok' => true, 'variables' => []]);
            return;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            api_tool_send_json(['ok' => true, 'variables' => []]);
            return;
        }
        /** @var mixed $decodedVars */
        $decodedVars = json_decode($raw, true);
        $vars = api_tool_shared_variables_normalize($decodedVars);
        api_tool_send_json(['ok' => true, 'variables' => $vars]);
        return;
    }

    if ($action === 'variables_save') {
        $ensure = api_tool_shared_variables_ensure_dir();
        if (!$ensure['ok']) {
            api_tool_send_json(['ok' => false, 'error' => $ensure['error']]);
            return;
        }
        $varsIn = $decoded['variables'] ?? null;
        $vars = api_tool_shared_variables_normalize($varsIn);
        $path = api_tool_shared_variables_absolute_path();
        $json = json_encode($vars, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            api_tool_send_json(['ok' => false, 'error' => '変数JSONのエンコードに失敗しました。']);
            return;
        }
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            api_tool_send_json(['ok' => false, 'error' => '変数ファイルの保存に失敗しました。']);
            return;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            api_tool_send_json(['ok' => false, 'error' => '変数ファイルの確定に失敗しました。']);
            return;
        }
        api_tool_send_json(['ok' => true, 'variables' => $vars]);
        return;
    }

    api_tool_send_json(['ok' => false, 'error' => '不明なストレージ操作です。']);
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    if (strpos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw === false) {
            $raw = '';
        }
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $action = isset($decoded['action']) && is_string($decoded['action']) ? $decoded['action'] : '';

            if ($action === 'http_request' && isset($decoded['url'], $decoded['method'])) {
                if (!function_exists('curl_init')) {
                    api_tool_send_json([
                        'ok' => false,
                        'status' => 0,
                        'duration_ms' => 0,
                        'responseContentType' => '',
                        'body' => '',
                        'error' => 'PHP の cURL 拡張が有効ではありません。',
                    ]);
                    exit;
                }
                api_tool_send_json(api_tool_execute_proxy($decoded));
                exit;
            }

            if (str_starts_with($action, 'storage_') || str_starts_with($action, 'variables_')) {
                api_tool_handle_storage_action($decoded);
                exit;
            }

            if (($action === '' || $action === 'execute') && isset($decoded['url'], $decoded['method'])) {
                if (!function_exists('curl_init')) {
                    api_tool_send_json([
                        'ok' => false,
                        'status' => 0,
                        'duration_ms' => 0,
                        'responseContentType' => '',
                        'body' => '',
                        'error' => 'PHP の cURL 拡張が有効ではありません。',
                    ]);
                    exit;
                }
                api_tool_send_json(api_tool_execute_proxy($decoded));
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>One-File API Tester</title>
    <style>
        :root {
            --bg-start: #fff7ed;
            --bg-end: #ffedd5;
            --panel: #ffffff;
            --panel-border: #dbe3ef;
            --text: #0f172a;
            --text-soft: #64748b;
            --primary: #f97316;
            --primary-hover: #ea580c;
            --success: #16a34a;
            --danger: #dc2626;
            --code-bg: #0b1120;
            --code-text: #d6e2ff;
            --line-strong: #fdba74;
            --line-mid: #fed7aa;
            --neutral-btn-bg: #e5e7eb;
            --neutral-btn-hover: #d1d5db;
            --neutral-btn-text: #374151;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 32px 16px;
            line-height: 1.6;
            font-family: "Inter", "Segoe UI", "Hiragino Kaku Gothic ProN", "Yu Gothic", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 18% 12%, rgba(251, 146, 60, 0.24), transparent 38%),
                radial-gradient(circle at 82% 88%, rgba(245, 158, 11, 0.2), transparent 36%),
                linear-gradient(135deg, var(--bg-start), var(--bg-end));
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.96);
            border: 2px solid var(--line-strong);
            border-radius: 18px;
            padding: 28px;
        }

        h1 {
            margin: 0;
            font-size: clamp(1.4rem, 2.4vw, 1.9rem);
            font-family: "Segoe UI", "Inter", "Yu Gothic UI", sans-serif;
            font-weight: 900;
            letter-spacing: 0.04em;
            color: #9a3412;
            text-transform: uppercase;
            position: relative;
            padding-left: 14px;
            line-height: 1.1;
        }

        h1::before {
            content: "";
            position: absolute;
            left: 0;
            top: 2px;
            bottom: 2px;
            width: 5px;
            border-radius: 999px;
            background: linear-gradient(180deg, #fb923c, #ea580c);
        }

        .top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
        }

        .top-tools {
            display: flex;
            align-items: center;
            gap: 8px;
            position: relative;
        }

        #menuBackdrop {
            position: fixed;
            inset: 0;
            background: rgba(2, 6, 23, 0.58);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.15s ease;
            z-index: 1000;
        }

        body.menu-open #menuBackdrop {
            opacity: 1;
        }

        body.menu-open .field,
        body.menu-open .accordion,
        body.menu-open #bodyField,
        body.menu-open .response-section {
            opacity: 0.45;
            transition: opacity 0.15s ease;
        }

        .field { margin-bottom: 18px; }
        .field-title-row {
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }
        .field-title-main {
            flex: 1;
            min-width: 0;
        }
        .field-title-dir {
            width: 220px;
            flex: 0 0 220px;
        }

        label {
            display: block;
            font-weight: 700;
            margin-bottom: 8px;
            font-size: 0.85rem;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        input, select, textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--line-mid);
            border-radius: 10px;
            font-size: 0.98rem;
            background: #fff;
            color: #0f172a;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s, background-color 0.2s;
        }

        input::placeholder, textarea::placeholder { color: #94a3b8; }
        textarea { resize: vertical; min-height: 84px; font-family: "Consolas", "Monaco", monospace; }

        input:focus, select:focus, textarea:focus {
            border-color: var(--line-strong);
            background: #ffffff;
        }

        .row { display: flex; gap: 10px; }
        .row input { flex: 1; }
        .row select {
            width: 130px;
            font-weight: 700;
            color: #9a3412;
            background: #ffedd5;
        }

        .row .send-inline-btn {
            width: auto;
            min-width: 136px;
            padding: 12px 16px;
        }

        .accordion {
            border: 2px solid var(--line-mid);
            border-radius: 12px;
            background: #fff8f1;
            margin-bottom: 18px;
            overflow: hidden;
        }

        .accordion summary {
            list-style: none;
            cursor: pointer;
            padding: 12px 14px;
            font-size: 0.86rem;
            font-weight: 700;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            display: flex;
            align-items: center;
            justify-content: space-between;
            user-select: none;
        }

        .accordion summary::-webkit-details-marker { display: none; }

        .accordion summary::after {
            content: "＋";
            color: #ea580c;
            font-size: 1rem;
            line-height: 1;
        }

        .accordion[open] summary::after { content: "−"; }

        .accordion-body {
            padding: 0 14px 14px;
            border-top: 2px solid var(--line-mid);
        }

        .content-type-row {
            margin-top: 10px;
        }

        .content-type-row select {
            width: 100%;
            background: #ffffff;
            color: #0f172a;
            font-weight: 500;
        }

        .content-type-row select:disabled {
            background: #e2e8f0;
            color: #94a3b8;
            cursor: not-allowed;
        }

        textarea:disabled {
            background: #e2e8f0;
            color: #94a3b8;
            cursor: not-allowed;
        }

        .header-row {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }

        #headersList,
        #bodyKeyValueList {
            max-height: 190px;
            overflow-y: auto;
            padding-right: 4px;
        }

        #headersList::-webkit-scrollbar,
        #bodyKeyValueList::-webkit-scrollbar {
            width: 8px;
        }

        #headersList::-webkit-scrollbar-thumb,
        #bodyKeyValueList::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 999px;
        }

        .header-row input { flex: 1; }

        .header-row button {
            width: auto;
            min-width: 38px;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 0.9rem;
            box-shadow: none;
            background: #e2e8f0;
            color: #334155;
        }

        .header-row button:hover {
            transform: none;
            box-shadow: none;
            filter: none;
            background: #cbd5e1;
        }

        .sub-btn {
            margin-top: 10px;
            width: auto;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            box-shadow: none;
            background: var(--neutral-btn-bg);
            color: var(--neutral-btn-text);
        }

        .sub-btn:hover {
            transform: none;
            box-shadow: none;
            filter: none;
            background: var(--neutral-btn-hover);
        }

        .hidden { display: none !important; }

        #bodyField {
            border: 2px solid var(--line-mid);
            border-radius: 12px;
            background: #fff8f1;
            padding: 14px;
        }

        .body-tools {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 8px;
        }

        .tool-btn {
            width: auto;
            height: 36px;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: none;
            background: var(--neutral-btn-bg);
            color: var(--neutral-btn-text);
        }

        .tool-btn:hover {
            transform: none;
            box-shadow: none;
            filter: none;
            background: var(--neutral-btn-hover);
        }

        .gear-btn {
            min-width: 40px;
            width: 40px;
            height: 36px;
            padding: 0;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        #settingsPanel {
            position: absolute;
            top: 44px;
            right: 0;
            width: min(520px, calc(100vw - 64px));
            border: 1px solid #d6deeb;
            border-radius: 12px;
            background: #f8fbff;
            box-shadow: none;
            padding: 12px;
            z-index: 1004;
        }

        #savedPanel {
            position: absolute;
            top: 44px;
            right: 96px;
            width: min(520px, calc(100vw - 64px));
            max-height: calc(100vh - 72px);
            border: 1px solid #d6deeb;
            border-radius: 12px;
            background: #f8fbff;
            box-shadow: none;
            padding: 12px;
            z-index: 1004;
            overflow-y: auto;
        }

        .settings-title {
            margin: 0 0 8px;
            font-size: 0.78rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 700;
        }

        .settings-item {
            border: 2px solid var(--line-mid);
            border-radius: 10px;
            background: #ffffff;
            overflow: hidden;
        }

        .settings-item summary {
            list-style: none;
            cursor: pointer;
            padding: 10px 12px;
            font-size: 0.86rem;
            font-weight: 700;
            color: #334155;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .settings-item summary::-webkit-details-marker { display: none; }
        .settings-item summary::after { content: "▾"; color: #ea580c; }

        .settings-content {
            border-top: 2px solid var(--line-mid);
            padding: 0 12px 12px;
        }

        .saved-list {
            max-height: none;
            overflow-y: auto;
            margin-top: 10px;
            padding-right: 4px;
        }

        .saved-collection-title {
            margin-top: 12px;
            margin-bottom: 6px;
            font-size: 0.74rem;
            font-weight: 800;
            color: #7c2d12;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .saved-collection-toggle {
            width: 100%;
            margin-top: 12px;
            margin-bottom: 6px;
            padding: 6px 8px;
            border: 1px solid #fed7aa;
            border-radius: 8px;
            background: #fff7ed;
            color: #7c2d12;
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .saved-collection-toggle:hover {
            background: #ffedd5;
        }

        .saved-collection-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
            margin-bottom: 6px;
        }

        .saved-collection-header .saved-collection-toggle {
            margin: 0;
            flex: 1;
        }

        .saved-collection-run {
            width: auto;
            min-width: 78px;
            height: 34px;
            padding: 8px 10px;
            border-radius: 8px;
            border: 1px solid #fdba74;
            background: #ffedd5;
            color: #9a3412;
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            line-height: 1;
        }

        .saved-collection-run:hover {
            transform: none;
            filter: none;
            box-shadow: none;
            background: #fdba74;
        }

        .saved-collection-caret {
            width: 1.1em;
            display: inline-block;
            text-align: center;
        }

        .saved-collection-items.hidden {
            display: none;
        }

        .saved-item {
            display: flex;
            gap: 6px;
            align-items: center;
            margin-top: 8px;
        }

        .saved-name {
            flex: 1;
            min-width: 0;
            border: 1px solid var(--line-mid);
            border-radius: 8px;
            padding: 8px 10px;
            background: #fff;
            color: #1f2937;
            font-size: 0.83rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .saved-name.saved-name--trigger {
            cursor: pointer;
            text-align: left;
            transition: background 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .saved-name.saved-name--trigger:hover {
            background: #fff7ed;
            border-color: var(--line-strong);
            box-shadow: 0 1px 0 rgba(251, 146, 60, 0.2);
        }

        .saved-name.saved-name--trigger:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }

        .saved-name.saved-name--trigger:active {
            background: #ffedd5;
        }

        .saved-item button {
            width: auto;
            min-width: 58px;
            padding: 8px 10px;
            border-radius: 8px;
            font-size: 0.8rem;
            box-shadow: none;
            background: var(--neutral-btn-bg);
            color: var(--neutral-btn-text);
        }

        .saved-item button:hover {
            transform: none;
            box-shadow: none;
            filter: none;
            background: var(--neutral-btn-hover);
        }

        button {
            width: 100%;
            border: none;
            border-radius: 12px;
            padding: 13px 20px;
            color: var(--neutral-btn-text);
            font-weight: 700;
            font-size: 1rem;
            letter-spacing: 0.02em;
            cursor: pointer;
            background: var(--neutral-btn-bg);
            box-shadow: none;
            transition: transform 0.18s ease, filter 0.18s ease;
        }

        button:hover {
            transform: translateY(-1px);
            box-shadow: none;
            filter: saturate(1.1);
            background: var(--neutral-btn-hover);
        }

        button:disabled {
            background: #94a3b8;
            box-shadow: none;
            cursor: not-allowed;
            transform: none;
            filter: none;
        }

        .response-section {
            margin-top: 26px;
            padding: 16px;
            border-radius: 14px;
            border: 2px solid var(--line-mid);
            background: #fff8f1;
        }

        .save-btn {
            width: auto;
            min-width: 84px;
            background: #ffedd5;
            color: #9a3412;
            border: 1px solid #fdba74;
        }

        .save-btn:hover {
            background: #fdba74;
        }

        .stop-btn {
            width: auto;
            min-width: 96px;
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .stop-btn:hover {
            background: #fecaca;
        }

        .send-inline-btn {
            color: #fff;
            background: linear-gradient(135deg, var(--primary), #fb923c);
        }

        .send-inline-btn:hover {
            background: linear-gradient(135deg, var(--primary-hover), #f97316);
        }

        .info-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            font-size: 0.9rem;
            color: #334155;
        }

        .info-label {
            font-weight: 700;
            font-size: 0.85rem;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .response-tools {
            display: inline-flex;
            gap: 6px;
            margin-right: 6px;
        }

        .response-target-tools {
            display: inline-flex;
            gap: 6px;
            margin-left: 0;
        }

        .response-mode-btn {
            width: auto;
            min-width: 78px;
            padding: 5px 10px;
            border-radius: 999px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #334155;
            font-size: 0.78rem;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            box-shadow: none;
        }

        .response-mode-btn.active {
            border-color: #fb923c;
            background: #ffedd5;
            color: #9a3412;
        }

        #statusTag {
            padding: 3px 10px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 0.82rem;
            background: #e2e8f0;
        }

        #statusTag:empty {
            display: none;
        }

        .response-output-shell {
            border: 1px solid #1e293b;
            border-radius: 12px;
            overflow: hidden;
            background: linear-gradient(180deg, #111827, var(--code-bg));
        }

        #output {
            margin: 0;
            width: 100%;
            box-sizing: border-box;
            min-height: 130px;
            max-height: min(62vh, 680px);
            padding: 18px;
            overflow-x: auto;
            overflow-y: auto;
            scrollbar-gutter: stable;
            border: none;
            border-radius: 0;
            background: transparent;
            color: var(--code-text);
            font-family: "JetBrains Mono", "Consolas", "Monaco", monospace;
            font-size: 0.9rem;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .error {
            color: #fee2e2;
            background: linear-gradient(180deg, #7f1d1d, #3f0d0d);
        }

        .save-toast {
            position: fixed;
            bottom: 32px;
            left: 50%;
            transform: translateX(-50%) translateY(18px);
            z-index: 300;
            padding: 14px 22px;
            border-radius: 14px;
            font-size: 0.95rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            color: #fff;
            background: linear-gradient(135deg, #15803d, #22c55e);
            box-shadow: 0 12px 32px rgba(22, 101, 52, 0.38);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.28s ease, transform 0.28s ease, visibility 0.28s;
            pointer-events: none;
            max-width: min(92vw, 440px);
            text-align: center;
            line-height: 1.45;
        }

        .save-toast.save-toast--visible {
            opacity: 1;
            visibility: visible;
            transform: translateX(-50%) translateY(0);
        }

        .batch-toast-stack {
            position: fixed;
            top: 120px;
            right: 16px;
            z-index: 900;
            display: flex;
            flex-direction: column;
            gap: 8px;
            width: min(420px, calc(100vw - 24px));
            pointer-events: none;
        }

        .batch-toast {
            pointer-events: auto;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            border-radius: 10px;
            padding: 9px 10px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #0f172a;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.18);
            font-size: 0.78rem;
            line-height: 1.35;
        }

        .batch-toast--ok {
            border-color: #86efac;
            background: #f0fdf4;
            color: #166534;
        }

        .batch-toast--error {
            border-color: #fecaca;
            background: #fef2f2;
            color: #991b1b;
        }

        .batch-toast--summary {
            font-size: 0.86rem;
            font-weight: 800;
            border-width: 2px;
            line-height: 1.5;
            white-space: pre-line;
        }

        .batch-toast__close {
            margin-left: auto;
            width: 22px;
            min-width: 22px;
            height: 22px;
            border-radius: 999px;
            padding: 0;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155;
            font-size: 0.78rem;
            line-height: 1;
            cursor: pointer;
        }

        .batch-toast__close:hover {
            transform: none;
            filter: none;
            box-shadow: none;
            background: #f1f5f9;
        }

        @keyframes saveBtnFlash {
            0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.55); }
            100% { box-shadow: 0 0 0 14px rgba(34, 197, 94, 0); }
        }

        .tool-btn.save-btn.save-btn-flash {
            animation: saveBtnFlash 0.7s ease-out 1;
        }

        @media (max-width: 700px) {
            .container { padding: 20px; }
            .row { flex-direction: column; }
            .row select { width: 100%; }
            .top-bar { flex-direction: column; align-items: stretch; }
            .top-tools { justify-content: flex-end; }
            .field-title-row { flex-direction: column; align-items: stretch; gap: 0; }
            .field-title-dir { width: 100%; flex-basis: auto; }
        }
    </style>
</head>
<body>
<div id="saveToast" class="save-toast" role="status" aria-live="polite" aria-atomic="true"></div>
<div id="batchToastStack" class="batch-toast-stack" aria-live="polite" aria-atomic="false"></div>
<div id="menuBackdrop" aria-hidden="true"></div>
<div class="container">
    <div class="top-bar">
        <h1>One-File API Tester</h1>
        <div class="top-tools">
        <button type="button" id="manualSaveBtn" class="tool-btn save-btn" onclick="void saveCurrentRequestManually()">Save</button>
        <button type="button" id="emergencyStopBtn" class="tool-btn stop-btn hidden" onclick="requestBatchStop()">Stop Execution</button>
        <button type="button" class="tool-btn" onclick="toggleSavedPanel()">Collections</button>
        <button type="button" class="tool-btn gear-btn" onclick="toggleSettingsPanel()" aria-label="Settings">⚙</button>
        <input type="file" id="bulkRequestFileInput" accept=".json,application/json" multiple class="hidden">

        <div id="savedPanel" class="hidden">
            <p class="settings-title">Collections</p>
            <div id="savedRequestsList" class="saved-list"></div>
        </div>

        <div id="settingsPanel" class="hidden">
            <p class="settings-title">Settings Menu</p>
            <details class="settings-item">
                <summary>Variables</summary>
                <div class="settings-content">
                    <div id="variablesList">
                        <div class="header-row">
                            <input type="text" class="var-key" placeholder="{{Email}}" value="{{Email}}">
                            <input type="text" class="var-value" placeholder="Variable Value (例: example@domain.com)">
                            <button type="button" onclick="removeVariableRow(this)">×</button>
                        </div>
                    </div>
                    <button type="button" class="sub-btn" onclick="addVariableRow()">+ 変数を追加</button>
                </div>
            </details>
            <details class="settings-item" style="margin-top:8px;">
                <summary>Import</summary>
                <div class="settings-content">
                    <button type="button" class="sub-btn" onclick="triggerBulkRequestImport()">+ Import JSON</button>
                </div>
            </details>
        </div>
    </div>
    </div>
    <div class="field field-title-row">
        <div class="field-title-main">
            <label>Title</label>
            <input type="text" id="requestTitleInput" placeholder="例: ユーザー作成APIテスト">
        </div>
        <div class="field-title-dir">
            <label>Collection</label>
            <select id="directorySelect" required>
                <option value="local">local</option>
            </select>
        </div>
    </div>

    <div class="field">
        <label>Method & URL</label>
        <div class="row">
            <select id="method">
                <option value="GET">GET</option>
                <option value="POST">POST</option>
                <option value="PUT">PUT</option>
                <option value="PATCH">PATCH</option>
                <option value="DELETE">DELETE</option>
            </select>
            <input type="text" id="urlInput" placeholder="https://jsonplaceholder.typicode.com/todos/1" value="https://jsonplaceholder.typicode.com/todos/1">
            <button id="sendBtn" class="send-inline-btn" onclick="executeRequest()">Send</button>
        </div>
    </div>

    <details class="accordion">
        <summary>Headers (オプション)</summary>
        <div class="accordion-body">
            <div class="content-type-row" id="contentTypeRow">
                <label for="contentTypeSelect">Content-Type</label>
                <select id="contentTypeSelect">
                    <option value="application/json">application/json</option>
                    <option value="application/x-www-form-urlencoded" selected>application/x-www-form-urlencoded</option>
                    <option value="text/plain">text/plain</option>
                    <option value="multipart/form-data">multipart/form-data</option>
                    <option value="">送信しない</option>
                </select>
            </div>
            <div id="headersList">
                <div class="header-row">
                    <input type="text" class="header-key" placeholder="Header Name (例: Authorization)">
                    <input type="text" class="header-value" placeholder="Header Value (例: Bearer xxx)">
                    <button type="button" onclick="removeHeaderRow(this)">×</button>
                </div>
            </div>
            <button type="button" class="sub-btn" onclick="addHeaderRow()">+ Headerを追加</button>
        </div>
    </details>

    <div class="field" id="bodyField">
        <label id="bodyLabel">Body</label>
        <textarea id="bodyRawInput" rows="5" placeholder='{"title":"sample","completed":false}'></textarea>
        <div id="bodyKeyValueWrap" class="hidden">
            <div id="bodyKeyValueList">
                <div class="header-row">
                    <input type="text" class="body-key" placeholder="Key (例: name)">
                    <input type="text" class="body-value" placeholder="Value (例: taro)">
                    <button type="button" onclick="removeBodyRow(this)">×</button>
                </div>
            </div>
            <button type="button" class="sub-btn" onclick="addBodyRow()">+ Body項目を追加</button>
        </div>
    </div>

    <div class="response-section">
        <div class="info-bar">
            <span class="info-label">Response Body</span>
            <div>
                <span class="response-tools">
                    <button id="formattedViewBtn" type="button" class="response-mode-btn active">Formatted</button>
                    <button id="rawViewBtn" type="button" class="response-mode-btn">Raw</button>
                </span>
                <span class="response-target-tools">
                    <button id="headersViewBtn" type="button" class="response-mode-btn">Headers</button>
                </span>
                <span id="statusTag"></span>
            </div>
        </div>
        <div class="response-output-shell">
            <pre id="output">ここに結果が表示されます</pre>
        </div>
    </div>
</div>

<script>
    const API_PROXY_ENDPOINT = <?php echo json_encode($_SERVER['SCRIPT_NAME'] ?? 'API.php', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    let savedRequests = [];
    const collapsedCollections = new Set();
    let collectionsCollapseInitialized = false;
    /** 保存ディレクトリ上のファイルベース名（拡張子 .json なし）。同じ名前のファイルへ上書き */
    let currentSavedRequestFile = null;
    let sharedVariablesSaveTimer = null;
    let sharedVariablesLoading = false;
    let responseViewMode = "formatted";
    let responseViewTarget = "body";
    let responseRawText = "";
    let responseFormattedText = "";
    let responseHeadersText = "";
    let collectionRunInProgress = false;
    let batchAbortRequested = false;
    let batchAbortController = null;
    const BATCH_TOAST_MAX_COUNT = 8;

    function renderResponseBody() {
        const output = document.getElementById("output");
        if (responseViewTarget === "headers") {
            output.innerText = responseHeadersText || "(no headers)";
            return;
        }
        output.innerText = responseViewMode === "raw" ? responseRawText : responseFormattedText;
    }

    function setResponseTexts(rawText, formattedText, headersText = "") {
        responseRawText = String(rawText ?? "");
        responseFormattedText = String(formattedText ?? "");
        responseHeadersText = String(headersText ?? "");
        renderResponseBody();
    }

    function setResponseViewMode(mode) {
        responseViewMode = mode === "raw" ? "raw" : "formatted";
        responseViewTarget = "body";
        document.getElementById("formattedViewBtn").classList.toggle("active", responseViewMode === "formatted");
        document.getElementById("rawViewBtn").classList.toggle("active", responseViewMode === "raw");
        document.getElementById("headersViewBtn").classList.remove("active");
        renderResponseBody();
    }

    function setResponseViewTarget(target) {
        responseViewTarget = target === "headers" ? "headers" : "body";
        document.getElementById("headersViewBtn").classList.toggle("active", responseViewTarget === "headers");
        document.getElementById("formattedViewBtn").classList.toggle(
            "active",
            responseViewTarget === "body" && responseViewMode === "formatted"
        );
        document.getElementById("rawViewBtn").classList.toggle(
            "active",
            responseViewTarget === "body" && responseViewMode === "raw"
        );
        renderResponseBody();
    }

    function getSavedItemFileKey(item) {
        const k = item?.fileName ?? item?.id;
        const s = k != null ? String(k).trim() : "";
        return s !== "" ? sanitizeFileBase(s.split("/").pop() || s) : null;
    }

    function sanitizeFileBase(s) {
        const t = String(s || "")
            .trim()
            .replace(/[\\/:*?"<>|\u0000-\u001f]/g, "_")
            .replace(/\.+$/g, "")
            .slice(0, 120);
        return t || "request";
    }

    function sanitizeFilePath(path) {
        return sanitizeFileBase(path);
    }

    function normalizeCollectionPath(value) {
        return String(value || "")
            .trim()
            .replaceAll("\\", "/")
            .replace(/\/+/g, "/")
            .replace(/^\/+|\/+$/g, "");
    }

    function normalizePath(value) {
        return normalizeCollectionPath(value);
    }

    function normalizeDirectoryPath(value) {
        const normalized = normalizePath(value);
        return normalized || "local";
    }

    function setDirectoryValue(pathValue) {
        const selectEl = document.getElementById("directorySelect");
        const normalized = normalizeDirectoryPath(pathValue);
        const existingOptions = Array.from(selectEl.options).map((option) => option.value);
        if (!existingOptions.includes(normalized)) {
            const opt = document.createElement("option");
            opt.value = normalized;
            opt.textContent = normalized;
            selectEl.appendChild(opt);
        }
        selectEl.value = normalized;
    }

    function refreshDirectoryOptions() {
        const selectEl = document.getElementById("directorySelect");
        const currentValue = normalizeDirectoryPath(selectEl.value);
        const dirs = new Set(["local"]);
        for (const item of savedRequests) {
            const dir = normalizeCollectionPath(item?.path || "");
            if (dir) dirs.add(dir);
        }
        const ordered = [...dirs].sort((a, b) => a.localeCompare(b));
        selectEl.innerHTML = "";
        for (const dir of ordered) {
            const opt = document.createElement("option");
            opt.value = dir;
            opt.textContent = dir;
            selectEl.appendChild(opt);
        }
        setDirectoryValue(currentValue);
    }

    /** 既存の保存名とぶつからないよう連番を付ける（末尾名のみ連番） */
    function uniquifyFileBase(base) {
        const leaf = sanitizeFilePath(base);
        const taken = (name) => savedRequests.some((r) => getSavedItemFileKey(r) === name);
        if (!taken(leaf)) return leaf;
        let n = 2;
        while (taken(`${leaf}_${n}`)) n += 1;
        return `${leaf}_${n}`;
    }

    let saveToastHideTimer = null;

    function showSaveToast(message) {
        const el = document.getElementById("saveToast");
        if (!el) return;
        el.textContent = message;
        el.classList.add("save-toast--visible");
        const btn = document.getElementById("manualSaveBtn");
        if (btn) {
            btn.classList.add("save-btn-flash");
            setTimeout(() => btn.classList.remove("save-btn-flash"), 700);
        }
        if (saveToastHideTimer) clearTimeout(saveToastHideTimer);
        saveToastHideTimer = setTimeout(() => {
            el.classList.remove("save-toast--visible");
            saveToastHideTimer = null;
        }, 2800);
    }

    function formatSaveToastMessage(fileBase) {
        if (!fileBase) return "Saved";
        const s = String(fileBase);
        const short = s.length > 40 ? `${s.slice(0, 38)}…` : s;
        return `Saved: ${short}.json`;
    }

    function pushBatchToast(message, type = "info", timeoutMs = 14000, variant = "") {
        const stack = document.getElementById("batchToastStack");
        if (!stack) return;
        const toast = document.createElement("div");
        const normalizedType = type === "error" ? "error" : (type === "ok" ? "ok" : "info");
        toast.dataset.toastType = normalizedType;
        const normalizedVariant = String(variant || "").trim().toLowerCase();
        toast.className = `batch-toast${normalizedType === "ok" ? " batch-toast--ok" : ""}${normalizedType === "error" ? " batch-toast--error" : ""}${normalizedVariant === "summary" ? " batch-toast--summary" : ""}`;
        const textEl = document.createElement("div");
        textEl.textContent = String(message || "");
        const closeBtn = document.createElement("button");
        closeBtn.type = "button";
        closeBtn.className = "batch-toast__close";
        closeBtn.textContent = "×";
        closeBtn.setAttribute("aria-label", "Close");
        closeBtn.addEventListener("click", () => {
            toast.remove();
        });
        toast.append(textEl, closeBtn);
        stack.appendChild(toast);
        while (stack.children.length > BATCH_TOAST_MAX_COUNT) {
            const removableOk = Array.from(stack.children).find((el) => el instanceof HTMLElement && el.dataset.toastType === "ok");
            if (removableOk) {
                removableOk.remove();
                continue;
            }
            stack.firstElementChild?.remove();
        }
        if (timeoutMs > 0) {
            setTimeout(() => {
                toast.remove();
            }, timeoutMs);
        }
    }

    function updateEmergencyStopButton() {
        const stopBtn = document.getElementById("emergencyStopBtn");
        if (!stopBtn) return;
        if (!collectionRunInProgress) {
            stopBtn.classList.add("hidden");
            stopBtn.disabled = false;
            stopBtn.textContent = "Stop Execution";
            return;
        }
        stopBtn.classList.remove("hidden");
        stopBtn.disabled = batchAbortRequested;
        stopBtn.textContent = batchAbortRequested ? "Stopping..." : "Stop Execution";
    }

    function requestBatchStop() {
        if (!collectionRunInProgress || batchAbortRequested) {
            return;
        }
        batchAbortRequested = true;
        if (batchAbortController) {
            batchAbortController.abort();
        }
        updateEmergencyStopButton();
        pushBatchToast("Emergency stop requested", "error", 0);
    }

    function clearBatchToasts() {
        const stack = document.getElementById("batchToastStack");
        if (!stack) return;
        stack.replaceChildren();
    }

    async function apiJsonPost(payload) {
        const res = await fetch(API_PROXY_ENDPOINT, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        });
        let data;
        try {
            data = await res.json();
        } catch (e) {
            throw new Error("サーバー応答が JSON ではありません（PHP のエラーの可能性があります）");
        }
        if (!data || data.ok !== true) {
            throw new Error(data && data.error ? String(data.error) : `HTTP ${res.status}`);
        }
        return data;
    }

    function buildRequestPayloadFromForm() {
        const variableValues = getVariableValues();
        const rawUrl = document.getElementById("urlInput").value;
        const url = applyVariables(rawUrl, variableValues).trim();
        const method = String(document.getElementById("method").value || "GET").toUpperCase();
        if (!url) {
            throw new Error("URLを入力してください");
        }
        const headers = getHeadersFromInputs(method, variableValues);
        const shouldSendBody = !["GET", "HEAD"].includes(method);
        let body = "";
        if (shouldSendBody) {
            const headerKeys = Object.keys(headers);
            const contentTypeKey = headerKeys.find((key) => key.toLowerCase() === "content-type");
            const contentTypeValue = contentTypeKey ? String(headers[contentTypeKey]).toLowerCase() : "";
            const bodyRaw = applyVariables(document.getElementById("bodyRawInput").value.trim(), variableValues);
            if (contentTypeValue.includes("application/x-www-form-urlencoded")) {
                const bodyParams = getBodyKeyValueParams(variableValues);
                body = new URLSearchParams(bodyParams).toString();
            } else if (bodyRaw) {
                if (contentTypeValue.includes("application/json")) {
                    const parsedBody = JSON.parse(bodyRaw);
                    body = JSON.stringify(parsedBody);
                    if (!contentTypeKey) {
                        headers["Content-Type"] = "application/json";
                    }
                } else {
                    body = bodyRaw;
                }
            }
        }
        return { url, method, headers, body };
    }

    function buildHeadersFromConfig(config, variableValues) {
        const method = String(config?.method || "GET").toUpperCase();
        const selectedContentType = String(config?.contentType || config?.["content-type"] || "");
        const headersIn = (config?.headers && typeof config.headers === "object") ? config.headers : {};
        const forbiddenHeaderNames = new Set(["content-type"]);
        const headers = {};
        if (method !== "GET" && selectedContentType) {
            headers["Content-Type"] = selectedContentType;
        }
        for (const [rawKey, rawValue] of Object.entries(headersIn)) {
            const key = applyVariables(String(rawKey || "").trim(), variableValues);
            const value = applyVariables(String(rawValue ?? "").trim(), variableValues);
            if (!key) continue;
            if (forbiddenHeaderNames.has(key.toLowerCase())) continue;
            headers[key] = value;
        }
        return headers;
    }

    function buildBodyFromConfig(config, headers, variableValues) {
        const method = String(config?.method || "GET").toUpperCase();
        const shouldSendBody = !["GET", "HEAD"].includes(method);
        if (!shouldSendBody) {
            return "";
        }
        const headerKeys = Object.keys(headers);
        const contentTypeKey = headerKeys.find((key) => key.toLowerCase() === "content-type");
        const contentTypeValue = contentTypeKey ? String(headers[contentTypeKey]).toLowerCase() : "";
        if (contentTypeValue.includes("application/x-www-form-urlencoded")) {
            const bodyParamsIn = (config?.bodyParams && typeof config.bodyParams === "object")
                ? config.bodyParams
                : ((config?.body && typeof config.body === "object" && !Array.isArray(config.body)) ? config.body : {});
            const bodyParams = {};
            for (const [rawKey, rawValue] of Object.entries(bodyParamsIn)) {
                const key = applyVariables(String(rawKey || "").trim(), variableValues);
                const value = applyVariables(String(rawValue ?? ""), variableValues);
                if (key) bodyParams[key] = value;
            }
            return new URLSearchParams(bodyParams).toString();
        }
        let bodyRaw = "";
        if (typeof config?.body === "string") {
            bodyRaw = config.body;
        } else if (config?.body && typeof config.body === "object" && !Array.isArray(config.body)) {
            bodyRaw = JSON.stringify(config.body);
        }
        bodyRaw = applyVariables(String(bodyRaw || "").trim(), variableValues);
        if (!bodyRaw) {
            return "";
        }
        if (contentTypeValue.includes("application/json")) {
            const parsedBody = JSON.parse(bodyRaw);
            if (!contentTypeKey) {
                headers["Content-Type"] = "application/json";
            }
            return JSON.stringify(parsedBody);
        }
        return bodyRaw;
    }

    function buildRequestPayloadFromConfig(config, variableValues) {
        const normalizedConfig = normalizeSavedItemConfig(config);
        const url = applyVariables(String(normalizedConfig.url || "").trim(), variableValues);
        const method = String(normalizedConfig.method || "GET").toUpperCase();
        if (!url) {
            throw new Error("URLが空です");
        }
        const headers = buildHeadersFromConfig(normalizedConfig, variableValues);
        const body = buildBodyFromConfig(normalizedConfig, headers, variableValues);
        return { url, method, headers, body };
    }

    async function executeRequestPayload(payload, signal = undefined) {
        const startTime = performance.now();
        const proxyRes = await fetch(API_PROXY_ENDPOINT, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ action: "http_request", ...payload }),
            signal
        });
        const endTime = performance.now();
        const clientDuration = Math.round(endTime - startTime);

        let data;
        try {
            data = await proxyRes.json();
        } catch (e) {
            throw new Error("サーバー応答がJSONではありません（PHPの実行エラーの可能性があります）");
        }

        if (data && data.error && (!data.status || data.status === 0)) {
            throw new Error(String(data.error));
        }
        if (!proxyRes.ok) {
            throw new Error(data && data.error ? String(data.error) : `HTTP ${proxyRes.status}`);
        }

        const status = typeof data.status === "number" ? data.status : 0;
        const serverDuration = typeof data.duration_ms === "number" ? data.duration_ms : clientDuration;
        const ok = typeof data.ok === "boolean" ? data.ok : (status >= 200 && status < 300);
        const contentType = String(data.responseContentType || "").toLowerCase();
        const headersRaw = String(data.responseHeaders || "");
        let rawData = data.body !== undefined && data.body !== null ? String(data.body) : "";
        let formattedData = rawData;
        if (contentType.includes("application/json") && formattedData) {
            try {
                formattedData = JSON.stringify(JSON.parse(formattedData), null, 4);
            } catch (e) {
                // 整形できない場合は生テキストのまま
            }
        }
        if (!rawData) {
            rawData = "(empty body)";
            formattedData = "(empty body)";
        }
        return { ok, status, serverDuration, rawData, formattedData, headersRaw };
    }

    async function executeRequest() {
        const btn = document.getElementById("sendBtn");
        const output = document.getElementById("output");
        const statusTag = document.getElementById("statusTag");
        const originalBtnLabel = btn.innerText;

        btn.disabled = true;
        btn.innerText = "通信中...";
        setResponseTexts("Waiting for response...", "Waiting for response...", "");
        statusTag.innerText = "";
        output.classList.remove("error");

        try {
            const payload = buildRequestPayloadFromForm();
            const result = await executeRequestPayload(payload);
            statusTag.innerText = `Status: ${result.status} (${result.serverDuration}ms)`;
            statusTag.style.color = result.ok ? "#28a745" : "#dc3545";
            setResponseTexts(result.rawData, result.formattedData, result.headersRaw);
        } catch (error) {
            output.classList.add("error");
            statusTag.innerText = "Error";
            statusTag.style.color = "#dc3545";
            const errText = `[リクエスト失敗]\n${error.message}\n\n可能性が高い原因:\n1. URLが間違っている\n2. 相手サーバーが応答しない / SSLエラー\n3. PHPのcURLが無効、またはネットワーク接続エラー\n4. サーバー側がhttp/https以外を拒否しています`;
            setResponseTexts(errText, errText, "");
        } finally {
            btn.disabled = false;
            btn.innerText = originalBtnLabel;
        }
    }

    async function runCollectionRequests(group) {
        if (collectionRunInProgress) {
            return;
        }
        closeTopPanels();
        const normalizedGroup = normalizeCollectionPath(group) || "local";
        const entries = savedRequests
            .filter((item) => (normalizeCollectionPath(item?.path || "") || "local") === normalizedGroup)
            .sort((a, b) => {
                const left = getSavedItemFileKey(a) || "";
                const right = getSavedItemFileKey(b) || "";
                return left.localeCompare(right);
            });
        if (!entries.length) {
            alert(`Collection "${normalizedGroup}" にリクエストがありません。`);
            return;
        }
        collectionRunInProgress = true;
        batchAbortRequested = false;
        batchAbortController = null;
        clearBatchToasts();
        updateEmergencyStopButton();
        renderSavedRequests();
        const sendBtn = document.getElementById("sendBtn");
        const statusTag = document.getElementById("statusTag");
        const output = document.getElementById("output");
        const originalSendLabel = sendBtn.innerText;
        const variableValues = getVariableValues();
        let processedCount = 0;
        let successCount = 0;
        const failed = [];
        output.classList.remove("error");
        setResponseTexts("Running collection...", "Running collection...", "");
        statusTag.innerText = "";
        pushBatchToast(`[${normalizedGroup}] Run All started (${entries.length} requests)`, "info", 6000);

        try {
            sendBtn.disabled = true;
            sendBtn.innerText = "Batch Running...";
            for (let i = 0; i < entries.length; i += 1) {
                if (batchAbortRequested) {
                    break;
                }
                const item = entries[i];
                const config = normalizeSavedItemConfig(item);
                const label = String(config.title || config.url || getSavedItemFileKey(item) || `request_${i + 1}`);
                try {
                    const payload = buildRequestPayloadFromConfig(config, variableValues);
                    batchAbortController = new AbortController();
                    const result = await executeRequestPayload(payload, batchAbortController.signal);
                    batchAbortController = null;
                    processedCount += 1;
                    if (result.ok) {
                        successCount += 1;
                        pushBatchToast(`[OK ${result.status}] ${label} (${result.serverDuration}ms)`, "ok");
                        statusTag.innerText = `Status: ${result.status} (${result.serverDuration}ms)`;
                        statusTag.style.color = "#28a745";
                    } else {
                        failed.push(`[${result.status}] ${label}`);
                        pushBatchToast(`[NG ${result.status}] ${label} (${result.serverDuration}ms)`, "error", 0);
                        statusTag.innerText = `Status: ${result.status} (${result.serverDuration}ms)`;
                        statusTag.style.color = "#dc3545";
                    }
                    setResponseTexts(result.rawData, result.formattedData, result.headersRaw);
                } catch (error) {
                    batchAbortController = null;
                    if (batchAbortRequested && error?.name === "AbortError") {
                        pushBatchToast(`Stopped: ${label}`, "error", 0);
                        break;
                    }
                    processedCount += 1;
                    failed.push(`[ERROR] ${label}: ${error.message}`);
                    pushBatchToast(`[ERROR] ${label}: ${error.message}`, "error", 0);
                    statusTag.innerText = "Error";
                    statusTag.style.color = "#dc3545";
                }
            }
            if (failed.length) {
                output.classList.add("error");
            }
            const totalCount = entries.length;
            const failedCount = failed.length;
            const resultType = batchAbortRequested || failedCount > 0 ? "error" : "ok";
            const summaryMessage = `[SUMMARY]\nCollection: ${normalizedGroup}\nTotal: ${totalCount}\nProcessed: ${processedCount}\nSuccess: ${successCount}\nFailed: ${failedCount}${batchAbortRequested ? "\nStopped: yes" : ""}`;
            pushBatchToast(summaryMessage, resultType, 0, "summary");
        } finally {
            sendBtn.disabled = false;
            sendBtn.innerText = originalSendLabel;
            collectionRunInProgress = false;
            batchAbortRequested = false;
            batchAbortController = null;
            updateEmergencyStopButton();
            renderSavedRequests();
        }
    }

    function getHeadersFromInputs(method, variableValues) {
        const headers = {};
        const selectedContentType = document.getElementById("contentTypeSelect").value;
        const rows = document.querySelectorAll("#headersList .header-row");
        const forbiddenHeaderNames = new Set(["content-type"]);

        if (method !== "GET" && selectedContentType) {
            headers["Content-Type"] = selectedContentType;
        }

        for (const row of rows) {
            const key = applyVariables(row.querySelector(".header-key").value.trim(), variableValues);
            const value = applyVariables(row.querySelector(".header-value").value.trim(), variableValues);
            if (!key) continue;
            if (forbiddenHeaderNames.has(key.toLowerCase())) continue;
            headers[key] = value;
        }

        return headers;
    }

    function updateMethodDependentState() {
        const method = document.getElementById("method").value;
        const contentTypeSelect = document.getElementById("contentTypeSelect");
        const contentTypeRow = document.getElementById("contentTypeRow");
        const bodyRawInput = document.getElementById("bodyRawInput");
        const bodyField = document.getElementById("bodyField");

        if (method === "GET") {
            contentTypeSelect.dataset.prevValue = contentTypeSelect.value || contentTypeSelect.dataset.prevValue || "application/x-www-form-urlencoded";
            contentTypeSelect.value = "";
            contentTypeSelect.disabled = true;
            bodyRawInput.disabled = true;
            contentTypeRow.style.display = "none";
            bodyField.style.display = "none";
            return;
        }

        contentTypeSelect.disabled = false;
        bodyRawInput.disabled = false;
        contentTypeRow.style.display = "";
        bodyField.style.display = "";
        if (!contentTypeSelect.value) {
            contentTypeSelect.value = contentTypeSelect.dataset.prevValue || "application/x-www-form-urlencoded";
        }

        updateBodyInputMode();
    }

    function updateBodyInputMode() {
        const contentType = document.getElementById("contentTypeSelect").value;
        const bodyLabel = document.getElementById("bodyLabel");
        const bodyRawInput = document.getElementById("bodyRawInput");
        const bodyKeyValueWrap = document.getElementById("bodyKeyValueWrap");

        if (contentType === "application/x-www-form-urlencoded") {
            bodyLabel.textContent = "Body (x-www-form-urlencoded: Key/Value)";
            bodyRawInput.classList.add("hidden");
            bodyKeyValueWrap.classList.remove("hidden");
            return;
        }

        bodyKeyValueWrap.classList.add("hidden");
        bodyRawInput.classList.remove("hidden");
        if (contentType === "application/json") {
            bodyLabel.textContent = "Body (JSON Raw)";
            bodyRawInput.placeholder = '{"title":"sample","completed":false}';
        } else {
            bodyLabel.textContent = "Body (Raw Text - オプション)";
            bodyRawInput.placeholder = "raw text body";
        }
    }

    function triggerBulkRequestImport() {
        document.getElementById("bulkRequestFileInput").click();
    }

    async function handleBulkRequestImport(event) {
        const files = Array.from(event.target.files || []);
        if (!files.length) return;

        currentSavedRequestFile = null;

        let firstApplied = false;
        const failedFiles = [];
        let successCount = 0;

        for (const file of files) {
            try {
                const text = await file.text();
                const config = JSON.parse(text);

                if (!firstApplied) {
                    applyRequestConfig(config);
                    firstApplied = true;
                }

                await saveImportedRequest(config, file.name, { skipReload: true });
                successCount += 1;
            } catch (error) {
                failedFiles.push(file.name);
            }
        }

        await loadSavedRequests();

        if (successCount > 0) {
            showSaveToast(
                successCount === 1
                    ? "Saved 1 request"
                    : `Saved ${successCount} requests`
            );
        }
        if (failedFiles.length) {
            alert(`Import finished.\nSucceeded: ${successCount}\nFailed: ${failedFiles.length}\n\nFailed files:\n${failedFiles.join("\n")}`);
        }
        closeTopPanels();

        event.target.value = "";
    }

    function applyRequestConfig(config) {
        const allowedMethods = new Set(["GET", "POST", "PUT", "PATCH", "DELETE"]);
        const importedMethod = String(config.method || "GET").toUpperCase();
        const method = allowedMethods.has(importedMethod) ? importedMethod : "GET";
        const url = String(config.url || config.endpoint || "");
        const title = String(config.title || config.name || "");
        const path = normalizeDirectoryPath(config.path || "");
        document.getElementById("requestTitleInput").value = title;
        setDirectoryValue(path);
        document.getElementById("method").value = method;
        document.getElementById("urlInput").value = url;

        const importedHeaders = config.headers && typeof config.headers === "object" ? config.headers : {};
        const contentTypeFromConfig =
            config.contentType ||
            config["content-type"] ||
            importedHeaders["Content-Type"] ||
            importedHeaders["content-type"] ||
            "";

        if (contentTypeFromConfig) {
            const contentTypeSelect = document.getElementById("contentTypeSelect");
            const options = Array.from(contentTypeSelect.options).map((option) => option.value);
            if (options.includes(contentTypeFromConfig)) {
                contentTypeSelect.value = contentTypeFromConfig;
            } else {
                const lowered = String(contentTypeFromConfig).toLowerCase();
                if (lowered.includes("application/json")) {
                    contentTypeSelect.value = "application/json";
                } else if (lowered.includes("application/x-www-form-urlencoded")) {
                    contentTypeSelect.value = "application/x-www-form-urlencoded";
                } else if (lowered.includes("text/plain")) {
                    contentTypeSelect.value = "text/plain";
                } else if (lowered.includes("multipart/form-data")) {
                    contentTypeSelect.value = "multipart/form-data";
                }
            }
        }

        const headerEntries = Object.entries(importedHeaders).filter(([key]) => key.toLowerCase() !== "content-type");
        setHeaderRows(headerEntries);

        let bodyForView = "";
        if (config.bodyParams && typeof config.bodyParams === "object") {
            setBodyParamRows(Object.entries(config.bodyParams));
        } else if (config.body && typeof config.body === "object" && !Array.isArray(config.body)) {
            setBodyParamRows(Object.entries(config.body));
            bodyForView = JSON.stringify(config.body, null, 2);
        } else if (typeof config.body === "string") {
            bodyForView = config.body;
            if (String(contentTypeFromConfig).toLowerCase().includes("application/json") && bodyForView.trim() !== "") {
                try {
                    bodyForView = JSON.stringify(JSON.parse(bodyForView), null, 2);
                } catch (e) {
                    // 非JSON文字列はそのまま表示
                }
            }
        } else {
            setBodyParamRows([]);
        }

        updateMethodDependentState();
        updateBodyInputMode();
        document.getElementById("bodyRawInput").value = bodyForView;
    }

    function collectCurrentConfig() {
        const idInput = currentSavedRequestFile ? String(currentSavedRequestFile).split("/").pop() : "";
        const title = document.getElementById("requestTitleInput").value.trim();
        const path = normalizeDirectoryPath(document.getElementById("directorySelect").value);
        const method = document.getElementById("method").value;
        const url = document.getElementById("urlInput").value;
        const contentType = document.getElementById("contentTypeSelect").value;
        const headers = getHeadersFromInputs(method, {});
        const bodyRaw = document.getElementById("bodyRawInput").value;
        const bodyParams = getBodyKeyValueParams({});

        const normalizedBody = normalizeBodyForStorage(contentType, bodyRaw);
        const config = { id: idInput || "", path, title, method, url, contentType, headers, body: normalizedBody };
        if (Object.keys(bodyParams).length) config.bodyParams = bodyParams;
        return config;
    }

    function normalizeBodyForStorage(contentType, body) {
        const ct = String(contentType || "").toLowerCase();
        if (!ct.includes("application/json")) {
            return typeof body === "string" ? body : "";
        }
        const raw = typeof body === "string" ? body.trim() : "";
        if (!raw) {
            return "";
        }
        try {
            // 保存時は JSON を 1 行化して \n の増加を抑える。
            return JSON.stringify(JSON.parse(raw));
        } catch (e) {
            // 入力が不正JSONなら既存挙動を壊さないため、そのまま保存する。
            return raw;
        }
    }

    async function saveCurrentRequestToHistory(source, options = {}) {
        const config = options.configOverride || collectCurrentConfig();
        const skipReload = Boolean(options.skipReload);
        const suppressSaveFeedback = Boolean(options.suppressSaveFeedback);
        const overwriteFileBase = options.overwriteFileBase != null ? String(options.overwriteFileBase) : "";
        const preferredFileBase = options.preferredFileBase != null ? String(options.preferredFileBase) : "";

        let resolvedFile = null;
        /* インポート時は overwriteFileBase / preferredFileBase で決める。currentSavedRequestFile を使うと
           一括インポート（skipReload で一覧未更新）の 2 件目以降が 1 件目のファイル名に上書きされる */
        if (resolvedFile == null && currentSavedRequestFile && source === "manual") {
            resolvedFile = currentSavedRequestFile;
        }
        if (resolvedFile == null && overwriteFileBase.trim() !== "") {
            resolvedFile = sanitizeFilePath(overwriteFileBase);
        }
        if (resolvedFile == null && typeof config?.id === "string" && config.id.trim() !== "") {
            const idPart = sanitizeFileBase(config.id);
            resolvedFile = idPart;
        }
        if (resolvedFile == null && preferredFileBase.trim() !== "") {
            resolvedFile = uniquifyFileBase(preferredFileBase);
        }
        if (resolvedFile == null) {
            resolvedFile = uniquifyFileBase(config.title || "request");
        }

        const resolvedLeafId = sanitizeFileBase(resolvedFile);
        const item = {
            ...config,
            id: resolvedLeafId,
            fileName: resolvedLeafId
        };

        try {
            const data = await apiJsonPost({ action: "storage_save", item });
            const key = data.item && (data.item.fileName || data.item.id);
            if (key) {
                currentSavedRequestFile = String(key);
            }
            if (!skipReload) {
                await loadSavedRequests();
            }
            if (!suppressSaveFeedback) {
                showSaveToast(formatSaveToastMessage(key || resolvedFile));
            }
        } catch (e) {
            alert(e instanceof Error ? e.message : String(e));
        }
    }

    async function saveImportedRequest(config, fileName, importOptions = {}) {
        const normalizedConfig = normalizeImportedConfig(config);
        const rawImportName = (fileName || "unnamed").replace(/\.json$/i, "");
        const overwriteFileBase = sanitizeFileBase(rawImportName);
        await saveCurrentRequestToHistory("import", {
            configOverride: normalizedConfig,
            skipReload: Boolean(importOptions.skipReload),
            suppressSaveFeedback: Boolean(importOptions.skipReload),
            overwriteFileBase
        });
    }

    function normalizeImportedConfig(config) {
        const id = typeof config?.id === "string" ? config.id.trim() : "";
        const path = normalizeDirectoryPath(config?.path || "");
        const method = String(config?.method || "GET").toUpperCase();
        const title = String(config?.title || config?.name || "").trim();
        const url = String(config?.url || config?.endpoint || "");
        const headers = config?.headers && typeof config.headers === "object" ? config.headers : {};
        const contentType = String(
            config?.contentType ||
            config?.["content-type"] ||
            headers["Content-Type"] ||
            headers["content-type"] ||
            ""
        );
        const bodyRaw = typeof config?.body === "string"
            ? config.body
            : (config?.body && typeof config.body === "object" && !Array.isArray(config.body))
                ? JSON.stringify(config.body, null, 2)
                : "";
        const body = normalizeBodyForStorage(contentType, bodyRaw);
        const bodyParams = config?.bodyParams && typeof config.bodyParams === "object" ? config.bodyParams : {};

        const normalized = { title, method, url, contentType, headers, body };
        if (id) normalized.id = sanitizeFileBase(id);
        normalized.path = path;
        if (Object.keys(bodyParams).length) normalized.bodyParams = bodyParams;
        return normalized;
    }

    async function saveCurrentRequestManually() {
        await saveCurrentRequestToHistory("manual");
    }

    async function loadSavedRequests() {
        try {
            const data = await apiJsonPost({ action: "storage_list" });
            const items = data.items;
            savedRequests = Array.isArray(items) ? items : [];
        } catch (e) {
            savedRequests = [];
            alert(e instanceof Error ? e.message : String(e));
        }
        refreshDirectoryOptions();
        renderSavedRequests();
    }

    function applySavedItemToForm(item) {
        currentSavedRequestFile = getSavedItemFileKey(item);
        const configToApply = normalizeSavedItemConfig(item);
        applyRequestConfig(configToApply);
        const bodyInput = document.getElementById("bodyRawInput");
        if (bodyInput && bodyInput.value === "") {
            const src = item;
            const rawBody = (src && typeof src.body === "string") ? src.body : "";
            if (rawBody !== "") {
                let bodyForView = rawBody;
                const ct = String(configToApply.contentType || src.contentType || "").toLowerCase();
                if (ct.includes("application/json")) {
                    try {
                        bodyForView = JSON.stringify(JSON.parse(rawBody), null, 2);
                    } catch (e) {
                        // そのまま表示
                    }
                }
                bodyInput.value = bodyForView;
            }
        }
        closeTopPanels();
    }

    function renderSavedRequests() {
        const list = document.getElementById("savedRequestsList");
        list.replaceChildren();

        if (!savedRequests.length) {
            const empty = document.createElement("div");
            empty.className = "saved-name";
            empty.textContent = "No saved requests";
            list.appendChild(empty);
            return;
        }

        const grouped = new Map();
        for (const item of savedRequests) {
            const key = getSavedItemFileKey(item) || "";
            if (!key) continue;
            const group = normalizeCollectionPath(item?.path || "") || "local";
            if (!grouped.has(group)) grouped.set(group, []);
            grouped.get(group).push({ item, leaf: key });
        }

        const orderedGroups = [...grouped.keys()].sort((a, b) => a.localeCompare(b));
        if (!collectionsCollapseInitialized) {
            for (const group of orderedGroups) {
                collapsedCollections.add(group);
            }
            collectionsCollapseInitialized = true;
        }

        for (const group of orderedGroups) {
            const headerRow = document.createElement("div");
            headerRow.className = "saved-collection-header";
            const toggle = document.createElement("button");
            toggle.type = "button";
            toggle.className = "saved-collection-toggle";
            const collapsed = collapsedCollections.has(group);
            toggle.setAttribute("aria-expanded", collapsed ? "false" : "true");

            const caret = document.createElement("span");
            caret.className = "saved-collection-caret";
            caret.textContent = collapsed ? "▸" : "▾";
            const label = document.createElement("span");
            label.textContent = group;
            toggle.append(caret, label);
            toggle.addEventListener("click", (event) => {
                event.stopPropagation();
                if (collapsedCollections.has(group)) {
                    collapsedCollections.delete(group);
                } else {
                    collapsedCollections.add(group);
                }
                renderSavedRequests();
            });
            headerRow.appendChild(toggle);
            const runAllBtn = document.createElement("button");
            runAllBtn.type = "button";
            runAllBtn.className = "saved-collection-run";
            runAllBtn.textContent = "Run All";
            runAllBtn.disabled = collectionRunInProgress;
            runAllBtn.addEventListener("click", async (event) => {
                event.stopPropagation();
                await runCollectionRequests(group);
            });
            headerRow.appendChild(runAllBtn);
            list.appendChild(headerRow);

            const entriesWrap = document.createElement("div");
            entriesWrap.className = "saved-collection-items";
            if (collapsed) entriesWrap.classList.add("hidden");

            const entries = grouped.get(group) || [];
            entries.sort((a, b) => a.leaf.localeCompare(b.leaf));
            for (const entry of entries) {
                const item = entry.item;
                const methodLabel = String(item?.method || "GET").toUpperCase();
                const titleLabel = String(item?.title || "").trim();
                const displayName = titleLabel
                    ? `${methodLabel} ${titleLabel}`
                    : `${methodLabel} ${item?.url || "(no title)"}`;

                const row = document.createElement("div");
                row.className = "saved-item";

                const nameEl = document.createElement("div");
                nameEl.className = "saved-name saved-name--trigger";
                nameEl.textContent = displayName;
                nameEl.setAttribute("title", `${displayName} — click to load into form`);
                nameEl.setAttribute("role", "button");
                nameEl.setAttribute("tabindex", "0");
                nameEl.setAttribute(
                    "aria-label",
                    `${displayName}, click or press Enter to load into form`
                );
                nameEl.addEventListener("click", (event) => {
                    event.stopPropagation();
                    applySavedItemToForm(item);
                });
                nameEl.addEventListener("keydown", (event) => {
                    if (event.key === "Enter" || event.key === " ") {
                        event.preventDefault();
                        event.stopPropagation();
                        applySavedItemToForm(item);
                    }
                });

                const deleteBtn = document.createElement("button");
                deleteBtn.type = "button";
                deleteBtn.textContent = "Delete";
                deleteBtn.addEventListener("click", async (event) => {
                    event.stopPropagation();
                    try {
                        const delKey = getSavedItemFileKey(item);
                        await apiJsonPost({ action: "storage_delete", fileName: delKey });
                        if (currentSavedRequestFile != null && delKey && String(currentSavedRequestFile) === String(delKey)) {
                            currentSavedRequestFile = null;
                        }
                        await loadSavedRequests();
                    } catch (e) {
                        alert(e instanceof Error ? e.message : String(e));
                    }
                });

                row.append(nameEl, deleteBtn);
                entriesWrap.appendChild(row);
            }
            list.appendChild(entriesWrap);
        }
    }

    function normalizeSavedItemConfig(item) {
        if (item && typeof item === "object") {
            const src = item;
            return {
                id: item.id || src.id || "",
                path: src.path || "",
                title: src.title || "",
                method: src.method || "GET",
                url: src.url || src.endpoint || "",
                contentType: src.contentType || src["content-type"] || "",
                headers: src.headers || {},
                body: typeof src.body === "string" ? src.body : "",
                bodyParams: src.bodyParams || {}
            };
        }
        return {};
    }

    function setHeaderRows(entries) {
        const list = document.getElementById("headersList");
        list.innerHTML = "";
        const rows = entries.length ? entries : [["", ""]];

        for (const [key, value] of rows) {
            const row = document.createElement("div");
            row.className = "header-row";
            row.innerHTML = `
                <input type="text" class="header-key" placeholder="Header Name (例: Authorization)">
                <input type="text" class="header-value" placeholder="Header Value (例: Bearer xxx)">
                <button type="button" onclick="removeHeaderRow(this)">×</button>
            `;
            row.querySelector(".header-key").value = String(key ?? "");
            row.querySelector(".header-value").value = String(value ?? "");
            list.appendChild(row);
        }
    }

    function setBodyParamRows(entries) {
        const list = document.getElementById("bodyKeyValueList");
        list.innerHTML = "";
        const rows = entries.length ? entries : [["", ""]];

        for (const [key, value] of rows) {
            const row = document.createElement("div");
            row.className = "header-row";
            row.innerHTML = `
                <input type="text" class="body-key" placeholder="Key (例: email)">
                <input type="text" class="body-value" placeholder="Value (例: user@example.com)">
                <button type="button" onclick="removeBodyRow(this)">×</button>
            `;
            row.querySelector(".body-key").value = String(key ?? "");
            row.querySelector(".body-value").value = String(value ?? "");
            list.appendChild(row);
        }
    }

    function setVariableRows(entries) {
        const list = document.getElementById("variablesList");
        list.innerHTML = "";
        const rows = entries.length ? entries : [["Email", ""]];

        for (const [key, value] of rows) {
            const row = document.createElement("div");
            row.className = "header-row";
            row.innerHTML = `
                <input type="text" class="var-key" placeholder="{{Email}}">
                <input type="text" class="var-value" placeholder="Variable Value (例: example@domain.com)">
                <button type="button" onclick="removeVariableRow(this)">×</button>
            `;
            row.querySelector(".var-key").value = wrapVariableKey(String(key ?? ""));
            row.querySelector(".var-value").value = String(value ?? "");
            list.appendChild(row);
        }
    }

    async function saveSharedVariablesNow(showError = true) {
        if (sharedVariablesLoading) {
            return;
        }
        const variables = getVariableValues();
        try {
            await apiJsonPost({ action: "variables_save", variables });
        } catch (e) {
            if (showError) {
                alert(e instanceof Error ? e.message : String(e));
            }
        }
    }

    function scheduleSharedVariablesSave() {
        if (sharedVariablesLoading) {
            return;
        }
        if (sharedVariablesSaveTimer) {
            clearTimeout(sharedVariablesSaveTimer);
        }
        sharedVariablesSaveTimer = setTimeout(() => {
            sharedVariablesSaveTimer = null;
            void saveSharedVariablesNow(false);
        }, 350);
    }

    async function loadSharedVariables() {
        sharedVariablesLoading = true;
        try {
            const data = await apiJsonPost({ action: "variables_get" });
            const vars = (data && typeof data.variables === "object" && data.variables != null)
                ? data.variables
                : {};
            setVariableRows(Object.entries(vars));
        } catch (e) {
            setVariableRows([]);
            alert(e instanceof Error ? e.message : String(e));
        } finally {
            sharedVariablesLoading = false;
        }
    }

    function getBodyKeyValueParams(variableValues) {
        const params = {};
        const rows = document.querySelectorAll("#bodyKeyValueList .header-row");
        for (const row of rows) {
            const key = applyVariables(row.querySelector(".body-key").value.trim(), variableValues);
            const value = applyVariables(row.querySelector(".body-value").value, variableValues);
            if (key) params[key] = value;
        }
        return params;
    }

    function getVariableValues() {
        const variableValues = {};
        const rows = document.querySelectorAll("#variablesList .header-row");

        for (const row of rows) {
            const key = normalizeVariableKey(row.querySelector(".var-key").value.trim());
            const value = row.querySelector(".var-value").value;
            if (key) variableValues[key] = value;
        }

        return variableValues;
    }

    function applyVariables(text, variableValues) {
        if (typeof text !== "string") return text;
        return text.replace(/\{\{(\w+)\}\}/g, (_, key) => {
            if (Object.prototype.hasOwnProperty.call(variableValues, key)) {
                return variableValues[key];
            }
            return `{{${key}}}`;
        });
    }

    function addBodyRow() {
        const list = document.getElementById("bodyKeyValueList");
        const row = document.createElement("div");
        row.className = "header-row";
        row.innerHTML = `
            <input type="text" class="body-key" placeholder="Key (例: email)">
            <input type="text" class="body-value" placeholder="Value (例: user@example.com)">
            <button type="button" onclick="removeBodyRow(this)">×</button>
        `;
        list.appendChild(row);
    }

    function removeBodyRow(button) {
        const list = document.getElementById("bodyKeyValueList");
        const rows = list.querySelectorAll(".header-row");
        if (rows.length === 1) {
            rows[0].querySelector(".body-key").value = "";
            rows[0].querySelector(".body-value").value = "";
            return;
        }
        button.parentElement.remove();
    }

    function addHeaderRow() {
        const list = document.getElementById("headersList");
        const row = document.createElement("div");
        row.className = "header-row";
        row.innerHTML = `
            <input type="text" class="header-key" placeholder="Header Name (例: Authorization)">
            <input type="text" class="header-value" placeholder="Header Value (例: Bearer xxx)">
            <button type="button" onclick="removeHeaderRow(this)">×</button>
        `;
        list.appendChild(row);
    }

    function removeHeaderRow(button) {
        const list = document.getElementById("headersList");
        const rows = list.querySelectorAll(".header-row");
        if (rows.length === 1) {
            rows[0].querySelector(".header-key").value = "";
            rows[0].querySelector(".header-value").value = "";
            return;
        }
        button.parentElement.remove();
    }

    function addVariableRow() {
        const list = document.getElementById("variablesList");
        const row = document.createElement("div");
        row.className = "header-row";
        row.innerHTML = `
            <input type="text" class="var-key" placeholder="{{Email}}">
            <input type="text" class="var-value" placeholder="Variable Value (例: example@domain.com)">
            <button type="button" onclick="removeVariableRow(this)">×</button>
        `;
        list.appendChild(row);
        scheduleSharedVariablesSave();
    }

    function removeVariableRow(button) {
        const list = document.getElementById("variablesList");
        const rows = list.querySelectorAll(".header-row");
        if (rows.length === 1) {
            rows[0].querySelector(".var-key").value = "{{Email}}";
            rows[0].querySelector(".var-value").value = "";
            scheduleSharedVariablesSave();
            return;
        }
        button.parentElement.remove();
        scheduleSharedVariablesSave();
    }

    function normalizeVariableKey(rawKey) {
        const trimmed = String(rawKey || "").trim();
        const matched = trimmed.match(/^\{\{(.+)\}\}$/);
        if (matched) return matched[1].trim();
        return trimmed;
    }

    function wrapVariableKey(key) {
        const normalized = normalizeVariableKey(key);
        return normalized ? `{{${normalized}}}` : "";
    }

    function toggleSettingsPanel() {
        const settingsPanel = document.getElementById("settingsPanel");
        const savedPanel = document.getElementById("savedPanel");
        settingsPanel.classList.toggle("hidden");
        savedPanel.classList.add("hidden");
        syncTopPanelBackdrop();
    }

    function toggleSavedPanel() {
        const settingsPanel = document.getElementById("settingsPanel");
        const savedPanel = document.getElementById("savedPanel");
        savedPanel.classList.toggle("hidden");
        settingsPanel.classList.add("hidden");
        syncTopPanelBackdrop();
    }

    function closeTopPanelsOnOutsideClick(event) {
        const topTools = document.querySelector(".top-tools");
        if (!topTools) return;
        if (topTools.contains(event.target)) return;

        closeTopPanels();
    }

    function closeTopPanels() {
        document.getElementById("settingsPanel").classList.add("hidden");
        document.getElementById("savedPanel").classList.add("hidden");
        syncTopPanelBackdrop();
    }

    function syncTopPanelBackdrop() {
        const settingsOpen = !document.getElementById("settingsPanel").classList.contains("hidden");
        const savedOpen = !document.getElementById("savedPanel").classList.contains("hidden");
        document.body.classList.toggle("menu-open", settingsOpen || savedOpen);
    }

    document.getElementById("method").addEventListener("change", updateMethodDependentState);
    document.getElementById("contentTypeSelect").addEventListener("change", updateBodyInputMode);
    document.getElementById("bulkRequestFileInput").addEventListener("change", handleBulkRequestImport);
    document.getElementById("headersViewBtn").addEventListener("click", () => setResponseViewTarget("headers"));
    document.getElementById("formattedViewBtn").addEventListener("click", () => setResponseViewMode("formatted"));
    document.getElementById("rawViewBtn").addEventListener("click", () => setResponseViewMode("raw"));
    document.getElementById("variablesList").addEventListener("input", () => {
        scheduleSharedVariablesSave();
    });
    document.addEventListener("click", closeTopPanelsOnOutsideClick);
    updateMethodDependentState();
    updateBodyInputMode();
    void loadSharedVariables();
    void loadSavedRequests();
</script>

</body>
</html>