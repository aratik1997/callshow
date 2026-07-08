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

    $caller = fetch_player_by_token($pdo, (int)$room['id'], $token, true);
    if (!$caller) { $pdo->rollBack(); json_error('Player not found.', 404); }
    if ((int)$room['turn_seat'] !== (int)$caller['seat']) { $pdo->rollBack(); json_error("It's not your turn."); }
    if (!(bool)$room['awaiting_show']) { $pdo->rollBack(); json_error('You cannot show right now.'); }

    $callerHand = json_decode($caller['hand'], true) ?: [];
    $callerTotal = hand_value($callerHand);
    // Defense in depth — awaiting_show already gates this, but re-check the
    // underlying rule: without an active call, and under the show threshold,
    // nobody can show.
    if (!(bool)$caller['has_called'] || $callerTotal >= SHOW_THRESHOLD) {
        $pdo->rollBack();
        json_error('You cannot show right now.');
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

    $pdo->commit();
    json_response(['ok' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Failed to show.', 500);
}
