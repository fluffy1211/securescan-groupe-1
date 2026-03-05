(function() {
  const dashTab = document.getElementById('nav-center-pill');
  if (dashTab && localStorage.getItem('ss_last_scan')) {
    dashTab.classList.add('nav-center--visible');
    if (dashTab.dataset.active) dashTab.classList.add('active');
  }
})();
