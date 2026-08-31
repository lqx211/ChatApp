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
  // 需要挂耳朵的头像容器选择器（按实际渲染宽度自动取 --av-size）
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

  function inject(root) {
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
        // 宿主需相对定位
        if (getComputedStyle(host).position === 'static') host.style.position = 'relative';
        // 头像容器若 overflow:hidden 会裁掉伸出头外的耳朵 → 改为可见
        host.style.overflow = 'visible';
        var w = c.offsetWidth || c.clientWidth || 0;
        if (w <= 0) continue; // 隐藏元素（未激活 tab/未布局）暂不挂，等可见后 MutationObserver 处理
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
    // 监听动态新增/显示的头像（聊天气泡/联系人/评论等异步渲染，以及隐藏 tab 切显示）
    var mo = new MutationObserver(function () {
      inject(document);
      // 兜底：元素插入瞬间可能未布局(offsetWidth=0)被跳过，延迟补扫一次
      clearTimeout(mo._t);
      mo._t = setTimeout(function () { inject(document); }, 150);
    });
    mo.observe(document.documentElement, { childList: true, subtree: true, attributes: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
