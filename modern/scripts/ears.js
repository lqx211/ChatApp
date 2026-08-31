/**
 * 头像兔子耳朵挂件 · 全站自动注入
 *
 * 给所有匹配的头像容器自动叠加 APNG 耳朵动画：
 *  - 耳朵大小按头像容器实际宽度自适应（上限 = space.php 基准 120px 头像 → 112px 耳朵）
 *  - 位置用 space.php 精调坐标反推的公式（CSS .av-ear 处理）
 *  - MutationObserver 覆盖动态新增（聊天气泡/联系人/评论等），幂等不重复
 *
 * 需要全站统一加载（chat.php / users.php / space.php）。
 */
(function () {
  'use strict';

  var EAR_SRC = '../../data/res/space-widget/ears.apng';
  // 需要挂耳朵的头像容器选择器（仅圆形头像会真正挂上）
  var SELECTORS = [
    '.head-avatar',
    '.sp-profile-av',
    '.poster-av',
    '.sp-board-av',
    '.f-cmt-av',
    '.msg-avatar',
    '.csi .ca',
    '.sa',
    '.user-avatar',
    '.srch-avatar',
    '.req-av'
  ];

  // 默认关闭：各页面按用户 space_ears 设置输出 window.EARS_ON=true 才注入
  var ENABLED = window.EARS_ON === true;

  // 仅圆形头像（border-radius 50%）才挂耳朵；方形头像不挂（避免视觉杂乱）
  function isRound(el) {
    if (!el) return false;
    var br = getComputedStyle(el).borderRadius;
    if (br === '50%') return true;
    var w = el.offsetWidth;
    if (w > 0 && /px/.test(br)) {
      var px = parseFloat(br);
      if (px >= w / 2 - 1) return true;
    }
    return false;
  }

  function inject(root) {
    if (!ENABLED) return;
    var doc = (root && root.querySelectorAll) ? root : document;
    if (!doc.querySelectorAll) return;
    SELECTORS.forEach(function (sel) {
      var nodes;
      try { nodes = doc.querySelectorAll(sel); } catch (e) { return; }
      for (var i = 0; i < nodes.length; i++) {
        var c = nodes[i];
        var host = c;
        // <img> 头像不能含子元素 → 自动包一层 span 作为宿主
        if (c.tagName === 'IMG') {
          if (c.getAttribute('data-av-ear-wrapped') === '1') {
            host = c.parentElement; // 已包裹
            if (!host) continue;
          } else {
            if (!c.parentNode) continue;
            var wrap = document.createElement('span');
            wrap.style.position = 'relative';
            wrap.style.display = 'inline-block';
            wrap.style.lineHeight = '0';
            wrap.style.verticalAlign = 'top';
            c.parentNode.insertBefore(wrap, c);
            wrap.appendChild(c);
            c.setAttribute('data-av-ear-wrapped', '1');
            host = wrap;
          }
        }
        // 幂等：宿主已挂耳朵则跳过
        if (host.getAttribute('data-av-ear') === '1' || host.querySelector('.av-ear')) continue;
        // 仅圆形头像显示耳朵
        if (!isRound(c)) continue;
        // 宿主需相对定位（有条件设置，避免无谓的 style 变更）
        if (getComputedStyle(host).position === 'static') host.style.position = 'relative';
        // 头像容器若 overflow:hidden 会裁掉伸出头外的耳朵 → 改为可见（仅当确实非 visible）
        if (getComputedStyle(host).overflow !== 'visible') host.style.overflow = 'visible';
        var w = c.offsetWidth || c.clientWidth || 0;
        if (w <= 0) continue; // 隐藏元素（未激活 tab/未布局）暂不挂，等可见后低频兜底扫描
        var img = document.createElement('img');
        img.className = 'av-ear';
        img.alt = '';
        img.src = EAR_SRC;
        img.style.setProperty('--av-size', w + 'px');
        host.appendChild(img);
        host.setAttribute('data-av-ear', '1');
      }
    });
  }

  function boot() {
    inject(document);
    // 只监听 childList（DOM 新增头像），不监听 attributes——
    // 否则注入改 style 会触发 attributes mutation 造成观察循环，卡死主线程(alert 点不动 OK)
    var mo = new MutationObserver(function () { inject(document); });
    mo.observe(document.documentElement, { childList: true, subtree: true });
    // 低频兜底：隐藏 tab（如未激活的个人档案）显示后、插入瞬间未布局的头像，由定时扫描补挂
    setInterval(function () { inject(document); }, 2000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
