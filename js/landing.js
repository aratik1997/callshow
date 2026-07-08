(function () {
  const tabButtons = document.querySelectorAll('.tab-btn');
  const tabs = {
    create: document.getElementById('tab-create'),
    join: document.getElementById('tab-join'),
  };

  tabButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      tabButtons.forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      Object.entries(tabs).forEach(([key, el]) => {
        el.classList.toggle('hidden', key !== btn.dataset.tab);
      });
    });
  });

  // Auto-rejoin if we already have a session for that same room. An invite
  // link for a *different* room (?join=CODE) should always win over a stale
  // session from a previous game.
  const savedRoom = localStorage.getItem('yaniv_room');
  const savedToken = localStorage.getItem('yaniv_token');
  const joinCodeParam = (new URLSearchParams(window.location.search).get('join') || '').toUpperCase();
  if (savedRoom && savedToken && (!joinCodeParam || joinCodeParam === savedRoom.toUpperCase())) {
    window.location.href = `room.html?code=${encodeURIComponent(savedRoom)}`;
    return;
  }

  const createNameEl = document.getElementById('create-name');
  const createBtn = document.getElementById('create-btn');
  const createErr = document.getElementById('create-error');

  const joinNameEl = document.getElementById('join-name');
  const joinCodeEl = document.getElementById('join-code');
  const joinBtn = document.getElementById('join-btn');
  const joinErr = document.getElementById('join-error');

  const savedName = localStorage.getItem('yaniv_name');
  if (savedName) {
    createNameEl.value = savedName;
    joinNameEl.value = savedName;
  }

  if (joinCodeParam) {
    joinCodeEl.value = joinCodeParam;
    document.querySelector('.tab-btn[data-tab="join"]').click();
    joinNameEl.focus();
  }

  async function postJSON(url, body) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({ ok: false, error: 'Bad server response.' }));
    if (!res.ok || !data.ok) throw new Error(data.error || 'Request failed.');
    return data;
  }

  function goToRoom(code, token, name) {
    localStorage.setItem('yaniv_room', code);
    localStorage.setItem('yaniv_token', token);
    localStorage.setItem('yaniv_name', name);
    window.location.href = `room.html?code=${encodeURIComponent(code)}`;
  }

  createBtn.addEventListener('click', async () => {
    createErr.textContent = '';
    const name = createNameEl.value.trim();
    if (!name) { createErr.textContent = 'Enter your name.'; return; }
    createBtn.disabled = true;
    try {
      const data = await postJSON('api/create_room.php', { name });
      goToRoom(data.room_code, data.token, name);
    } catch (e) {
      createErr.textContent = e.message;
      createBtn.disabled = false;
    }
  });

  joinBtn.addEventListener('click', async () => {
    joinErr.textContent = '';
    const name = joinNameEl.value.trim();
    const code = joinCodeEl.value.trim().toUpperCase();
    if (!name) { joinErr.textContent = 'Enter your name.'; return; }
    if (!code) { joinErr.textContent = 'Enter the room code.'; return; }
    joinBtn.disabled = true;
    try {
      const data = await postJSON('api/join_room.php', { name, code });
      goToRoom(data.room_code, data.token, name);
    } catch (e) {
      joinErr.textContent = e.message;
      joinBtn.disabled = false;
    }
  });

  [createNameEl, joinNameEl, joinCodeEl].forEach((el) => {
    el.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        if (el === joinCodeEl || el === joinNameEl) joinBtn.click();
        else createBtn.click();
      }
    });
  });
})();
