// assets/app.js - 前台与后台共用的少量 JS（更新：日期后追加星期）
(function(){
  // 保证在 DOM 就绪后执行
  document.addEventListener('DOMContentLoaded', function() {
    // --------- 公共：时钟与模式按钮（前台使用） ----------
    function updateClock(){
      const timeEl = document.getElementById('time');
      const dateEl = document.getElementById('date');
      if(!timeEl && !dateEl) return;
      const d = new Date();
      const hh = String(d.getHours()).padStart(2,'0');
      const mm = String(d.getMinutes()).padStart(2,'0');
      const ss = String(d.getSeconds()).padStart(2,'0');
      const yyyy = d.getFullYear();
      const mo = String(d.getMonth()+1).padStart(2,'0');
      const dd = String(d.getDate()).padStart(2,'0');
      // 中文星期数组（0=周日, 1=周一 ...）
      const weekdays = ['周日','周一','周二','周三','周四','周五','周六'];
      const weekday = weekdays[d.getDay()] || '';
      if (timeEl) timeEl.textContent = hh + ':' + mm + ':' + ss;
      if (dateEl) dateEl.textContent = `${yyyy}-${mo}-${dd} ${weekday}`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    document.querySelectorAll('.mode-btn').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const mode = btn.dataset.mode;
        localStorage.setItem('nav_mode', mode);
        highlightMode();
      });
    });
    function highlightMode(){
      const cur = localStorage.getItem('nav_mode') || 'v6';
      document.querySelectorAll('.mode-btn').forEach(b=>{
        if (b.dataset.mode === cur) b.style.background = 'linear-gradient(90deg,#6b5ef0,#e86aa7)';
        else b.style.background = 'rgba(255,255,255,0.08)';
      });
    }
    highlightMode();

    // --------- 后台逻辑初始化（如果在后台页面） ----------
    const svcList = document.getElementById('svc-list');
    const saveOrderBtn = document.getElementById('save-order-btn');
    const btnNew = document.getElementById('btn-new');

    // modal 元素（可在后台存在）
    const modalOverlay = document.getElementById('modal-overlay');
    const modalClose = modalOverlay ? modalOverlay.querySelector('.modal-close') || document.getElementById('modal-close') : document.getElementById('modal-close');
    const modalTitle = document.getElementById('modal-title');
    const svcForm = document.getElementById('svc-form');
    const modalReset = document.getElementById('modal-reset');

    // 如果没有后台区域就结束后台初始化（只有前台时）
    if (!svcList) {
      // 前台：绑定卡片点击（若存在）
      document.querySelectorAll('.card').forEach(card=>{
        card.addEventListener('click', ()=>{
          const mode = localStorage.getItem('nav_mode') || 'v6';
          const url = card.dataset[mode];
          if (!url) {
            alert('此服务尚未填写对应链接');
            return;
          }
          window.open(url, '_blank');
        });
      });
      return;
    }

    // ---------- modal 显示/隐藏 ----------
    function showModal() {
      if (!modalOverlay) return;
      modalOverlay.classList.add('show');
      modalOverlay.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      setTimeout(()=> {
        const f = svcForm ? svcForm.querySelector('input[name="name"]') : null;
        if (f) f.focus();
      }, 140);
    }
    function hideModal() {
      if (!modalOverlay) return;
      modalOverlay.classList.remove('show');
      modalOverlay.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    }

    if (modalClose) modalClose.addEventListener('click', hideModal);

    // ---------- 其余后台逻辑（拖拽 / 编辑 / 删除 / 状态切换 / 保存排序 / 表单提交） ----------
    // ...（其余代码保持之前实现，不影响前台时钟显示）
    let dragEl = null;
    svcList.addEventListener('dragstart', (e)=>{
      let tr = e.target.closest('tr[draggable="true"]');
      if (!tr) return;
      dragEl = tr;
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', tr.dataset.id || ''); } catch (err) {}
      tr.style.opacity = '0.4';
    });
    svcList.addEventListener('dragend', (e)=>{
      if (dragEl) dragEl.style.opacity = '';
      dragEl = null;
      svcList.querySelectorAll('tr').forEach(r=>r.classList.remove('drag-over'));
    });
    svcList.addEventListener('dragover', (e)=>{
      e.preventDefault();
      const tr = e.target.closest('tr[draggable="true"]');
      if (!tr || tr === dragEl) return;
      tr.classList.add('drag-over');
    });
    svcList.addEventListener('dragleave', (e)=>{
      const tr = e.target.closest('tr[draggable="true"]');
      if (tr) tr.classList.remove('drag-over');
    });
    svcList.addEventListener('drop', (e)=>{
      e.preventDefault();
      const target = e.target.closest('tr[draggable="true"]');
      if (!target || !dragEl || target === dragEl) return;
      const rect = target.getBoundingClientRect();
      const offset = e.clientY - rect.top;
      if (offset > rect.height / 2) {
        target.parentNode.insertBefore(dragEl, target.nextSibling);
      } else {
        target.parentNode.insertBefore(dragEl, target);
      }
      svcList.querySelectorAll('tr').forEach(r=>r.classList.remove('drag-over'));
    });

    if (saveOrderBtn) {
      saveOrderBtn.addEventListener('click', async ()=>{
        const ids = Array.from(svcList.querySelectorAll('tr')).map(tr => tr.dataset.id);
        if (!ids.length) return alert('没有可排序的项');
        if (!confirm('确认将当前表格顺序保存为排序吗？')) return;
        try {
          const res = await fetch('api.php?action=reorder', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order: ids })
          });
          const data = await res.json();
          if (data.ok) {
            alert('排序保存成功，页面将刷新');
            location.reload();
          } else {
            alert('保存失败: ' + (data.err || '未知错误'));
          }
        } catch (err) {
          alert('请求失败: ' + err);
        }
      });
    }

    svcList.addEventListener('click', async (e)=>{
      if (e.target.matches('.edit-btn')) {
        const id = e.target.dataset.id;
        if (!id) return;
        try {
          const res = await fetch('api.php?action=get&id=' + encodeURIComponent(id));
          const data = await res.json();
          if (!data.ok) { alert('获取失败'); return; }
          fillForm(data.row);
          modalTitle && (modalTitle.textContent = '编辑服务 #' + id);
          showModal();
        } catch (err) {
          alert('请求失败: ' + err);
        }
        return;
      }
      if (e.target.matches('.del-btn')) {
        const id = e.target.dataset.id;
        if (!id) return;
        if (!confirm('确认删除？')) return;
        try {
          const res = await fetch('api.php?action=delete&id=' + encodeURIComponent(id), { method: 'POST' });
          const data = await res.json();
          if (data.ok) {
            location.reload();
          } else {
            alert('删除失败: ' + (data.err || '未知错误'));
          }
        } catch (err) {
          alert('请求失败: ' + err);
        }
        return;
      }
      if (e.target.matches('.status-badge')) {
        const badge = e.target;
        const id = badge.dataset.id;
        if (!id) return;
        const currentlyEnabled = badge.classList.contains('enabled');
        if (currentlyEnabled) {
          badge.classList.remove('enabled'); badge.classList.add('disabled'); badge.textContent = '禁用';
        } else {
          badge.classList.remove('disabled'); badge.classList.add('enabled'); badge.textContent = '启用';
        }
        try {
          const res = await fetch('api.php?action=toggle&id=' + encodeURIComponent(id), { method: 'POST' });
          const data = await res.json();
          if (!data.ok) {
            if (currentlyEnabled) {
              badge.classList.remove('disabled'); badge.classList.add('enabled'); badge.textContent = '启用';
            } else {
              badge.classList.remove('enabled'); badge.classList.add('disabled'); badge.textContent = '禁用';
            }
            alert('切换失败: ' + (data.err || '未知错误'));
          }
        } catch (err) {
          if (currentlyEnabled) {
            badge.classList.remove('disabled'); badge.classList.add('enabled'); badge.textContent = '启用';
          } else {
            badge.classList.remove('enabled'); badge.classList.add('disabled'); badge.textContent = '禁用';
          }
          alert('请求失败: ' + err);
        }
        return;
      }
    });

    if (btnNew) {
      btnNew.addEventListener('click', ()=>{
        modalTitle && (modalTitle.textContent = '新增服务');
        resetFormFields();
        showModal();
      });
    }

    function fillForm(row) {
      if (!svcForm) return;
      svcForm.id.value = row.id || '';
      svcForm.name.value = row.name || '';
      svcForm.description.value = row.description || '';
      svcForm.link_v6.value = row.link_v6 || '';
      svcForm.link_v4.value = row.link_v4 || '';
      svcForm.link_lan.value = row.link_lan || '';
      svcForm.enabled.checked = row.enabled == 1;
      const fileInp = svcForm.querySelector('input[type=file]');
      if (fileInp) fileInp.value = '';
    }
    function resetFormFields() {
      if (!svcForm) return;
      svcForm.reset();
      svcForm.id.value = '';
    }

    if (modalReset) {
      modalReset.addEventListener('click', (e)=>{
        e.preventDefault();
        resetFormFields();
      });
    }

    if (svcForm) {
      svcForm.addEventListener('submit', async (ev)=>{
        ev.preventDefault();
        const fd = new FormData(svcForm);
        try {
          const res = await fetch('api.php?action=save', { method: 'POST', body: fd });
          const data = await res.json();
          if (data.ok) {
            alert('保存成功');
            hideModal();
            location.reload();
          } else {
            alert('保存失败: ' + (data.err || '未知错误'));
          }
        } catch (err) {
          alert('请求失败: ' + err);
        }
      });
    }

    if (!svcForm && modalClose) {
      modalClose.addEventListener('click', hideModal);
    }

  }); // DOMContentLoaded end
})();