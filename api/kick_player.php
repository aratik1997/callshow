<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$input = read_json_body();
$code = strtoupper(trim((string)($input['code'] ?? '')));
$token = (string)($input['token'] ?? '');
$targetSeat = $input['seat'] ?? null;
if (!is_int($targetSeat) && !(is_string($targetSeat) && $targetSeat !== '')) json_error('Missing target seat.');

$pdo = get_db();
$pdo->beginTransaction();
try {
    $room = fetch_room_by_code($pdo, $code, true);
    if (!$room) { $pdo->rollBack(); json_error('Room not found.', 404); }

    $host = fetch_player_by_token($pdo, (int)$room['id'], $token, true);
    if (!$host) { $pdo->rollBack(); json_error('Player not found.', 404); }
    if (!$host['is_host']) { $pdo->rollBack(); json_error('Only the host can kick players.'); }

    $players = fetch_players($pdo, (int)$room['id'], true);
    $target = null;
    foreach ($players as $p) {
        if ((int)$p['seat'] === (int)$targetSeat) { $target = $p; break; }
    }
    if (!$target) { $pdo->rollBack(); json_error('Player not found.', 404); }
    if ((int)$target['id'] === (int)$host['id']) { $pdo->rollBack(); json_error("You can't kick yourself."); }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO kicks (token, kicked_by) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE kicked_by = VALUES(kicked_by), created_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$target['token'], $host['name']]);
    } catch (Throwable $e) {
        // kicks table may not exist yet (migration not run) — the kick itself
        // should still go through, just without the "kicked by X" message.
    }

    remove_player($pdo, $room, $target, "{$target['name']} was kicked by the host.");

    $stmt = $pdo->prepare(
        'UPDATE rooms SET last_kick_at = CURRENT_TIMESTAMP, last_kick_by = ?, last_kicked_name = ? WHERE id = ?'
    );
    $stmt->execute([$host['name'], $target['name'], $room['id']]);

    $pdo->commit();
    json_response(['ok' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Failed to kick player.', 500);
}
