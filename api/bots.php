<?php
/**
 * Computer players for Kolshi (Yaniv). A bot is a players row with is_bot = 1. It plays through the
 * same actions a person uses (api/actions.php), so combos, draws, calls and scores all follow the
 * rules.
 *
 * On its turn a bot does three things in order, one per paced step:
 *   1. throw a combo, 2. draw (from the stock or the table), 3. when it has called, show or pass.
 *
 * Difficulty only changes the choices:
 *  - easy:   throws a random single card, always draws from the stock, and shows only with a very low hand.
 *  - medium: throws its highest single card, takes a table card only when it's low, and shows at a low hand.
 *  - hard:   throws the valid combo that sheds the most points, takes a table card when it helps
 *            (matches a rank or run in hand, or is cheap), and shows as soon as the hand is very low.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/actions.php';

const BOT_DIFFICULTIES = ['easy', 'medium', 'hard'];

/** Seconds a bot "thinks" before each step, so it reads as a person rather than instant. */
const BOT_DELAY_SECONDS = [
    'easy'   => [4, 6],
    'medium' => [2, 4],
    'hard'   => [1, 3],
];

/** Show when the hand totals at or below this (must also be under the normal show threshold). */
const BOT_SHOW_AT = ['easy' => 3, 'medium' => 5, 'hard' => 7];

/** Chooses which card ids a bot throws: a valid single, set or run. */
function bot_choose_throw(array $hand, string $difficulty): array {
    if ($difficulty === 'easy') {
        return [$hand[array_rand($hand)]['id']];
    }

    if ($difficulty === 'medium') {
        $best = $hand[0];
        foreach ($hand as $c) {
            if (card_value($c) > card_value($best)) $best = $c;
        }
        return [$best['id']];
    }

    // Hard: every valid combo of up to four cards; throw the one that sheds the most points.
    $best = null;
    $bestShed = -1;
    $n = count($hand);
    $maxSize = min(4, $n);
    for ($size = 1; $size <= $maxSize; $size++) {
        foreach (bot_combinations($n, $size) as $indexes) {
            $cards = array_map(fn($i) => $hand[$i], $indexes);
            if (validate_combo($cards) === false) continue;
            $shed = hand_value($cards);
            if ($shed > $bestShed) {
                $bestShed = $shed;
                $best = array_column($cards, 'id');
            }
        }
    }
    // Every hand has at least one valid single, so $best is always set.
    return $best ?? [$hand[0]['id']];
}

/** All index combinations of $size items out of $n (small n only; hands are at most a few dozen cards). */
function bot_combinations(int $n, int $size, int $start = 0, array $current = []): array {
    if (count($current) === $size) return [$current];
    $out = [];
    for ($i = $start; $i < $n; $i++) {
        foreach (bot_combinations($n, $size, $i + 1, [...$current, $i]) as $combo) {
            $out[] = $combo;
        }
    }
    return $out;
}

/**
 * Chooses where a bot draws from: 'stock', or a card on the table (the previous throw).
 * Returns ['source' => 'stock'] or ['source' => 'table', 'card_id' => ...].
 */
function bot_choose_draw(array $hand, array $currentThrow, string $difficulty): array {
    if ($difficulty === 'easy' || !$currentThrow) {
        return ['source' => 'stock'];
    }

    // A table card is worth it if it's cheap, or if it lines up with something already in hand.
    $handRanks = array_count_values(array_map(fn($c) => $c['r'], array_filter($hand, fn($c) => $c['s'] !== 'X')));
    $bestCard = null;
    $bestScore = null;
    foreach ($currentThrow as $c) {
        $value = card_value($c);
        $score = $value;
        if ($c['s'] !== 'X' && isset($handRanks[$c['r']])) {
            $score -= 4; // would complete a set with a card in hand
        }
        if ($bestScore === null || $score < $bestScore) {
            $bestCard = $c;
            $bestScore = $score;
        }
    }

    // Stock cards average roughly 5 points; take the table card only when it beats that.
    $threshold = $difficulty === 'hard' ? 5 : 3;
    if ($bestScore !== null && $bestScore <= $threshold) {
        return ['source' => 'table', 'card_id' => $bestCard['id']];
    }
    return ['source' => 'stock'];
}

/** True once the bot's thinking time for its next step has passed. Schedules the step the first time. */
function bot_advance_step(PDO $pdo, array $room, array $bot, string $difficulty): void {
    // Only one step per due time: scheduled on first sight, then acted on when due.
    if ($room['bot_next_at'] === null) {
        $delay = mt_rand(BOT_DELAY_SECONDS[$difficulty][0], BOT_DELAY_SECONDS[$difficulty][1]);
        $pdo->prepare('UPDATE rooms SET bot_next_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', time() + $delay), $room['id']]);
        return;
    }
    if (strtotime($room['bot_next_at']) > time()) {
        return;
    }

    $hand = json_decode($bot['hand'], true) ?: [];
    if ((bool)$room['awaiting_show']) {
        $wantsToShow = (bool)$bot['has_called'] && hand_value($hand) <= BOT_SHOW_AT[$difficulty];
        if ($wantsToShow) {
            kolshi_show($pdo, $room, $bot);
        } else {
            kolshi_pass_show($pdo, $room, $bot);
        }
    } elseif ((bool)$room['awaiting_draw']) {
        $currentThrow = $room['current_throw'] ? (json_decode($room['current_throw'], true) ?: []) : [];
        $choice = bot_choose_draw($hand, $currentThrow, $difficulty);
        kolshi_draw($pdo, $room, $bot, $choice['source'], $choice['card_id'] ?? '');
    } else {
        if (count($hand) === 0) {
            return; // nothing to throw; the turn timeout moves the game on
        }
        kolshi_throw($pdo, $room, $bot, bot_choose_throw($hand, $difficulty));
    }

    // Each step either succeeds or is rejected by the rules. Either way the next step is scheduled
    // fresh, so a bot that can't act just keeps waiting and the turn timeout moves the game on.
    $pdo->prepare('UPDATE rooms SET bot_next_at = NULL WHERE id = ?')->execute([$room['id']]);
}

/**
 * Lets the bot whose turn it is take its next step, if it's due. Called from the state poll. Holds
 * the room row locked for the whole step, so two overlapping polls can't both act.
 */
function advance_bots(PDO $pdo, int $roomId): void {
    $pdo->beginTransaction();
    try {
        $room = fetch_room_by_code_id($pdo, $roomId);
        if (!$room || $room['status'] !== 'playing') {
            if ($room && $room['bot_next_at'] !== null) {
                $pdo->prepare('UPDATE rooms SET bot_next_at = NULL WHERE id = ?')->execute([$roomId]);
            }
            $pdo->commit();
            return;
        }

        $current = null;
        foreach (fetch_players($pdo, $roomId, true) as $p) {
            if ((int)$p['seat'] === (int)$room['turn_seat']) { $current = $p; break; }
        }
        if (!$current || !(int)$current['is_bot']) {
            if ($room['bot_next_at'] !== null) {
                $pdo->prepare('UPDATE rooms SET bot_next_at = NULL WHERE id = ?')->execute([$roomId]);
            }
            $pdo->commit();
            return;
        }

        $difficulty = in_array($current['bot_difficulty'], BOT_DIFFICULTIES, true) ? $current['bot_difficulty'] : 'medium';
        bot_advance_step($pdo, $room, $current, $difficulty);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

/** The room row, locked for update (advance_bots' own copy; fetch_room_by_code takes a code). */
function fetch_room_by_code_id(PDO $pdo, int $roomId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE id = ? FOR UPDATE');
    $stmt->execute([$roomId]);
    $row = $stmt->fetch();
    return $row ?: null;
}
