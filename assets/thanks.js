(function () {
  const params = new URLSearchParams(window.location.search);
  const sessionId = params.get('session_id');
  const input = document.getElementById('session-id');
  const btn   = document.getElementById('copy-session');
  if (!input || !btn) return;

  if (sessionId) {
    input.value = sessionId;
  } else {
    input.value = '注文番号が取得できませんでした';
    input.classList.add('is-error');
  }

  input.addEventListener('focus', e => e.target.select());
  input.addEventListener('click',  e => e.target.select());

  btn.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(input.value);
      const prev = btn.textContent;
      btn.textContent = 'コピーしました';
      btn.classList.add('is-done');
      setTimeout(() => { btn.textContent = prev; btn.classList.remove('is-done'); }, 1600);
    } catch (err) {
      input.focus(); input.select();
      alert('コピーに失敗しました。選択状態で Ctrl/Cmd + C を押してください。');
    }
  });
})();

