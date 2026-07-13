# Kolshi

A browser-based multiplayer card game built on Yaniv rules, with a two-phase
Call/Show win condition, a 30-second turn timer with auto-play, host
moderation tools, in-game chat, sound effects, and a taunt bell — all running
on plain PHP + MySQL with no build step, no Node.js, and no WebSocket server.
Create a room, share the 5-letter code, and play in real time over polling.

## Features

- **Core gameplay** — singles/sets/runs of 3+ (wild jokers), throw first then
  draw from the deck or any card on the table (the previous player's throw,
  never your own).
- **Call/Show mechanic** — a hand under 11 auto-"calls"; on any later turn,
  as long as the hand stays under 11 after playing, that player may Show and
  reveal every hand. Lowest total wins; if the shower isn't strictly lowest,
  that's an Asaf — they eat their hand total + 30 penalty points instead.
- **Turn timer** — 30 seconds per turn; if it runs out, the game auto-throws
  a random card and auto-draws so play never stalls.
- **Host controls** — start the game, kick a disruptive player (they get a
  "kicked by X" message, everyone else sees a toast), or discard the current
  round early and deal a fresh one on demand.
- **Mid-game joining** — new players can join anytime before the game ends;
  they spectate until the next round deals them in.
- **Double deck** — rooms with more than 6 active players automatically play
  with two shuffled-together decks.
- **Scorecard** — full round-by-round score history, elimination at 200+
  (with the 100→50 / 200→100 resets), last player standing wins.
- **Chat and a taunt bell** — an in-room chat panel, plus a bell button on
  each opponent's box that only they hear/see when rung (5-second cooldown).
- **Sound effects** — turn notification, card throw, draw, call, and bell
  sounds, all synthesized live with the Web Audio API (no audio files).
- **Resilient polling** — the client skips re-rendering when nothing changed,
  backs off and retries instead of bailing out on transient errors, and
  throttles background/inactive tabs to reduce load on the database.

## Stack

- **Backend:** PHP (PDO/MySQL), plain scripts in `api/` — no framework, no
  Composer install needed.
- **Frontend:** static HTML/CSS/vanilla JS, polls `api/state.php` on an
  interval (~1.5s active, ~10s for background tabs).
- **DB:** one small MySQL schema (`sql/schema.sql`).

## Running locally (XAMPP)

1. Start Apache + MySQL in the XAMPP control panel.
2. Open **phpMyAdmin** → New database → Import → choose `sql/schema.sql` → Go.
3. Copy `api/config.example.php` to `api/config.php` and fill in your local
   MySQL details (XAMPP's default is host `localhost`, user `root`, no
   password).
4. Visit `http://localhost/callshow/` in your browser.
5. Open a second tab (or another device on the same Wi-Fi, using your
   computer's local IP instead of `localhost`) to test with a second player.

## Deploying to cPanel / shared hosting

1. **Create the database.** In cPanel → *MySQL® Databases*: create a
   database and a user with a strong password, and add the user to the
   database with **All Privileges**.
2. **Import the schema.** cPanel → *phpMyAdmin* → select your database →
   *Import* → choose `sql/schema.sql` → Go.
3. **Upload the files** into `public_html/` (or a subfolder / subdomain's
   document root) via File Manager or FTP.
4. **Copy `api/config.example.php` to `api/config.php`** and fill in the
   real host/database/user/password cPanel gave you. `config.php` is
   gitignored on purpose — never commit real credentials.
5. Visit your domain — the landing page should load. Create a room, copy the
   invite link (click the room code chip once you're in), send it to friends.

No Node.js, no WebSocket/port configuration, no build step — it's just PHP
files served by Apache, so it works on virtually any shared hosting plan.

## How it works

- Every player holds a random token (in `localStorage`) that identifies them
  within a room — there are no accounts or passwords.
- The frontend polls `GET api/state.php`; all moves go through `POST`
  endpoints (`discard.php`, `draw.php`, `show.php`, `kick_player.php`, …)
  that use row locking (`SELECT ... FOR UPDATE`) so two players acting at
  once can't corrupt game state.
- The turn timer is enforced statelessly: every `state.php` poll checks
  whether the current turn's deadline has passed and auto-resolves it if so
  — no cron job or background worker needed.
- See the in-app "How do I play?" link for the full player-facing rules.

## Notes / limits

- Max 8 players per room (2 decks once more than 6 are active).
- No accounts — anyone with the room code can join. Codes exclude ambiguous
  characters (0/O/1/I).
- Keep the folder structure intact when deploying — everything uses relative
  paths (`api/...`, `css/...`, `js/...`).
