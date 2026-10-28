<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/actions.php';

$input = read_json_body();
$code = strtoupper(trim((string)($input['code'] ?? '')));
$token = (string)($input['token'] ?? '');
$source = (string)($input['source'] ?? '');
$cardId = (string)($input['card_id'] ?? '');
if (!in_array($source, ['stock', 'table', 'left', 'right'], true)) json_error('Invalid draw source.');
if ($source === 'table' && $cardId === '') json_error('Missing card_id.');

$pdo = get_db();
$pdo->beginTransaction();
try {
    $room = fetch_room_by_code($pdo, $code, true);
    if (!$room) { $pdo->rollBack(); json_error('Room not found.', 404); }

    $player = fetch_player_by_token($pdo, (int)$room['id'], $token, true);
    if (!$player) { $pdo->rollBack(); json_error('Player not found.', 404); }

    $error = kolshi_draw($pdo, $room, $player, $source, $cardId);
    if ($error !== null) { $pdo->rollBack(); json_error($error); }

    $pdo->commit();
    json_response(['ok' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Failed to draw.', 500);
}
