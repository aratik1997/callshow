<?php
/**
 * The four things a player can do on their turn: throw a combo, draw, show, or pass the show decision.
 * Human endpoints (discard/draw/show/pass_show.php) and bot turns both go through these, so a bot's
 * move is validated and scored exactly like a person's.
 *
 * Every function expects the caller to already hold a transaction with the room row (and the acting
 * player row) locked FOR UPDATE. Each returns null on success, or the error message to show.
 */

/** Throws the given card ids as one combo, staging them until the player draws. */
function kolshi_throw(PDO $pdo, array $room, array $player, array $cardIds): ?string {
    if ($room['status'] !== 'playing') return 'Game is not in progress.';
    if ((int)$room['turn_seat'] !== (int)$player['seat']) return "It's not your turn.";
    if ((bool)$room['awaiting_show']) return 'Decide whether to show first.';
    if ((bool)$room['awaiting_draw']) return 'You already threw — draw a card to finish your turn.';

    $hand = json_decode($player['hand'], true) ?: [];
    $handById = [];
    foreach ($hand as $c) $handById[$c['id']] = $c;

    $thrown = [];
    foreach ($cardIds as $id) {
        if (!isset($handById[$id])) return 'Invalid card selection.';
        $thrown[] = $handById[$id];
    }
    if (count(array_unique($cardIds)) !== count($cardIds)) return 'Duplicate card in selection.';

    $comboType = validate_combo($thrown);
    if ($comboType === false) return 'That is not a valid single, set, or run.';

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
    return null;
}

/** Draws from the stock or from the table (the previous player's throw), then ends the turn. */
function kolshi_draw(PDO $pdo, array $room, array $player, string $source, string $cardId): ?string {
    if ($room['status'] !== 'playing') return 'Game is not in progress.';
    if ((int)$room['turn_seat'] !== (int)$player['seat']) return "It's not your turn.";
    if ((bool)$room['awaiting_show']) return 'Decide whether to show first.';
    if (!(bool)$room['awaiting_draw']) return 'Throw a card (or combo) first.';

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
            if (count($discardPile) === 0) return 'No cards left to draw.';
            shuffle($discardPile);
            $drawPile = $discardPile;
            $discardPile = [];
        }
        $drawnCard = array_pop($drawPile);
    } elseif ($source === 'table') {
        if (count($currentThrow) === 0) return 'Nothing to draw from the table.';
        $idx = null;
        foreach ($currentThrow as $i => $c) {
            if ($c['id'] === $cardId) { $idx = $i; break; }
        }
        if ($idx === null) return 'That card is no longer on the table.';
        $drawnCard = array_splice($currentThrow, $idx, 1)[0];
    } elseif ($source === 'left') {
        if (count($currentThrow) === 0) return 'Nothing to draw from the table.';
        $drawnCard = array_shift($currentThrow);
    } else { // right
        if (count($currentThrow) === 0) return 'Nothing to draw from the table.';
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
    return null;
}

/** The caller calls Show: every hand is scored and the round (or game) ends. */
function kolshi_show(PDO $pdo, array $room, array $caller): ?string {
    if ($room['status'] !== 'playing') return 'Game is not in progress.';
    if ((int)$room['turn_seat'] !== (int)$caller['seat']) return "It's not your turn.";
    if (!(bool)$room['awaiting_show']) return 'You cannot show right now.';

    $callerHand = json_decode($caller['hand'], true) ?: [];
    $callerTotal = hand_value($callerHand);
    // Defense in depth — awaiting_show already gates this, but re-check the
    // underlying rule: without an active call, and under the show threshold,
    // nobody can show.
    if (!(bool)$caller['has_called'] || $callerTotal >= SHOW_THRESHOLD) {
        return 'You cannot show right now.';
    }

    $players = fetch_players($pdo, (int)$room['id'], true);
    $active = array_values(array_filter($players, fn($p) => !$p['eliminated'] && !is_spectator($p)));

    $totals = [];
    $hands = [];
    foreach ($active as $p) {
        $h = json_decode($p['hand'], true) ?: [];
        $hands[$p['id']] = $h;
        $totals[$p['id']] = hand_value($h);
    }

    $otherTotals = [];
    foreach ($active as $p) {
        if ((int)$p['id'] !== (int)$caller['id']) $otherTotals[$p['id']] = $totals[$p['id']];
    }
    $minOther = min($otherTotals);
    $asaf = $callerTotal >= $minOther;

    $result = [];
    foreach ($active as $p) {
        $isCaller = (int)$p['id'] === (int)$caller['id'];
        $isAsafWinner = $asaf && !$isCaller && $totals[$p['id']] === $minOther;

        if ($isCaller) {
            $points = $asaf ? ($callerTotal + 30) : 0;
        } else {
            $points = $totals[$p['id']];
        }

        $newScore = (int)$p['score'] + $points;
        if ($newScore === 100) $newScore = 50;
        elseif ($newScore === 200) $newScore = 100;
        $eliminated = $newScore > ELIMINATION_SCORE;

        $stmt = $pdo->prepare('UPDATE players SET score = ?, eliminated = ? WHERE id = ?');
        $stmt->execute([$newScore, $eliminated ? 1 : 0, $p['id']]);

        $result[] = [
            'seat'       => (int)$p['seat'],
            'name'       => $p['name'],
            'hand'       => $hands[$p['id']],
            'total'      => $totals[$p['id']],
            'points'     => $points,
            'new_score'  => $newScore,
            'is_caller'  => $isCaller,
            'is_asaf'    => $isAsafWinner,
            'eliminated' => $eliminated,
        ];
    }

    // The round's winner is whoever added the fewest points — normally the
    // caller (0 points) on a clean show, or the asaf-winner when the caller
    // got it wrong.
    $minPoints = min(array_column($result, 'points'));
    foreach ($result as &$r) { $r['is_winner'] = $r['points'] === $minPoints; }
    unset($r);

    usort($result, fn($a, $b) => $a['seat'] <=> $b['seat']);

    $roundHistory = $room['round_history'] ? (json_decode($room['round_history'], true) ?: []) : [];
    $roundHistory[] = [
        'round_number' => (int)$room['round_number'],
        'results'      => array_map(fn($r) => [
            'seat'   => $r['seat'],
            'name'   => $r['name'],
            'points' => $r['points'],
        ], $result),
    ];
    $roundHistoryJson = json_encode($roundHistory);

    $remainingActive = array_values(array_filter($result, fn($r) => !$r['eliminated']));

    if (count($remainingActive) <= 1) {
        $winnerName = count($remainingActive) === 1 ? $remainingActive[0]['name'] : $result[0]['name'];
        $stmt = $pdo->prepare(
            'UPDATE rooms SET status = "finished", last_round_result = ?, round_history = ?, winner_name = ?, awaiting_show = 0 WHERE id = ?'
        );
        $stmt->execute([json_encode($result), $roundHistoryJson, $winnerName, $room['id']]);
        log_event($pdo, (int)$room['id'], "$winnerName wins the game!");
    } else {
        $stmt = $pdo->prepare('UPDATE rooms SET status = "round_end", last_round_result = ?, round_history = ?, awaiting_show = 0 WHERE id = ?');
        $stmt->execute([json_encode($result), $roundHistoryJson, $room['id']]);
    }

    if ($asaf) {
        $winnerRow = array_values(array_filter($result, fn($r) => $r['is_asaf']))[0] ?? null;
        $wname = $winnerRow ? $winnerRow['name'] : '';
        log_event($pdo, (int)$room['id'], "ASAF! {$caller['name']} showed but $wname had a lower hand.");
    } else {
        log_event($pdo, (int)$room['id'], "{$caller['name']} showed and won the round!");
    }
    return null;
}

/** The caller stays called and continues the game instead of showing. */
function kolshi_pass_show(PDO $pdo, array $room, array $player): ?string {
    if ($room['status'] !== 'playing') return 'Game is not in progress.';
    if ((int)$room['turn_seat'] !== (int)$player['seat']) return "It's not your turn.";
    if (!(bool)$room['awaiting_show']) return 'There is no show decision pending.';

    $players = fetch_players($pdo, (int)$room['id'], true);
    $nextSeat = next_active_seat($players, (int)$player['seat']);

    $stmt = $pdo->prepare('UPDATE rooms SET awaiting_show = 0, awaiting_draw = 0, turn_seat = ?, turn_deadline = ? WHERE id = ?');
    $stmt->execute([$nextSeat, new_turn_deadline(), $room['id']]);

    log_event($pdo, (int)$room['id'], "{$player['name']} stayed called and continued.");
    return null;
}
