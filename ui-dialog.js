(function () {
  let queue = Promise.resolve();

  function inferType(message) {
    const text = String(message || '').toLowerCase();
    if (/xóa|khóa|không được|lỗi|thất bại|hết hạn/.test(text)) return 'danger';
    if (/cảnh báo|chưa|vui lòng|chắc chắn|xác nhận/.test(text)) return 'warning';
    if (/thành công|đã lưu|đã mở/.test(text)) return 'success';
    return 'info';
  }

  function iconFor(type) {
    return { danger: '!', warning: '!', success: '✓', info: 'i' }[type] || 'i';
  }

  function titleFor(type, confirmMode) {
    if (confirmMode) return type === 'danger' ? 'Xác nhận thao tác quan trọng' : 'Xác nhận thao tác';
    return { danger: 'Không thể thực hiện', warning: 'Cần kiểm tra thông tin', success: 'Thao tác thành công', info: 'Thông báo hệ thống' }[type];
  }

  function show(message, options) {
    return new Promise(resolve => {
      const type = options.type || inferType(message);
      const overlay = document.createElement('div');
      overlay.className = 'smart-dialog-overlay';
      overlay.innerHTML = `<div class="smart-dialog ${type}" role="dialog" aria-modal="true" aria-labelledby="smart-dialog-title">
        <div class="smart-dialog-head"><div class="smart-dialog-icon">${iconFor(type)}</div><h3 id="smart-dialog-title" class="smart-dialog-title"></h3></div>
        <div class="smart-dialog-message"></div>
        <div class="smart-dialog-actions"></div>
      </div>`;
      overlay.querySelector('.smart-dialog-title').textContent = options.title || titleFor(type, options.confirm);
      overlay.querySelector('.smart-dialog-message').textContent = String(message || '');
      const actions = overlay.querySelector('.smart-dialog-actions');

      function close(result) {
        overlay.classList.remove('visible');
        setTimeout(() => { overlay.remove(); resolve(result); }, 170);
      }

      if (options.confirm) {
        const cancel = document.createElement('button');
        cancel.type = 'button'; cancel.className = 'smart-dialog-cancel'; cancel.textContent = options.cancelText || 'Hủy';
        cancel.addEventListener('click', () => close(false)); actions.appendChild(cancel);
      }
      const confirm = document.createElement('button');
      confirm.type = 'button'; confirm.className = 'smart-dialog-confirm';
      confirm.textContent = options.confirmText || (options.confirm ? 'Xác nhận' : 'Đã hiểu');
      confirm.addEventListener('click', () => close(true)); actions.appendChild(confirm);
      overlay.addEventListener('keydown', event => { if (event.key === 'Escape') close(false); });
      document.body.appendChild(overlay);
      requestAnimationFrame(() => overlay.classList.add('visible'));
      setTimeout(() => confirm.focus(), 20);
    });
  }

  function enqueue(message, options = {}) {
    const task = () => show(message, options);
    const result = queue.then(task, task);
    queue = result.catch(() => false);
    return result;
  }

  window.SmartDialog = {
    alert(message, options = {}) { return enqueue(message, { ...options, confirm: false }); },
    confirm(message, options = {}) { return enqueue(message, { ...options, confirm: true }); }
  };
  window.alert = message => window.SmartDialog.alert(message);
})();
