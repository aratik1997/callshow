<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

// Lets the host abandon the round in progress and immediately deal a fresh
// one — no score is recorded for the discarded round. Anyone who joined
// mid-round as a spectator gets dealt in, same as a normal round transition.

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
    if (!$player['is_host']) { $pdo->rollBack(); json_error('Only the host can start a new round.'); }
    if ($room['status'] !== 'playing') { $pdo->rollBack(); json_error('No round is in progress.'); }

    start_fresh_round($pdo, (int)$room['id']);

    log_event($pdo, (int)$room['id'], 'Host discarded this round and started a new one.');

    $pdo->commit();
    json_response(['ok' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Failed to start a new round.', 500);
}
