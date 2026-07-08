<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$input = read_json_body();
$code = strtoupper(trim((string)($input['code'] ?? '')));
$token = (string)($input['token'] ?? '');

$pdo = get_db();
$pdo->beginTransaction();
try {
    $room = fetch_room_by_code($pdo, $code, true);
    if (!$room) { $pdo->rollBack(); json_error('Room not found.', 404); }

    $player = fetch_player_by_token($pdo, (int)$room['id'], $token, true);
    if (!$player) { $pdo->rollBack(); json_error('Player not found.', 404); }
    if (!$player['is_host']) { $pdo->rollBack(); json_error('Only the host can start the game.'); }
    if ($room['status'] !== 'waiting') { $pdo->rollBack(); json_error('Game already started.'); }

    $players = fetch_players($pdo, (int)$room['id'], true);
    if (count($players) < 2) { $pdo->rollBack(); json_error('Need at least 2 players to start.'); }

    $startSeat = $players[array_rand($players)]['seat'];
    deal_new_round($pdo, (int)$room['id'], $players, (int)$startSeat);
    $stmt = $pdo->prepare('UPDATE rooms SET round_number = 1, round_history = "[]" WHERE id = ?');
    $stmt->execute([$room['id']]);

    log_event($pdo, (int)$room['id'], 'The game has started!');

    $pdo->commit();
    json_response(['ok' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Failed to start game.', 500);
}
