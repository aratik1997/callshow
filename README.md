# Yaniv Online

A browser-based multiplayer Yaniv card game. Create a room, share the 5-letter
code with friends, and play in real time (polling-based, no WebSocket/Node.js
required — works on plain PHP + MySQL hosting).

## Stack

- **Backend:** PHP (PDO/MySQL), plain scripts in `api/` — no framework, no
  Composer install needed.
- **Frontend:** static HTML/CSS/vanilla JS, polls `api/state.php` every ~1.2s.
- **DB:** one small MySQL schema (`sql/schema.sql`) — 3 tables.

## Running locally (XAMPP)

1. Start Apache + MySQL in the XAMPP control panel.
2. Open **phpMyAdmin** → New database named `yaniv` → Import →
   choose `sql/schema.sql` → Go.
3. `api/config.php` already points at `localhost` / `yaniv` / `root` / *(no
   password)*, the default XAMPP MySQL login. Edit it if yours differs.
4. Visit `http://localhost/callshow/` in your browser.
5. Open a second tab (or another device on the same Wi-Fi, using your
   computer's local IP instead of `localhost`) to test with a second player.

## Deploying to cPanel

1. **Create the database.** In cPanel → *MySQL® Databases*:
   - Create a database (e.g. `yaniv`) — cPanel will prefix it, giving you
     something like `myuser_yaniv`.
   - Create a database user with a strong password, and add it to the
     database with **All Privileges**.
2. **Import the schema.** cPanel → *phpMyAdmin* → select your new database →
   *Import* → choose `sql/schema.sql` → Go.
3. **Upload the files.** Zip this whole folder and upload via cPanel →
   *File Manager* (or FTP) into `public_html/` (or a subfolder / subdomain's
   document root if you want it at a specific path). Extract the zip there.
4. **Edit `api/config.php`** with the real values cPanel gave you:
   ```php
   return [
       'db_host' => 'localhost',
       'db_name' => 'myuser_yaniv-35303835963c',
       'db_user' => 'myuser_yaniv-35303835963c',
       'db_pass' => '?nmr@}rE_tge',
   ];
   ```
5. Visit your domain — the landing page should load. Create a room, copy the
   invite link (click the room code chip once you're in), send it to friends.

No Node.js, no WebSocket/port configuration, no build step — it's just PHP
files served by Apache, so it works on virtually any shared cPanel plan.

## How it works

- Every player holds a random 40-character token (in `localStorage`), which
  identifies them within a room — there are no accounts/passwords.
- The frontend polls `GET api/state.php` on an interval; all moves go through
  `POST` endpoints (`discard.php`, `draw.php`, `show.php`, `pass_show.php`, …)
  that use row locking (`SELECT ... FOR UPDATE`) so two players acting at once
  can't corrupt game state.
- Rules implemented: singles/sets/runs of 3+ (with wild jokers), throw first
  then draw from the deck or any card on the table (the previous player's
  throw, never your own). A hand under 11 auto-"calls"; on any later turn,
  as long as the hand stays under 11 after playing, that player may Show
  (or keep playing — going back to 11+ cancels the call, and without an
  active call nobody can Show). Showing reveals every hand: lowest total
  wins, ties/lower-others trigger an Asaf penalty for the shower. Also
  implements the 100→50 / 200→100 score resets and elimination at 200+.
  See the in-app "How do I play Yaniv?" link for the full rules.

## Notes / limits

- Max 8 players per room.
- No accounts — anyone with the room code can join while it's in "waiting"
  status. Codes exclude ambiguous characters (0/O/1/I).
- If you want a custom domain path, just keep the folder structure intact —
  everything uses relative paths (`api/...`, `css/...`, `js/...`).
