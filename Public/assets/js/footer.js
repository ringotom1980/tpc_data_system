// Footer 共用行為：清理 Modal 遮罩殘留
document.addEventListener('hidden.bs.modal', () => {
  document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
  document.body.classList.remove('modal-open');
  document.body.style.removeProperty('padding-right');
});
