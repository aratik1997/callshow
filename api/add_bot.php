<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/bots.php';

$input = read_json_body();
$code = strtoupper(trim((string)($input['code'] ?? '')));
$token = (string)($input['token'] ?? '');
$difficulty = strtolower(trim((string)($input['difficulty'] ?? 'medium')));

if (!in_array($difficulty, BOT_DIFFICULTIES, true)) json_error('Bot difficulty must be easy, medium or hard.');

$pdo = get_db();
$pdo->beginTransaction();
try {
    $room = fetch_room_by_code($pdo, $code, true);
    if (!$room) { $pdo->rollBack(); json_error('Room not found.', 404); }

    $host = fetch_player_by_token($pdo, (int)$room['id'], $token, true);
    if (!$host) { $pdo->rollBack(); json_error('Player not found.', 404); }
    if (!$host['is_host']) { $pdo->rollBack(); json_error('Only the host can add bots.'); }
    if ($room['status'] !== 'waiting') { $pdo->rollBack(); json_error('Bots can only be added before the game starts.'); }

    $players = fetch_players($pdo, (int)$room['id'], true);
    if (count($players) >= MAX_PLAYERS) { $pdo->rollBack(); json_error('This room is full (' . MAX_PLAYERS . ' players max).'); }

    // The first "Bot N" name nobody in the room is using.
    $taken = array_map(fn($p) => mb_strtolower($p['name']), $players);
    $number = 1;
    while (in_array(mb_strtolower("Bot {$number}"), $taken, true)) {
        $number++;
    }
    $name = "Bot {$number}";

    $nextSeat = 0;
    foreach ($players as $p) {
        $nextSeat = max($nextSeat, (int)$p['seat'] + 1);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO players (room_id, token, name, seat, hand, score, is_host, is_bot, bot_difficulty) VALUES (?,?,?,?,"[]",0,0,1,?)'
    );
    $stmt->execute([$room['id'], gen_token(), $name, $nextSeat, $difficulty]);

    log_event($pdo, (int)$room['id'], "{$name} joined the table.");

    $pdo->commit();
    json_response(['ok' => true, 'name' => $name]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Failed to add bot.', 500);
}
