/* ============================================================================
 * ChatApp · DeepSeek Agent Engine（apps/deepseek/agent.js）
 *
 * 纯前端「文本协议」工具调用引擎 —— 不需要后端支持 function calling：
 *   1) 系统提示词里声明工具清单 + 调用协议（<tool>{...}</tool>）；
 *   2) 流式解析模型输出，把工具块从正文里「抠」出来自动执行；
 *   3) 结果以 <tool_result> 形式回灌模型，自动进入下一轮，形成 agent 循环。
 *
 * 安全边界（刻意不提供的能力）：
 *   - 没有文件/终端/任意 URL 抓取；工具只有三类：
 *       a. 纯本地计算（时间/算术/单位/随机）
 *       b. 读取「当前登录用户自己」的 ChatApp 数据（只读 GET，走本站 api/）
 *       c. 本地记忆（localStorage，只存在用户自己的浏览器里）
 *   - 所有 ChatApp 工具都无法修改数据、无法访问别人隐私；写操作一律不提供。
 * ==========================================================================*/
(function (global) {
  'use strict';

  /* ========================= 通用小工具 ========================= */
  function clampStr(s, n) {
    s = String(s == null ? '' : s);
    return s.length > n ? s.slice(0, n) + '…' : s;
  }
  function pad2(n) { return (n < 10 ? '0' : '') + n; }
  function round(v, d) {
    var p = Math.pow(10, d == null ? 6 : d);
    return Math.round(v * p) / p;
  }
  function num(v) {
    var n = typeof v === 'number' ? v : parseFloat(String(v == null ? '' : v).replace(/[,，\s]/g, ''));
    return isFinite(n) ? n : NaN;
  }
  function fmtNum(v) {
    if (!isFinite(v)) return String(v);
    if (Number.isInteger(v) && Math.abs(v) < 1e15) return String(v);
    var a = Math.abs(v);
    if (a !== 0 && (a < 1e-4 || a >= 1e15)) return v.toExponential(6).replace(/e([+-])/, 'e$1');
    return String(round(v, 10));
  }
  /* 结果格式化：整数可能是 BigInt（任意精度） */
  function fmtVal(v) {
    if (typeof v === 'bigint') {
      var s = v.toString();
      if (s.length > 4000) return s.slice(0, 4000) + '…（共 ' + s.length + ' 位，已截断）';
      return s;
    }
    return fmtNum(v);
  }
  function argText(args) {
    var out = [];
    Object.keys(args || {}).forEach(function (k) {
      var v = args[k];
      if (v == null || v === '') return;
      out.push(k + '=' + clampStr(typeof v === 'object' ? JSON.stringify(v) : String(v), 40));
    });
    return out.join(' ');
  }

  /* ====================== 安全数学表达式求值 ======================
     自己写词法/递归下降解析，绝不使用 eval / Function —— 无注入面。 */
  var BIG_DIGIT_LIMIT = 4000;      // 单个整数结果的位数上限（防内存爆炸）
  function isBig(v) { return typeof v === 'bigint'; }
  function N(v) { return isBig(v) ? Number(v) : v; }
  function bothBig(a, b) { return isBig(a) && isBig(b); }
  function bigDigits(v) { return v.toString().length; }
  function add(a, b) { return bothBig(a, b) ? a + b : N(a) + N(b); }
  function sub(a, b) { return bothBig(a, b) ? a - b : N(a) - N(b); }
  function mul(a, b) { return bothBig(a, b) ? a * b : N(a) * N(b); }
  function div(a, b) {
    if (bothBig(a, b)) {
      if (b === 0n) throw new Error('除以 0');
      if (a % b === 0n) return a / b;              // 整除 → 保持精确
      return N(a) / N(b);
    }
    var d = N(b);
    if (d === 0) throw new Error('除以 0');
    return N(a) / d;
  }
  function modv(a, b) {
    if (bothBig(a, b)) { if (b === 0n) throw new Error('对 0 取模'); return a % b; }
    var m = N(b);
    if (m === 0) throw new Error('对 0 取模');
    return N(a) % m;
  }
  function powv(a, b) {
    if (bothBig(a, b)) {
      if (b < 0n) return Math.pow(N(a), N(b));
      if (b > 1000000n) throw new Error('指数过大（最多 1000000）');
      var est = bigDigits(a < 0n ? -a : a) * (Number(b) || 1);
      if (est > BIG_DIGIT_LIMIT) throw new Error('幂结果约 ' + est + ' 位，超过 ' + BIG_DIGIT_LIMIT + ' 位上限');
      return a ** b;
    }
    return Math.pow(N(a), N(b));
  }

  var FNS_NUM = {
    sqrt: [Math.sqrt, 1], cbrt: [Math.cbrt, 1], exp: [Math.exp, 1],
    ln: [Math.log, 1], log: [Math.log10, 1], log2: [Math.log2, 1], log10: [Math.log10, 1],
    sin: [Math.sin, 1], cos: [Math.cos, 1], tan: [Math.tan, 1],
    asin: [Math.asin, 1], acos: [Math.acos, 1], atan: [Math.atan, 1],
    sinh: [Math.sinh, 1], cosh: [Math.cosh, 1], tanh: [Math.tanh, 1],
    atan2: [Math.atan2, 2],
    hypot: [function () {
      var s = 0;
      for (var k = 0; k < arguments.length; k++) s += Math.pow(arguments[k], 2);
      return Math.sqrt(s);
    }, -1]
  };
  /* 这些函数对 BigInt 保持精确 */
  var FNS_EXACT = {
    abs: [function (a) { return isBig(a) ? (a < 0n ? -a : a) : Math.abs(a); }, 1],
    sign: [function (a) { return isBig(a) ? (a > 0n ? 1 : (a < 0n ? -1 : 0)) : Math.sign(a); }, 1],
    floor: [function (a) { return isBig(a) ? a : Math.floor(a); }, 1],
    ceil: [function (a) { return isBig(a) ? a : Math.ceil(a); }, 1],
    trunc: [function (a) { return isBig(a) ? a : Math.trunc(a); }, 1],
    round: [function (a) { return isBig(a) ? a : Math.round(a); }, 1],
    pow: [function (a, b) { return powv(a, b); }, 2],
    min: [function () {
      var vals = Array.prototype.slice.call(arguments);
      return vals.every(isBig) ? vals.reduce(function (x, y) { return y < x ? y : x; }) : Math.min.apply(null, vals.map(N));
    }, -1],
    max: [function () {
      var vals = Array.prototype.slice.call(arguments);
      return vals.every(isBig) ? vals.reduce(function (x, y) { return y > x ? y : x; }) : Math.max.apply(null, vals.map(N));
    }, -1],
    fact: [function (a) {
      var k = Number(a);
      if (!isFinite(k) || k < 0) throw new Error('阶乘只支持非负整数');
      k = Math.floor(k);
      if (k > 1000) throw new Error('阶乘最多 1000（再大结果位数太多）');
      var r = 1n;
      for (var i = 2n; i <= BigInt(k); i++) r *= i;
      return r;
    }, 1]
  };

  function evalExpr(input) {
    var s = String(input == null ? '' : input)
      .replace(/[×✕・·∗]/g, '*').replace(/[÷∕]/g, '/').replace(/[−–—]/g, '-')
      .replace(/[（]/g, '(').replace(/[）]/g, ')').replace(/，/g, ',').replace(/\s+/g, '');
    if (!s) throw new Error('表达式为空');
    if (s.length > 250) throw new Error('表达式过长（最多 250 字符）');
    var i = 0;
    function eat(c) { if (s.charAt(i) === c) { i++; return true; } return false; }

    function expr() {
      var v = term();
      for (;;) {
        if (eat('+')) v = add(v, term());
        else if (eat('-')) v = sub(v, term());
        else return v;
      }
    }
    function term() {
      var v = unary();
      for (;;) {
        if (eat('*')) v = mul(v, unary());
        else if (eat('/')) v = div(v, unary());
        else if (eat('%')) v = modv(v, unary());
        else return v;
      }
    }
    function unary() {
      if (eat('-')) { var a = unary(); return isBig(a) ? -a : -a; }
      if (eat('+')) return unary();
      return power();
    }
    function power() {
      var b = atom();
      if (eat('^')) return powv(b, unary());
      return b;
    }
    function atom() {
      if (eat('(')) {
        var v = expr();
        if (!eat(')')) throw new Error('括号不匹配');
        return v;
      }
      var m = /^[0-9]*\.?[0-9]+(?:e[+-]?[0-9]+)?/i.exec(s.slice(i));
      if (m) {
        i += m[0].length;
        var lit = m[0];
        if (!/[.eE]/.test(lit)) {
          if (lit.replace(/^0+/, '').length > BIG_DIGIT_LIMIT) throw new Error('数字太大（最多 ' + BIG_DIGIT_LIMIT + ' 位）');
          return BigInt(lit);
        }
        return parseFloat(lit);
      }
      var id = /^[A-Za-z_\u4e00-\u9fa5][A-Za-z0-9_\u4e00-\u9fa5]*/.exec(s.slice(i));
      if (!id) throw new Error('无法解析：' + clampStr(s.slice(i), 12));
      var name = id[0].toLowerCase();
      i += id[0].length;
      if (name === 'pi') return Math.PI;
      if (name === 'e') return Math.E;
      if (!eat('(')) throw new Error('未知常量：' + name);
      var args = [];
      if (!eat(')')) {
        for (;;) {
          args.push(expr());
          if (eat(',')) continue;
          if (eat(')')) break;
          throw new Error('函数参数括号不匹配');
        }
      }
      var def = FNS_EXACT[name] || FNS_NUM[name];
      if (!def) throw new Error('未知函数：' + name);
      if (def[1] >= 0 && args.length !== def[1]) throw new Error(name + ' 需要 ' + def[1] + ' 个参数');
      /* 浮点函数（sqrt/sin/…）把 BigInt 参数转成 Number；精确函数保持原样 */
      var r = FNS_EXACT[name] ? def[0].apply(null, args) : def[0].apply(null, args.map(N));
      if (isBig(r)) return r;
      if (typeof r !== 'number' || isNaN(r)) throw new Error(name + ' 计算结果无效');
      if (!isFinite(r)) throw new Error(name + ' 结果不是有限数');
      return r;
    }

    var result = expr();
    if (i !== s.length) throw new Error('无法解析：' + clampStr(s.slice(i), 12));
    if (typeof result === 'number' && !isFinite(result)) throw new Error('结果不是有限数');
    return fmtVal(result);
  }

  /* ========================= 单位换算 ========================= */
  var UNITS = {
    '长度': { m: 1, km: 1000, cm: 0.01, mm: 0.001, um: 1e-6, nm: 1e-9, mi: 1609.344, yd: 0.9144, ft: 0.3048, in: 0.0254, nmi: 1852, 里: 500, 尺: 0.3333333333 },
    '质量': { kg: 1, g: 0.001, mg: 1e-6, t: 1000, lb: 0.45359237, oz: 0.028349523125, 斤: 0.5, 两: 0.05 },
    '面积': { m2: 1, km2: 1e6, cm2: 1e-4, ha: 10000, acre: 4046.8564224, ft2: 0.09290304, 亩: 666.6666667 },
    '体积': { l: 1, ml: 0.001, m3: 1000, gal: 3.785411784, qt: 0.946352946, pt: 0.473176473, cup: 0.2365882365, floz: 0.0295735295625 },
    '数据': { b: 1, kb: 1024, mb: 1048576, gb: 1073741824, tb: 1099511627776, pb: 1125899906842624, kbit: 128, mbit: 131072, gbit: 134217728 },
    '时间': { ms: 0.001, s: 1, min: 60, h: 3600, d: 86400, week: 604800, month: 2592000, year: 31536000 },
    '速度': { 'm/s': 1, 'km/h': 0.2777777778, mph: 0.44704, knot: 0.5144444444, 'ft/s': 0.3048 },
    '压强': { pa: 1, kpa: 1000, mpa: 1e6, bar: 100000, atm: 101325, psi: 6894.757293168, mmhg: 133.322387415 },
    '能量': { j: 1, kj: 1000, cal: 4.184, kcal: 4184, wh: 3600, kwh: 3600000, ev: 1.602176634e-19 }
  };
  var TEMP_ALIAS = {
    'c': 'c', '°c': 'c', '℃': 'c', 'celsius': 'c', '摄氏度': 'c', '摄氏': 'c',
    'f': 'f', '°f': 'f', '℉': 'f', 'fahrenheit': 'f', '华氏度': 'f', '华氏': 'f',
    'k': 'k', 'kelvin': 'k', '开尔文': 'k', '开氏度': 'k'
  };
  function normUnit(u) {
    u = String(u == null ? '' : u).trim().toLowerCase().replace(/\s+/g, '').replace(/^°/, '°');
    u = u.replace(/^平方/, '').replace(/\^2$/, '2').replace(/\^3$/, '3');
    return u;
  }
  function convertUnit(value, from, to) {
    var v = num(value);
    if (isNaN(v)) throw new Error('value 必须是数字');
    var f = normUnit(from), t = normUnit(to);
    if (!f || !t) throw new Error('请提供 from / to 单位');
    var fk = TEMP_ALIAS[f], tk = TEMP_ALIAS[t];
    if (fk || tk) {
      if (!fk || !tk) throw new Error('温度与其他单位不能互转');
      var celsius = fk === 'c' ? v : (fk === 'f' ? (v - 32) * 5 / 9 : v - 273.15);
      var out = tk === 'c' ? celsius : (tk === 'f' ? celsius * 9 / 5 + 32 : celsius + 273.15);
      return { value: round(out, 4), from: f, to: t, category: '温度', formula: 'C↔F↔K' };
    }
    var cat = null, fac = null;
    Object.keys(UNITS).forEach(function (k) {
      if (fac) return;
      if (UNITS[k][f] != null && UNITS[k][t] != null) { cat = k; fac = UNITS[k]; }
    });
    if (!fac) throw new Error('找不到同时包含 ' + from + ' 与 ' + to + ' 的单位族（可先查支持的族）');
    return { value: round(v * fac[f] / fac[t], 8), from: f, to: t, category: cat };
  }

  /* ========================= 本地记忆 =========================
     只写进用户自己的 localStorage，服务器完全看不到。 */
  var MEM_KEY = 'chatapp_ds_mem';
  function memLoad() {
    try { var o = JSON.parse(localStorage.getItem(MEM_KEY) || '{}'); return (o && typeof o === 'object') ? o : {}; } catch (e) { return {}; }
  }
  function memSave(o) { try { localStorage.setItem(MEM_KEY, JSON.stringify(o)); } catch (e) {} }
  function memSet(key, value) {
    key = clampStr(String(key || '').trim(), 60);
    if (!key) throw new Error('key 不能为空');
    var o = memLoad();
    if (!o[key] && Object.keys(o).length >= 50) throw new Error('本地记忆已满（最多 50 条），先 forget 一些');
    o[key] = { value: clampStr(String(value == null ? '' : value), 500), at: new Date().toISOString() };
    memSave(o);
    return { remembered: key, total: Object.keys(o).length };
  }
  function memGet(key) {
    var o = memLoad();
    if (key) {
      var k = String(key).trim();
      if (!o[k]) return { found: false, key: k, hint: '没有这条记忆，可先用 recall 不带参数看看全部' };
      return { found: true, key: k, value: o[k].value, at: o[k].at };
    }
    var list = Object.keys(o).map(function (k) { return { key: k, value: o[k].value, at: o[k].at }; });
    return { count: list.length, items: list };
  }
  function memDel(key) {
    var k = String(key || '').trim(), o = memLoad();
    if (!o[k]) return { deleted: false, key: k, hint: '没有这条记忆' };
    delete o[k]; memSave(o);
    return { deleted: true, key: k, total: Object.keys(o).length };
  }

  /* ==================== 服务端工具网关（tools.php） ====================
     前端只发「我要调用哪个工具 + 参数」，权限 / 参数校验 / 限流 / 审计全在服务端做。
     API_BASE：默认就是本应用目录；嵌入到聊天页（机器人联系人）时用 setApiBase 指过去。 */
  var API_BASE = '/apps/deepseek/';
  function setApiBase(b) {
    if (!b) return;
    b = String(b);
    API_BASE = (b.charAt(b.length - 1) === '/') ? b : (b + '/');
  }
  function postJSON(body) {
    return fetch(API_BASE + 'tools.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (r) {
      if (!r.ok) throw new Error('工具服务返回 HTTP ' + r.status);
      return r.json().catch(function () { throw new Error('工具服务响应不是 JSON'); });
    });
  }
  function serverRun(tool, args) {
    return postJSON({ action: 'run', tool: tool, args: args || {} }).then(function (j) {
      if (!j || j.ok !== true) throw new Error((j && j.error) || '服务端拒绝了这次调用');
      return j.data;
    });
  }

  /* ================= 运行时状态：可用性 + 用户勾选 =================
     前端开关只能「收窄」可用工具；服务端会再判一次，绝不依赖这里。 */
  var ctx = { loggedIn: false, isAdmin: false, aiLevel: 0 };
  var enabled = {};   // {toolName: bool}；页面从 localStorage 恢复
  /* 账号级 AI 权限档位（服务端 users.ai_level 为准，这里只是 UI 提示） */
  var LEVEL_NAMES = ['关闭', '仅读', '读写', '允许所有'];
  /* 每个工具需要的最低档位 —— 必须和 tools.php 里 $TOOL_DEFS 的 min_level 一致 */
  var TOOL_MIN_LEVEL = {
    ca_leaderboard: 0, ca_emoji: 0,
    ca_profile: 1, ca_level: 1, ca_find_user: 1, ca_conversations: 1, ca_groups: 1,
    ca_online: 1, ca_tickets: 1, ca_history: 1, ca_message: 1,
    ca_send_dm: 2,
    ca_admin_stats: 3
  };
  function levelName(v) { v = Number(v) || 0; return LEVEL_NAMES[v < 0 ? 0 : (v > 3 ? 3 : v)]; }
  function minLevelOf(t) { return (t && TOOL_MIN_LEVEL[t.name] != null) ? TOOL_MIN_LEVEL[t.name] : 0; }
  /* 旧名字（历史对话里可能残留）→ 新名字 */
  var ALIAS = {
    get_time: 'now', my_profile: 'ca_profile', my_level: 'ca_level', leaderboard: 'ca_leaderboard',
    find_user: 'ca_find_user', my_conversations: 'ca_conversations', my_groups: 'ca_groups',
    check_online: 'ca_online', my_tickets: 'ca_tickets'
  };
  function resolveName(n) { n = String(n || '').trim(); return ALIAS[n] || n; }
  function setContext(c) {
    c = c || {};
    if (c.loggedIn != null) ctx.loggedIn = !!c.loggedIn;
    if (c.isAdmin != null) ctx.isAdmin = !!c.isAdmin;
    if (c.aiLevel != null) ctx.aiLevel = Math.max(0, Math.min(3, Number(c.aiLevel) || 0));
    else if (c.prefs) {   // 兼容旧字段
      var lv = 0;
      if (Number(c.prefs.ai_send_dm)) lv = 2;
      else if (Number(c.prefs.ai_read_chats)) lv = 1;
      ctx.aiLevel = lv;
    }
  }
  function setEnabled(map) { enabled = (map && typeof map === 'object') ? map : {}; }
  function isOn(t) {
    if (!t) return false;
    if (enabled[t.name] != null) return !!enabled[t.name];
    return t.defaultOn !== false;
  }
  /* 不可用的原因（null = 可用）。注意：这只是 UI 提示，服务端会再判一次。 */
  function unavailableReason(t) {
    if (t.needsLogin && !ctx.loggedIn) return '需要先登录 ChatApp';
    if (t.scope === 'admin' && !ctx.isAdmin) return '仅站主/管理员可用';
    var need = minLevelOf(t);
    if (need && ctx.aiLevel < need) return '需要 AI 权限「' + levelName(need) + '」（当前：' + levelName(ctx.aiLevel) + '）';
    return null;
  }
  function activeTools() {
    return TOOLS.filter(function (t) { return isOn(t) && !unavailableReason(t); });
  }
  function defaultEnabledMap() {
    var m = {};
    TOOLS.forEach(function (t) { m[t.name] = t.defaultOn !== false; });
    return m;
  }
  /* 按档位生成一份勾选表：档位允许的工具全部打开，档位外的一律关掉
     （页面在用户切换档位时用它重置逐项勾选，省得让人点 20 个开关） */
  function prefsForLevel(level) {
    var lv = Math.max(0, Math.min(3, Number(level) || 0));
    var m = {};
    TOOLS.forEach(function (t) {
      m[t.name] = (minLevelOf(t) <= lv) && !(t.needsLogin && !ctx.loggedIn);
    });
    return m;
  }

  /* ========================= 工具定义 ========================= */
  /* run(args) → Promise<object>；抛错 = 执行失败（会以 ok:false 回灌模型） */
  var TOOLS = [
    {
      name: 'now', group: 'local', scope: 'local', defaultOn: true,
      desc: '获取当前日期时间（含星期、UTC 偏移、时间戳）',
      params: { timezone: '可选。形如 "+08:00" 或 "-05:00"；默认用你在 ChatApp 设置里的时区，再退回浏览器本地时区' },
      run: function (a) {
        var now = new Date();
        var off = null, src = '浏览器本地时区';
        if (a.timezone && /^[+-]\d{1,2}:?\d{0,2}$/.test(String(a.timezone).trim())) {
          var m = /^([+-])(\d{1,2}):?(\d{0,2})$/.exec(String(a.timezone).trim());
          off = (m[1] === '-' ? -1 : 1) * (parseInt(m[2], 10) * 60 + parseInt(m[3] || '0', 10));
          src = 'ChatApp 时区设置';
        } else {
          off = -now.getTimezoneOffset();
        }
        var t = new Date(now.getTime() + off * 60000);
        var sign = off < 0 ? '-' : '+';
        var absOff = Math.abs(off);
        return Promise.resolve({
          time: t.getUTCFullYear() + '-' + pad2(t.getUTCMonth() + 1) + '-' + pad2(t.getUTCDate()) + ' ' +
                pad2(t.getUTCHours()) + ':' + pad2(t.getUTCMinutes()) + ':' + pad2(t.getUTCSeconds()),
          weekday: ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'][t.getUTCDay()],
          utc_offset: sign + pad2(Math.floor(absOff / 60)) + ':' + pad2(absOff % 60),
          source: src,
          unix: Math.floor(now.getTime() / 1000)
        });
      }
    },
    {
      name: 'calc', group: 'local', scope: 'local', defaultOn: true,
      desc: '精确计算数学表达式（+ - * / % ^、括号、sqrt/abs/round/min/max/log/sin 等函数、常量 pi/e、阶乘 fact）；**整数是任意精度**（2^200、fact(100)、大数乘除都不会丢精度），带小数或开方才落回浮点',
      params: { expr: '表达式字符串，如 "(3+5)*2"、"sqrt(2)*100"、"2^10"、"fact(5)+1"' },
      run: function (a) {
        if (!a.expr) throw new Error('缺少 expr');
        return Promise.resolve({ expr: String(a.expr), result: evalExpr(a.expr) });
      }
    },
    {
      name: 'convert', group: 'local', scope: 'local', defaultOn: true,
      desc: '单位换算，支持：长度/质量/面积/体积/数据/时间/速度/压强/能量/温度',
      params: { value: '数值', from: '原单位，如 km、lb、gb、c', to: '目标单位，如 mi、kg、mb、f' },
      run: function (a) { return Promise.resolve(convertUnit(a.value, a.from, a.to)); }
    },
    {
      name: 'random', group: 'local', scope: 'local', defaultOn: true,
      desc: '随机数 / 掷骰子 / 抽签 / 抛硬币',
      params: {
        kind: 'dice（骰式，如 2d6+3）| number（区间整数）| pick（从候选中抽一个）| coin（抛硬币）',
        dice: 'kind=dice 时的骰式，如 "2d6+3"、"1d100"',
        min: 'kind=number 时最小值（默认 1）', max: 'kind=number 时最大值（默认 100）',
        items: 'kind=pick 时的候选数组，如 ["A","B","C"]'
      },
      run: function (a) {
        var kind = String(a.kind || 'number').toLowerCase();
        if (kind === 'dice' || a.dice) {
          var m = /^(\d*)d(\d+)([+-]\d+)?$/i.exec(String(a.dice || '1d6').trim());
          if (!m) throw new Error('骰式格式如 2d6+3');
          var cnt = m[1] ? parseInt(m[1], 10) : 1, face = parseInt(m[2], 10), mod = m[3] ? parseInt(m[3], 10) : 0;
          if (cnt < 1 || cnt > 100) throw new Error('骰子个数 1~100');
          if (face < 2 || face > 1000000) throw new Error('骰面 2~1000000');
          var rolls = [], sum = 0;
          for (var i = 0; i < cnt; i++) { var r = 1 + Math.floor(Math.random() * face); rolls.push(r); sum += r; }
          return Promise.resolve({ kind: 'dice', dice: String(a.dice || '1d6'), rolls: rolls.length > 50 ? rolls.slice(0, 50).concat(['…']) : rolls, total: sum + mod, modifier: mod });
        }
        if (kind === 'pick') {
          var items = a.items;
          if (typeof items === 'string') { try { items = JSON.parse(items); } catch (e) { items = items.split(/[,，\n]/); } }
          if (!Array.isArray(items) || !items.length) throw new Error('items 需要是数组');
          return Promise.resolve({ kind: 'pick', picked: items[Math.floor(Math.random() * items.length)], from: items.length });
        }
        if (kind === 'coin') return Promise.resolve({ kind: 'coin', result: Math.random() < 0.5 ? '正面' : '反面' });
        var lo = a.min == null || a.min === '' ? 1 : Math.ceil(num(a.min));
        var hi = a.max == null || a.max === '' ? 100 : Math.floor(num(a.max));
        if (!isFinite(lo) || !isFinite(hi)) throw new Error('min/max 必须是数字');
        if (hi < lo) { var t = lo; lo = hi; hi = t; }
        return Promise.resolve({ kind: 'number', min: lo, max: hi, result: lo + Math.floor(Math.random() * (hi - lo + 1)) });
      }
    },
    {
      name: 'ca_profile', group: 'mine', scope: 'self', server: true, defaultOn: true, needsLogin: true,
      desc: '读取我自己的 ChatApp 资料（用户名/UID/显示名/性别/生日/时区/签名/等级/隐私开关/注册时间）',
      params: {}
    },
    {
      name: 'ca_level', group: 'mine', scope: 'self', server: true, defaultOn: true, needsLogin: true,
      desc: '读取我的等级与经验：当前等级、EXP、升级进度、连续签到、今日是否已签到、等级上限',
      params: {}
    },
    {
      name: 'ca_leaderboard', group: 'mine', scope: 'public', server: true, defaultOn: true, needsLogin: true,
      desc: '查看全站等级排行榜（按等级 + EXP）',
      params: { limit: '返回前 N 名，默认 10，最多 50' }
    },
    {
      name: 'ca_find_user', group: 'mine', scope: 'self', server: true, defaultOn: true, needsLogin: true,
      desc: '按用户名或 UID 精确查找用户（遵守对方隐私设置；查不到说明对方不可被搜索或不存在）',
      params: { q: '完整用户名，或 UID（数字）' }
    },
    {
      name: 'ca_conversations', group: 'mine', scope: 'self', server: true, defaultOn: true, needsLogin: true,
      desc: '读取我最近的私聊会话列表（对方名字、最后消息时间、未读数；是否附消息预览由账号开关决定）',
      params: { limit: '条数，默认 10，最多 30' }
    },
    {
      name: 'ca_groups', group: 'mine', scope: 'self', server: true, defaultOn: true, needsLogin: true,
      desc: '读取我加入的群聊列表（群名、GID、我在群里的角色、是否免打扰）',
      params: { limit: '条数，默认 10，最多 30' }
    },
    {
      name: 'ca_online', group: 'mine', scope: 'self', server: true, defaultOn: true, needsLogin: true,
      desc: '查询若干用户现在是否在线（以及是否勿扰、是否在打字）',
      params: { users: '用户名数组，如 ["alice","bob"]，最多 20 个' }
    },
    {
      name: 'ca_tickets', group: 'mine', scope: 'self', server: true, defaultOn: true, needsLogin: true,
      desc: '读取我自己提交过的工单（Bug / 建议 / 账号问题）及状态',
      params: { status: 'open（进行中，默认）| closed（已结束）| all（全部）', limit: '条数，默认 10，最多 30' }
    },
    {
      name: 'ca_history', group: 'mine', scope: 'self', server: true, defaultOn: false, needsLogin: true,
      desc: '读聊天记录（只读；只能读我自己参与的对话）。按对方用户名读私聊，或按群名/GID 读群聊；支持关键词搜索与翻页',
      params: {
        with: '对方用户名（读和 TA 的私聊）',
        group: '群名或 GID（读群聊）——with 与 group 二选一',
        keyword: '可选，按关键词过滤',
        limit: '条数，默认 20，最多 50',
        before_id: '可选，翻页：只取 id 小于它的更早消息'
      }
    },
    {
      name: 'ca_message', group: 'mine', scope: 'self', server: true, defaultOn: false, needsLogin: true,
      desc: '按消息 ID 读一条具体消息（只能读我参与的对话里的消息；端到端加密的消息读不出内容）',
      params: { id: '消息 ID（数字）' }
    },
    {
      name: 'ca_emoji', group: 'mine', scope: 'public', server: true, defaultOn: true, needsLogin: true,
      desc: '在内置表情包里按关键词搜表情，返回可直接写在回复里的表情代码（如 /微笑、/流泪）；写进回复即渲染成表情图。提示词里已经给了全表，一般不用调这个 —— 只在表被截断或想确认某个意思对应哪个代码时用',
      params: { q: '关键词，如 哭、笑、猫、爱心', limit: '返回几个，默认 12，最多 40' }
    },

    /* ---------- 会真正改动数据的操作（默认关闭 + 账号级开关 + 服务端限流） ---------- */
    {
      name: 'ca_send_dm', group: 'write', scope: 'write', server: true, defaultOn: false, needsLogin: true,
      desc: '以「我」的身份给好友发一条私聊消息（走网页同一套发送管线：仅限好友、黑名单/受限账号会被拒绝；5 分钟最多 3 条）',
      params: { to: '收件人用户名（必须是你的好友）', text: '消息内容，最多 500 字' }
    },

    /* ---------- 管理员 ---------- */
    {
      name: 'ca_admin_stats', group: 'admin', scope: 'admin', server: true, defaultOn: false, needsLogin: true,
      desc: '站点统计：用户数 / 在线数 / 消息总数与今日消息 / 群数 / 待处理工单（仅站主与管理员）',
      params: {}
    },
    {
      name: 'remember', group: 'local', scope: 'local', defaultOn: true,
      desc: '把一小段信息记到本地（只存在我自己的浏览器里，服务器看不到），以后可以用 recall 取回',
      params: { key: '标题/键，如 "生日提醒"', value: '内容，最多 500 字' },
      run: function (a) { return Promise.resolve(memSet(a.key, a.value)); }
    },
    {
      name: 'recall', group: 'local', scope: 'local', defaultOn: true,
      desc: '取回本地记住的信息；不传 key 就列出全部记忆',
      params: { key: '可选，要取回的键' },
      run: function (a) { return Promise.resolve(memGet(a.key)); }
    },
    {
      name: 'forget', group: 'local', scope: 'local', defaultOn: true,
      desc: '删除一条本地记忆',
      params: { key: '要删除的键' },
      run: function (a) { return Promise.resolve(memDel(a.key)); }
    }
  ];

  var TOOL_MAP = {};
  TOOLS.forEach(function (t) { TOOL_MAP[t.name] = t; });

  /* ==================== 系统提示词（默认人设） ====================
     这就是内置的「好用的默认提示词」：写清风格 + 协议 + 边界。
     用户可在设置里覆盖；留空 = 用这份。 */
  function persona() {
    return [
      '你是 ChatApp 里的 AI 助手（底层模型 DeepSeek V4 Flash），一个可以调用工具、自己动手把事办完的小助手。',
      '',
      '## 说话方式',
      '- 用和用户相同的语言回答（用户说中文就中文，说英文就英文），不要中英混着来。',
      '- 先给结论，再给必要的细节；能一句话说清就别写三段。',
      '- 可以用 Markdown（**加粗**、列表、`代码`、表格）和适量 emoji，但别堆砌。',
      '- 不确定就说不确定，不知道就说不知道。绝不编造事实、数据、链接或工具结果。',
      '',
      '## 什么时候用工具',
      '- 问到「现在几点/今天几号」→ now；任何算术、单位换算 → calc / convert，不要心算（calc 的整数是任意精度，位数再多也不怕）。',
      '- 问到用户自己的资料、等级、排行榜、好友、群、会话、工单 → 用 ca_* 工具去查，不要凭印象猜。',
      '- 要看聊天内容时用 ca_history（按对方用户名或群）或 ca_message（按消息 ID）；这两个需要用户先在设置里开启「允许 AI 读取会话摘要」，没开时会返回权限错误。',
      '- 表情代码表已经在下文「表情」一节里给全了，直接用；只有在表里找不到想要的意思时才调 ca_emoji 搜。',
      '- 用户让你「记一下」→ remember；问「我之前让你记的」→ recall。',
      '- 闲聊、写作、解释概念、写代码这类不需要外部信息的问题，直接回答，别硬用工具。',
      '- 一轮最多调用 3 个工具；能一次查完就别来回多次。',
      '',
      '## 表情与图片',
      '- 表达情绪时用 ChatApp 内置表情代码（`/打招呼`、`/笑哭` 这种），写进正文即渲染成表情图；别用 Unicode emoji（👋😊）、颜文字或 HTML —— 完整代码表在下面「表情」一节。',
      '- 表情别堆砌：一句话后面跟一两个就够，别连着列一排。',
      '- 用户可以直接发图片（截图 / 涂鸦 / 表情包 / 自定义表情），你有视觉能力，看得懂就直接看着回答，不要假装没看到；描述图片时简洁一点。',
      '',
      '## 关于会改动数据的工具',
      '- ca_send_dm 会真的以用户身份把消息发出去。只有用户明确说「帮我发给某人」时才能调用。',
      '- 发送前先把「发给谁 + 发什么」说清楚让用户确认；用户已在上一条消息里写明了收件人与内容时可直接发。',
      '- 一次只发一条，绝不群发；发完把发送结果（含时间）如实告诉用户。',
      '- 其余工具都是只读的：不要答应帮用户改密码、删好友、改资料、发动态，指路让他自己在对应页面操作。',
      '',
      '## 边界（很重要）',
      '- 你只有下面列出的工具。你**没有**文件系统、终端、浏览器控制或访问服务器/数据库的能力。',
      '- 不索取密码、验证码、API Key 等敏感信息；用户主动贴出来也不要复述。',
      '- 只看用户当前账号能看的数据，不猜测、不套取其他用户的隐私。',
      '- 违法、有害、伤害他人的请求直接拒绝，一句话说明原因就好。'
    ].join('\n');
  }

  function protocolSection(tools) {
    var lines = [
      '## 怎么调用工具（必须严格照做）',
      '这个会话已经把下面的工具用 **API 原生 function calling** 注册好了（请求里的 tools 参数）。',
      '- 要调用工具就**直接发起 function call**（工具调用），参数放在 arguments 里；不要在正文里写任何标签、JSON、伪代码或自创语法。',
      '- 正文（content）里只写给用户看的话；工具调用不会显示给用户。',
      '- **绝对不要输出任何形如 `<|DSML|…|>`、`<tool>`、`<use_tool>`、XML/HTML 的标记，也不要写「我准备调用 xx」这类伪代码** —— 这些会被原样显示给用户，看起来就是一堆乱码。',
      '',
      '万一当前接口不支持原生 function calling，只允许下面这一种兜底写法（可以和正文混在一起）：',
      '',
      '<use_tool name="工具名">',
      '{"参数名":"参数值"}',
      '</use_tool>',
      '',
      '规则：',
      '- 兜底标签名必须正好是 use_tool，name 用双引号包住；标签里必须是**合法 JSON 对象**（键与字符串都用双引号）。',,
      '- 没有参数的工具，标签里写 {}（也可自闭合：<use_tool name="now"/>）。',
      '- 只有一个参数的工具，可以省略 JSON 直接写值：<use_tool name="calc">1+2</use_tool>。',
      '- 一条回复最多 3 个标签；不要写注释，不要输出 HTML。',
      '- 系统随后会给你观察结果：',
      '  <tool_result name="工具名">{"ok":true,"data":{…}}</tool_result>',
      '  然后你继续回答用户。',
      '- 绝不自己编造 <tool_result>；**绝对不要输出 <tool_result> 标签**（那是系统发给你的观察结果，不是你该写的内容，写了会被系统丢掉）。',
      '- 也不要把工具返回的 JSON 原样贴出来 —— 用自然语言把关键信息总结给用户，需要展示表格/列表时自己重排一下。',
      '- 不要复述标签里的 JSON；不要在同一条回复里既调用工具又给出最终结论。',
      '- 返回 ok:false 时按 error 的原因如实说明（未登录 / 不是好友 / 未开启 / 被限流 / 查不到），不要假装成功，也不要反复重试同一个失败调用。',
      '',
      '### 示例',
      '用户：现在几点了？',
      '你：<use_tool name="now">{}</use_tool>',
      '系统：<tool_result name="now">{"ok":true,"data":{"time":"2026-09-12 21:30:05","weekday":"星期六","utc_offset":"+08:00"}}</tool_result>',
      '你：现在是 21:30（星期六，UTC+08:00）。',
      '',
      '用户：我几级了？',
      '你：<use_tool name="ca_level">{}</use_tool>',
      '系统：<tool_result name="ca_level">{"ok":true,"data":{"level":12,"exp":3456,"upgrade_progress_percent":62.5,"signed_today":false}}</tool_result>',
      '你：你现在 12 级、3456 EXP，升级进度 62.5%；今天还没签到，签到能拿 EXP 哦。',
      '',
      '## 你当前可用的工具'];
    tools.forEach(function (t) {
      var ps = Object.keys(t.params || {});
      var pd = ps.length ? '参数：' + ps.map(function (k) { return k + '（' + t.params[k] + '）'; }).join('；') : '无参数';
      var tag = t.scope === 'write' ? '**[会改数据]** ' : (t.scope === 'admin' ? '**[管理员]** ' : '');
      lines.push('- ' + tag + t.name + '：' + t.desc + '。' + pd);
    });
    if (!tools.length) lines.push('-（用户把工具都关了，请直接用已有知识回答）');
    return lines.join('\n');
  }

  function contextSection(c) {
    c = c || {};
    var t = new Date(), off = -t.getTimezoneOffset(), sign = off < 0 ? '-' : '+', ao = Math.abs(off);
    var lines = ['## 当前上下文',
      '- 时间：' + t.getFullYear() + '-' + pad2(t.getMonth() + 1) + '-' + pad2(t.getDate()) + ' ' +
        pad2(t.getHours()) + ':' + pad2(t.getMinutes()) + '（浏览器本地 UTC' + sign + pad2(Math.floor(ao / 60)) + ':' + pad2(ao % 60) + '）',
      '- 模型：DeepSeek V4 Flash  ·  运行环境：ChatApp「Deepseek」应用（可调用工具的 agent）'];
    if (c.username) lines.push('- 当前登录用户：' + c.username + (c.uid ? '（UID ' + c.uid + '）' : ''));
    else lines.push('- 当前未登录：要使用 ca_* 系列工具需要先登录 ChatApp');
    if (c.isAdmin) lines.push('- 账号身份：站主/管理员（可使用 ca_admin_stats）');
    if (c.lang) lines.push('- 界面语言：' + c.lang);
    if (c.memCount) lines.push('- 本地记忆：' + c.memCount + ' 条（可用 recall 查看）');
    return lines.join('\n');
  }

  /* 内置表情约定（始终附加，即使用户换了自定义人设）：
     让模型写 ChatApp 的 /代码，而不是 Unicode emoji。
     整个内置表情表直接写进提示词 —— 表不大（~200 条，1~2KB），比让模型去调工具搜省事，
     而且未登录时也能用。表太大时（超过 EMOJI_LIST_LIMIT）自动截断并退回 ca_emoji。 */
  var EMOJI_LIST_LIMIT = 500;
  function emojiCatalog() {
    var list = (global.EMOJI_BUILTIN && global.EMOJI_BUILTIN.length) ? global.EMOJI_BUILTIN : null;
    if (!list) return null;
    var order = [], byGroup = {}, n = 0;
    for (var i = 0; i < list.length && n < EMOJI_LIST_LIMIT; i++) {
      var e = list[i] || {}, code = String(e.code || '');
      if (!code || !e.img) continue;                    // 没有图的代码渲染不出表情，不给模型
      var g = String(e.group || '表情');
      if (!byGroup[g]) { byGroup[g] = []; order.push(g); }
      if (byGroup[g].indexOf(code) >= 0) continue;
      byGroup[g].push(code);
      n++;
    }
    if (!order.length) return null;
    var truncated = n < list.filter(function (x) { return x && x.img && x.code; }).length;
    return {
      text: order.map(function (g) { return '- ' + g + '：' + byGroup[g].join(' '); }).join('\n'),
      count: n,
      truncated: truncated
    };
  }

  function emojiSection() {
    var lines = [
      '## 表情：只用 ChatApp 内置表情代码',
      '- 想表达情绪（打招呼 / 笑 / 哭 / 比心…）时，**直接把内置表情代码写在正文里**（如 `/打招呼`），前端会渲染成表情图。',
      '- **不要用 Unicode emoji**（👋 😊 🎉 👍 ❤ 这些），不要用颜文字，也不要自己拼 <img>/HTML：“挥手” 就写 `/打招呼`，“笑到哭” 就写 `/笑哭`。',
      '- 只能用下面表里的代码（带斜杠、不加空格、不自造）；表外的写法不会渲染，只会显示成普通文字。'
    ];
    var cat = emojiCatalog();
    if (cat) {
      lines.push('');
      lines.push('### 内置表情全表（共 ' + cat.count + ' 个，按分组）：');
      lines.push(cat.text);
      if (cat.truncated) lines.push('（表太长已截断，缺的用 ca_emoji 搜）');
    } else {
      lines.push('- 代码表拿不到时，先用 ca_emoji 搜（q 填「打招呼」「哭」「比心」这类意思）再用到回复里。');
    }
    lines.push('- 不要堆砌：一句话后面带一两个就够。');
    return lines.join('\n');
  }

  /* cfg: {system, tools(bool), toolList(可覆盖 activeTools()), ctx} */
  function buildSystemPrompt(cfg, c) {
    cfg = cfg || {};
    var base = (cfg.system && cfg.system.trim()) ? cfg.system.trim() : persona();
    var parts = [base];
    if (cfg.tools === false) {
      parts.push('## 本次会话已关闭工具\n你暂时不能调用任何工具，请直接用已有知识回答；涉及实时信息或用户数据时，说明需要用户去设置里开启工具。');
    } else {
      parts.push(protocolSection(cfg.toolList || activeTools()));
    }
    parts.push(emojiSection());
    parts.push(contextSection(c || ctx));
    return parts.join('\n\n');
  }

  /* ==================== 原生 function calling ====================
     优先走 API 的 tools 参数：调用从 delta.tool_calls 里来（OpenAI 兼容格式），
     模型不需要（也不应该）在正文里写任何调用标记，也就不会再吐 DSML 之类的东西。 */
  function toolSchemas(list) {
    return (list || activeTools()).map(function (t) {
      var props = {}, required = [];
      Object.keys(t.params || {}).forEach(function (k) {
        var d = String(t.params[k] || '');
        props[k] = { type: 'string', description: d };
        // 描述里写了「可选/默认」的当可选参数，其余都标成必填（服务端还会再校验一次）
        if (d.indexOf('可选') < 0 && d.indexOf('默认') < 0) required.push(k);
      });
      var tag = t.scope === 'write' ? '[会改动数据] ' : (t.scope === 'admin' ? '[管理员] ' : '');
      var schema = { type: 'object', properties: props };
      if (required.length) schema.required = required;
      return {
        type: 'function',
        function: {
          name: t.name,
          description: tag + t.desc,
          // 无参数的工具也要给出合法的空对象 schema（properties 必须是 {} 而不是 []）
          parameters: schema
        }
      };
    });
  }

  /* 流式 delta.tool_calls（按 index 分片）→ 完整调用列表 */
  function createToolCallAccumulator() {
    var order = [], byIndex = {};
    function slot(ix) {
      if (!byIndex[ix]) { byIndex[ix] = { id: '', name: '', argsText: '' }; order.push(ix); }
      return byIndex[ix];
    }
    return {
      feed: function (deltas) {
        if (!Array.isArray(deltas)) return;
        deltas.forEach(function (d) {
          if (!d) return;
          var ix = (typeof d.index === 'number') ? d.index : order.length;
          var s = slot(ix);
          if (d.id) s.id = String(d.id);
          var fn = d.function || {};
          if (fn.name) s.name += String(fn.name);            // 名字偶尔也分片
          if (fn.arguments) s.argsText += String(fn.arguments);
        });
      },
      end: function () {
        var out = [];
        order.forEach(function (ix) {
          var s = byIndex[ix];
          if (!s.name) return;
          var txt = String(s.argsText || '').trim(), args = null;
          if (txt) { try { args = JSON.parse(txt); } catch (e) { args = null; } }
          if (!args || typeof args !== 'object' || Array.isArray(args)) args = bodyToArgs(s.name, txt);
          out.push({ type: 'tool', name: resolveName(s.name), args: args, raw: txt, id: s.id, native: true });
        });
        return out;
      }
    };
  }

  /* ==================== DSML 吞拿 ====================
     个别模型会自己吐 DeepSeek 自带的调用标记（<|DSML|calls> / <|DSML|invoke name="x">
     / <|DSML|parameter name="x">…），看着就像乱码。这里全部吞掉，能解析的就当工具调用。 */
  var DSML_OPEN = /<\|\s*DS(?:ML|LM)\s*\|/i;
  var DSML_CLOSE_MARK = /<\/\|\s*DS(?:ML|LM)\s*\|[^>]*>/i;
  var DSML_MARKS = ['<|DSML|', '</|DSML|', '<|DSML|invoke', '<|DSML|calls', '<|DSML|parameter',
                    '</|DSML|invoke>', '</|DSML|calls>', '</|DSML|parameter>',
                    '<|DSLM|', '</|DSLM|', '<|DSLM|invoke', '<|DSLM|calls', '<|DSLM|parameter'];
  /* 找一个 DSML 块：从 <|DSML| 开始，到 </|DSML|calls>（或 </|DSML|invoke>）结束；
     单独落在外面的闭合标记（</|DSML|…>）也当垃圾吞掉；结尾没到就先扣着不上屏 */
  function dsmlFind(buf) {
    var om = DSML_OPEN.exec(buf);
    var cm = DSML_CLOSE_MARK.exec(buf);
    if (cm && (!om || cm.index < om.index)) return { start: cm.index, end: cm.index + cm[0].length, lone: true };
    if (!om) return null;
    var rest = buf.slice(om.index);
    var callsEnd = /<\/\|\s*DS(?:ML|LM)\s*\|\s*calls\s*>/i.exec(rest);
    var invEnd = /<\/\|\s*DS(?:ML|LM)\s*\|\s*invoke\s*>/i.exec(rest);
    var em = null;
    if (callsEnd && (!invEnd || callsEnd.index < invEnd.index)) em = callsEnd;
    else if (invEnd) em = invEnd;
    return { start: om.index, end: em ? (om.index + em.index + em[0].length) : -1 };
  }
  function dsmlCoerce(v) {
    var s = String(v == null ? '' : v).trim();
    try { return JSON.parse(s); } catch (e) { return s; }
  }
  function dsmlParse(block) {
    var calls = [], invRe = /<\|\s*DS(?:ML|LM)\s*\|\s*invoke\s+name\s*=\s*"([^"]+)"\s*>/gi, m, starts = [];
    while ((m = invRe.exec(block)) !== null) starts.push({ name: m[1], at: m.index, body: m.index + m[0].length });
    starts.forEach(function (s, i) {
      var to = (i + 1 < starts.length) ? starts[i + 1].at : block.length;
      var body = block.slice(s.body, to);              // 原始片段：参数标签还留着，才能在下面匹配
      var args = {}, found = false;
      var pr = /<\|\s*DS(?:ML|LM)\s*\|\s*parameter\s+name\s*=\s*"([^"]+)"\s*>([\s\S]*?)<\/?\|\s*DS(?:ML|LM)\s*\|\s*parameter\s*>/gi, pm;
      while ((pm = pr.exec(body)) !== null) { found = true; args[pm[1]] = dsmlCoerce(pm[2]); }
      if (!found) {
        var jm = /\{[\s\S]*\}/.exec(body.replace(/<\/?\|?\s*DS(?:ML|LM)\s*\|[^>]*>/gi, '\n'));
        if (jm) { try { var j = JSON.parse(jm[0]); if (j && typeof j === 'object' && !Array.isArray(j)) args = j; } catch (e) {} }
      }
      calls.push({ type: 'tool', name: resolveName(s.name), args: args, raw: block.slice(s.at, to).trim() });
    });
    return calls;
  }
  /* 把协议残留（DSML / use_tool / tool_result）从一段文字里清掉：思维链展示用 */
  function stripProtocol(text) {
    var s = String(text == null ? '' : text);
    for (;;) {
      var d = dsmlFind(s);
      if (!d) break;
      if (d.end < 0) { s = s.slice(0, d.start); break; }
      s = s.slice(0, d.start) + s.slice(d.end);
    }
    s = s.replace(/<\/?\|\s*[A-Za-z]+\s*\|[^>]*>/g, '');
    s = s.replace(/<\/?\s*(?:use_tool|tool|tool_result)[^>]*>/gi, '');
    return s;
  }

  /* ==================== <use_tool> 流式解析器 ====================
     边收边拆：正文照常上屏，工具块被扣下来交给执行器。
     兼容两种写法：
       新：<use_tool name="calc">{"expr":"1+2"}</use_tool>
       旧：<tool>{"name":"calc","args":{"expr":"1+2"}}</tool>（历史对话里可能还有） */
  var OPEN_NEW = '<use_tool', OPEN_OLD = '<tool>', RES_OPEN = '<tool_result', RES_CLOSE = '</tool_result>';

  /* 标签体 → 参数对象；容忍 JSON / 围栏 / 单参数直写 */
  function bodyToArgs(name, body) {
    var txt = String(body == null ? '' : body).trim();
    var t = TOOL_MAP[resolveName(name)];
    var keys = t ? Object.keys(t.params || {}) : [];
    if (!txt) return {};
    var j = null;
    try { j = JSON.parse(txt); } catch (e) {
      var m = /```(?:json)?\s*([\s\S]*?)\s*```/.exec(txt);
      if (m) { try { j = JSON.parse(m[1]); } catch (e2) {} }
    }
    if (j && typeof j === 'object' && !Array.isArray(j)) {
      if (j.args && typeof j.args === 'object' && Object.keys(j).length === 1) return j.args;
      return j;
    }
    if (keys.length === 1) { var o = {}; o[keys[0]] = txt; return o; }
    if (j !== null) { var o2 = {}; o2.value = j; return o2; }
    return {};
  }
  function parseLegacy(raw) {
    var txt = String(raw || '').trim(), j = null;
    try { j = JSON.parse(txt); } catch (e) {
      var m = /```(?:json)?\s*([\s\S]*?)\s*```/.exec(txt);
      if (m) { try { j = JSON.parse(m[1]); } catch (e2) { return null; } } else return null;
    }
    if (!j || typeof j !== 'object') return null;
    var name = j.name || j.tool || j.tool_name;
    if (!name) return null;
    var args = j.args != null ? j.args : (j.arguments != null ? j.arguments : j.parameters);
    if (typeof args === 'string') { try { args = JSON.parse(args); } catch (e) { args = {}; } }
    if (!args || typeof args !== 'object') args = {};
    return { type: 'tool', name: resolveName(String(name).trim()), args: args, raw: txt, legacy: true };
  }

  /* 末尾可能是被截断的标签开头 → 先扣住不上屏 */
  function partialTagCut(s) {
    var cut = s.length;
    var marks = [OPEN_NEW, OPEN_OLD, RES_OPEN, RES_CLOSE].concat(DSML_MARKS);
    marks.forEach(function (o) {
      var max = Math.min(o.length - 1, s.length);
      for (var k = 1; k <= max; k++) {
        if (o.indexOf(s.slice(-k)) === 0) { cut = Math.min(cut, s.length - k); break; }
      }
    });
    return cut;
  }
  function openTagOf(s) {
    var m = /name\s*=\s*"([^"]*)"/.exec(s) || /name\s*=\s*'([^']*)'/.exec(s);
    return m ? m[1].trim() : null;
  }

  function createStreamParser() {
    var buf = '', pending = [];
    function push(x) { pending.push(x); }
    function drain(final) {
      for (;;) {
        var iNew = buf.indexOf(OPEN_NEW), iOld = buf.indexOf(OPEN_OLD), iRes = buf.indexOf(RES_OPEN);
        var dsm = dsmlFind(buf);
        var i = -1, kind = '';
        if (dsm && (iNew < 0 || dsm.start < iNew) && (iOld < 0 || dsm.start < iOld) && (iRes < 0 || dsm.start < iRes)) {
          i = dsm.start; kind = 'dsml';
        }
        else if (iRes >= 0 && (iNew < 0 || iRes < iNew) && (iOld < 0 || iRes < iOld)) { i = iRes; kind = 'res'; }
        else if (iNew >= 0 && (iOld < 0 || iNew <= iOld)) { i = iNew; kind = 'new'; }
        else if (iOld >= 0) { i = iOld; kind = 'old'; }

        if (i < 0) {
          if (final) { if (buf) push({ type: 'text', text: buf }); buf = ''; break; }
          var cut = partialTagCut(buf);
          if (cut > 0) push({ type: 'text', text: buf.slice(0, cut) });
          buf = buf.slice(cut);
          break;
        }
        if (i > 0) push({ type: 'text', text: buf.slice(0, i) });
        buf = buf.slice(i);

        if (kind === 'dsml') {
          /* 模型自己吐的 <|DSML|…> 调用标记：解析成工具调用，一个字都不上屏 */
          if (dsm.end < 0) { if (final) { buf = ''; break; } break; }
          var blen = dsm.end - dsm.start;              /* buf 已经被切到起点，长度要按相对量算 */
          var block = buf.slice(0, blen);
          buf = buf.slice(blen);
          if (!dsm.lone) dsmlParse(block).forEach(push);
          while (buf.charAt(0) === '\n') buf = buf.slice(1);
          continue;
        }
        if (kind === 'res') {
          /* 模型有时会把系统给它的 <tool_result> 原样吐出来 → 全部吞掉，不上屏也不入历史 */
          var rEnd = buf.indexOf(RES_CLOSE);
          if (rEnd < 0) { if (final) { buf = ''; break; } break; }
          buf = buf.slice(rEnd + RES_CLOSE.length);
          while (buf.charAt(0) === '\n') buf = buf.slice(1);
          continue;
        }

        if (kind === 'old') {                          /* ---- 旧格式 ---- */
          var jOld = buf.indexOf('</tool>');
          if (jOld < 0) { if (final) { push({ type: 'text', text: buf }); buf = ''; break; } break; }
          var rawOld = buf.slice(OPEN_OLD.length, jOld);
          buf = buf.slice(jOld + '</tool>'.length);
          var cOld = parseLegacy(rawOld);
          if (cOld) push(cOld);
          continue;
        }
        /* ---- 新格式 ---- */
        var gt = buf.indexOf('>');
        if (gt < 0) { if (final) { push({ type: 'text', text: buf }); buf = ''; break; } break; }
        var openTag = buf.slice(0, gt + 1);
        var nm = openTagOf(openTag);
        if (/\/\s*>$/.test(openTag)) {                 /* 自闭合 */
          buf = buf.slice(gt + 1);
          if (nm) push({ type: 'tool', name: resolveName(nm), args: {}, raw: openTag });
          continue;
        }
        var end = buf.indexOf('</use_tool>', gt);
        if (end < 0) { if (final) { push({ type: 'text', text: buf }); buf = ''; break; } break; }
        var body = buf.slice(gt + 1, end);
        buf = buf.slice(end + '</use_tool>'.length);
        if (nm) push({ type: 'tool', name: resolveName(nm), args: bodyToArgs(nm, body), raw: openTag + body + '</use_tool>' });
        else { var cFb = parseLegacy(body); if (cFb) push(cFb); }   /* name 写漏了但体内是 JSON */
      }
      var out = pending; pending = [];
      return out;
    }
    return {
      feed: function (chunk) { buf += chunk; return drain(false); },
      end: function () { return drain(true); }
    };
  }

  /* 兜底：整段文本里若只有 ```tool 围栏（模型没按协议输出）也认 */
  function extractFencedCalls(text) {
    var re = /```(?:tool|tool_call|use_tool)\s*([\s\S]*?)```/g, calls = [], m, cleaned = text;
    while ((m = re.exec(text)) !== null) {
      var c = parseLegacy(m[1]);
      if (c) calls.push(c);
    }
    if (calls.length) cleaned = text.replace(re, '').replace(/\n{3,}/g, '\n\n');
    return { calls: calls, text: cleaned };
  }

  /* 把一段历史内容拆成 正文 / 工具卡 两类片段（用于重新渲染） */
  function splitToolBlocks(content) {
    var parts = [], buf = String(content == null ? '' : content);
    function openTagOf(s) {
      var m = /name\s*=\s*"([^"]*)"/.exec(s) || /name\s*=\s*'([^']*)'/.exec(s);
      return m ? m[1].trim() : null;
    }
    for (;;) {
      var iNew = buf.indexOf(OPEN_NEW), iOld = buf.indexOf(OPEN_OLD), iRes = buf.indexOf(RES_OPEN);
      var dsm2 = dsmlFind(buf);
      var i = -1, kind = '';
      if (dsm2 && (iNew < 0 || dsm2.start < iNew) && (iOld < 0 || dsm2.start < iOld) && (iRes < 0 || dsm2.start < iRes)) { i = dsm2.start; kind = 'dsml'; }
      else if (iRes >= 0 && (iNew < 0 || iRes < iNew) && (iOld < 0 || iRes < iOld)) { i = iRes; kind = 'res'; }
      else if (iNew >= 0 && (iOld < 0 || iNew <= iOld)) { i = iNew; kind = 'new'; }
      else if (iOld >= 0) { i = iOld; kind = 'old'; }
      if (i < 0) { if (buf) parts.push({ type: 'text', text: buf }); break; }
      if (i > 0) parts.push({ type: 'text', text: buf.slice(0, i) });
      buf = buf.slice(i);
      if (kind === 'dsml') {
        if (dsm2.end < 0) break;                       /* 没闭合：整段不显示 */
        var blen2 = dsm2.end - dsm2.start;
        var blk = buf.slice(0, blen2);
        buf = buf.slice(blen2);
        if (!dsm2.lone) dsmlParse(blk).forEach(function (c) { parts.push(c); });
        continue;
      }
      if (kind === 'res') {                            /* 吞掉模型回显的 tool_result */
        var rEnd = buf.indexOf(RES_CLOSE);
        if (rEnd < 0) break;
        buf = buf.slice(rEnd + RES_CLOSE.length);
        while (buf.charAt(0) === '\n') buf = buf.slice(1);
        continue;
      }
      if (kind === 'old') {
        var jOld = buf.indexOf('</tool>');
        if (jOld < 0) { parts.push({ type: 'text', text: buf }); break; }
        var cOld = parseLegacy(buf.slice(OPEN_OLD.length, jOld));
        buf = buf.slice(jOld + '</tool>'.length);
        if (cOld) parts.push(cOld);
        continue;
      }
      var gt = buf.indexOf('>');
      if (gt < 0) { parts.push({ type: 'text', text: buf }); break; }
      var openTag = buf.slice(0, gt + 1);
      var nm = openTagOf(openTag);
      if (/\/\s*>$/.test(openTag)) {
        buf = buf.slice(gt + 1);
        if (nm) parts.push({ type: 'tool', name: resolveName(nm), args: {}, raw: openTag });
        continue;
      }
      var end = buf.indexOf('</use_tool>', gt);
      if (end < 0) { parts.push({ type: 'text', text: buf }); break; }
      var body = buf.slice(gt + 1, end);
      buf = buf.slice(end + '</use_tool>'.length);
      if (nm) parts.push({ type: 'tool', name: resolveName(nm), args: bodyToArgs(nm, body), raw: openTag + body + '</use_tool>' });
      else { var c2 = parseLegacy(body); if (c2) parts.push(c2); }
    }
    var out = [];
    parts.forEach(function (p) {
      if (p.type !== 'text') { out.push(p); return; }
      var f = extractFencedCalls(p.text);
      if (f.calls.length) { if (f.text.trim()) out.push({ type: 'text', text: f.text }); f.calls.forEach(function (c) { out.push(c); }); }
      else out.push(p);
    });
    return out;
  }

  /* ========================= 工具执行 ========================= */
  function execute(name, args) {
    var n = resolveName(name);
    var t = TOOL_MAP[n];
    if (!t) {
      return Promise.resolve({
        ok: false,
        error: '不存在名为 "' + name + '" 的工具',
        available: activeTools().map(function (x) { return x.name; })
      });
    }
    var reason = unavailableReason(t);
    if (reason) return Promise.resolve({ ok: false, error: n + ' 暂时不可用：' + reason });
    if (!isOn(t)) {
      return Promise.resolve({ ok: false, error: '用户已把工具 "' + n + '" 关闭了；不要重试，如确实需要请让用户在设置里打开' });
    }

    var t0 = Date.now();
    var p = t.server ? serverRun(n, args || {}) : Promise.resolve().then(function () { return t.run(args || {}); });
    return p.then(function (data) {
      var s = JSON.stringify(data == null ? null : data);
      if (s && s.length > 4000) data = { truncated: true, note: '结果过长已截断，可缩小查询范围', preview: s.slice(0, 4000) };
      if (data && typeof data === 'object' && !Array.isArray(data) && data._ms === undefined) data._ms = Date.now() - t0;
      return { ok: true, data: data };
    }).catch(function (e) {
      return { ok: false, error: (e && e.message) ? e.message : '执行失败', _ms: Date.now() - t0 };
    });
  }
  function executeAll(calls) {
    return Promise.all(calls.map(function (c) {
      return execute(c.name, c.args).then(function (r) {
        return { name: c.name, args: c.args, result: r };
      });
    }));
  }
  /* 回灌给模型的消息体 */
  function resultMessage(rows) {
    var body = rows.map(function (r) {
      return '<tool_result name="' + r.name + '">' + JSON.stringify(r.result) + '</tool_result>';
    }).join('\n');
    return body + '\n（以上是工具的真实返回。若还需要别的信息，可以继续调用工具；否则现在就用用户的语言给出完整回答。）';
  }

  /* ========================= 工具卡片 UI ========================= */
  var STYLE_ID = 'ds-agent-style';
  function injectStyles() {
    if (document.getElementById(STYLE_ID)) return;
    var css = [
      '.ds-tool{margin:4px 0;border:1px solid #3a3a3a;border-left:3px solid #5a7a9a;background:#1f2429;border-radius:5px;',
      '  padding:6px 9px;font-size:.74em;line-height:1.5;color:#9fb4c7;max-width:100%;overflow:hidden}',
      '.ds-tool[data-state="run"]{border-left-color:#c8a35a}',
      '.ds-tool[data-state="err"]{border-left-color:#a05252;color:#d99}',
      '.ds-tool .ds-t-name{font-weight:600;color:#8fc7ff}',
      '.ds-tool .ds-t-args{color:#7d8794;word-break:break-all}',
      '.ds-tool .ds-t-out{margin-top:3px;color:#b9c7d4;word-break:break-word;white-space:pre-wrap}',
      '.ds-tool .ds-t-out.err{color:#e08080}',
      '.ds-tool .ds-t-raw{margin-top:4px;padding:4px 6px;background:#161a1e;border:1px solid #2f3439;border-radius:4px;',
      '  color:#8b98a5;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.92em;white-space:pre-wrap;word-break:break-all;display:none}',
      '.ds-tool.open .ds-t-raw{display:block}',
      '.ds-tool .ds-t-click{cursor:pointer;user-select:none}',
      '.ds-status{color:#8a939c;font-size:.76em;padding:2px 2px 6px;font-style:italic}'
    ].join('\n');
    var st = document.createElement('style');
    st.id = STYLE_ID; st.textContent = css;
    document.head.appendChild(st);
  }
  function toolCard(call) {
    injectStyles();
    var el = document.createElement('div');
    el.className = 'ds-tool';
    el.dataset.state = 'run';
    var head = document.createElement('div');
    head.className = 'ds-t-click';
    head.innerHTML = '🔧 <span class="ds-t-name"></span> <span class="ds-t-args"></span>';
    head.querySelector('.ds-t-name').textContent = call.name;
    head.querySelector('.ds-t-args').textContent = argText(call.args);
    var out = document.createElement('div');
    out.className = 'ds-t-out';
    out.textContent = '执行中…';
    var raw = document.createElement('pre');
    raw.className = 'ds-t-raw';
    el.appendChild(head); el.appendChild(out); el.appendChild(raw);
    head.addEventListener('click', function () { el.classList.toggle('open'); });
    return el;
  }
  function fillToolCard(el, res) {
    var out = el.querySelector('.ds-t-out');
    el.dataset.state = res.ok ? 'ok' : 'err';
    if (res.ok) {
      var s = typeof res.data === 'string' ? res.data : JSON.stringify(res.data);
      out.textContent = clampStr(s, 300);
      el.querySelector('.ds-t-raw').textContent = JSON.stringify(res.data, null, 1) +
        '\n// 耗时 ' + ((res.data && res.data._ms) != null ? res.data._ms : '?') + 'ms';
    } else {
      out.textContent = '✗ ' + (res.error || '执行失败');
      out.classList.add('err');
      el.querySelector('.ds-t-raw').textContent = JSON.stringify(res, null, 1) +
        '\n// 耗时 ' + (res._ms != null ? res._ms : '?') + 'ms';
    }
  }

  /* ========================= 导出 ========================= */
  global.DSAgent = {
    VERSION: '2.0',
    TOOLS: TOOLS,
    apiBase: function () { return API_BASE; },
    setApiBase: setApiBase,
    tool: function (n) { return TOOL_MAP[resolveName(n)] || null; },
    resolveName: resolveName,
    setContext: setContext,
    setEnabled: setEnabled,
    levelName: levelName,
    minLevelOf: minLevelOf,
    prefsForLevel: prefsForLevel,
    isOn: isOn,
    unavailableReason: unavailableReason,
    activeTools: activeTools,
    defaultEnabledMap: defaultEnabledMap,
    persona: persona,
    buildSystemPrompt: buildSystemPrompt,
    createStreamParser: createStreamParser,
    toolSchemas: toolSchemas,
    createToolCallAccumulator: createToolCallAccumulator,
    stripProtocol: stripProtocol,
    splitToolBlocks: splitToolBlocks,
    extractFencedCalls: extractFencedCalls,
    execute: execute,
    executeAll: executeAll,
    resultMessage: resultMessage,
    toolCard: toolCard,
    fillToolCard: fillToolCard,
    evalExpr: evalExpr,
    convertUnit: convertUnit,
    memory: { all: memLoad, set: memSet, get: memGet, del: memDel },
    prefs: function () { return postJSON({ action: 'prefs' }); },
    setPrefs: function (p) { return postJSON({ action: 'set_prefs', prefs: p }); },
    fmtNum: fmtNum
  };
})(window);
