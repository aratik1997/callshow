(function () {
  const params = new URLSearchParams(window.location.search);
  const urlCode = (params.get('code') || '').toUpperCase();
  const savedRoom = (localStorage.getItem('yaniv_room') || '').toUpperCase();
  const token = localStorage.getItem('yaniv_token');
  const myName = localStorage.getItem('yaniv_name') || '';

  if (!urlCode || !token || urlCode !== savedRoom) {
    window.location.href = urlCode ? `index.html?join=${encodeURIComponent(urlCode)}` : 'index.html';
    return;
  }

  const CODE = urlCode;
  const SUIT_SYMBOL = { S: '♠', H: '♥', D: '♦', C: '♣' };
  const RED_SUITS = { H: 1, D: 1 };
  const RANK_LABEL = { 1: 'A', 11: 'J', 12: 'Q', 13: 'K' };

  function rankLabel(r) { return RANK_LABEL[r] || String(r); }
  function isJoker(card) { return card.s === 'X'; }
  function suitClass(card) {
    if (isJoker(card)) return 'joker';
    return RED_SUITS[card.s] ? 'red' : 'black';
  }

  function buildCardEl(card, opts) {
    opts = opts || {};
    const el = document.createElement('div');
    el.className = 'playing-card ' + suitClass(card) + (opts.extraClass ? ' ' + opts.extraClass : '');
    if (isJoker(card)) {
      el.innerHTML = `<div class="corner">★</div><div class="center-suit">🃏</div><div class="corner bottom">★</div>`;
    } else {
      const label = rankLabel(card.r) + SUIT_SYMBOL[card.s];
      el.innerHTML = `<div class="corner">${label}</div><div class="center-suit">${SUIT_SYMBOL[card.s]}</div><div class="corner bottom">${label}</div>`;
    }
    if (opts.onClick) el.addEventListener('click', opts.onClick);
    return el;
  }

  function buildMiniCard(card) {
    const el = document.createElement('div');
    el.className = 'mini-card ' + suitClass(card);
    el.textContent = isJoker(card) ? '🃏' : rankLabel(card.r) + SUIT_SYMBOL[card.s];
    return el;
  }

  function buildBackCard(extraClass) {
    const el = document.createElement('div');
    el.className = 'playing-card back' + (extraClass ? ' ' + extraClass : '');
    return el;
  }

  async function api(url, body) {
    const opts = body
      ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
      : { method: 'GET' };
    const res = await fetch(url, opts);
    const data = await res.json().catch(() => ({ ok: false, error: 'Bad server response.' }));
    if (!res.ok || !data.ok) throw new Error(data.error || 'Request failed.');
    return data;
  }

  // ---------- DOM refs ----------
  const roomCodeTag = document.getElementById('room-code-tag');
  const lobbyOverlay = document.getElementById('lobby-overlay');
  const lobbyCode = document.getElementById('lobby-code');
  const lobbyPlayers = document.getElementById('lobby-players');
  const lobbyHostControls = document.getElementById('lobby-host-controls');
  const lobbyGuestMsg = document.getElementById('lobby-guest-msg');
  const opponentsRow = document.getElementById('opponents-row');
  const tableArea = document.getElementById('table-area');
  const handRow = document.getElementById('hand-row');
  const yourName = document.getElementById('your-name');
  const yourTotal = document.getElementById('your-total');
  const roundEndOverlay = document.getElementById('round-end-overlay');
  const roundEndTitle = document.getElementById('round-end-title');
  const roundEndTable = document.getElementById('round-end-table');
  const roundEndControls = document.getElementById('round-end-controls');
  const finishedOverlay = document.getElementById('finished-overlay');
  const finishedTable = document.getElementById('finished-table');
  const scorecardTitle = document.getElementById('scorecard-title');
  const scorecardTable = document.getElementById('scorecard-table');
  const copyToast = document.getElementById('copy-toast');
  const callToast = document.getElementById('call-toast');
  const turnToast = document.getElementById('turn-toast');
  const chatToggleBtn = document.getElementById('chat-toggle-btn');
  const chatBadge = document.getElementById('chat-badge');
  const chatPanel = document.getElementById('chat-panel');
  const chatCloseBtn = document.getElementById('chat-close-btn');
  const chatMessagesEl = document.getElementById('chat-messages');
  const chatForm = document.getElementById('chat-form');
  const chatInput = document.getElementById('chat-input');

  roomCodeTag.textContent = `Room: ${CODE}`;
  lobbyCode.textContent = CODE;
  yourName.textContent = myName;

  async function leaveRoom() {
    if (!confirm('Leave this room?')) return;
    try { await api('api/leave_room.php', { code: CODE, token }); } catch (e) { /* ignore */ }
    localStorage.removeItem('yaniv_room');
    localStorage.removeItem('yaniv_token');
    window.location.href = 'index.html';
  }
  document.getElementById('leave-btn').addEventListener('click', leaveRoom);
  document.getElementById('lobby-leave-btn').addEventListener('click', leaveRoom);

  document.getElementById('back-home-btn').addEventListener('click', () => {
    localStorage.removeItem('yaniv_room');
    localStorage.removeItem('yaniv_token');
    window.location.href = 'index.html';
  });

  roomCodeTag.addEventListener('click', () => {
    const link = `${window.location.origin}${window.location.pathname.replace('room.html', 'index.html')}?join=${CODE}`;
    navigator.clipboard.writeText(link).then(() => {
      copyToast.classList.add('show');
      setTimeout(() => copyToast.classList.remove('show'), 1800);
    }).catch(() => {});
  });

  let selectedIds = new Set();
  let actionErrorTimer = null;

  function showActionError(el, message) {
    el.textContent = message;
    el.classList.remove('hidden');
    clearTimeout(actionErrorTimer);
    actionErrorTimer = setTimeout(() => { el.textContent = ''; }, 4000);
  }

  // ---------- Rendering ----------
  function renderLobby(data) {
    lobbyOverlay.classList.remove('hidden');
    lobbyPlayers.innerHTML = '';
    data.players.forEach((p) => {
      const row = document.createElement('div');
      row.className = 'lobby-player-item';
      const span = document.createElement('span');
      span.innerHTML = `${escapeHtml(p.name)}${p.is_host ? ' <span class="host-badge">(host)</span>' : ''}${p.is_you ? ' — you' : ''}`;
      row.appendChild(span);
      if (data.is_host && !p.is_you) {
        const kickBtn = document.createElement('button');
        kickBtn.className = 'kick-btn';
        kickBtn.title = `Kick ${p.name}`;
        kickBtn.textContent = '✕';
        kickBtn.addEventListener('click', () => kickPlayer(p.seat, p.name));
        row.appendChild(kickBtn);
      }
      lobbyPlayers.appendChild(row);
    });

    if (data.is_host) {
      lobbyGuestMsg.classList.add('hidden');
      lobbyHostControls.innerHTML = '';
      const btn = document.createElement('button');
      btn.className = 'btn-primary';
      btn.textContent = data.players.length < 2 ? 'Need 2+ players to start' : 'Start Game';
      btn.disabled = data.players.length < 2;
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        try { await api('api/start_game.php', { code: CODE, token }); }
        catch (e) { alert(e.message); btn.disabled = data.players.length < 2; }
      });
      lobbyHostControls.appendChild(btn);
    } else {
      lobbyHostControls.innerHTML = '';
      lobbyGuestMsg.classList.remove('hidden');
    }
  }

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  async function kickPlayer(seat, name) {
    if (!confirm(`Kick ${name} from the room?`)) return;
    try { await api('api/kick_player.php', { code: CODE, token, seat }); }
    catch (e) { alert(e.message); }
  }

  function renderOpponents(data) {
    opponentsRow.innerHTML = '';
    data.players.forEach((p) => {
      const pod = document.createElement('div');
      pod.className = 'player-pod';
      if (p.is_you) pod.classList.add('you');
      if (p.seat === data.turn_seat) pod.classList.add('active-turn');
      if (!p.connected) pod.classList.add('disconnected');
      if (p.eliminated) pod.classList.add('eliminated');
      if (p.spectating) pod.classList.add('spectating');
      pod.innerHTML = `
        ${p.seat === data.turn_seat ? '<div class="turn-flag">TURN</div>' : ''}
        <div class="avatar">${escapeHtml(p.name.slice(0, 2).toUpperCase())}</div>
        <div class="pname">${escapeHtml(p.name)}${p.is_you ? ' <span class="you-badge">YOU</span>' : ''}${p.is_host ? ' <span class="host-badge">★</span>' : ''}${p.has_called ? ' <span class="called-badge">CALLED</span>' : ''}</div>
        <div class="pmeta">${p.eliminated ? 'Out' : (p.spectating ? 'Spectating' : p.card_count + ' cards')}</div>
      `;
      if (data.is_host && !p.is_you) {
        const kickBtn = document.createElement('button');
        kickBtn.className = 'kick-btn';
        kickBtn.title = `Kick ${p.name}`;
        kickBtn.textContent = '✕';
        kickBtn.addEventListener('click', () => kickPlayer(p.seat, p.name));
        pod.appendChild(kickBtn);
      }
      opponentsRow.appendChild(pod);
    });
  }

  function renderTable(data) {
    tableArea.innerHTML = '';

    async function doDraw(source, errEl) {
      try { await api('api/draw.php', { code: CODE, token, source }); }
      catch (e) { showActionError(errEl, e.message); }
    }

    const canDraw = data.is_your_turn && data.awaiting_draw;

    // Stock pile — click it directly to draw from the deck.
    const stockPile = document.createElement('div');
    stockPile.className = 'pile';
    const stockStack = document.createElement('div');
    stockStack.className = 'stock-stack' + (canDraw ? ' pickable-deck' : '');
    stockStack.appendChild(buildBackCard());
    const stockCount = document.createElement('div');
    stockCount.className = 'stock-count';
    stockCount.textContent = data.draw_count;
    stockStack.appendChild(stockCount);
    if (canDraw) stockStack.addEventListener('click', () => doDraw('stock', actionError));
    stockPile.innerHTML = '<div class="pile-label">Deck</div>';
    stockPile.appendChild(stockStack);
    tableArea.appendChild(stockPile);

    // Table throw — whatever the previous player threw, always visible to
    // everyone. During your own draw phase, the end cards are clickable.
    const throwPileWrap = document.createElement('div');
    throwPileWrap.className = 'pile';
    throwPileWrap.innerHTML = '<div class="pile-label">Table</div>';
    const throwCardsWrap = document.createElement('div');
    throwCardsWrap.className = 'pile-cards throw-pile';
    const throwCards = data.current_throw || [];
    const canPickThrow = data.is_your_turn && data.awaiting_draw;
    if (throwCards.length === 0) {
      const emptySlot = document.createElement('div');
      emptySlot.className = 'empty-slot';
      throwCardsWrap.appendChild(emptySlot);
    } else {
      throwCards.forEach((c, idx) => {
        const isEnd = idx === 0 || idx === throwCards.length - 1;
        const side = idx === 0 ? 'left' : 'right';
        const el = buildCardEl(c, {
          extraClass: canPickThrow && isEnd ? 'pickable' : '',
          onClick: canPickThrow && isEnd ? () => doDraw(side, actionError) : null,
        });
        throwCardsWrap.appendChild(el);
      });
    }
    throwPileWrap.appendChild(throwCardsWrap);
    tableArea.appendChild(throwPileWrap);

    // Just played — the current turn player's outgoing throw, visible to
    // everyone immediately (before they draw). Not drawable by anyone yet;
    // it becomes the new Table pile once they draw.
    const pendingCards = data.pending_throw || [];
    if (pendingCards.length > 0) {
      const justPlayedWrap = document.createElement('div');
      justPlayedWrap.className = 'pile';
      justPlayedWrap.innerHTML = '<div class="pile-label">Just Played</div>';
      const justPlayedCards = document.createElement('div');
      justPlayedCards.className = 'pile-cards';
      pendingCards.forEach((c) => justPlayedCards.appendChild(buildCardEl(c)));
      justPlayedWrap.appendChild(justPlayedCards);
      tableArea.appendChild(justPlayedWrap);
    }

    // Center status + actions
    const center = document.createElement('div');
    center.className = 'center-status';
    const turnMsg = document.createElement('div');
    turnMsg.className = 'turn-msg';
    const turnPlayer = data.players.find((p) => p.seat === data.turn_seat);
    turnMsg.textContent = data.is_your_turn ? "Your turn!" : `Waiting for ${turnPlayer ? turnPlayer.name : '...'}…`;
    center.appendChild(turnMsg);
    const roundTag = document.createElement('div');
    roundTag.className = 'round-tag';
    roundTag.textContent = `Round ${data.round_number}`;
    center.appendChild(roundTag);

    if (data.status === 'playing') {
      const timerTag = document.createElement('div');
      timerTag.className = 'timer-tag';
      timerTag.id = 'timer-tag';
      center.appendChild(timerTag);
    }

    const actionRow = document.createElement('div');
    actionRow.className = 'action-row';
    const actionError = document.createElement('div');
    actionError.className = 'form-error hidden';

    if (data.is_spectating) {
      const hint = document.createElement('div');
      hint.className = 'pickup-hint';
      hint.textContent = "You're spectating — you'll be dealt in from the next round.";
      center.appendChild(hint);
    } else if (data.is_your_turn && data.awaiting_show) {
      const hint = document.createElement('div');
      hint.className = 'pickup-hint';
      hint.textContent = `Your hand is ${data.your_hand_value} — you can show, or stay called and continue:`;
      center.appendChild(hint);

      const showBtn = document.createElement('button');
      showBtn.className = 'btn-primary';
      showBtn.textContent = 'Show!';
      showBtn.addEventListener('click', async () => {
        showBtn.disabled = true;
        try { await api('api/show.php', { code: CODE, token }); }
        catch (e) { showActionError(actionError, e.message); showBtn.disabled = false; }
      });
      actionRow.appendChild(showBtn);

      const continueBtn = document.createElement('button');
      continueBtn.className = 'btn-secondary';
      continueBtn.textContent = "Don't Show";
      continueBtn.addEventListener('click', async () => {
        continueBtn.disabled = true;
        try { await api('api/pass_show.php', { code: CODE, token }); }
        catch (e) { showActionError(actionError, e.message); continueBtn.disabled = false; }
      });
      actionRow.appendChild(continueBtn);
    } else if (data.is_your_turn && !data.awaiting_draw) {
      const hint = document.createElement('div');
      hint.className = 'pickup-hint';
      hint.textContent = data.has_called
        ? `Called — hand is ${data.your_hand_value}, need under 9 to show (next turn). Throw a card:`
        : `Hand is ${data.your_hand_value}${data.your_hand_value < 11 ? ' — you\'ll be called after this turn.' : '.'} Throw a card:`;
      center.appendChild(hint);

      const throwBtn = document.createElement('button');
      throwBtn.className = 'btn-secondary';
      throwBtn.textContent = `Throw Selected (${selectedIds.size})`;
      throwBtn.disabled = selectedIds.size === 0;
      throwBtn.addEventListener('click', async () => {
        throwBtn.disabled = true;
        try {
          await api('api/discard.php', { code: CODE, token, card_ids: Array.from(selectedIds) });
          selectedIds.clear();
        } catch (e) { showActionError(actionError, e.message); throwBtn.disabled = false; }
      });
      actionRow.appendChild(throwBtn);
    } else if (data.is_your_turn && data.awaiting_draw) {
      const hint = document.createElement('div');
      hint.className = 'pickup-hint';
      hint.textContent = 'Click the deck, or a card at either end of the table pile, to draw:';
      center.appendChild(hint);
    } else {
      const hint = document.createElement('div');
      hint.className = 'pickup-hint';
      hint.textContent = data.is_spectating
        ? "You're spectating — you'll be dealt in from the next round."
        : data.awaiting_show
        ? `${turnPlayer ? turnPlayer.name : 'They'} is deciding whether to show…`
        : (data.awaiting_draw ? 'They are drawing a card…' : 'Sit tight — not your turn.');
      center.appendChild(hint);
    }

    center.appendChild(actionRow);
    center.appendChild(actionError);
    tableArea.appendChild(center);

    // Discard pile
    const discardPileWrap = document.createElement('div');
    discardPileWrap.className = 'pile';
    discardPileWrap.innerHTML = '<div class="pile-label">Discard</div>';
    const cardsWrap = document.createElement('div');
    cardsWrap.className = 'pile-cards';
    (data.discard_top || []).forEach((c) => cardsWrap.appendChild(buildCardEl(c)));
    discardPileWrap.appendChild(cardsWrap);
    tableArea.appendChild(discardPileWrap);
  }

  function renderHand(data) {
    handRow.innerHTML = '';
    const validIds = new Set((data.your_hand || []).map((c) => c.id));
    Array.from(selectedIds).forEach((id) => { if (!validIds.has(id)) selectedIds.delete(id); });

    const canSelect = data.is_your_turn && !data.awaiting_draw && !data.awaiting_show && data.status === 'playing';
    (data.your_hand || []).forEach((card) => {
      const selected = selectedIds.has(card.id);
      const el = buildCardEl(card, {
        extraClass: selected ? 'selected' : '',
        onClick: canSelect ? () => {
          if (selectedIds.has(card.id)) selectedIds.delete(card.id);
          else selectedIds.add(card.id);
          renderHand(lastData);
          renderTable(lastData);
        } : null,
      });
      if (!canSelect) el.style.cursor = 'default';
      handRow.appendChild(el);
    });

    const total = data.your_hand_value;
    yourTotal.textContent = `Hand total: ${total}` + (data.has_called ? ' — Called!' : '');
    yourTotal.classList.toggle('low', data.has_called);
  }

function buildResultsTable(table, results, opts) {
    opts = opts || {};
    const hideScore = !!opts.hideScore;
    table.innerHTML = `
      <tr><th>#</th><th>Player</th><th>Hand</th><th>Total</th><th>Points</th>${hideScore ? '' : '<th>Score</th>'}</tr>
    `;
    const ranked = results.slice().sort((a, b) => a.points - b.points);
    ranked.forEach((r, i) => {
      const tr = document.createElement('tr');
      if (r.is_winner) tr.className = 'winner-row';
      const handTd = document.createElement('td');
      handTd.className = 'mini-hand';
      (r.hand || []).forEach((c) => handTd.appendChild(buildMiniCard(c)));

      let tags = '';
      if (r.is_winner) tags += '<span class="tag-winner">🏆 WINNER</span>';
      if (r.is_caller) tags += '<span class="tag-caller">SHOWED</span>';
      if (r.eliminated) tags += '<span class="tag-out">OUT</span>';

      tr.innerHTML = `<td>${i + 1}</td><td>${escapeHtml(r.name)}${tags}</td>`;
      tr.appendChild(handTd);
      tr.innerHTML += `<td>${r.total}</td><td>+${r.points}</td>` + (hideScore ? '' : `<td>${r.new_score}</td>`);
      table.appendChild(tr);
    });
  }

  function renderRoundEnd(data) {
    roundEndOverlay.classList.remove('hidden');
    const results = data.last_round_result || [];
    roundEndTitle.textContent = 'Round Over';
    buildResultsTable(roundEndTable, results, { hideScore: true });

    roundEndControls.innerHTML = '';
    if (data.is_host) {
      const btn = document.createElement('button');
      btn.className = 'btn-primary';
      btn.textContent = 'Start Next Round';
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        try { await api('api/next_round.php', { code: CODE, token }); }
        catch (e) { alert(e.message); btn.disabled = false; }
      });
      roundEndControls.appendChild(btn);
    } else {
      const p = document.createElement('div');
      p.className = 'pickup-hint';
      p.textContent = 'Waiting for the host to start the next round…';
      roundEndControls.appendChild(p);
    }
  }

  function buildScorecard(table, roundHistory, finalResults) {
    roundHistory = roundHistory || [];
    finalResults = finalResults || [];
    if (roundHistory.length === 0) { table.innerHTML = ''; return; }

    const nameBySeat = new Map();
    roundHistory.forEach((r) => r.results.forEach((p) => nameBySeat.set(p.seat, p.name)));
    finalResults.forEach((p) => nameBySeat.set(p.seat, p.name));
    const seats = Array.from(nameBySeat.keys()).sort((a, b) => a - b);
    const finalBySeat = new Map(finalResults.map((r) => [r.seat, r]));

    let html = '<tr><th>Player</th>' + roundHistory.map((r) => `<th>R${r.round_number}</th>`).join('') + '<th>Total</th></tr>';
    seats.forEach((seat) => {
      const cells = roundHistory.map((r) => {
        const p = r.results.find((x) => x.seat === seat);
        return `<td>${p ? '+' + p.points : '—'}</td>`;
      }).join('');
      const final = finalBySeat.get(seat);
      const total = final ? final.new_score : '—';
      html += `<tr><td>${escapeHtml(nameBySeat.get(seat))}</td>${cells}<td><strong>${total}</strong></td></tr>`;
    });
    table.innerHTML = html;
  }

  function renderFinished(data) {
    finishedOverlay.classList.remove('hidden');
    if (!data.last_round_result) {
      document.getElementById('finished-title').textContent = 'All other players left the room';
      finishedTable.innerHTML = '';
      scorecardTitle.classList.add('hidden');
      scorecardTable.innerHTML = '';
    } else {
      document.getElementById('finished-title').textContent = `🏆 ${data.winner_name} wins the game!`;
      buildResultsTable(finishedTable, data.last_round_result || []);
      scorecardTitle.classList.remove('hidden');
      buildScorecard(scorecardTable, data.round_history, data.last_round_result);
    }
    localStorage.removeItem('yaniv_room');
    localStorage.removeItem('yaniv_token');
  }

  let previousCalled = null;
  let callToastTimer = null;

  function showCallToast(message) {
    callToast.textContent = message;
    callToast.classList.add('show');
    clearTimeout(callToastTimer);
    callToastTimer = setTimeout(() => callToast.classList.remove('show'), 2500);
  }

  function checkCallToasts(data) {
    const seatsNow = {};
    (data.players || []).forEach((p) => { seatsNow[p.seat] = p.has_called; });
    if (previousCalled) {
      (data.players || []).forEach((p) => {
        if (p.has_called && !previousCalled[p.seat]) {
          showCallToast(p.is_you ? 'You called!' : `${p.name} called!`);
        }
      });
    }
    previousCalled = seatsNow;
  }

  // ---------- "Your turn" popup + sound ----------
  let audioCtx = null;
  function unlockAudio() {
    if (!audioCtx) {
      try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); }
      catch (e) { /* Web Audio unsupported — silently skip sound */ }
    } else if (audioCtx.state === 'suspended') {
      audioCtx.resume();
    }
  }
  document.addEventListener('click', unlockAudio);
  document.addEventListener('keydown', unlockAudio);

  function playTurnChime() {
    if (!audioCtx) return;
    const now = audioCtx.currentTime;
    [660, 880].forEach((freq, i) => {
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = 'sine';
      osc.frequency.value = freq;
      const t = now + i * 0.13;
      gain.gain.setValueAtTime(0, t);
      gain.gain.linearRampToValueAtTime(0.28, t + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.001, t + 0.28);
      osc.connect(gain).connect(audioCtx.destination);
      osc.start(t);
      osc.stop(t + 0.3);
    });
  }

  let turnToastTimer = null;
  function showTurnToast() {
    turnToast.classList.remove('show');
    void turnToast.offsetWidth; // restart animation
    turnToast.classList.add('show');
    clearTimeout(turnToastTimer);
    turnToastTimer = setTimeout(() => turnToast.classList.remove('show'), 2200);
    playTurnChime();
  }

  let wasYourTurn = false;
  function checkYourTurn(data) {
    const isNow = !!data.is_your_turn;
    if (isNow && !wasYourTurn) showTurnToast();
    wasYourTurn = isNow;
  }

  // ---------- Chat ----------
  let chatOpen = false;
  const renderedChatIds = new Set();
  let unreadChatCount = 0;

  function setChatOpen(open) {
    chatOpen = open;
    chatPanel.classList.toggle('hidden', !open);
    if (open) {
      unreadChatCount = 0;
      chatBadge.classList.add('hidden');
      chatBadge.textContent = '0';
      chatMessagesEl.scrollTop = chatMessagesEl.scrollHeight;
      chatInput.focus();
    }
  }
  chatToggleBtn.addEventListener('click', () => setChatOpen(!chatOpen));
  chatCloseBtn.addEventListener('click', () => setChatOpen(false));

  chatForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const msg = chatInput.value.trim();
    if (!msg) return;
    chatInput.value = '';
    try { await api('api/send_chat.php', { code: CODE, token, message: msg }); }
    catch (err) { /* ignore — will just not appear */ }
  });

  function renderChat(data) {
    const messages = data.chat || [];
    let added = false;
    messages.forEach((m) => {
      if (renderedChatIds.has(m.id)) return;
      renderedChatIds.add(m.id);
      added = true;
      const isYou = m.seat === data.your_seat;
      const row = document.createElement('div');
      row.className = 'chat-msg' + (isYou ? ' you' : '');
      const nameSpan = document.createElement('span');
      nameSpan.className = 'chat-msg-name';
      nameSpan.textContent = (isYou ? 'You' : m.name) + ':';
      row.appendChild(nameSpan);
      row.appendChild(document.createTextNode(m.message));
      chatMessagesEl.appendChild(row);
      if (!chatOpen && !isYou) unreadChatCount++;
    });
    if (added) {
      if (chatOpen) {
        chatMessagesEl.scrollTop = chatMessagesEl.scrollHeight;
      } else if (unreadChatCount > 0) {
        chatBadge.textContent = String(unreadChatCount);
        chatBadge.classList.remove('hidden');
      }
    }
  }

  // ---------- Turn countdown ----------
  // Ticks every second independent of polling, so the number counts down
  // smoothly instead of only jumping when a poll happens to land.
  function updateTimerDisplay() {
    const el = document.getElementById('timer-tag');
    if (!el) return;
    if (!lastData || !lastData.turn_deadline || lastData.status !== 'playing') { el.textContent = ''; return; }
    const remaining = Math.max(0, Math.ceil((lastData.turn_deadline - Date.now()) / 1000));
    el.textContent = `⏱ ${remaining}s`;
    el.classList.toggle('low', remaining <= 10);
  }
  setInterval(updateTimerDisplay, 1000);

  let lastData = null;
  let lastStatus = null;

  function render(data) {
    lastData = data;
    checkCallToasts(data);
    checkYourTurn(data);

    renderOpponents(data);
    renderTable(data);
    renderHand(data);
    renderChat(data);
    updateTimerDisplay();

    // Only touch the overlay panels when the status actually changes — toggling
    // their hidden class every poll (even to the same state) restarts their
    // entrance animation and makes them flicker.
    if (data.status !== lastStatus) {
      lobbyOverlay.classList.add('hidden');
      roundEndOverlay.classList.add('hidden');
      finishedOverlay.classList.add('hidden');

      if (data.status === 'waiting') renderLobby(data);
      else if (data.status === 'round_end') renderRoundEnd(data);
      else if (data.status === 'finished') renderFinished(data);

      lastStatus = data.status;
    } else if (data.status === 'waiting') {
      renderLobby(data);
    } else if (data.status === 'round_end') {
      renderRoundEnd(data);
    }
  }

  let polling = true;
  let lastDataJSON = null;
  async function poll() {
    if (!polling) return;
    try {
      const data = await api(`api/state.php?code=${encodeURIComponent(CODE)}&token=${encodeURIComponent(token)}`);
      // Skip the (expensive, DOM-rebuilding) render entirely when nothing has
      // actually changed since the last poll — this is what was causing the
      // constant flicker at a fast poll rate.
      const json = JSON.stringify(data);
      if (json !== lastDataJSON) {
        lastDataJSON = json;
        render(data);
      }
    } catch (e) {
      polling = false;
      alert(e.message || 'Lost connection to the room.');
      localStorage.removeItem('yaniv_room');
      localStorage.removeItem('yaniv_token');
      window.location.href = 'index.html';
      return;
    }
    setTimeout(poll, 1100);
  }
  poll();
})();
