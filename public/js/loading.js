(function() {
  const wrap = document.querySelector('.loading-wrap');
  if (!wrap) return;

  const runUrl = wrap.dataset.runUrl;
  const csrfToken = wrap.dataset.csrfToken;
  const immediateError = wrap.dataset.immediateError === '1';

  if (immediateError) {
    const statusDot = document.getElementById('status-dot');
    const errorBox = document.getElementById('error-box');
    if (statusDot) statusDot.style.background = '#DC2626';
    if (errorBox) errorBox.style.display = 'block';
    return;
  }

  if (!runUrl || !csrfToken) return;

  function setStep(n, state) {
    const card  = document.getElementById('step-' + n);
    const icon  = document.getElementById('icon-' + n);
    const label = document.getElementById('state-' + n);
    const bar   = document.getElementById('bar-' + n);
    if (!card || !icon || !label || !bar) return;

    card.className = 'step-card ' + state;

    if (state === 'active') {
      label.innerHTML = '<span class="spin">↻</span> En cours...';
      bar.style.width = '65%';
      setTimeout(() => { if (card.className.includes('active')) bar.style.width = '85%'; }, 4000);
    } else if (state === 'done') {
      label.innerHTML = '✓ Terminé';
      bar.style.width = '100%';
      icon.textContent = '✅';
    }
  }

  setTimeout(() => setStep(1, 'active'),  200);
  setTimeout(() => setStep(1, 'done'),   5000);
  setTimeout(() => setStep(2, 'active'), 5200);
  setTimeout(() => setStep(2, 'done'),  25000);
  setTimeout(() => setStep(3, 'active'), 25200);

  fetch(runUrl, {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrfToken },
  })
  .then(res => res.json())
  .then(data => {
    if (data.status === 'done') {
      setStep(1, 'done');
      setStep(2, 'done');
      setStep(3, 'done');

      const statusDot = document.getElementById('status-dot');
      const statusLabel = document.getElementById('status-label');
      if (statusDot) statusDot.style.background = '#059669';
      if (statusLabel) statusLabel.textContent = 'Analyse terminée !';

      setTimeout(() => { window.location.href = data.redirectUrl; }, 1000);
    } else {
      showError(data.error || 'Une erreur est survenue.');
    }
  })
  .catch(err => showError(err.message));

  function showError(msg) {
    const statusDot = document.getElementById('status-dot');
    const statusLabel = document.getElementById('status-label');
    const errorBox = document.getElementById('error-box');
    const errorMsg = document.getElementById('error-msg');
    if (statusDot) statusDot.style.background = '#DC2626';
    if (statusLabel) statusLabel.textContent = "Échec de l'analyse";
    if (errorBox) errorBox.style.display = 'block';
    if (errorMsg) errorMsg.textContent = msg;
  }
})();
