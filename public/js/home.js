(function() {
  const form = document.querySelector('.scan-form');
  const input = document.querySelector('.scan-input');
  if (form && input) {
    form.addEventListener('submit', function() {
      const repo = input.value?.trim();
      if (repo) {
        localStorage.setItem('ss_last_scan', JSON.stringify({ repo, date: new Date().toISOString() }));
      }
    });
  }
})();
