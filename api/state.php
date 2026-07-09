<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$code = strtoupper(trim((string)($_GET['code'] ?? '')));
$token = (string)($_GET['token'] ?? '');
if ($code === '' || $token === '') json_error('Missing code or token.');

$pdo = get_db();

$room = fetch_room_by_code($pdo, $code);
if (!$room) json_error('Room not found.', 404);

auto_resolve_expired_turn($pdo, (int)$room['id']);

// Re-fetch — auto-play may have just changed the room/turn state above.
$room = fetch_room_by_code($pdo, $code);
if (!$room) json_error('Room not found.', 404);

$me = fetch_player_by_token($pdo, (int)$room['id'], $token);
if (!$me) {
    $kickedBy = null;
    try {
        $kickStmt = $pdo->prepare('SELECT kicked_by FROM kicks WHERE token = ?');
        $kickStmt->execute([$token]);
        $kickRow = $kickStmt->fetch();
        if ($kickRow) {
            $kickedBy = $kickRow['kicked_by'];
            $pdo->prepare('DELETE FROM kicks WHERE token = ?')->execute([$token]);
        }
    } catch (Throwable $e) {
        // kicks table may not exist yet (migration not run) — fall through to the generic message.
    }
    if ($kickedBy !== null) json_error("$kickedBy kicked you from this room.", 403);
    json_error('Player not found.', 404);
}

$stmt = $pdo->prepare('UPDATE players SET last_seen = CURRENT_TIMESTAMP WHERE id = ?');
$stmt->execute([$me['id']]);

$players = fetch_players($pdo, (int)$room['id']);

$playersOut = [];
foreach ($players as $p) {
    $hand = json_decode($p['hand'], true) ?: [];
    $playersOut[] = [
        'seat'         => (int)$p['seat'],
        'name'         => $p['name'],
        'is_host'      => (bool)$p['is_host'],
        'is_you'       => $p['token'] === $token,
        'connected'    => (strtotime($p['last_seen']) >= time() - 20),
        'card_count'   => count($hand),
        'score'        => (int)$p['score'],
        'eliminated'   => (bool)$p['eliminated'],
        'has_called'   => (bool)$p['has_called'],
        'spectating'   => $room['status'] !== 'waiting' && is_spectator($p),
    ];
}

$chatOut = [];
try {
    $chatStmt = $pdo->prepare(
        'SELECT id, seat, name, message, UNIX_TIMESTAMP(created_at) * 1000 AS ts
         FROM chat_messages WHERE room_id = ? ORDER BY id DESC LIMIT 40'
    );
    $chatStmt->execute([(int)$room['id']]);
    $chatOut = array_map(function ($r) {
        return [
            'id'      => (int)$r['id'],
            'seat'    => (int)$r['seat'],
            'name'    => $r['name'],
            'message' => $r['message'],
            'ts'      => (int)$r['ts'],
        ];
    }, array_reverse($chatStmt->fetchAll()));
} catch (Throwable $e) {
    // chat_messages table may not exist yet (migration not run) — don't let
    // that break the whole game poll, just show no chat history for now.
}

$myHand = json_decode($me['hand'], true) ?: [];
$currentThrow = $room['current_throw'] ? (json_decode($room['current_throw'], true) ?: []) : [];
$pendingThrow = $room['pending_throw'] ? (json_decode($room['pending_throw'], true) ?: []) : [];
$discardPile = json_decode($room['discard_pile'], true) ?: [];
$drawPile = json_decode($room['draw_pile'], true) ?: [];

$response = [
    'ok'               => true,
    'room_code'        => $room['code'],
    'status'           => $room['status'],
    'round_number'     => (int)$room['round_number'],
    'yaniv_threshold'  => (int)$room['yaniv_threshold'],
    'players'          => $playersOut,
    'your_seat'        => (int)$me['seat'],
    'your_hand'        => $myHand,
    'your_hand_value'  => hand_value($myHand),
    'your_score'       => (int)$me['score'],
    'your_eliminated'  => (bool)$me['eliminated'],
    'is_spectating'    => $room['status'] !== 'waiting' && is_spectator($me),
    'is_host'          => (bool)$me['is_host'],
    'turn_seat'        => (int)$room['turn_seat'],
    'is_your_turn'     => (int)$room['turn_seat'] === (int)$me['seat'] && !$me['eliminated'],
    'awaiting_draw'    => (bool)$room['awaiting_draw'],
    'awaiting_show'    => (bool)$room['awaiting_show'],
    'has_called'       => (bool)$me['has_called'],
    'discard_top'      => array_slice($discardPile, -6),
    'current_throw'    => $currentThrow,
    'pending_throw'    => $pendingThrow,
    'draw_count'       => count($drawPile),
    'turn_deadline'    => $room['turn_deadline'] ? (strtotime($room['turn_deadline']) * 1000) : null,
    'chat'             => $chatOut,
    'bell_at'          => !empty($room['bell_rung_at']) ? (strtotime($room['bell_rung_at']) * 1000) : null,
    'bell_by'          => $room['bell_rung_by'] ?? null,
    'bell_target'      => $room['bell_target'] ?? null,
    'bell_target_seat' => isset($room['bell_target_seat']) ? (int)$room['bell_target_seat'] : null,
    'last_kick_at'      => !empty($room['last_kick_at']) ? (strtotime($room['last_kick_at']) * 1000) : null,
    'last_kick_by'      => $room['last_kick_by'] ?? null,
    'last_kicked_name'  => $room['last_kicked_name'] ?? null,
];

if ($room['status'] === 'round_end' || $room['status'] === 'finished') {
    $response['last_round_result'] = $room['last_round_result'] ? json_decode($room['last_round_result'], true) : null;
}
if ($room['status'] === 'finished') {
    $response['winner_name'] = $room['winner_name'];
    $response['round_history'] = $room['round_history'] ? json_decode($room['round_history'], true) : [];
}

json_response($response);
