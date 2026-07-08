<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$input = read_json_body();
$code = strtoupper(trim((string)($input['code'] ?? '')));
$token = (string)($input['token'] ?? '');
$cardIds = $input['card_ids'] ?? null;
if (!is_array($cardIds) || count($cardIds) === 0) json_error('No cards selected.');

$pdo = get_db();
$pdo->beginTransaction();
try {
    $room = fetch_room_by_code($pdo, $code, true);
    if (!$room) { $pdo->rollBack(); json_error('Room not found.', 404); }
    if ($room['status'] !== 'playing') { $pdo->rollBack(); json_error('Game is not in progress.'); }

    $player = fetch_player_by_token($pdo, (int)$room['id'], $token, true);
    if (!$player) { $pdo->rollBack(); json_error('Player not found.', 404); }
    if ((int)$room['turn_seat'] !== (int)$player['seat']) { $pdo->rollBack(); json_error("It's not your turn."); }
    if ((bool)$room['awaiting_show']) { $pdo->rollBack(); json_error('Decide whether to show first.'); }
    if ((bool)$room['awaiting_draw']) { $pdo->rollBack(); json_error('You already threw — draw a card to finish your turn.'); }

    $hand = json_decode($player['hand'], true) ?: [];
    $handById = [];
    foreach ($hand as $c) $handById[$c['id']] = $c;

    $thrown = [];
    foreach ($cardIds as $id) {
        if (!isset($handById[$id])) { $pdo->rollBack(); json_error('Invalid card selection.'); }
        $thrown[] = $handById[$id];
    }
    if (count(array_unique($cardIds)) !== count($cardIds)) { $pdo->rollBack(); json_error('Duplicate card in selection.'); }

    $comboType = validate_combo($thrown);
    if ($comboType === false) { $pdo->rollBack(); json_error('That is not a valid single, set, or run.'); }

    $thrownIds = array_flip($cardIds);
    $remainingHand = array_values(array_filter($hand, fn($c) => !isset($thrownIds[$c['id']])));

    $stmt = $pdo->prepare('UPDATE players SET hand = ? WHERE id = ?');
    $stmt->execute([json_encode($remainingHand), $player['id']]);

    // Stage the throw — it isn't drawable yet. It stays hidden from the table
    // (and from this player's own upcoming draw) until they draw to end their
    // turn, at which point it becomes the next player's pickup row.
    $stmt = $pdo->prepare('UPDATE rooms SET pending_throw = ?, awaiting_draw = 1 WHERE id = ?');
    $stmt->execute([json_encode($thrown), $room['id']]);

    $label = $comboType === 'single' ? '1 card' : ($comboType . ' of ' . count($thrown));
    log_event($pdo, (int)$room['id'], "{$player['name']} threw a $label.");

    $pdo->commit();
    json_response(['ok' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Failed to discard.', 500);
}
