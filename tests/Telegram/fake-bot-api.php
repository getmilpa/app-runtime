<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

/*
 * A LOCAL STAND-IN FOR TELEGRAM'S BOT API. It is not Telegram and proves nothing about Telegram's own behaviour:
 * it answers the methods this house calls in the shape the Bot API documents, and keeps what it was told so a
 * test can read it back. Run as a router: `FAKE_BOT_TOKEN=… FAKE_BOT_STATE=/path/state.json php -S 127.0.0.1:PORT fake-bot-api.php`.
 *
 *   POST /bot<token>/sendMessage | editMessageText | answerCallbackQuery | setWebhook | getUpdates
 *   POST /__inject   an update (e.g. {"callback_query": {...}}): queued for getUpdates, and delivered to the
 *                    webhook when one was set — with the secret header unless the body says "secret": false
 *   GET  /__state    everything it holds
 */
$token = (string) getenv('FAKE_BOT_TOKEN');
$file = (string) getenv('FAKE_BOT_STATE');
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], \PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];

$lock = fopen($file . '.lock', 'c');
flock($lock, \LOCK_EX);
$state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
$state = is_array($state) ? $state : ['fake' => true, 'next' => 1, 'messages' => [], 'calls' => [], 'updates' => [], 'webhook' => null, 'deliveries' => []];

$answer = static function (int $status, array $json) use (&$state, $file, $lock): void {
    file_put_contents($file, json_encode($state, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    flock($lock, \LOCK_UN);
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($json);
};

if ($path === '/__state') {
    $answer(200, $state);

    return;
}

if ($path === '/__inject') {
    $withSecret = ($body['secret'] ?? true) !== false;
    unset($body['secret']);
    $update = ['update_id' => count($state['updates']) + 1] + $body;
    $state['updates'][] = $update;
    $delivery = null;
    if (is_array($state['webhook'])) {
        $headers = "Content-Type: application/json\r\n";
        if ($withSecret && ($state['webhook']['secret_token'] ?? '') !== '') {
            $headers .= 'X-Telegram-Bot-Api-Secret-Token: ' . $state['webhook']['secret_token'] . "\r\n";
        }
        @file_get_contents((string) $state['webhook']['url'], false, stream_context_create(['http' => [
            'method' => 'POST', 'header' => $headers, 'content' => json_encode($update), 'ignore_errors' => true, 'timeout' => 5,
        ]]));
        $delivery = ['update_id' => $update['update_id'], 'status' => $http_response_header[0] ?? 'no answer', 'secret_sent' => $withSecret];
        $state['deliveries'][] = $delivery;
    }
    $answer(200, ['ok' => true, 'update' => $update, 'delivery' => $delivery]);

    return;
}

if (preg_match('~^/bot([^/]+)/(\w+)$~', $path, $m) !== 1) {
    $answer(404, ['ok' => false, 'error_code' => 404, 'description' => 'Not Found']);

    return;
}
if ($token === '' || !hash_equals($token, $m[1])) {
    $answer(401, ['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized']);

    return;
}

$method = $m[2];
$state['calls'][] = ['method' => $method, 'body' => $body];
switch ($method) {
    case 'sendMessage':
        if (!isset($body['chat_id'], $body['text']) || getenv('FAKE_BOT_REFUSE') === $method) {
            $answer(400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request']);

            return;
        }
        $id = $state['next']++;
        $state['messages'][(string) $id] = ['chat_id' => (string) $body['chat_id'], 'text' => $body['text'], 'reply_markup' => $body['reply_markup'] ?? null, 'edits' => 0];
        $answer(200, ['ok' => true, 'result' => ['message_id' => $id, 'chat' => ['id' => $body['chat_id']], 'text' => $body['text']]]);

        return;
    case 'editMessageText':
        $id = (string) ($body['message_id'] ?? '');
        if (!isset($state['messages'][$id]) || $state['messages'][$id]['chat_id'] !== (string) ($body['chat_id'] ?? '')) {
            $answer(400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message to edit not found']);

            return;
        }
        $state['messages'][$id]['text'] = $body['text'] ?? '';
        $state['messages'][$id]['reply_markup'] = $body['reply_markup'] ?? null;
        ++$state['messages'][$id]['edits'];
        $answer(200, ['ok' => true, 'result' => ['message_id' => (int) $id]]);

        return;
    case 'answerCallbackQuery':
        $answer(200, ['ok' => true, 'result' => true]);

        return;
    case 'setWebhook':
        $state['webhook'] = ['url' => (string) ($body['url'] ?? ''), 'secret_token' => (string) ($body['secret_token'] ?? '')];
        $answer(200, ['ok' => true, 'result' => true]);

        return;
    case 'getUpdates':
        $answer(200, ['ok' => true, 'result' => $state['updates']]);

        return;
    default:
        $answer(404, ['ok' => false, 'error_code' => 404, 'description' => 'Not Found: method not found']);
}
