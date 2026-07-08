<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$input = read_json_body();
$code = strtoupper(trim((string)($input['code'] ?? '')));
$token = (string)($input['token'] ?? '');
$message = trim((string)($input['message'] ?? ''));

if ($code === '' || $token === '') json_error('Missing code or token.');
if ($message === '') json_error('Message is empty.');
if (mb_strlen($message) > 200) $message = mb_substr($message, 0, 200);

$pdo = get_db();

$room = fetch_room_by_code($pdo, $code);
if (!$room) json_error('Room not found.', 404);

$player = fetch_player_by_token($pdo, (int)$room['id'], $token);
if (!$player) json_error('Player not found.', 404);

try {
    $stmt = $pdo->prepare('INSERT INTO chat_messages (room_id, seat, name, message) VALUES (?, ?, ?, ?)');
    $stmt->execute([(int)$room['id'], (int)$player['seat'], $player['name'], $message]);
} catch (Throwable $e) {
    json_error('Chat is not set up yet — ask the host to run the latest DB migration.', 500);
}

json_response(['ok' => true]);
