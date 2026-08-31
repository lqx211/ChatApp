#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
ChatApp CLI - 命令行版聊天客户端

功能:
  - 注册 / 登录 / 登出
  - 私聊消息 (发送 / 拉取 / 轮询)
  - 群聊消息 (发送 / 拉取 / 历史)
  - 联系人管理 (搜索 / 好友请求 / 列表)
  - 群组管理 (创建 / 加入 / 成员 / 踢人 / 解散)
  - 未读消息查看

依赖:
  - Python 3.6+ (仅标准库)
"""

import json
import os
import sys
import time
import signal
import threading
import urllib.request
import urllib.parse
import http.cookiejar
import getpass
import readline
import re
from datetime import datetime

# ============================================================
# 配置
# ============================================================
DEFAULT_SERVER = os.environ.get('CHATAPP_SERVER', 'http://127.0.0.1:8080')
POLL_INTERVAL  = int(os.environ.get('CHATAPP_POLL', '2'))  # 轮询间隔(秒)
CONFIG_DIR     = os.path.expanduser('~/.chatapp')
CONFIG_FILE    = os.path.join(CONFIG_DIR, 'config.json')

# ============================================================
# ANSI 颜色
# ============================================================
class C:
    RESET   = '\033[0m'
    BOLD    = '\033[1m'
    DIM     = '\033[2m'
    RED     = '\033[31m'
    GREEN   = '\033[32m'
    YELLOW  = '\033[33m'
    BLUE    = '\033[34m'
    MAGENTA = '\033[35m'
    CYAN    = '\033[36m'
    WHITE   = '\033[37m'

    @staticmethod
    def strip(s: str) -> str:
        """去除 ANSI 转义"""
        return re.sub(r'\033\[[0-9;]*m', '', s)

# ============================================================
# API 客户端
# ============================================================
class ChatAppAPI:
    def __init__(self, server: str):
        server = server.strip()
        # 自动补全协议前缀
        if server and not server.startswith(('http://', 'https://')):
            server = 'http://' + server
        self.server = server.rstrip('/')
        self.cj = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.cj)
        )
        self.opener.addheaders = [
            ('User-Agent', 'ChatApp-CLI/1.0'),
            ('Content-Type', 'application/x-www-form-urlencoded'),
        ]
        self.last_poll = {}
        self.username = None

    # ---------- 底层请求 ----------
    def set_cookie_file(self, path: str) -> None:
        """把会话 cookie 持久化到文件（MozillaCookieJar），便于 AI/脚本跨进程保持登录态。"""
        self.cj = http.cookiejar.MozillaCookieJar(path)
        if os.path.exists(path):
            try:
                self.cj.load(ignore_discard=True, ignore_expires=True)
            except Exception:
                pass
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.cj)
        )
        self.opener.addheaders = [
            ('User-Agent', 'ChatApp-CLI/1.0'),
            ('Content-Type', 'application/x-www-form-urlencoded'),
        ]

    def save_cookies(self) -> None:
        """把当前会话 cookie 写回文件（配合 set_cookie_file 使用）。"""
        try:
            self.cj.save(ignore_discard=True, ignore_expires=True)
        except Exception:
            pass

    def _req(self, path: str, params: dict = None, method: str = 'POST',
             timeout: float = 10) -> dict:
        """发送请求并解析 JSON 响应"""
        url = self.server + path
        data = None
        if params:
            if method == 'GET':
                sep = '&' if '?' in url else '?'
                url += sep + urllib.parse.urlencode(params)
            else:
                data = urllib.parse.urlencode(params).encode('utf-8')
        req = urllib.request.Request(url, data=data, method=method)
        try:
            with self.opener.open(req, timeout=timeout) as resp:
                return json.loads(resp.read().decode('utf-8'))
        except urllib.error.HTTPError as e:
            body = e.read().decode('utf-8', errors='replace')
            try:
                return json.loads(body)
            except Exception:
                return {'success': False, 'error': f'HTTP {e.code}: {body[:200]}'}
        except Exception as e:
            return {'success': False, 'error': f'网络错误: {e}'}

    # ---------- 认证 ----------
    def register(self, username: str, password: str, lang: str = 'en') -> dict:
        r = self._req('/api/auth.php', {
            'action': 'register', 'username': username,
            'password': password, 'language': lang,
            **self._pow_params(),
        })
        if r.get('success'):
            self.username = username
        return r

    def login(self, username: str, password: str) -> dict:
        r = self._req('/api/auth.php', {
            'action': 'login', 'username': username, 'password': password,
            **self._pow_params(),
        })
        if r.get('success'):
            self.username = username
        return r

    # ---------- Proof-of-Work（auth.php 的 PoW 挑战）----------
    # 与 api/pow.php 的 chatapp_pow_hash / chatapp_pow_target 位级一致。
    POW_SEED = [0x24, 0x5a, 0x10, 0x9f, 0x3d, 0x77, 0x81, 0xc2, 0x4b, 0x0e, 0x96, 0x55,
                0x1a, 0x68, 0xdc, 0x03, 0x7e, 0x92, 0x40, 0xcf, 0x11, 0x5d, 0xaa, 0x38,
                0x66, 0xf1, 0x0b, 0x9c, 0x27, 0x74, 0xdb, 0x32]

    @staticmethod
    def pow_hash(input_: str) -> str:
        """Custom PoW hash → 64 lowercase hex chars. Bit-identical to PHP."""
        state = list(ChatAppAPI.POW_SEED)
        b = input_.encode('latin-1')
        n = len(b)
        for rnd in range(32):
            state[0] = (state[0] ^ (rnd + 1)) & 0xff
            for i in range(32):
                ib = b[(i + rnd) % n] if n > 0 else 0
                a = state[i]
                bv = state[(i + 7) % 32]
                c = state[(i + 13) % 32]
                x = (((a << 3) | (a >> 5)) & 0xff)
                x = (x + bv) & 0xff
                x = (x ^ c) & 0xff
                x = (x ^ ib) & 0xff
                k = ((rnd * 31 + i * 7 + 11) & 0xff)
                state[i] = (x + k) & 0xff
            t = state[0]; state[0] = state[31]; state[31] = t
            t = state[5]; state[5] = state[21]; state[21] = t
        return ''.join('%02x' % x for x in state)

    @staticmethod
    def pow_target(bits: int) -> str:
        """Target = 2^(256-bits), 64-char lowercase hex (no big-int needed)."""
        shift = 256 - bits
        idx = shift // 4
        digit = 1 << (shift % 4)
        s = ('%x' % digit) + ('0' * idx)
        return s.rjust(64, '0')

    def _pow_params(self) -> dict:
        """拉取 challenge、爆破 nonce，返回 pow_challenge/pow_nonce 参数字典。"""
        try:
            r = self._req('/api/auth.php?action=challenge', method='GET', timeout=8)
            ch = r.get('challenge')
            if not r.get('success') or not ch:
                return {}
            target = r.get('target') or self.pow_target(int(r.get('target_bits') or 15))
            nonce = 0
            while nonce <= 9999999999:
                if self.pow_hash('%s:%d' % (ch, nonce)) < target:
                    return {'pow_challenge': ch, 'pow_nonce': str(nonce)}
                nonce += 1
        except Exception:
            pass
        return {}

    def logout(self) -> dict:
        return self._req('/api/auth.php', {'action': 'logout'})

    def check(self) -> dict:
        return self._req('/api/auth.php', {'action': 'check'}, method='GET')

    # ---------- 消息 ----------
    def send_dm(self, recipient: str, message: str) -> dict:
        return self._req('/api/chat.php', {
            'action': 'send', 'recipient': recipient, 'message': message,
        })

    def fetch_dm(self, after: int = 0) -> dict:
        return self._req(f'/api/chat.php?action=fetch&after={after}', method='GET')

    def fetch_all(self, before: int = 0, after: int = 0, limit: int = 50,
                  dm: str = '') -> dict:
        q = {'action': 'all', 'limit': limit}
        if before: q['before'] = before
        if after:  q['after'] = after
        if dm:     q['dm'] = dm
        return self._req('/api/chat.php?' + urllib.parse.urlencode(q), method='GET')

    def unread_counts(self) -> dict:
        return self._req('/api/chat.php', {'action': 'unread_counts'})

    def mark_read(self, from_user: str) -> dict:
        return self._req('/api/chat.php', {
            'action': 'mark_read', 'from': from_user,
        })

    def revoke_message(self, message_id: int) -> dict:
        return self._req('/api/chat.php', {
            'action': 'revoke', 'message_id': message_id,
        })

    def search_messages(self, q: str, dm: str = '', group_id: int = 0,
                        page: int = 1) -> dict:
        p = {'action': 'search_messages', 'q': q, 'page': page}
        if dm:      p['dm'] = dm
        if group_id: p['group_id'] = group_id
        return self._req('/api/chat.php?' + urllib.parse.urlencode(p), method='GET')

    # ---------- 联系人 ----------
    def contact_search(self, q: str) -> dict:
        return self._req('/api/contacts.php?action=search&q=' + urllib.parse.quote(q),
                         method='GET')

    def contact_list(self) -> dict:
        return self._req('/api/contacts.php?action=list', method='GET')

    def contact_pending(self) -> dict:
        return self._req('/api/contacts.php?action=pending', method='GET')

    def contact_send_request(self, username: str, msg: str = '') -> dict:
        return self._req('/api/contacts.php', {
            'action': 'send_request', 'username': username, 'msg': msg,
        })

    def contact_respond(self, username: str, response: str,
                        note: str = '') -> dict:
        return self._req('/api/contacts.php', {
            'action': 'respond', 'username': username,
            'response': response, 'note': note,
        })

    def contact_delete(self, username: str) -> dict:
        return self._req('/api/contacts.php', {
            'action': 'delete', 'username': username,
        })

    def contact_nickname(self, username: str, note: str) -> dict:
        return self._req('/api/contacts.php', {
            'action': 'change_nickname', 'username': username, 'note': note,
        })

    # ---------- 群组 ----------
    def group_create(self, name: str) -> dict:
        return self._req('/api/group.php', {
            'action': 'create', 'name': name,
        })

    def group_list_my(self) -> dict:
        return self._req('/api/group.php?action=list_my', method='GET')

    def group_search(self, q: str = '', page: int = 1) -> dict:
        return self._req('/api/group.php?action=search&q=' + urllib.parse.quote(q) +
                         f'&page={page}', method='GET')

    def group_join(self, gid: int) -> dict:
        return self._req('/api/group.php', {
            'action': 'join_by_gid', 'group_id': gid,
        })

    def group_info(self, gid: int) -> dict:
        return self._req(f'/api/group.php?action=info&group_id={gid}', method='GET')

    def group_members(self, gid: int) -> dict:
        return self._req(f'/api/group.php?action=members&group_id={gid}', method='GET')

    def group_send(self, gid: int, message: str) -> dict:
        return self._req('/api/group.php', {
            'action': 'send', 'group_id': gid, 'message': message,
        })

    def group_history(self, gid: int, limit: int = 50, before: int = 0) -> dict:
        p = [f'action=history', f'group_id={gid}', f'limit={limit}']
        if before: p.append(f'before={before}')
        return self._req('/api/group.php?' + '&'.join(p), method='GET')

    def group_fetch(self, gid: int, after: int = 0) -> dict:
        return self._req(f'/api/group.php?action=fetch&group_id={gid}&after={after}',
                         method='GET')

    def group_pending(self, gid: int) -> dict:
        return self._req(f'/api/group.php?action=pending&group_id={gid}', method='GET')

    def group_approve(self, request_id: int) -> dict:
        return self._req('/api/group.php', {
            'action': 'approve', 'request_id': request_id,
        })

    def group_reject(self, request_id: int) -> dict:
        return self._req('/api/group.php', {
            'action': 'reject', 'request_id': request_id,
        })

    def group_kick(self, gid: int, user_id: int) -> dict:
        return self._req('/api/group.php', {
            'action': 'kick', 'group_id': gid, 'user_id': user_id,
        })

    def group_set_admin(self, gid: int, user_id: int) -> dict:
        return self._req('/api/group.php', {
            'action': 'set_admin', 'group_id': gid, 'user_id': user_id,
        })

    def group_unset_admin(self, gid: int, user_id: int) -> dict:
        return self._req('/api/group.php', {
            'action': 'unset_admin', 'group_id': gid, 'user_id': user_id,
        })

    def group_rename(self, gid: int, name: str) -> dict:
        return self._req('/api/group.php', {
            'action': 'rename', 'group_id': gid, 'name': name,
        })

    def group_set_visibility(self, gid: int, public: bool) -> dict:
        return self._req('/api/group.php', {
            'action': 'set_visibility', 'group_id': gid,
            'public': 1 if public else 0,
        })

    def group_toggle_mute(self, gid: int) -> dict:
        return self._req('/api/group.php', {
            'action': 'toggle_mute', 'group_id': gid,
        })

    def group_leave(self, gid: int) -> dict:
        return self._req('/api/group.php', {
            'action': 'leave', 'group_id': gid,
        })

    def group_dissolve(self, gid: int) -> dict:
        return self._req('/api/group.php', {
            'action': 'dissolve', 'group_id': gid,
        })

    # ---------- 设置 ----------
    def change_password(self, current: str, new: str) -> dict:
        return self._req('/api/settings.php', {
            'action': 'change_password',
            'current_password': current, 'new_password': new,
        })

    def change_display_name(self, name: str) -> dict:
        return self._req('/api/settings.php', {
            'action': 'change_display_name', 'display_name': name,
        })

    def change_language(self, lang: str) -> dict:
        return self._req('/api/settings.php', {
            'action': 'change_language', 'language': lang,
        })

    def toggle_dnd(self) -> dict:
        return self._req('/api/settings.php', {'action': 'toggle_dnd'})

    def toggle_data_saver(self) -> dict:
        return self._req('/api/settings.php', {'action': 'toggle_data_saver'})

    def discover_users(self, q: str = '', page: int = 1) -> dict:
        p = {'action': 'discover', 'q': q, 'page': page}
        return self._req('/api/settings.php?' + urllib.parse.urlencode(p), method='GET')


# ============================================================
# 配置持久化
# ============================================================
def load_config() -> dict:
    try:
        with open(CONFIG_FILE, 'r', encoding='utf-8') as f:
            return json.load(f)
    except Exception:
        return {'server': DEFAULT_SERVER}


def save_config(cfg: dict):
    os.makedirs(CONFIG_DIR, exist_ok=True)
    with open(CONFIG_FILE, 'w', encoding='utf-8') as f:
        json.dump(cfg, f, ensure_ascii=False, indent=2)


def save_aliases(aliases: dict):
    cfg = load_config()
    cfg['aliases'] = aliases
    save_config(cfg)


def load_aliases() -> dict:
    return load_config().get('aliases', {})


# ============================================================
# 显示辅助
# ============================================================
def now_str() -> str:
    return datetime.now().strftime('%H:%M:%S')


def banner(text: str):
    w = 60
    print()
    print(C.CYAN + '═' * w + C.RESET)
    print(C.CYAN + '  ' + text + C.RESET)
    print(C.CYAN + '═' * w + C.RESET)
    print()


def info(msg: str):
    print(f'{C.DIM}[{now_str()}]{C.RESET} {msg}')


def ok(msg: str):
    print(f'  {C.GREEN}✔{C.RESET} {msg}')


def err(msg: str):
    print(f'  {C.RED}✘{C.RESET} {msg}')


def fmt_username(u: dict) -> str:
    """格式化用户名 + 显示名"""
    name = u.get('display_name') or u.get('username') or '?'
    uname = u.get('username') or '?'
    if name and name != uname:
        return f'{name} ({uname})'
    return uname


def fmt_msg(msg: dict) -> str:
    """格式化一条消息"""
    who = fmt_username({'username': msg.get('username'), 'display_name': msg.get('display_name')})
    mid = msg.get('id', 0)
    t = msg.get('time', '')

    if msg.get('is_deleted'):
        content = C.DIM + '[此消息已撤回]' + C.RESET
    else:
        mtype = msg.get('msg_type')
        body = msg.get('message', '')

        if mtype == 'photo':
            content = C.MAGENTA + '[图片附件]' + C.RESET
            if msg.get('attachment_url'):
                content += f" {C.DIM}{msg['attachment_url']}{C.RESET}"
        elif mtype == 'audio':
            content = C.MAGENTA + '[语音附件]' + C.RESET
        elif mtype == 'file':
            name = msg.get('attachment_name') or '文件'
            size = msg.get('attachment_size')
            sz = f' ({size} 字节)' if size else ''
            content = C.MAGENTA + f'[文件] {name}{sz}' + C.RESET
        elif mtype == 'temp':
            name = msg.get('attachment_name') or '闪传文件'
            size = msg.get('attachment_size')
            sz = f' ({size} 字节)' if size else ''
            revoked = msg.get('temp_revoked')
            rv = C.RED + ' [已撤回]' + C.RESET if revoked else ''
            content = C.MAGENTA + f'[闪传] {name}{sz}' + C.RESET + rv
        elif mtype == 'md':
            content = C.CYAN + body + C.RESET
        else:
            content = body

    # 回复引用
    if msg.get('reply_data'):
        rd = msg['reply_data']
        rname = rd.get('display_name') or rd.get('username') or '?'
        rmsg = rd.get('message', '')
        content += f"\n{C.DIM}  ↳ 回复 {rname}: {rmsg[:60]}{C.RESET}"

    prefix = C.BLUE + who + C.RESET
    return f'  {C.DIM}[#{mid} {t}]{C.RESET} {prefix}: {content}'


def print_messages(msgs: list, show_from: bool = True):
    if not msgs:
        print('  (暂无消息)')
        return
    for m in msgs:
        who = fmt_username({'username': m.get('username'), 'display_name': m.get('display_name')})
        mid = m.get('id', 0)
        t = m.get('time', '')
        content = m.get('message', '')
        marker = '→' if show_from and m.get('username') != api.username else ' '
        name_color = C.GREEN if m.get('username') != api.username else C.YELLOW
        print(f'  {C.DIM}[#{mid} {t}]{C.RESET} {marker} {name_color}{who}{C.RESET}: {content}')


# ============================================================
# 交互式命令处理
# ============================================================

# 全局 API 实例
api: ChatAppAPI = None
running = True
poll_thread = None


def cmd_help(args):
    banner('帮助')
    print(' 认证')
    print('   login <用户名>           登录')
    print('   register                注册新账号')
    print('   logout                  登出')
    print('   whoami                  查看当前登录状态')
    print()
    print(' 消息')
    print('   send <用户名> <内容>     发送私聊消息')
    print('   msgs [用户名]            查看最近消息 (可指定私聊对象)')
    print('   his <用户名> [数量]      查看与某人的聊天历史')
    print('   unread                  查看未读消息')
    print('   revoke <消息ID>          撤回消息 (发送后2分钟内)')
    print('   search <关键词>          搜索消息')
    print()
    print(' 联系人')
    print('   contacts                列出联系人')
    print('   find <用户名/UID>        搜索用户')
    print('   discover [关键词]        发现用户')
    print('   add <用户名> [附言]      发送好友请求')
    print('   pending                 查看待处理的好友请求')
    print('   accept <用户名>          接受好友请求')
    print('   reject <用户名>          拒绝好友请求')
    print('   nickname <用户名> <备注> 设置好友备注')
    print('   unfriend <用户名>        删除好友')
    print()
    print(' 群组')
    print('   gcreate <名称>           创建群组')
    print('   groups                  我的群组列表')
    print('   gsearch [关键词]         搜索群组')
    print('   gjoin <群组ID>           加入群组')
    print('   ginfo <群组ID>           查看群组信息')
    print('   gmembers <群组ID>        查看群组成员')
    print('   gsend <群组ID> <内容>    发送群消息')
    print('   ghis <群组ID> [数量]     查看群消息历史')
    print('   gpending <群组ID>        查看入群申请 (管理员)')
    print('   gapprove <请求ID>        批准入群申请')
    print('   greject <请求ID>         拒绝入群申请')
    print('   gkick <群组ID> <用户ID>  踢出成员 (管理员)')
    print('   gadmin <群组ID> <用户ID> 设为管理员 (群主)')
    print('   gunadmin <群组ID> <用户ID>取消管理员 (群主)')
    print('   grename <群组ID> <名称>  重命名群组 (群主)')
    print('   gvis <群组ID> <public>   设置群组可见性 (群主)')
    print('   gmute <群组ID>           开关群组静音')
    print('   gleave <群组ID>          退出群组')
    print('   gdis <群组ID>            解散群组 (群主)')
    print()
    print(' 设置')
    print('   passwd                  修改密码')
    print('   setname <名称>           修改显示名称')
    print('   lang <语言>              切换语言 (en/zh/zh_egg/wyw/raw)')
    print('   dnd                     切换免打扰模式')
    print('   saver                   切换省流量模式')
    print()
    print(' 别名 & 管道')
    print('   alias [名称=命令]        查看/设置命令别名')
    print('   unalias <名称>           删除命令别名')
    print('   cmd1 | grep <关键词>     管道过滤输出')
    print('   cmd1 | head [N]          管道前N行')
    print('   cmd1 | tail [N]          管道后N行')
    print('   cmd1 | wc                统计管道行数')
    print('   cmd1 | sort              排序管道输出')
    print('   cmd1 | cat               打印管道输出')
    print('   echo <文本>              输出文本')
    print('   cmd1 && cmd2             前一个成功才执行 cmd2')
    print('   cmd1 || cmd2             前一个失败才执行 cmd2')
    print()
    print(' 其他')
    print('   server <URL>             查看/设置服务器地址')
    print('   clear                    清屏')
    print('   help                     显示帮助')
    print('   quit                     退出')


def cmd_login(args):
    if len(args) < 1:
        err('用法: login <用户名>')
        return
    username = args[0]
    password = getpass.getpass(f'密码 for {username}: ')
    r = api.login(username, password)
    if r.get('success'):
        ok(f'登录成功: {username}')
        cfg = load_config()
        cfg['server'] = api.server
        save_config(cfg)
        # 启动轮询
        start_polling()
    else:
        err(r.get('error', '登录失败'))


def cmd_register(args):
    username = input(f'{C.CYAN}用户名 (3-20位字母数字下划线):{C.RESET} ').strip()
    if not re.match(r'^[a-zA-Z0-9_]{3,20}$', username):
        err('用户名格式无效')
        return
    while True:
        pw1 = getpass.getpass('密码 (至少8位含字母和数字): ')
        if len(pw1) < 8:
            err('密码太短')
            continue
        pw2 = getpass.getpass('确认密码: ')
        if pw1 != pw2:
            err('两次密码不一致')
            continue
        break
    r = api.register(username, pw1)
    if r.get('success'):
        ok(f'注册成功: {username}')
        start_polling()
    else:
        err(r.get('error', '注册失败'))


def cmd_logout(args):
    r = api.logout()
    stop_polling()
    api.username = None
    if r.get('success'):
        ok('已登出')
    else:
        err('登出失败')


def cmd_whoami(args):
    r = api.check()
    if r.get('success'):
        name = r.get('display_name') or r.get('username') or '?'
        ok(f'已登录: {r.get("username", "?")} ({name})')
    else:
        err('未登录')


def cmd_send(args):
    if len(args) < 2:
        err('用法: send <用户名> <内容>')
        return
    recipient = args[0]
    msg = ' '.join(args[1:])
    r = api.send_dm(recipient, msg)
    if r.get('success'):
        ok(f'已发送给 {recipient} (message_id={r.get("message_id")})')
    else:
        err(r.get('error', '发送失败'))


def cmd_msgs(args):
    """查看最近消息"""
    dm = args[0] if args else ''
    r = api.fetch_all(limit=50, dm=dm)
    if not r.get('success'):
        err('获取消息失败')
        return
    msgs = r.get('messages', [])
    if not msgs:
        print('  (暂无消息)')
        return
    print(f'\n{C.CYAN}═ 最近 {len(msgs)} 条消息 ═{C.RESET}\n')
    for m in msgs:
        print(fmt_msg(m))
    print()


def cmd_his(args):
    """查看与某人的聊天历史"""
    if len(args) < 1:
        err('用法: his <用户名> [数量]')
        return
    dm = args[0]
    limit = min(100, int(args[1])) if len(args) > 1 else 50
    r = api.fetch_all(limit=limit, dm=dm)
    if not r.get('success'):
        err('获取消息历史失败')
        return
    msgs = r.get('messages', [])
    if not msgs:
        print(f'  (与 {dm} 暂无聊天记录)')
        return
    print(f'\n{C.CYAN}═ 与 {dm} 的聊天记录 ({len(msgs)} 条) ═{C.RESET}\n')
    for m in msgs:
        print(fmt_msg(m))
    print()


def cmd_unread(args):
    r = api.unread_counts()
    if not r.get('success'):
        err('获取未读失败')
        return
    counts = r.get('counts', {})
    if not counts:
        ok('没有未读消息')
        return
    print(f'\n{C.CYAN}═ 未读消息 ═{C.RESET}\n')
    for uname, cnt in sorted(counts.items(), key=lambda x: -x[1]):
        print(f'  {C.YELLOW}{uname}{C.RESET}: {C.RED}{cnt}{C.RESET} 条未读')
        # 自动标记已读
        api.mark_read(uname)
    print()


def cmd_revoke(args):
    if len(args) < 1:
        err('用法: revoke <消息ID>')
        return
    mid = int(args[0])
    r = api.revoke_message(mid)
    if r.get('success'):
        ok(f'消息 #{mid} 已撤回')
    else:
        err('撤回失败 (可能超过2分钟或不是你的消息)')


def cmd_search(args):
    if len(args) < 1:
        err('用法: search <关键词>')
        return
    q = args[0]
    r = api.search_messages(q)
    if not r.get('success'):
        err(r.get('error', '搜索失败'))
        return
    msgs = r.get('messages', [])
    total = r.get('total', 0)
    if not msgs:
        print(f'  (未找到包含 "{q}" 的消息)')
        return
    print(f'\n{C.CYAN}═ 搜索结果: {total} 条 ═{C.RESET}\n')
    for m in msgs:
        print(fmt_msg(m))
    print()


def cmd_contacts(args):
    r = api.contact_list()
    if not r.get('success'):
        err('获取联系人失败')
        return
    cs = r.get('contacts', [])
    if not cs:
        print('  (联系人列表为空)')
        return
    print(f'\n{C.CYAN}═ 联系人 ({len(cs)}) ═{C.RESET}\n')
    for c in cs:
        note = f" {C.DIM}[备注: {c.get('note')}]{C.RESET}" if c.get('note') else ''
        avatar = c.get('avatar') or ''
        av = f' [{avatar}]' if avatar else ''
        last = c.get('last_msg_time') or '从未'
        print(f'  {C.YELLOW}{c.get("username")}{C.RESET}{note}  {C.DIM}最近: {last}{C.RESET}')
    print()


def cmd_find(args):
    if len(args) < 1:
        err('用法: find <用户名/UID>')
        return
    q = args[0]
    r = api.contact_search(q)
    if not r.get('success'):
        err('搜索失败')
        return
    users = r.get('users', [])
    if not users:
        print(f'  (未找到用户 "{q}")')
        return
    print()
    for u in users:
        rel = u.get('relation') or '无关系'
        rel_c = C.GREEN if rel == 'accepted' else C.YELLOW
        print(f'  UID={u.get("user_id")}  {C.YELLOW}{u.get("username")}{C.RESET}  [{rel_c}{rel}{C.RESET}]')
    print()


def cmd_discover(args):
    q = args[0] if args else ''
    r = api.discover_users(q)
    if not r.get('success'):
        err('发现用户失败')
        return
    users = r.get('users', [])
    total = r.get('total', 0)
    if not users:
        print('  (没有发现用户)')
        return
    print(f'\n{C.CYAN}═ 发现用户 ({total} 个) ═{C.RESET}\n')
    for u in users:
        dn = u.get('display_name') or u.get('username')
        av = u.get('avatar') or ''
        avs = f' [{av}]' if av else ''
        print(f'  UID={u.get("user_id")}  {C.YELLOW}{u.get("username")}{C.RESET} '
              f'({C.DIM}{dn}{C.RESET}){avs}')
    print()


def cmd_add(args):
    if len(args) < 1:
        err('用法: add <用户名> [附言]')
        return
    username = args[0]
    msg = ' '.join(args[1:]) if len(args) > 1 else ''
    r = api.contact_send_request(username, msg)
    if r.get('success'):
        ok(f'已向 {username} 发送好友请求')
    else:
        err(r.get('error', '发送请求失败'))


def cmd_pending(args):
    r = api.contact_pending()
    if not r.get('success'):
        err('获取待处理请求失败')
        return
    pend = r.get('pending', [])
    if not pend:
        print('  (没有待处理的好友请求)')
        return
    print(f'\n{C.CYAN}═ 待处理的好友请求 ═{C.RESET}\n')
    for p in pend:
        dn = p.get('display_name') or p.get('username')
        msg = f"  {C.DIM}留言: {p.get('msg')}{C.RESET}" if p.get('msg') else ''
        print(f'  {C.YELLOW}{p.get("username")}{C.RESET} ({dn}){msg}')
    print()


def cmd_accept(args):
    if len(args) < 1:
        err('用法: accept <用户名>')
        return
    r = api.contact_respond(args[0], 'accept')
    if r.get('success'):
        ok(f'已接受 {args[0]} 的好友请求')
    else:
        err(r.get('error', '接受失败'))


def cmd_reject(args):
    if len(args) < 1:
        err('用法: reject <用户名>')
        return
    r = api.contact_respond(args[0], 'reject')
    if r.get('success'):
        ok(f'已拒绝 {args[0]} 的好友请求')
    else:
        err(r.get('error', '拒绝失败'))


def cmd_nickname(args):
    if len(args) < 2:
        err('用法: nickname <用户名> <备注>')
        return
    r = api.contact_nickname(args[0], ' '.join(args[1:]))
    if r.get('success'):
        ok(f'已设置 {args[0]} 的备注')
    else:
        err(r.get('error', '设置失败'))


def cmd_unfriend(args):
    if len(args) < 1:
        err('用法: unfriend <用户名>')
        return
    r = api.contact_delete(args[0])
    if r.get('success'):
        ok(f'已删除好友 {args[0]}')
    else:
        err('删除失败')


# ---------- 群组命令 ----------

def cmd_gcreate(args):
    if len(args) < 1:
        err('用法: gcreate <名称>')
        return
    r = api.group_create(' '.join(args))
    if r.get('success'):
        ok(f'群组创建成功! 群组ID: {C.YELLOW}{r.get("group_id")}{C.RESET}')
    else:
        err(r.get('error', '创建失败'))


def cmd_groups(args):
    r = api.group_list_my()
    if not r.get('success'):
        err('获取群组列表失败')
        return
    gs = r.get('groups', [])
    if not gs:
        print('  (你还没有加入任何群组)')
        return
    print(f'\n{C.CYAN}═ 我的群组 ({len(gs)}) ═{C.RESET}\n')
    for g in gs:
        role = g.get('role', 'member')
        role_c = {'owner': C.RED, 'admin': C.MAGENTA, 'member': C.DIM}.get(role, C.DIM)
        muted = f" {C.BLUE}[已静音]{C.RESET}" if g.get('muted') else ''
        public = f" {C.GREEN}[公开]{C.RESET}" if g.get('public') else f" {C.YELLOW}[私密]{C.RESET}"
        print(f'  {C.YELLOW}GID={g.get("group_id")}{C.RESET}  {g.get("name")}  '
              f'{role_c}{role}{C.RESET}{public}{muted}')
    print()


def cmd_gsearch(args):
    q = args[0] if args else ''
    r = api.group_search(q)
    if not r.get('success'):
        err('搜索群组失败')
        return
    gs = r.get('groups', [])
    total = r.get('total', 0)
    if not gs:
        print('  (未找到群组)')
        return
    print(f'\n{C.CYAN}═ 群组搜索结果 ({total} 个) ═{C.RESET}\n')
    for g in gs:
        vis = f" {C.GREEN}[公开]{C.RESET}" if g.get('public') else f" {C.YELLOW}[私密]{C.RESET}"
        print(f'  {C.YELLOW}GID={g.get("group_id")}{C.RESET}  {g.get("name")}{vis}  '
              f'{C.DIM}成员: {g.get("member_count")} 创建者: {g.get("owner_name")}{C.RESET}')
    print()


def cmd_gjoin(args):
    if len(args) < 1:
        err('用法: gjoin <群组ID>')
        return
    gid = int(args[0])
    r = api.group_join(gid)
    if r.get('success'):
        if r.get('joined'):
            ok(f'已加入群组 {gid}')
        elif r.get('requested'):
            ok(f'已发送入群申请至群组 {gid}')
    else:
        err(r.get('error', '加入失败'))


def cmd_ginfo(args):
    if len(args) < 1:
        err('用法: ginfo <群组ID>')
        return
    gid = int(args[0])
    r = api.group_info(gid)
    if not r.get('success'):
        err('获取群组信息失败')
        return
    g = r.get('group', {})
    vis = '公开' if g.get('public') else '私密'
    role = g.get('my_role') or '未加入'
    print()
    print(f'  {C.CYAN}{g.get("name")}{C.RESET}')
    print(f'  GID:      {g.get("group_id")}')
    print(f'  可见性:   {vis}')
    print(f'  创建者:   {g.get("owner_name")} (UID {g.get("owner_id")})')
    print(f'  创建时间: {g.get("created_at")}')
    print(f'  我的角色: {role}')
    if g.get('my_muted'):
        print(f'  {C.BLUE}  当前已静音{C.RESET}')
    print()


def cmd_gmembers(args):
    if len(args) < 1:
        err('用法: gmembers <群组ID>')
        return
    gid = int(args[0])
    r = api.group_members(gid)
    if not r.get('success'):
        err('获取群组成员失败')
        return
    ms = r.get('members', [])
    if not ms:
        print('  (群组为空)')
        return
    print(f'\n{C.CYAN}═ 群组 {gid} 成员 ({len(ms)}) ═{C.RESET}\n')
    for m in ms:
        role = m.get('role', 'member')
        role_c = {'owner': C.RED + C.BOLD, 'admin': C.MAGENTA, 'member': ''}.get(role, '')
        muted = f" {C.BLUE}[静音]{C.RESET}" if m.get('muted') else ''
        print(f'  UID={m.get("user_id")}  {C.YELLOW}{m.get("username")}{C.RESET} '
              f'({C.DIM}{m.get("display_name")}{C.RESET})  '
              f'{role_c}{role}{C.RESET}{muted}  '
              f'{C.DIM}加入: {m.get("joined_at")}{C.RESET}')
    print()


def cmd_gsend(args):
    if len(args) < 2:
        err('用法: gsend <群组ID> <内容>')
        return
    gid = int(args[0])
    msg = ' '.join(args[1:])
    r = api.group_send(gid, msg)
    if r.get('success'):
        ok(f'群消息已发送 (id={r.get("id")})')
    else:
        err(r.get('error', '发送失败'))


def cmd_ghis(args):
    if len(args) < 1:
        err('用法: ghis <群组ID> [数量]')
        return
    gid = int(args[0])
    limit = min(100, int(args[1])) if len(args) > 1 else 50
    r = api.group_history(gid, limit=limit)
    if not r.get('success'):
        err('获取群消息历史失败')
        return
    msgs = r.get('messages', [])
    if not msgs:
        print('  (群组暂无消息)')
        return
    print(f'\n{C.CYAN}═ 群组 {gid} 消息历史 ({len(msgs)} 条) ═{C.RESET}\n')
    for m in msgs:
        who = m.get('display_name') or m.get('username') or '?'
        mid = m.get('id', 0)
        t = m.get('time', '')
        print(f'  {C.DIM}[#{mid} {t}]{C.RESET} {C.GREEN}{who}{C.RESET}: {m.get("message", "")}')
    print()


def cmd_gpending(args):
    if len(args) < 1:
        err('用法: gpending <群组ID>')
        return
    gid = int(args[0])
    r = api.group_pending(gid)
    if not r.get('success'):
        err('获取入群申请失败 (需要群组管理员权限)')
        return
    reqs = r.get('requests', [])
    if not reqs:
        print('  (没有待处理的入群申请)')
        return
    print(f'\n{C.CYAN}═ 群组 {gid} 入群申请 ═{C.RESET}\n')
    for rq in reqs:
        print(f'  申请ID={rq.get("id")}  {C.YELLOW}{rq.get("username")}{C.RESET} '
              f'({C.DIM}{rq.get("display_name")}{C.RESET})  {C.DIM}{rq.get("created_at")}{C.RESET}')
    print()


def cmd_gapprove(args):
    if len(args) < 1:
        err('用法: gapprove <申请ID>')
        return
    rid = int(args[0])
    r = api.group_approve(rid)
    if r.get('success'):
        ok(f'已批准申请 #{rid}')
    else:
        err('批准失败')


def cmd_greject(args):
    if len(args) < 1:
        err('用法: greject <申请ID>')
        return
    rid = int(args[0])
    r = api.group_reject(rid)
    if r.get('success'):
        ok(f'已拒绝申请 #{rid}')
    else:
        err('拒绝失败')


def cmd_gkick(args):
    if len(args) < 2:
        err('用法: gkick <群组ID> <用户ID>')
        return
    gid, uid = int(args[0]), int(args[1])
    r = api.group_kick(gid, uid)
    if r.get('success'):
        ok(f'已将 UID {uid} 踢出群组 {gid}')
    else:
        err('踢人失败 (需要管理员权限)')


def cmd_gadmin(args):
    if len(args) < 2:
        err('用法: gadmin <群组ID> <用户ID>')
        return
    gid, uid = int(args[0]), int(args[1])
    r = api.group_set_admin(gid, uid)
    if r.get('success'):
        ok(f'已将 UID {uid} 设为管理员')
    else:
        err('设置失败 (需要群主权限)')


def cmd_gunadmin(args):
    if len(args) < 2:
        err('用法: gunadmin <群组ID> <用户ID>')
        return
    gid, uid = int(args[0]), int(args[1])
    r = api.group_unset_admin(gid, uid)
    if r.get('success'):
        ok(f'已取消 UID {uid} 的管理员')
    else:
        err('取消失败 (需要群主权限)')


def cmd_grename(args):
    if len(args) < 2:
        err('用法: grename <群组ID> <新名称>')
        return
    gid = int(args[0])
    name = ' '.join(args[1:])
    r = api.group_rename(gid, name)
    if r.get('success'):
        ok(f'群组 {gid} 已重命名为 "{name}"')
    else:
        err('重命名失败 (需要群主权限)')


def cmd_gvis(args):
    if len(args) < 2:
        err('用法: gvis <群组ID> <public: 0/1>')
        return
    gid = int(args[0])
    pub = args[1] in ('1', 'true', 'yes', 'y')
    r = api.group_set_visibility(gid, pub)
    if r.get('success'):
        ok(f'群组 {gid} 可见性已设为 {"公开" if pub else "私密"}')
    else:
        err('设置失败 (需要群主权限)')


def cmd_gmute(args):
    if len(args) < 1:
        err('用法: gmute <群组ID>')
        return
    gid = int(args[0])
    r = api.group_toggle_mute(gid)
    if r.get('success'):
        st = '已静音' if r.get('muted') else '已取消静音'
        ok(f'群组 {gid} {st}')
    else:
        err('操作失败')


def cmd_gleave(args):
    if len(args) < 1:
        err('用法: gleave <群组ID>')
        return
    gid = int(args[0])
    r = api.group_leave(gid)
    if r.get('success'):
        ok(f'已退出群组 {gid}')
    else:
        err(r.get('error', '退出失败 (群主不能直接退出)'))


def cmd_gdis(args):
    if len(args) < 1:
        err('用法: gdis <群组ID>')
        return
    gid = int(args[0])
    confirm = input(f'确定要解散群组 {gid} 吗? (y/N): ').strip().lower()
    if confirm != 'y':
        print('  已取消')
        return
    r = api.group_dissolve(gid)
    if r.get('success'):
        ok(f'群组 {gid} 已解散')
    else:
        err('解散失败 (需要群主权限)')


# ---------- 设置命令 ----------

def cmd_passwd(args):
    cp = getpass.getpass('当前密码: ')
    while True:
        np = getpass.getpass('新密码 (至少8位含字母和数字): ')
        if len(np) < 8:
            err('密码太短')
            continue
        nf = getpass.getpass('确认新密码: ')
        if np != nf:
            err('两次密码不一致')
            continue
        break
    r = api.change_password(cp, np)
    if r.get('success'):
        ok('密码修改成功')
    else:
        err(r.get('error', '修改失败'))


def cmd_setname(args):
    if len(args) < 1:
        err('用法: setname <名称>')
        return
    name = ' '.join(args)
    r = api.change_display_name(name)
    if r.get('success'):
        ok(f'显示名称已设为 "{name}"')
    else:
        err('设置失败')


def cmd_lang(args):
    if len(args) < 1:
        err('用法: lang <en/zh/zh_egg/wyw/raw>')
        return
    lang = args[0]
    r = api.change_language(lang)
    if r.get('success'):
        ok(f'语言已切换为 {lang}')
    else:
        err('切换失败')


def cmd_dnd(args):
    r = api.toggle_dnd()
    if r.get('success'):
        st = '已开启' if r.get('dnd') else '已关闭'
        ok(f'免打扰模式{st}')
    else:
        err('操作失败')


def cmd_saver(args):
    r = api.toggle_data_saver()
    if r.get('success'):
        st = '已开启' if r.get('data_saver') else '已关闭'
        ok(f'省流量模式{st}')
    else:
        err('操作失败')


def cmd_server(args):
    global api
    if args:
        url = args[0]
        api = ChatAppAPI(url)
        cfg = load_config()
        cfg['server'] = url
        save_config(cfg)
        ok(f'服务器地址已设为: {url}')
    else:
        print(f'  当前服务器: {C.CYAN}{api.server}{C.RESET}')


# ---------- 别名命令 ----------

def cmd_alias(args):
    """alias [名称=命令] [名称 命令]  - 查看/设置命令别名"""
    global aliases
    if not args:
        if not aliases:
            print('  (没有定义别名)')
            return True
        print(f'\n{C.CYAN}═ 命令别名 ═{C.RESET}\n')
        for name, cmd_str in sorted(aliases.items()):
            print(f'  {C.YELLOW}{name}{C.RESET} = {C.DIM}{cmd_str}{C.RESET}')
        print()
        return True

    if len(args) == 1 and '=' in args[0]:
        name, _, val = args[0].partition('=')
        name = name.strip()
        val = val.strip()
        if not name or not val:
            err('用法: alias <名称>=<命令>')
            return False
        aliases[name] = val
        save_aliases(aliases)
        ok(f'别名已设置: {name} = {val}')
        return True

    if len(args) >= 2:
        name = args[0]
        val = ' '.join(args[1:])
        aliases[name] = val
        save_aliases(aliases)
        ok(f'别名已设置: {name} = {val}')
        return True

    err('用法: alias <名称>=<命令> 或 alias <名称> <命令>')
    return False


def cmd_unalias(args):
    """unalias <名称>   - 删除命令别名"""
    global aliases
    if len(args) < 1:
        err('用法: unalias <名称>')
        return False
    name = args[0]
    if name in aliases:
        del aliases[name]
        save_aliases(aliases)
        ok(f'别名已删除: {name}')
        return True
    err(f'别名不存在: {name}')
    return False


def cmd_clear(args):
    os.system('clear' if os.name == 'posix' else 'cls')


def cmd_quit(args):
    global running
    stop_polling()
    running = False


def cmd_unknown(args):
    err(f'未知命令: {args[0]} (输入 help 查看帮助)')


# ---------- 管道内置命令 ----------
pipe_buffer = []   # 管道缓冲区: 上一个命令的输出行列表


def _clear_pipe():
    global pipe_buffer
    pipe_buffer = []


def cmd_grep(args):
    """grep <关键词>  - 过滤管道输出"""
    global pipe_buffer
    if not args:
        err('用法: grep <关键词>')
        return False
    kw = ' '.join(args)
    pipe_buffer = [l for l in pipe_buffer if kw in C.strip(l)]
    return True


def cmd_head(args):
    """head [N]  - 显示管道输出的前N行 (默认10)"""
    global pipe_buffer
    n = int(args[0]) if args and args[0].isdigit() else 10
    pipe_buffer = pipe_buffer[:n]
    return True


def cmd_tail(args):
    """tail [N]  - 显示管道输出的后N行 (默认10)"""
    global pipe_buffer
    n = int(args[0]) if args and args[0].isdigit() else 10
    pipe_buffer = pipe_buffer[-n:]
    return True


def cmd_wc(args):
    """wc  - 统计管道输出的行数"""
    global pipe_buffer
    n = len(pipe_buffer)
    print(f'  {C.CYAN}{n}{C.RESET} 行')
    return True


def cmd_sort(args):
    """sort  - 对管道输出排序"""
    global pipe_buffer
    pipe_buffer = sorted(pipe_buffer, key=lambda l: C.strip(l))
    return True


def cmd_cat(args):
    """cat  - 逐行原样打印管道输出"""
    global pipe_buffer
    for l in pipe_buffer:
        print(l)
    return True


def cmd_echo(args):
    """echo <文本>  - 输出文本到管道或屏幕"""
    global pipe_buffer
    text = ' '.join(args) if args else ''
    pipe_buffer = [text]
    print(text)
    return True




# ============================================================
# 命令路由 & 执行引擎
# ============================================================
COMMANDS = {
    'help':      {'fn': cmd_help,      'desc': '显示帮助'},
    '?':         {'fn': cmd_help,      'desc': '显示帮助'},
    'login':     {'fn': cmd_login,     'desc': '登录'},
    'register':  {'fn': cmd_register,  'desc': '注册'},
    'logout':    {'fn': cmd_logout,    'desc': '登出'},
    'whoami':    {'fn': cmd_whoami,    'desc': '当前用户'},
    'send':      {'fn': cmd_send,      'desc': '发送私聊'},
    'msgs':      {'fn': cmd_msgs,      'desc': '最近消息'},
    'his':       {'fn': cmd_his,       'desc': '聊天历史'},
    'unread':    {'fn': cmd_unread,    'desc': '未读消息'},
    'revoke':    {'fn': cmd_revoke,    'desc': '撤回消息'},
    'search':    {'fn': cmd_search,    'desc': '搜索消息'},
    'contacts':  {'fn': cmd_contacts,  'desc': '联系人'},
    'find':      {'fn': cmd_find,      'desc': '搜索用户'},
    'discover':  {'fn': cmd_discover,  'desc': '发现用户'},
    'add':       {'fn': cmd_add,       'desc': '发送好友请求'},
    'pending':   {'fn': cmd_pending,   'desc': '好友请求'},
    'accept':    {'fn': cmd_accept,    'desc': '接受请求'},
    'reject':    {'fn': cmd_reject,    'desc': '拒绝请求'},
    'nickname':  {'fn': cmd_nickname,  'desc': '设置备注'},
    'unfriend':  {'fn': cmd_unfriend,  'desc': '删除好友'},
    'gcreate':   {'fn': cmd_gcreate,   'desc': '创建群组'},
    'groups':    {'fn': cmd_groups,    'desc': '我的群组'},
    'gsearch':   {'fn': cmd_gsearch,   'desc': '搜索群组'},
    'gjoin':     {'fn': cmd_gjoin,     'desc': '加入群组'},
    'ginfo':     {'fn': cmd_ginfo,     'desc': '群组信息'},
    'gmembers':  {'fn': cmd_gmembers,  'desc': '群组成员'},
    'gsend':     {'fn': cmd_gsend,     'desc': '发送群消息'},
    'ghis':      {'fn': cmd_ghis,      'desc': '群历史'},
    'gpending':  {'fn': cmd_gpending,  'desc': '入群申请'},
    'gapprove':  {'fn': cmd_gapprove,  'desc': '批准申请'},
    'greject':   {'fn': cmd_greject,   'desc': '拒绝申请'},
    'gkick':     {'fn': cmd_gkick,     'desc': '踢出成员'},
    'gadmin':    {'fn': cmd_gadmin,    'desc': '设为管理员'},
    'gunadmin':  {'fn': cmd_gunadmin,  'desc': '取消管理员'},
    'grename':   {'fn': cmd_grename,   'desc': '重命名群组'},
    'gvis':      {'fn': cmd_gvis,      'desc': '设置可见性'},
    'gmute':     {'fn': cmd_gmute,     'desc': '群组静音'},
    'gleave':    {'fn': cmd_gleave,    'desc': '退出群组'},
    'gdis':      {'fn': cmd_gdis,      'desc': '解散群组'},
    'passwd':    {'fn': cmd_passwd,    'desc': '修改密码'},
    'setname':   {'fn': cmd_setname,   'desc': '显示名称'},
    'lang':      {'fn': cmd_lang,      'desc': '切换语言'},
    'dnd':       {'fn': cmd_dnd,       'desc': '免打扰'},
    'saver':     {'fn': cmd_saver,     'desc': '省流量'},
    'server':    {'fn': cmd_server,    'desc': '服务器设置'},
    'alias':     {'fn': cmd_alias,     'desc': '命令别名'},
    'unalias':   {'fn': cmd_unalias,   'desc': '删除别名'},
    'clear':     {'fn': cmd_clear,     'desc': '清屏'},
    'quit':      {'fn': cmd_quit,      'desc': '退出'},
    'exit':      {'fn': cmd_quit,      'desc': '退出'},
    # 管道内置命令
    'grep':      {'fn': cmd_grep,      'desc': '过滤输出'},
    'head':      {'fn': cmd_head,      'desc': '显示前N行'},
    'tail':      {'fn': cmd_tail,      'desc': '显示后N行'},
    'wc':        {'fn': cmd_wc,        'desc': '统计行数'},
    'sort':      {'fn': cmd_sort,      'desc': '排序'},
    'cat':       {'fn': cmd_cat,       'desc': '原样输出'},
    'echo':      {'fn': cmd_echo,      'desc': '输出文本'},
}

aliases = {}  # 命令别名 {name: cmd_string}


# ============================================================
# 消息轮询
# ============================================================
poll_lock = threading.Lock()
latest_dm_seen = 0      # 全局最新 DM 消息 ID
latest_group_ids = {}   # {gid: latest_id}
poll_active = False
pending_notify = []     # 新消息通知队列
groups_loaded = False   # 是否已初始化群组轮询基线

def start_polling():
    global poll_active
    if poll_active:
        return
    poll_active = True
    # 建立轮询基线，避免历史消息刷屏
    _seed_poll_baseline()
    t = threading.Thread(target=poll_loop, daemon=True)
    t.start()
    info(f'消息轮询已启动 (每 {POLL_INTERVAL}s)')


def stop_polling():
    global poll_active, latest_dm_seen, latest_group_ids, groups_loaded
    poll_active = False
    latest_dm_seen = 0
    latest_group_ids = {}
    groups_loaded = False


def _seed_poll_baseline():
    """登录后获取当前最新消息 ID 作为轮询基线，避免历史消息刷屏"""
    global latest_dm_seen, groups_loaded, latest_group_ids
    try:
        r = api.fetch_all(limit=1)
        if r.get('success') and r.get('latest_id'):
            latest_dm_seen = int(r['latest_id'])
        # 初始化群组基线
        gr = api.group_list_my()
        if gr.get('success'):
            for g in gr.get('groups', []):
                gid = g.get('group_id')
                if gid and gid not in latest_group_ids:
                    h = api.group_history(gid, limit=1)
                    if h.get('success') and h.get('messages'):
                        latest_group_ids[gid] = int(h['messages'][-1]['id'])
                    else:
                        latest_group_ids[gid] = 0
        groups_loaded = True
    except Exception:
        pass


def poll_loop():
    """后台轮询新消息"""
    global pending_notify, latest_dm_seen
    while poll_active:
        try:
            if not api or not api.username:
                time.sleep(POLL_INTERVAL)
                continue

            # 轮询私聊消息 (追踪全局最新 ID)
            r = api.fetch_dm(latest_dm_seen)
            if r.get('success'):
                for m in r.get('messages', []):
                    mid = m.get('id', 0)
                    if mid <= latest_dm_seen:
                        continue
                    latest_dm_seen = mid
                    sender = m.get('username', '')
                    if sender == api.username:
                        continue
                    pending_notify.append(m)
                    # 自动标记已读
                    try:
                        api.mark_read(sender)
                    except Exception:
                        pass

            # 轮询群组消息
            gr = api.group_list_my()
            if gr.get('success'):
                for g in gr.get('groups', []):
                    if g.get('muted'):
                        continue
                    gid = g.get('group_id')
                    if not gid:
                        continue
                    last_g = latest_group_ids.get(gid)
                    # 首次发现群组时初始化基线
                    if last_g is None:
                        h = api.group_history(gid, limit=1)
                        if h.get('success') and h.get('messages'):
                            latest_group_ids[gid] = int(h['messages'][-1]['id'])
                        else:
                            latest_group_ids[gid] = 0
                        continue
                    if last_g == 0:
                        continue
                    f = api.group_fetch(gid, last_g)
                    if f.get('success'):
                        for m in f.get('messages', []):
                            mid = m.get('id', 0)
                            if mid > last_g:
                                latest_group_ids[gid] = mid
                                sender = m.get('username', '')
                                if sender == api.username:
                                    continue
                                m['_group_name'] = g.get('name', f'群{gid}')
                                m['_group_id'] = gid
                                pending_notify.append(m)

        except Exception:
            pass

        time.sleep(POLL_INTERVAL)


def print_notifications():
    """打印后台收到的通知"""
    global pending_notify
    if not pending_notify:
        return
    msgs = pending_notify
    pending_notify = []
    for m in msgs:
        if '_group_id' in m:
            gname = m.get('_group_name', f"群{m.get('_group_id')}")
            who = m.get('display_name') or m.get('username') or '?'
            print(f'\n{C.BLUE}⚡ [{gname}]{C.RESET} {C.GREEN}{who}{C.RESET}: '
                  f'{C.WHITE}{m.get("message", "")[:100]}{C.RESET}')
            sys.stdout.flush()
        else:
            who = m.get('display_name') or m.get('username') or '?'
            print(f'\n{C.BLUE}📩{C.RESET} {C.GREEN}{who}{C.RESET}: '
                  f'{C.WHITE}{m.get("message", "")[:100]}{C.RESET}')
            sys.stdout.flush()


# ============================================================
# 命令执行引擎 (管道 / && / || / 别名)
# ============================================================
import io
import contextlib

last_output_lines = []   # 最近一次命令的输出行


def _exec_handler(handler, args):
    """执行命令处理函数，捕获输出到 last_output_lines，返回布尔状态"""
    global last_output_lines
    buf = io.StringIO()
    try:
        with contextlib.redirect_stdout(buf):
            ret = handler['fn'](args)
    except Exception as e:
        err(f'命令执行异常: {e}')
        return False
    output = buf.getvalue()
    last_output_lines = output.splitlines()
    # None 且输出中无错误标记 → 成功；有 ✘ → 失败
    if ret is None:
        return '✘' not in output
    if isinstance(ret, bool):
        return ret
    # 数字等其他返回值: 0 为成功 (shell 惯例)
    try:
        return int(ret) == 0
    except (TypeError, ValueError):
        return bool(ret)


def _resolve_cmd(cmd_str, args):
    """解析命令名，支持别名递归展开"""
    cname = cmd_str.lower()
    if cname in aliases:
        alias_cmd = aliases[cname]
        if args:
            alias_cmd = alias_cmd + ' ' + ' '.join(args)
        parts = alias_cmd.split()
        if not parts:
            return '', []
        return _resolve_cmd(parts[0], parts[1:])
    return cname, args


def _run_simple(seg: str) -> bool:
    """执行一段命令 (支持管道 |)，返回状态"""
    global pipe_buffer
    pipe_parts = [p.strip() for p in seg.split('|')]
    first = pipe_parts[0].split()
    if not first:
        return False

    cname, args = _resolve_cmd(first[0], first[1:])
    handler = COMMANDS.get(cname)
    if not handler:
        err(f'未知命令: {cname}')
        return False

    status = _exec_handler(handler, args)

    # 执行后续管道命令
    if len(pipe_parts) > 1:
        if status:
            pipe_buffer = last_output_lines
        for p in pipe_parts[1:]:
            pp = p.split()
            if not pp:
                continue
            pcname, pargs = _resolve_cmd(pp[0], pp[1:])
            phandler = COMMANDS.get(pcname)
            if not phandler:
                err(f'未知管道命令: {pcname}')
                return False
            status = _exec_handler(phandler, pargs)
            if not status:
                return False

        # 如果最后一条管道命令不是 cat/echo，需要把缓冲输出到屏幕
        last_p = pipe_parts[-1].strip()
        lpname = last_p.split()[0].lower() if last_p else ''
        if lpname not in ('cat', 'echo'):
            for l in pipe_buffer:
                print(l)
    else:
        # 无管道: 将命令输出打印到屏幕
        for l in last_output_lines:
            print(l)

    return status


def execute_line(line: str) -> bool:
    """执行一行命令，支持 | 管道、&& 和 || 连接符 (shell 风格)"""
    # 按 && / || 分割 (保留操作符)
    segments = re.split(r'\s+(\|\||&&)\s+', line)

    # 将 [cmd, op, cmd, op, cmd, ...] 转换为 [(cmd, op), ...]
    parts = []
    found_op = None
    for s in segments:
        s = s.strip()
        if not s:
            continue
        if s in ('&&', '||'):
            found_op = s
        else:
            parts.append((s, found_op))
            found_op = None

    # 执行序列: cmd1 op1 cmd2 op2 cmd3 ...
    # shell 语义: && 前成功才执行; || 前失败才执行
    # 这里简化: 每次执行后根据下一个 op 决定是否继续
    final = True
    for idx, (cmd_str, op) in enumerate(parts):
        if idx == 0:
            final = _run_simple(cmd_str)
        else:
            # op 是当前命令与前一命令之间的操作符
            prev_status = final
            if op == '&&':
                if prev_status:
                    final = _run_simple(cmd_str)
                else:
                    final = False
            elif op == '||':
                if not prev_status:
                    final = _run_simple(cmd_str)
                else:
                    final = True

    return final


# ============================================================
# 主循环
# ============================================================
def handle_sigint(sig, frame):
    print()
    sys.exit(0)


def main():
    global api, aliases
    signal.signal(signal.SIGINT, handle_sigint)

    # 加载配置
    cfg = load_config()
    api = ChatAppAPI(cfg.get('server', DEFAULT_SERVER))
    aliases = load_aliases()

    # 显示 banner
    print()
    banner('ChatApp CLI')
    print(f'  服务器: {C.CYAN}{api.server}{C.RESET}')
    print(f'  输入 {C.YELLOW}help{C.RESET} 查看帮助, {C.YELLOW}quit{C.RESET} 退出')
    print()

    # readline 补全
    def completer(text, state):
        options = [c for c in COMMANDS if c.startswith(text)]
        if state < len(options):
            return options[state] + ' '
        return None

    readline.set_completer(completer)
    readline.parse_and_bind('tab: complete')
    readline.parse_and_bind('set enable-bracketed-paste on')

    global running
    while running:
        # 打印通知
        print_notifications()

        try:
            prompt = f'{C.CYAN}chatapp{C.RESET}'
            if api.username:
                prompt += f' {C.YELLOW}@{api.username}{C.RESET}'
            prompt += f'{C.DIM}>{C.RESET} '

            line = input(prompt).strip()
        except (EOFError, KeyboardInterrupt):
            print()
            break

        if not line:
            continue

        # 执行命令（支持管道 / && / ||）
        try:
            execute_line(line)
        except Exception as e:
            err(f'命令错误: {e}')

    print(f'\n{C.DIM}再见!{C.RESET}')
    sys.exit(0)


if __name__ == '__main__':
    main()
