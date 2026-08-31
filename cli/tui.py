#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
ChatApp TUI —— 终端交互式聊天客户端（Textual 实现）

在终端里显示完整 UI（会话列表 / 消息区 / 输入框 / 联系人 / 群组 / 设置），
替代旧版命令式 CLI 的交互体验。复用 cli/chatapp.py 的 ChatAppAPI 后端封装。

运行（使用全局 venv）：
    /Users/jadenlau/.venv/bin/python cli/tui.py
    或  CHATAPP_SERVER=http://127.0.0.1:8080 /Users/jadenlau/.venv/bin/python cli/tui.py
"""
import os
import sys

# 保证从任意目录运行都能 import 到同目录的 chatapp.py
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import threading

from rich.text import Text

from textual.app import App, ComposeResult
from textual.containers import Horizontal, Vertical, VerticalScroll
from textual.screen import ModalScreen
from textual.widgets import Button, Footer, Header, Input, Label, OptionList, RichLog, Static
from textual.widgets.option_list import Option

from chatapp import ChatAppAPI

DEFAULT_SERVER = os.environ.get('CHATAPP_SERVER', 'http://127.0.0.1:8080')


# ============================================================
# 消息渲染（rich Text，无 ANSI 依赖）
# ============================================================
def render_msg(m: dict, me: str) -> Text:
    """把后端消息 dict 渲染成 rich Text"""
    who = (m.get('display_name') or m.get('username') or '?')
    mid = m.get('id', 0)
    t = m.get('time', '')
    mine = (m.get('username') or '') == me

    t = Text()
    t.append(f'[{mid} {t}]  ', style='dim')
    t.append(who + ': ', style=('bold #7fb0ff' if not mine else 'bold #e8b45a'))
    if m.get('is_deleted'):
        t.append('[此消息已撤回]', style='dim italic')
        return t
    mtype = m.get('msg_type')
    body = m.get('message', '')
    if mtype == 'photo':
        t.append('[图片附件] ', style='magenta')
        if m.get('attachment_url'):
            t.append(m['attachment_url'], style='dim')
    elif mtype == 'audio':
        t.append('[语音附件]', style='magenta')
    elif mtype == 'file':
        t.append(f"[文件] {m.get('attachment_name') or '文件'}", style='magenta')
    elif mtype == 'temp':
        t.append(f"[闪传] {m.get('attachment_name') or '文件'}", style='magenta')
        if m.get('temp_revoked'):
            t.append(' [已撤回]', style='red')
    elif mtype == 'md':
        t.append(body, style='cyan')
    else:
        t.append(body)
    if m.get('reply_data'):
        rd = m['reply_data']
        rn = rd.get('display_name') or rd.get('username') or '?'
        t.append(f"\n    ↳ 回复 {rn}: {str(rd.get('message',''))[:60]}", style='dim')
    return t


# ============================================================
# 登录 / 注册弹窗
# ============================================================
class LoginScreen(ModalScreen):
    """登录或注册；dismiss(True) 表示登录成功进入主界面"""
    CSS = """
    LoginScreen {
        align: center middle;
        background: #111 transparent 60%;
    }
    #login-box {
        width: 56;
        padding: 1 2;
        border: round #4a5260;
        background: #1a1e26;
    }
    #login-title { text-align: center; width: 100%; height: 1; }
    #login-msg { width: 100%; height: 1; color: #e06a6a; text-align: center; }
    #login-actions { height: 3; align: center middle; }
    Button { width: 14; }
    """

    def compose(self) -> ComposeResult:
        with Vertical(id='login-box'):
            yield Static('ChatApp TUI', id='login-title')
            yield Input(placeholder='服务器 (回车默认)', id='li_server')
            yield Input(placeholder='用户名', id='li_user')
            yield Input(placeholder='密码', password=True, id='li_pass')
            yield Label('', id='login-msg')
            with Horizontal(id='login-actions'):
                yield Button('登 录', variant='primary', id='li_login')
                yield Button('注 册', variant='success', id='li_reg')

    def on_mount(self):
        self.query_one('#li_server').value = DEFAULT_SERVER
        self.query_one('#li_server').focus()

    def _do(self, action: str):
        user = self.query_one('#li_user').value.strip()
        pw = self.query_one('#li_pass').value
        srv = self.query_one('#li_server').value.strip() or DEFAULT_SERVER
        msg = self.query_one('#login-msg')
        if not user or not pw:
            msg.update('请输入用户名和密码')
            return
        api = ChatAppAPI(srv)
        if action == 'login':
            r = api.login(user, pw)
        else:
            r = api.register(user, pw)
        if r.get('success'):
            api.username = user
            self.dismiss(api)
        else:
            msg.update(str(r.get('error') or '失败'))

    def on_button_pressed(self, event: Button.Pressed):
        if event.button.id == 'li_login':
            self._do('login')
        elif event.button.id == 'li_reg':
            self._do('register')

    def on_input_submitted(self, event: Input.Submitted):
        if event.input.id == 'li_server':
            self.query_one('#li_user').focus()
        elif event.input.id == 'li_user':
            self.query_one('#li_pass').focus()
        elif event.input.id == 'li_pass':
            self._do('login')


# ============================================================
# 主应用
# ============================================================
class ChatAppTUI(App):
    TITLE = 'ChatApp TUI'
    CSS = """
    #nav { width: 20; border-right: solid #2a2f38; background: #14171e; }
    #nav Button { width: 100%; margin: 1 0 1 0; border: none; background: transparent; color: #9aa0b0; }
    #nav Button:focus { background: #222a36; color: #fff; }
    #nav Button.active { background: #2b3a52; color: #8fc0ff; }
    #main { width: 1fr; }
    .panel { width: 30; border-right: solid #2a2f38; background: #1a1e26; }
    .msgbox { width: 1fr; height: 1fr; }
    RichLog { border: solid #2a2f38; background: #12151b; padding: 1; }
    Input { border: round #3a4350; background: #1a1e26; margin: 0 0 1 0; }
    Input:focus { border: round #7fb0ff; }
    .title { height: 1; color: #8fc0ff; padding: 0 1; }
    #contact-view, #setting-view { padding: 1 2; }
    #setting-view .row { height: 3; }
    #status { height: 1; color: #6b7280; padding: 0 1; }
    """

    BINDINGS = [
        ('q', 'quit', '退出'),
        ('r', 'refresh', '刷新'),
        ('f5', 'refresh', '刷新'),
    ]

    def __init__(self):
        super().__init__()
        self.api: ChatAppAPI = None
        self.me = ''
        self.current_tab = 'dm'
        self.dm_conv = None          # 当前私聊对象 username
        self.dm_cache = {}           # username -> 已加载消息 id 列表
        self.dm_last = {}            # username -> 最后消息 id（增量）
        self.group_conv = None       # 当前群组 id
        self.group_last = {}
        self._poll_on = False
        self._unread_map = {}
        self._contacts = []
        self._lang_toggle = True

    # ---------- 布局 ----------
    def compose(self) -> ComposeResult:
        yield Header(show_clock=True)
        with Horizontal():
            with Vertical(id='nav'):
                yield Button('私 聊', id='tab_dm', classes='active')
                yield Button('群 组', id='tab_group')
                yield Button('联系人', id='tab_contact')
                yield Button('设 置', id='tab_setting')
                yield Static('', id='status')
            with Vertical(id='main'):
                # --- 私聊视图 ---
                with Horizontal(id='view-dm'):
                    with Vertical(id='dm_list', classes='panel'):
                        yield Label('会话', classes='title')
                        yield OptionList(id='ol_dm')
                    with Vertical(id='dm_right'):
                        yield RichLog(id='dm_log', highlight=False)
                        yield Input(placeholder='输入消息，回车发送 (Ctrl+C 清空)', id='in_dm')
                # --- 群组视图 ---
                with Horizontal(id='view-group'):
                    with Vertical(id='group_list', classes='panel'):
                        yield Label('我的群组', classes='title')
                        yield OptionList(id='ol_group')
                    with Vertical(id='group_right'):
                        yield RichLog(id='group_log', highlight=False)
                        yield Input(placeholder='群消息，回车发送', id='in_group')
                # --- 联系人视图 ---
                with Vertical(id='view-contact'):
                    yield Label('联系人 / 发现用户', classes='title')
                    yield OptionList(id='ol_contact')
                    with Horizontal():
                        yield Input(placeholder='搜索用户名/UID，回车搜索', id='in_find')
                        yield Button('刷新', id='btn_contact_refresh')
                        yield Button('待处理请求', id='btn_pending')
                # --- 设置视图 ---
                with Vertical(id='view-setting'):
                    yield Label('设置', classes='title')
                    with VerticalScroll():
                        yield Static('', id='set_info')
                        yield Button('修改昵称', id='btn_nickname')
                        yield Button('修改密码', id='btn_passwd')
                        yield Button('切换 DND(勿扰)', id='btn_dnd')
                        yield Button('切换语言', id='btn_lang')
                        yield Button('退出登录', id='btn_logout')
        yield Footer()

    def on_mount(self):
        # 显示各视图状态：默认只有私聊可见
        for v in ('view-dm', 'view-group', 'view-contact', 'view-setting'):
            self.query_one('#' + v).display = 'none'
        self.query_one('#view-dm').display = 'block'
        self.push_screen(LoginScreen(), callback=self._after_login)

    # ---------- 登录后初始化 ----------
    def _after_login(self, api):
        if api is None:
            self.exit()
            return
        self.api = api
        self.me = api.username or ''
        self.query_one('#status').update(f'  {self.me} @ {api.server}')
        self.load_contacts()
        self.load_groups()
        self.refresh_unread()
        if not self._poll_on:
            self._poll_on = True
            self.set_interval(2.5, self._poll)

    # ---------- 视图切换 ----------
    def _switch_tab(self, name: str):
        self.current_tab = name
        mapping = {'dm': 'view-dm', 'group': 'view-group',
                   'contact': 'view-contact', 'setting': 'view-setting'}
        for k, v in mapping.items():
            self.query_one('#' + v).display = 'block' if k == name else 'none'
        for tid in ('tab_dm', 'tab_group', 'tab_contact', 'tab_setting'):
            btn = self.query_one('#' + tid)
            btn.set_classes('active' if tid == 'tab_' + name else '')
        if name == 'contact':
            self.query_one('#in_find').focus()
        elif name == 'dm':
            self.query_one('#in_dm').focus()

    def on_button_pressed(self, event: Button.Pressed):
        bid = event.button.id
        if bid in ('tab_dm', 'tab_group', 'tab_contact', 'tab_setting'):
            self._switch_tab(bid[4:])
        elif bid == 'btn_contact_refresh':
            self.load_contacts()
        elif bid == 'btn_pending':
            self.show_pending()
        elif bid == 'btn_nickname':
            self._prompt_text('修改昵称', '新昵称', self._set_nickname)
        elif bid == 'btn_passwd':
            self._prompt_text('修改密码', '新密码', self._set_passwd)
        elif bid == 'btn_dnd':
            r = self.api.toggle_dnd()
            self._toast_setting('DND 已' + ('开启' if r.get('success') else '失败'))
        elif bid == 'btn_lang':
            self._lang_toggle = not getattr(self, '_lang_toggle', True)
            cur = 'zh' if self._lang_toggle else 'en'
            r = self.api.change_language(cur)
            self._toast_setting('语言 → ' + cur + ('（已切换）' if r.get('success') else '（失败）'))
        elif bid == 'btn_logout':
            self.api.logout()
            self.exit()

    # ---------- 私聊 ----------
    def load_contacts(self):
        if not self.api:
            return
        r = self.api.contact_list()
        if not r.get('success'):
            return
        ol = self.query_one('#ol_dm')
        ol.clear_options()
        unread = self._unread_map or {}
        for c in r.get('contacts', []):
            uname = c.get('username') or ''
            name = c.get('note') or (c.get('display_name') or uname)
            if not uname:
                continue
            cnt = unread.get(uname, 0)
            badge = f'  ●{cnt}' if cnt else ''
            ol.add_option(Option(Text(f'{name}{badge}', style='#d5dbe6'), id=uname))
        self._contacts = r.get('contacts', [])

    def _open_dm(self, uname: str):
        self.dm_conv = uname
        log = self.query_one('#dm_log')
        log.clear()
        r = self.api.fetch_all(limit=50, dm=uname)
        if r.get('success'):
            msgs = r.get('messages', [])
            for m in msgs:
                log.write(render_msg(m, self.me))
            if msgs:
                self.dm_last[uname] = int(msgs[-1].get('id', 0))
        else:
            log.write(Text(str(r.get('error')), style='red'))
        self.query_one('#in_dm').focus()

    def send_dm_msg(self):
        if not self.dm_conv:
            self._toast('请先选择会话')
            return
        inp = self.query_one('#in_dm')
        txt = inp.value.strip()
        if not txt:
            return
        r = self.api.send_dm(self.dm_conv, txt)
        inp.value = ''
        if r.get('success'):
            # 直接追加本地回显
            self.query_one('#dm_log').write(
                Text(f'[{r.get("message_id", "?")} 刚刚]  {self.me}: ', style='dim bold #e8b45a') + Text(txt))
        else:
            self._toast(str(r.get('error') or '发送失败'))

    # ---------- 群组 ----------
    def load_groups(self):
        if not self.api:
            return
        r = self.api.group_list_my()
        if not r.get('success'):
            return
        ol = self.query_one('#ol_group')
        ol.clear_options()
        for g in r.get('groups', []):
            gid = int(g.get('group_id', 0))
            name = g.get('name') or f'群 {gid}'
            role = g.get('role', 'member')
            mark = {'owner': ' 群主', 'admin': ' 管理'}.get(role, '')
            ol.add_option(Option(Text(f'{name}{mark}', style='#d5dbe6'), id=str(gid)))

    def _open_group(self, gid: int):
        self.group_conv = gid
        log = self.query_one('#group_log')
        log.clear()
        r = self.api.group_history(gid, limit=50)
        if r.get('success'):
            for m in r.get('messages', []):
                log.write(render_msg(m, self.me))
            msgs = r.get('messages', [])
            if msgs:
                self.group_last[gid] = int(msgs[-1].get('id', 0))
        else:
            log.write(Text(str(r.get('error')), style='red'))
        self.query_one('#in_group').focus()

    def send_group_msg(self):
        if self.group_conv is None:
            self._toast('请先选择群组')
            return
        inp = self.query_one('#in_group')
        txt = inp.value.strip()
        if not txt:
            return
        r = self.api.group_send(self.group_conv, txt)
        inp.value = ''
        if r.get('success'):
            self.query_one('#group_log').write(
                Text(f'[? 刚刚]  {self.me}: ', style='dim bold #e8b45a') + Text(txt))
        else:
            self._toast(str(r.get('error') or '发送失败'))

    # ---------- 联系人管理 ----------
    def _find_user(self, q: str):
        r = self.api.contact_search(q)
        ol = self.query_one('#ol_contact')
        ol.clear_options()
        if not r.get('success') or not r.get('users'):
            ol.add_option(Option(Text('(未找到)', style='dim')))
            return
        for u in r.get('users', []):
            uname = u.get('username') or ''
            rel = u.get('relation') or '无关系'
            label = Text(f'{uname}  UID={u.get("user_id")}  [{rel}]', style='#d5dbe6')
            ol.add_option(Option(label, id=uname))

    def show_pending(self):
        r = self.api.contact_pending()
        ol = self.query_one('#ol_contact')
        ol.clear_options()
        if not r.get('success'):
            return
        ps = r.get('requests') or r.get('pending') or []
        if not ps:
            ol.add_option(Option(Text('(暂无待处理请求)', style='dim')))
            return
        for p in ps:
            uname = p.get('username') or p.get('user_from') or ''
            reqid = p.get('request_id') or p.get('id') or 0
            label = Text(f'[请求] {uname}  接受/拒绝', style='#e8b45a')
            ol.add_option(Option(label, id=f'__req__{uname}'))

    # ---------- 未读 & 轮询 ----------
    def refresh_unread(self):
        if not self.api:
            return
        r = self.api.unread_counts()
        if r.get('success'):
            self._unread_map = {k: int(v) for k, v in (r.get('unread') or {}).items()}
            self.load_contacts()

    def _poll(self):
        if not self.api:
            return
        try:
            self.refresh_unread()
            # 当前私聊会话增量拉取
            if self.dm_conv:
                after = self.dm_last.get(self.dm_conv, 0)
                r = self.api.fetch_dm(after=after)
                if r.get('success'):
                    msgs = r.get('messages', [])
                    if msgs:
                        for m in msgs:
                            if m.get('username') != self.me:
                                self.query_one('#dm_log').write(render_msg(m, self.me))
                        self.dm_last[self.dm_conv] = int(msgs[-1].get('id', 0))
            # 当前群组增量拉取
            if self.group_conv is not None:
                after = self.group_last.get(self.group_conv, 0)
                r = self.api.group_fetch(self.group_conv, after=after)
                if r.get('success'):
                    msgs = r.get('messages', [])
                    if msgs:
                        for m in msgs:
                            if m.get('username') != self.me:
                                self.query_one('#group_log').write(render_msg(m, self.me))
                        self.group_last[self.group_conv] = int(msgs[-1].get('id', 0))
        except Exception:
            pass

    # ---------- 输入 / 选项事件 ----------
    def on_input_submitted(self, event: Input.Submitted):
        iid = event.input.id
        if iid == 'in_dm':
            self.send_dm_msg()
        elif iid == 'in_group':
            self.send_group_msg()
        elif iid == 'in_find':
            self._find_user(event.value.strip())

    def on_option_list_option_selected(self, event: OptionList.OptionSelected):
        oid = event.option.id
        if not oid:
            return
        if event.option_list.id == 'ol_dm':
            self._open_dm(oid)
        elif event.option_list.id == 'ol_group':
            self._open_group(int(oid))
        elif event.option_list.id == 'ol_contact':
            if oid.startswith('__req__'):
                uname = oid[7:]
                r = self.api.contact_respond(uname, 'accept')
                self._toast('已接受 ' + uname if r.get('success') else '操作失败')
                self.show_pending()
            else:
                # 直接发起加好友
                r = self.api.contact_send_request(oid, '')
                self._toast('已发送好友请求' if r.get('success') else (r.get('error') or '失败'))

    # ---------- 设置 ----------
    def _prompt_text(self, title: str, placeholder: str, cb):
        """弹出文本输入弹窗，确定后把值交给 cb（仅在非空时回调）"""
        def on_result(v):
            if v:
                cb(v)
        self.push_screen(PromptScreen(title, placeholder), callback=on_result)

    def _set_nickname(self, val: str):
        r = self.api.change_display_name(val)
        self._toast_setting('昵称已更新' if r.get('success') else '失败')

    def _set_passwd(self, val: str):
        r = self.api.change_password('', val)
        self._toast_setting('密码已更新' if r.get('success') else '失败')

    def _toast(self, msg: str):
        self.notify(msg, timeout=3)

    def _toast_setting(self, msg: str):
        self.query_one('#set_info').update('  ' + msg)
        self.notify(msg, timeout=2)

    def action_refresh(self):
        self.load_contacts()
        self.load_groups()
        self.refresh_unread()
        self.notify('已刷新', timeout=1)


class PromptScreen(ModalScreen):
    """简易文本输入弹窗（改昵称/密码等）"""
    CSS = """
    PromptScreen { align: center middle; background: #111 transparent 60%; }
    #pb { width: 50; padding: 1 2; border: round #4a5260; background: #1a1e26; }
    """

    def __init__(self, title, placeholder):
        super().__init__()
        self._title = title
        self._placeholder = placeholder

    def compose(self) -> ComposeResult:
        with Vertical(id='pb'):
            yield Static(self._title)
            yield Input(placeholder=self._placeholder, id='p_input')
            with Horizontal():
                yield Button('确定', variant='primary', id='p_ok')
                yield Button('取消', id='p_cancel')

    def on_mount(self):
        self.query_one('#p_input').focus()

    def _done(self, val):
        self.dismiss(val)

    def on_button_pressed(self, event: Button.Pressed):
        if event.button.id == 'p_ok':
            self.dismiss(self.query_one('#p_input').value.strip())
        else:
            self.dismiss(None)

    def on_input_submitted(self, event: Input.Submitted):
        self.dismiss(self.query_one('#p_input').value.strip())


def main():
    ChatAppTUI().run()


if __name__ == '__main__':
    main()
