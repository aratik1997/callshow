<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

// Anyone in the room can ring the bell at another player to taunt them.
// Cooldown is enforced here (room-wide, not per-player) so it can't be
// spammed even by several different players taking turns.

$input = read_json_body();
$code = strtoupper(trim((string)($input['code'] ?? '')));
$token = (string)($input['token'] ?? '');
$targetSeat = $input['target_seat'] ?? null;

$pdo = get_db();
$pdo->beginTransaction();
try {
    $room = fetch_room_by_code($pdo, $code, true);
    if (!$room) { $pdo->rollBack(); json_error('Room not found.', 404); }

    $player = fetch_player_by_token($pdo, (int)$room['id'], $token, true);
    if (!$player) { $pdo->rollBack(); json_error('Player not found.', 404); }

    if (!empty($room['bell_rung_at']) && strtotime($room['bell_rung_at']) > time() - BELL_COOLDOWN_SECONDS) {
        $pdo->rollBack();
        json_error('The bell just rang — wait a moment.');
    }

    $targetName = null;
    $targetSeatInt = null;
    if ($targetSeat !== null) {
        $players = fetch_players($pdo, (int)$room['id'], true);
        foreach ($players as $p) {
            if ((int)$p['seat'] === (int)$targetSeat) { $targetName = $p['name']; $targetSeatInt = (int)$p['seat']; break; }
        }
    }

    $stmt = $pdo->prepare(
        'UPDATE rooms SET bell_rung_at = CURRENT_TIMESTAMP, bell_rung_by = ?, bell_target = ?, bell_target_seat = ? WHERE id = ?'
    );
    $stmt->execute([$player['name'], $targetName, $targetSeatInt, $room['id']]);

    $logMsg = $targetName ? "{$player['name']} rang the bell at $targetName." : "{$player['name']} rang the bell.";
    log_event($pdo, (int)$room['id'], $logMsg);

    $pdo->commit();
    json_response(['ok' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Failed to ring the bell.', 500);
}
