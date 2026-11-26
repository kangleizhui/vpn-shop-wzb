/* 统一的自定义控件：下拉、日期选择、确认弹窗
   用法：
   - <select data-nice>
   - <input type="date" data-picker>
   - <form data-confirm="确定删除？">
*/
(function () {
  /* ---------------- 下拉 ---------------- */
  function closeAll() {
    document.querySelectorAll('.nsel.open').forEach(function (w) { w.classList.remove('open'); });
  }

  function enhance(sel) {
    if (sel.dataset.niceReady) return;
    sel.dataset.niceReady = '1';

    var wrap = document.createElement('div');
    wrap.className = 'nsel';
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    sel.classList.add('nsel-native');

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'nsel-btn';
    var menu = document.createElement('div');
    menu.className = 'nsel-menu';
    wrap.appendChild(btn);
    wrap.appendChild(menu);

    function render() {
      var opt = sel.options[sel.selectedIndex];
      btn.innerHTML = '<span></span><i>▾</i>';
      btn.firstChild.textContent = opt ? opt.text : '';
      menu.innerHTML = '';
      for (var i = 0; i < sel.options.length; i++) {
        (function (o) {
          var it = document.createElement('button');
          it.type = 'button';
          it.className = 'nsel-item' + (o.selected ? ' on' : '');
          it.textContent = o.text;
          it.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (sel.value !== o.value) {
              sel.value = o.value;
              sel.dispatchEvent(new Event('change', { bubbles: true }));
            }
            render();
            wrap.classList.remove('open');
          });
          menu.appendChild(it);
        })(sel.options[i]);
      }
    }

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var open = wrap.classList.contains('open');
      closeAll();
      document.querySelectorAll('.dp.open').forEach(function (d) { d.classList.remove('open'); });
      if (!open) wrap.classList.add('open');
    });
    btn.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); btn.click(); }
      if (e.key === 'Escape') wrap.classList.remove('open');
    });
    document.addEventListener('click', function () { wrap.classList.remove('open'); });

    sel.addEventListener('change', render);
    render();
  }

  document.addEventListener('click', closeAll);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(); });

  /* ---------------- 日期选择 ---------------- */
  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parseYmd(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
    if (!m) return null;
    var d = new Date(+m[1], +m[2] - 1, +m[3]);
    return isNaN(d.getTime()) ? null : d;
  }

  function enhanceDate(inp) {
    if (inp.dataset.pickerReady) return;
    inp.dataset.pickerReady = '1';

    var wrap = document.createElement('div');
    wrap.className = 'dp';
    inp.parentNode.insertBefore(wrap, inp);
    wrap.appendChild(inp);
    inp.classList.add('dp-native');

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'nsel-btn dp-btn';
    var pop = document.createElement('div');
    pop.className = 'dp-pop';
    wrap.appendChild(btn);
    wrap.appendChild(pop);

    var view = parseYmd(inp.value) || new Date();

    function paint() {
      var val = parseYmd(inp.value);
      btn.innerHTML = '<span></span><i>▾</i>';
      btn.firstChild.textContent = val ? ymd(val) : '选择日期';

      var y = view.getFullYear(), m = view.getMonth();
      var first = new Date(y, m, 1);
      var start = first.getDay();               // 0=周日
      var days = new Date(y, m + 1, 0).getDate();
      var today = ymd(new Date());

      var html = '<div class="dp-head"><button type="button" data-nav="-1">‹</button><b>' + y + ' 年 ' + (m + 1) + ' 月</b><button type="button" data-nav="1">›</button></div><div class="dp-grid">';
      ['日', '一', '二', '三', '四', '五', '六'].forEach(function (w) { html += '<span class="dp-dow">' + w + '</span>'; });
      for (var i = 0; i < start; i++) html += '<span></span>';
      for (var d = 1; d <= days; d++) {
        var ds = y + '-' + pad(m + 1) + '-' + pad(d);
        var cls = 'dp-day' + (val && ymd(val) === ds ? ' on' : '') + (ds === today ? ' today' : '');
        html += '<button type="button" class="' + cls + '" data-d="' + ds + '">' + d + '</button>';
      }
      html += '</div><div class="dp-foot"><button type="button" data-today="1">今天</button><button type="button" data-close="1">关闭</button></div>';
      pop.innerHTML = html;

      pop.querySelectorAll('[data-nav]').forEach(function (b) {
        b.addEventListener('click', function (e) {
          e.preventDefault(); e.stopPropagation();
          view.setMonth(view.getMonth() + (+b.dataset.nav));
          paint();
        });
      });
      pop.querySelectorAll('[data-d]').forEach(function (b) {
        b.addEventListener('click', function (e) {
          e.preventDefault(); e.stopPropagation();
          inp.value = b.dataset.d;
          inp.dispatchEvent(new Event('change', { bubbles: true }));
          wrap.classList.remove('open');
        });
      });
      pop.querySelector('[data-today]').addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        inp.value = ymd(new Date());
        view = new Date();
        inp.dispatchEvent(new Event('change', { bubbles: true }));
        wrap.classList.remove('open');
      });
      pop.querySelector('[data-close]').addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        wrap.classList.remove('open');
      });
    }

    btn.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      var open = wrap.classList.contains('open');
      closeAll();
      wrap.classList.toggle('open', !open);
      if (!open) paint();
    });
    document.addEventListener('click', function () { wrap.classList.remove('open'); });
    inp.addEventListener('change', paint);
    paint();
  }

  /* ---------------- 确认弹窗 ---------------- */
  function uiConfirm(msg, onOk) {
    var mask = document.createElement('div');
    mask.className = 'uimask';
    mask.innerHTML = '<div class="uibox"><div class="uibox-t">确认操作</div><div class="uibox-m"></div>'
      + '<div class="uibox-a"><button type="button" class="btn ghost" data-no>取消</button><button type="button" class="btn danger" data-yes>确定</button></div></div>';
    mask.querySelector('.uibox-m').textContent = msg;
    document.body.appendChild(mask);
    void mask.offsetWidth;
    mask.classList.add('on');
    function done(ok) {
      mask.classList.remove('on');
      setTimeout(function () { mask.remove(); }, 180);
      if (ok && onOk) onOk();
    }
    mask.querySelector('[data-no]').addEventListener('click', function () { done(false); });
    mask.querySelector('[data-yes]').addEventListener('click', function () { done(true); });
    mask.addEventListener('click', function (e) { if (e.target === mask) done(false); });
    document.addEventListener('keydown', function esc(e) {
      if (e.key === 'Escape') { document.removeEventListener('keydown', esc); done(false); }
    });
  }
  window.uiConfirm = uiConfirm;

  // 按钮级确认：<button data-confirm="...">，确认后带上该按钮的 name/value 提交
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('button[data-confirm]') : null;
    if (!b) return;
    e.preventDefault();
    uiConfirm(b.getAttribute('data-confirm'), function () {
      var f = b.form;
      if (!f) return;
      if (b.name) {
        var h = document.createElement('input');
        h.type = 'hidden';
        h.name = b.name;
        h.value = b.value || '1';
        f.appendChild(h);
      }
      f.submit();
    });
  }, true);

  document.addEventListener('submit', function (e) {
    var f = e.target;
    var q = f.getAttribute && f.getAttribute('data-confirm');
    if (q && !f.dataset.confirmed) {
      e.preventDefault();
      uiConfirm(q, function () { f.dataset.confirmed = '1'; f.submit(); });
    }
  }, true);

  /* ---------------- 失效倒计时 ---------------- */
  var cdReloaded = false;
  function tickCountdown() {
    var now = Math.floor(Date.now() / 1000);
    var dead = false;
    document.querySelectorAll('.cd[data-ts]').forEach(function (el) {
      var left = parseInt(el.getAttribute('data-ts'), 10) - now;
      if (isNaN(left)) return;
      if (left <= 0) {
        el.textContent = '已失效';
        el.classList.add('dead');
        dead = true;
        return;
      }
      var h = Math.floor(left / 3600), m = Math.floor((left % 3600) / 60), sec = left % 60;
      el.textContent = (h > 0 ? h + ' 小时 ' : '') + m + ' 分 ' + (sec < 10 ? '0' + sec : sec) + ' 秒';
    });
    if (dead && !cdReloaded) {
      cdReloaded = true;
      setTimeout(function () { location.reload(); }, 1600);
    }
  }

  /* ---------------- 启动 ---------------- */
  function boot() {
    tickCountdown();
    setInterval(tickCountdown, 1000);
    document.querySelectorAll('select[data-nice]').forEach(enhance);
    document.querySelectorAll('input[type=date][data-picker]').forEach(enhanceDate);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
