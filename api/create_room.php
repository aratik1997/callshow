<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$input = read_json_body();
$name = clean_name((string)($input['name'] ?? ''));

$pdo = get_db();
$pdo->beginTransaction();
try {
    do {
        $code = gen_room_code();
        $stmt = $pdo->prepare('SELECT id FROM rooms WHERE code = ?');
        $stmt->execute([$code]);
    } while ($stmt->fetch());

    $stmt = $pdo->prepare('INSERT INTO rooms (code, status) VALUES (?, "waiting")');
    $stmt->execute([$code]);
    $roomId = (int)$pdo->lastInsertId();

    $token = gen_token();
    $stmt = $pdo->prepare(
        'INSERT INTO players (room_id, token, name, seat, hand, score, is_host) VALUES (?,?,?,0,"[]",0,1)'
    );
    $stmt->execute([$roomId, $token, $name]);

    log_event($pdo, $roomId, "$name created the room.");

    $pdo->commit();
    json_response(['ok' => true, 'room_code' => $code, 'token' => $token]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Failed to create room.', 500);
}
