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
    if ($room['status'] !== 'playing') { $pdo->rollBack(); json_error('Game is not in progress.'); }

    $player = fetch_player_by_token($pdo, (int)$room['id'], $token, true);
    if (!$player) { $pdo->rollBack(); json_error('Player not found.', 404); }
    if ((int)$room['turn_seat'] !== (int)$player['seat']) { $pdo->rollBack(); json_error("It's not your turn."); }
    if (!(bool)$room['awaiting_show']) { $pdo->rollBack(); json_error('There is no show decision pending.'); }

    $players = fetch_players($pdo, (int)$room['id'], true);
    $nextSeat = next_active_seat($players, (int)$player['seat']);

    $stmt = $pdo->prepare('UPDATE rooms SET awaiting_show = 0, awaiting_draw = 0, turn_seat = ?, turn_deadline = ? WHERE id = ?');
    $stmt->execute([$nextSeat, new_turn_deadline(), $room['id']]);

    log_event($pdo, (int)$room['id'], "{$player['name']} stayed called and continued.");

    $pdo->commit();
    json_response(['ok' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Failed to continue.', 500);
}
