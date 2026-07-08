<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$input = read_json_body();
$name = clean_name((string)($input['name'] ?? ''));
$code = strtoupper(trim((string)($input['code'] ?? '')));
if ($code === '') json_error('Room code required.');

$pdo = get_db();
$pdo->beginTransaction();
try {
    $room = fetch_room_by_code($pdo, $code, true);
    if (!$room) {
        $pdo->rollBack();
        json_error('Room not found.', 404);
    }
    if ($room['status'] === 'finished') {
        $pdo->rollBack();
        json_error('This game has already finished.');
    }

    $players = fetch_players($pdo, (int)$room['id'], true);
    if (count($players) >= MAX_PLAYERS) {
        $pdo->rollBack();
        json_error('Room is full.');
    }

    $nextSeat = 0;
    foreach ($players as $p) {
        $nextSeat = max($nextSeat, (int)$p['seat'] + 1);
    }

    $token = gen_token();
    $stmt = $pdo->prepare(
        'INSERT INTO players (room_id, token, name, seat, hand, score, is_host) VALUES (?,?,?,?,"[]",0,0)'
    );
    $stmt->execute([$room['id'], $token, $name, $nextSeat]);

    if ($room['status'] === 'waiting') {
        log_event($pdo, (int)$room['id'], "$name joined the room.");
    } else {
        log_event($pdo, (int)$room['id'], "$name joined and will play from the next round.");
    }

    $pdo->commit();
    json_response(['ok' => true, 'room_code' => $code, 'token' => $token]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Failed to join room.', 500);
}
