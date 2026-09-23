function logout() {
  var msg = "¿Desea cerrar la sesión?";
  var prom = (typeof SwalConfirm === 'function') ? SwalConfirm(msg, 'Cerrar sesión') : Promise.resolve(confirm(msg));
  prom.then(function(ok) {
    if (!ok) return;
    try {
      var keys = ['ix2-sidebar-module', 'ix2-sidebar-acc', 'ix2-sidebar-scroll', 'ix2-sidebar-tree-exp'];
      keys.forEach(function(k) {
        sessionStorage.removeItem(k);
        localStorage.removeItem(k);
      });
      var i;
      for (i = sessionStorage.length - 1; i >= 0; i--) {
        var sk = sessionStorage.key(i);
        if (sk && sk.indexOf('ix2-sidebar-module:') === 0) sessionStorage.removeItem(sk);
      }
      for (i = localStorage.length - 1; i >= 0; i--) {
        var lk = localStorage.key(i);
        if (lk && lk.indexOf('ix2-sidebar-module:') === 0) localStorage.removeItem(lk);
      }
    } catch (eClearNav) {}
    window.location.href = "core/auth/logout.php";
  });
}
