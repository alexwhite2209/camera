/* Админка АЙРИС: подтверждения и мелкие удобства. */
(() => {
  'use strict';
  // Подтверждение для опасных кнопок (удаление и т.п.)
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-confirm]');
    if (b && !window.confirm(b.dataset.confirm)) e.preventDefault();
  });
  // Выбор чата Telegram из найденных
  document.querySelectorAll('[data-chat]').forEach(ch => ch.addEventListener('click', () => {
    const input = document.getElementById('tg_chat');
    if (input) { input.value = ch.dataset.chat; input.focus(); }
  }));
  // Предупреждение о несохранённых правках
  let dirty = false;
  document.querySelectorAll('form').forEach(f => {
    f.addEventListener('input', () => { dirty = true; });
    f.addEventListener('submit', () => { dirty = false; });
  });
  window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
})();
