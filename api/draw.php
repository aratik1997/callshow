<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$input = read_json_body();
$code = strtoupper(trim((string)($input['code'] ?? '')));
$token = (string)($input['token'] ?? '');
$source = (string)($input['source'] ?? '');
$cardId = (string)($input['card_id'] ?? '');
if (!in_array($source, ['stock', 'table', 'left', 'right'], true)) json_error('Invalid draw source.');
if ($source === 'table' && $cardId === '') json_error('Missing card_id.');

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
    if (!(bool)$room['awaiting_draw']) { $pdo->rollBack(); json_error('Throw a card (or combo) first.'); }

    $drawPile = json_decode($room['draw_pile'], true) ?: [];
    $discardPile = json_decode($room['discard_pile'], true) ?: [];
    // This is the pile left on the table by the previous player — never this
    // player's own throw, which is staged separately in pending_throw.
    $currentThrow = $room['current_throw'] ? (json_decode($room['current_throw'], true) ?: []) : [];
    $pendingThrow = $room['pending_throw'] ? (json_decode($room['pending_throw'], true) ?: []) : [];

    $drawnCard = null;

    if ($source === 'stock') {
        if (count($drawPile) === 0) {
            // Reshuffle the resolved discard history into a fresh stock pile.
            // The live current_throw (previous player's combo) stays on the table.
            if (count($discardPile) === 0) {
                $pdo->rollBack();
                json_error('No cards left to draw.');
            }
            shuffle($discardPile);
            $drawPile = $discardPile;
            $discardPile = [];
        }
        $drawnCard = array_pop($drawPile);
    } elseif ($source === 'table') {
        if (count($currentThrow) === 0) { $pdo->rollBack(); json_error('Nothing to draw from the table.'); }
        $idx = null;
        foreach ($currentThrow as $i => $c) {
            if ($c['id'] === $cardId) { $idx = $i; break; }
        }
        if ($idx === null) { $pdo->rollBack(); json_error('That card is no longer on the table.'); }
        $drawnCard = array_splice($currentThrow, $idx, 1)[0];
    } elseif ($source === 'left') {
        if (count($currentThrow) === 0) { $pdo->rollBack(); json_error('Nothing to draw from the table.'); }
        $drawnCard = array_shift($currentThrow);
    } else { // right
        if (count($currentThrow) === 0) { $pdo->rollBack(); json_error('Nothing to draw from the table.'); }
        $drawnCard = array_pop($currentThrow);
    }

    $hand = json_decode($player['hand'], true) ?: [];
    $hand[] = $drawnCard;

    $stmt = $pdo->prepare('UPDATE players SET hand = ? WHERE id = ?');
    $stmt->execute([json_encode($hand), $player['id']]);

    // Turn is over: whatever's left of the old table pile becomes permanent
    // history, and this player's own throw is now revealed for the next player.
    foreach ($currentThrow as $c) $discardPile[] = $c;

    $srcLabel = $source === 'stock' ? 'the deck' : 'the table';
    log_event($pdo, (int)$room['id'], "{$player['name']} drew from $srcLabel.");

    // Evaluate call/show status now that this turn's hand is final.
    $handValue = hand_value($hand);
    $hadCalled = (bool)$player['has_called'];
    $showPending = false;

    if ($hadCalled) {
        if ($handValue >= CALL_THRESHOLD) {
            $stmt = $pdo->prepare('UPDATE players SET has_called = 0 WHERE id = ?');
            $stmt->execute([$player['id']]);
            log_event($pdo, (int)$room['id'], "{$player['name']}'s call is off (hand back up to $handValue).");
        } else {
            $showPending = true;
        }
    } elseif ($handValue < CALL_THRESHOLD) {
        $stmt = $pdo->prepare('UPDATE players SET has_called = 1 WHERE id = ?');
        $stmt->execute([$player['id']]);
        log_event($pdo, (int)$room['id'], "{$player['name']} has called! (hand: $handValue)");
    }

    if ($showPending) {
        $stmt = $pdo->prepare(
            'UPDATE rooms SET draw_pile = ?, discard_pile = ?, current_throw = ?, pending_throw = NULL,
             awaiting_draw = 0, awaiting_show = 1 WHERE id = ?'
        );
        $stmt->execute([json_encode($drawPile), json_encode($discardPile), json_encode($pendingThrow), $room['id']]);
    } else {
        $players = fetch_players($pdo, (int)$room['id'], true);
        $nextSeat = next_active_seat($players, (int)$player['seat']);

        $stmt = $pdo->prepare(
            'UPDATE rooms SET draw_pile = ?, discard_pile = ?, current_throw = ?, pending_throw = NULL,
             awaiting_draw = 0, awaiting_show = 0, turn_seat = ?, turn_deadline = ? WHERE id = ?'
        );
        $stmt->execute([
            json_encode($drawPile), json_encode($discardPile), json_encode($pendingThrow),
            $nextSeat, new_turn_deadline(), $room['id'],
        ]);
    }

    $pdo->commit();
    json_response(['ok' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Failed to draw.', 500);
}
