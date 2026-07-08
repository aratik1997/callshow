<?php
declare(strict_types=1);

const MAX_PLAYERS = 8;
const ELIMINATION_SCORE = 200;
const CALL_THRESHOLD = 11; // hand value below this: player is (auto) "called"
const SHOW_THRESHOLD = 9;  // hand value below this, on a later turn: player may Show
const TURN_SECONDS = 30;   // time budget per turn before auto-play kicks in
const BELL_COOLDOWN_SECONDS = 5; // minimum gap between bell rings, room-wide

function new_turn_deadline(): string {
    return date('Y-m-d H:i:s', time() + TURN_SECONDS);
}

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function json_error(string $message, int $status = 400): void {
    json_response(['ok' => false, 'error' => $message], $status);
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function gen_token(): string {
    return bin2hex(random_bytes(20));
}

function gen_room_code(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I to avoid confusion
    $code = '';
    for ($i = 0; $i < 5; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

function clean_name(string $name): string {
    $name = trim(strip_tags($name));
    $name = preg_replace('/\s+/', ' ', $name) ?? '';
    if ($name === '') $name = 'Player';
    return mb_substr($name, 0, 20);
}

const SUITS = ['S', 'H', 'D', 'C'];

/**
 * Build a fresh deck (54 cards: 52 + 2 jokers), or two shuffled-together
 * decks (108 cards) for bigger tables. Card ids get a "_b" suffix on the
 * second deck so every card still has a unique id.
 */
function build_deck(int $numDecks = 1): array {
    $deck = [];
    for ($d = 0; $d < $numDecks; $d++) {
        $suffix = $d === 0 ? '' : '_b';
        foreach (SUITS as $s) {
            for ($r = 1; $r <= 13; $r++) {
                $deck[] = ['id' => $s . $r . $suffix, 'r' => $r, 's' => $s];
            }
        }
        $deck[] = ['id' => 'X1' . $suffix, 'r' => 0, 's' => 'X'];
        $deck[] = ['id' => 'X2' . $suffix, 'r' => 0, 's' => 'X'];
    }
    return $deck;
}

function card_value(array $card): int {
    if ($card['s'] === 'X') return 0;
    if ($card['r'] === 1) return 1;
    if ($card['r'] >= 11) return 10;
    return $card['r'];
}

function hand_value(array $hand): int {
    $total = 0;
    foreach ($hand as $c) $total += card_value($c);
    return $total;
}

/**
 * Validate a set of cards thrown together on one turn.
 * Returns 'single' | 'set' | 'run' on success, or false if invalid.
 */
function validate_combo(array $cards) {
    $n = count($cards);
    if ($n < 1) return false;
    if ($n === 1) return 'single';

    $jokers = array_values(array_filter($cards, fn($c) => $c['s'] === 'X'));
    $normal = array_values(array_filter($cards, fn($c) => $c['s'] !== 'X'));
    $jCount = count($jokers);

    // SET: every non-joker card shares the same rank (jokers are wild).
    $ranks = array_unique(array_map(fn($c) => $c['r'], $normal));
    if (count($ranks) <= 1) {
        return 'set';
    }

    // RUN: every non-joker card shares the same suit, distinct ranks,
    // and any gaps between them can be covered by jokers (no wrap past A/K).
    $suits = array_unique(array_map(fn($c) => $c['s'], $normal));
    $uniqueRanks = array_unique(array_map(fn($c) => $c['r'], $normal));
    if ($n >= 3 && count($suits) === 1 && count($normal) === count($uniqueRanks)) {
        $rlist = array_values($uniqueRanks);
        sort($rlist);
        $min = $rlist[0];
        $max = $rlist[count($rlist) - 1];
        $span = $max - $min + 1;
        $internalGaps = $span - count($rlist);
        if ($internalGaps <= $jCount) {
            $leftover = $jCount - $internalGaps;
            $room = ($min - 1) + (13 - $max);
            if ($leftover <= $room) {
                return 'run';
            }
        }
    }

    return false;
}

function fetch_room_by_code(PDO $pdo, string $code, bool $forUpdate = false): ?array {
    $sql = 'SELECT * FROM rooms WHERE code = ?' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([strtoupper($code)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetch_players(PDO $pdo, int $roomId, bool $forUpdate = false): array {
    $sql = 'SELECT * FROM players WHERE room_id = ? ORDER BY seat ASC' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$roomId]);
    return $stmt->fetchAll();
}

function fetch_player_by_token(PDO $pdo, int $roomId, string $token, bool $forUpdate = false): ?array {
    $sql = 'SELECT * FROM players WHERE room_id = ? AND token = ?' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$roomId, $token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function log_event(PDO $pdo, int $roomId, string $message): void {
    $stmt = $pdo->prepare('INSERT INTO game_log (room_id, message) VALUES (?, ?)');
    $stmt->execute([$roomId, $message]);
}

/**
 * Find the seat of the next player after $fromSeat who is eligible for a
 * turn — not eliminated, and not a mid-round spectator (someone who joined
 * after the round started and hasn't been dealt a hand yet).
 */
function next_active_seat(array $players, int $fromSeat): int {
    $seats = array_map(fn($p) => (int)$p['seat'], $players);
    sort($seats);
    $count = count($seats);
    $idx = array_search($fromSeat, $seats, true);
    if ($idx === false) $idx = 0;
    for ($i = 1; $i <= $count; $i++) {
        $candidateSeat = $seats[($idx + $i) % $count];
        foreach ($players as $p) {
            if ((int)$p['seat'] === $candidateSeat && !$p['eliminated'] && !is_spectator($p)) {
                return $candidateSeat;
            }
        }
    }
    return $fromSeat;
}

/** A non-eliminated player with an empty hand mid-round is a spectator
 *  waiting for the next round to be dealt in. */
function is_spectator(array $player): bool {
    if ((bool)$player['eliminated']) return false;
    $hand = json_decode($player['hand'], true) ?: [];
    return count($hand) === 0;
}

/**
 * Deal a fresh round: builds & shuffles a deck, gives 5 cards to each
 * non-eliminated player, sets up the draw/discard piles, and starts the
 * turn at $startSeat. Must be called inside an already-open transaction.
 */
function deal_new_round(PDO $pdo, int $roomId, array $players, int $startSeat): void {
    $active = array_values(array_filter($players, fn($p) => !$p['eliminated']));

    // Bigger tables need more cards in circulation.
    $numDecks = count($active) > 6 ? 2 : 1;
    $deck = build_deck($numDecks);
    shuffle($deck);

    foreach ($active as $p) {
        $hand = array_splice($deck, 0, 5);
        $stmt = $pdo->prepare('UPDATE players SET hand = ? WHERE id = ?');
        $stmt->execute([json_encode($hand), $p['id']]);
    }
    // Players already eliminated sit out with an empty hand.
    foreach ($players as $p) {
        if ($p['eliminated']) {
            $stmt = $pdo->prepare('UPDATE players SET hand = "[]" WHERE id = ?');
            $stmt->execute([$p['id']]);
        }
    }

    // Fresh round: nobody has called yet.
    $stmt = $pdo->prepare('UPDATE players SET has_called = 0 WHERE room_id = ?');
    $stmt->execute([$roomId]);

    // The initial face-up card sits on the table as the live "current throw" —
    // the first player throws their own combo first, then draws (from stock,
    // or from either end of this starter card / whatever's currently on the table).
    $discardTop = array_splice($deck, 0, 1);

    $stmt = $pdo->prepare(
        'UPDATE rooms SET status = "playing", turn_seat = ?, awaiting_draw = 0, awaiting_show = 0,
         draw_pile = ?, discard_pile = ?, current_throw = ?, pending_throw = NULL, last_round_result = NULL,
         turn_deadline = ?
         WHERE id = ?'
    );
    $stmt->execute([$startSeat, json_encode($deck), json_encode([]), json_encode($discardTop), new_turn_deadline(), $roomId]);
}

/**
 * Reshuffle seat order among everyone still in the game, deal a fresh round,
 * and bump the round counter. Shared by the natural round_end -> next round
 * transition and the host's "force a new round" mid-game reset. Must be
 * called inside an already-open, row-locked transaction.
 */
function start_fresh_round(PDO $pdo, int $roomId): void {
    $players = fetch_players($pdo, $roomId, true);

    $active = array_values(array_filter($players, fn($p) => !$p['eliminated']));
    $seats = array_map(fn($p) => (int)$p['seat'], $active);
    shuffle($seats);
    foreach ($active as $i => $p) {
        $stmt = $pdo->prepare('UPDATE players SET seat = ? WHERE id = ?');
        $stmt->execute([$seats[$i], $p['id']]);
    }

    $players = fetch_players($pdo, $roomId, true);
    $active = array_values(array_filter($players, fn($p) => !$p['eliminated']));
    $startSeat = min(array_map(fn($p) => (int)$p['seat'], $active));
    deal_new_round($pdo, $roomId, $players, $startSeat);

    $stmt = $pdo->prepare('UPDATE rooms SET round_number = round_number + 1 WHERE id = ?');
    $stmt->execute([$roomId]);
}

function require_input_string(array $input, string $key): string {
    if (!isset($input[$key]) || !is_string($input[$key]) || trim($input[$key]) === '') {
        json_error("Missing field: $key");
    }
    return $input[$key];
}

/**
 * Remove a player from a room — used for both voluntary leaving and host
 * kicks. Deletes the room if they were last, hands off host, advances the
 * turn (folding any orphaned staged throw into history) if it was their
 * turn, and force-finishes the game if only one player is left. Must be
 * called inside an already-open, row-locked transaction.
 */
function remove_player(PDO $pdo, array $room, array $player, string $logMessage): void {
    $wasTurn = $room['status'] === 'playing' && (int)$room['turn_seat'] === (int)$player['seat'];

    $stmt = $pdo->prepare('DELETE FROM players WHERE id = ?');
    $stmt->execute([$player['id']]);

    $remaining = fetch_players($pdo, (int)$room['id'], true);
    if (count($remaining) === 0) {
        $stmt = $pdo->prepare('DELETE FROM rooms WHERE id = ?');
        $stmt->execute([$room['id']]);
    } else {
        if ((bool)$player['is_host']) {
            $newHost = $remaining[0];
            $stmt = $pdo->prepare('UPDATE players SET is_host = 1 WHERE id = ?');
            $stmt->execute([$newHost['id']]);
        }

        if ($room['status'] !== 'waiting') {
            if (count($remaining) === 1) {
                $stmt = $pdo->prepare(
                    'UPDATE rooms SET status = "finished", winner_name = ?, last_round_result = NULL WHERE id = ?'
                );
                $stmt->execute([$remaining[0]['name'], $room['id']]);
            } elseif ($wasTurn) {
                $pendingThrow = $room['pending_throw'] ? (json_decode($room['pending_throw'], true) ?: []) : [];
                $discardPile = json_decode($room['discard_pile'], true) ?: [];
                foreach ($pendingThrow as $c) $discardPile[] = $c;

                $nextSeat = next_active_seat($remaining, (int)$player['seat']);
                $stmt = $pdo->prepare(
                    'UPDATE rooms SET turn_seat = ?, awaiting_draw = 0, awaiting_show = 0, pending_throw = NULL,
                     discard_pile = ?, turn_deadline = ? WHERE id = ?'
                );
                $stmt->execute([$nextSeat, json_encode($discardPile), new_turn_deadline(), $room['id']]);
            }
        }
    }

    log_event($pdo, (int)$room['id'], $logMessage);
}

/**
 * If the current turn's time budget has expired, auto-play for that player:
 * throw one random card (if they haven't thrown yet) and draw from the
 * stock, then pass the turn on. Safe to call on every poll — locks the room
 * row so only one concurrent request actually resolves an expired turn.
 */
function auto_resolve_expired_turn(PDO $pdo, int $roomId): void {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM rooms WHERE id = ? FOR UPDATE');
        $stmt->execute([$roomId]);
        $room = $stmt->fetch();
        if (!$room || $room['status'] !== 'playing' || !$room['turn_deadline']
            || strtotime($room['turn_deadline']) > time()) {
            $pdo->rollBack();
            return;
        }

        $players = fetch_players($pdo, $roomId, true);
        $current = null;
        foreach ($players as $p) {
            if ((int)$p['seat'] === (int)$room['turn_seat']) { $current = $p; break; }
        }
        if (!$current) {
            $pdo->rollBack();
            return;
        }

        if ((bool)$room['awaiting_show']) {
            $nextSeat = next_active_seat($players, (int)$current['seat']);
            $stmt = $pdo->prepare(
                'UPDATE rooms SET awaiting_show = 0, awaiting_draw = 0, turn_seat = ?, turn_deadline = ? WHERE id = ?'
            );
            $stmt->execute([$nextSeat, new_turn_deadline(), $roomId]);
            log_event($pdo, $roomId, "{$current['name']} ran out of time and stayed called.");
            $pdo->commit();
            return;
        }

        $hand = json_decode($current['hand'], true) ?: [];
        $pendingThrow = $room['pending_throw'] ? (json_decode($room['pending_throw'], true) ?: []) : [];

        if (!(bool)$room['awaiting_draw'] && count($hand) > 0) {
            $idx = array_rand($hand);
            $pendingThrow = [$hand[$idx]];
            array_splice($hand, $idx, 1);
            $stmt = $pdo->prepare('UPDATE players SET hand = ? WHERE id = ?');
            $stmt->execute([json_encode($hand), $current['id']]);
            log_event($pdo, $roomId, "{$current['name']} ran out of time — threw a random card.");
        }

        $drawPile = json_decode($room['draw_pile'], true) ?: [];
        $discardPile = json_decode($room['discard_pile'], true) ?: [];
        $currentThrow = $room['current_throw'] ? (json_decode($room['current_throw'], true) ?: []) : [];

        if (count($drawPile) === 0 && count($discardPile) > 0) {
            shuffle($discardPile);
            $drawPile = $discardPile;
            $discardPile = [];
        }
        $drawnCard = count($drawPile) > 0 ? array_pop($drawPile) : null;
        if ($drawnCard !== null) $hand[] = $drawnCard;

        $stmt = $pdo->prepare('UPDATE players SET hand = ? WHERE id = ?');
        $stmt->execute([json_encode($hand), $current['id']]);

        foreach ($currentThrow as $c) $discardPile[] = $c;

        $handValue = hand_value($hand);
        $hadCalled = (bool)$current['has_called'];
        if ($hadCalled && $handValue >= CALL_THRESHOLD) {
            $stmt = $pdo->prepare('UPDATE players SET has_called = 0 WHERE id = ?');
            $stmt->execute([$current['id']]);
        } elseif (!$hadCalled && $handValue < CALL_THRESHOLD) {
            $stmt = $pdo->prepare('UPDATE players SET has_called = 1 WHERE id = ?');
            $stmt->execute([$current['id']]);
        }
        // Auto-play never offers the Show pause — keeps the game moving.

        $players = fetch_players($pdo, $roomId, true);
        $nextSeat = next_active_seat($players, (int)$current['seat']);

        $stmt = $pdo->prepare(
            'UPDATE rooms SET draw_pile = ?, discard_pile = ?, current_throw = ?, pending_throw = NULL,
             awaiting_draw = 0, awaiting_show = 0, turn_seat = ?, turn_deadline = ? WHERE id = ?'
        );
        $stmt->execute([
            json_encode($drawPile), json_encode($discardPile), json_encode($pendingThrow),
            $nextSeat, new_turn_deadline(), $roomId,
        ]);
        log_event($pdo, $roomId, "{$current['name']} ran out of time — auto-drew a card.");

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}
