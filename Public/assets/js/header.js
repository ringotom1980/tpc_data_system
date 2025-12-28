// Header 共用行為：設定 PUBLIC_BASE
document.addEventListener('DOMContentLoaded', () => {
  window.PUBLIC_BASE = document.body.dataset.publicBase || '/Public';
});
