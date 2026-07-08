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
    if (!$player['is_host']) { $pdo->rollBack(); json_error('Only the host can start the next round.'); }
    if ($room['status'] !== 'round_end') { $pdo->rollBack(); json_error('Round has not ended yet.'); }

    $players = fetch_players($pdo, (int)$room['id'], true);
    $startSeat = next_active_seat($players, (int)$room['turn_seat']);
    deal_new_round($pdo, (int)$room['id'], $players, $startSeat);

    $stmt = $pdo->prepare('UPDATE rooms SET round_number = round_number + 1 WHERE id = ?');
    $stmt->execute([$room['id']]);

    log_event($pdo, (int)$room['id'], 'Next round has started.');

    $pdo->commit();
    json_response(['ok' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Failed to start next round.', 500);
}
