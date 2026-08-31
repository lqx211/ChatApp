#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
ChatApp AI CLI —— 无头命令行客户端，专供 AI / 脚本 / 自动化调用。

一条命令做一件事，结果输出 JSON（utf-8，不依赖终端），登录态持久化在
cookie 文件（默认 ~/.chatapp/ai.cookies），因此可先 login 一次，之后所有
命令复用会话，无需每次带密码。

覆盖范围：
  - 认证: login / register / logout / me
  - Settings 全部项目: settings(资料/开关/隐私/语言/时区/密码/头像)、
    block(黑名单)、bg(壁纸)、profile-bg(空间封面)、sig(签名隐私)、discover
  - 聊天全部项目: dm(私聊)、content(我的内容)、group(群组)、contact(联系人)
  - 朋友圈(space): moments post/list/comment/blog/留言板…

用法示例（AI 视角）:
  ai_cli.py --server http://127.0.0.1:8080 login --user mobtest2 --pass password
  ai_cli.py settings get
  ai_cli.py dm send alice "hello world"
  ai_cli.py dm conversations
  ai_cli.py group list
  ai_cli.py moments post "今天天气不错" --visibility 1

退出码: 0 = 成功（含后端 success=false 但已返回可读 JSON，可看 error 字段）；
       非 0 = 参数错误/网络异常/未登录。
"""
import argparse
import base64
import json
import mimetypes
import os
import sys
import urllib.parse

from chatapp import ChatAppAPI

DEFAULT_SERVER = os.environ.get('CHATAPP_SERVER', 'http://127.0.0.1:8080')
DEFAULT_COOKIE = os.path.join(os.path.expanduser('~'), '.chatapp', 'ai.cookies')


# ============================================================
# 基础设施
# ============================================================
def out(r, pretty=False):
    """打印结果 JSON；success=false 时也正常输出（退出码 1，便于 AI 判断失败）。"""
    text = json.dumps(r, ensure_ascii=False, indent=2 if pretty else None)
    print(text)
    if not r.get('success', False):
        sys.exit(1)


def die(msg):
    print(json.dumps({'success': False, 'error': str(msg)}, ensure_ascii=False))
    sys.exit(2)


def data_uri(path):
    """本地文件 → data URI（供 avatar/bg/profile-bg 上传）。"""
    if not os.path.isfile(path):
        die('文件不存在: ' + path)
    mime, _ = mimetypes.guess_type(path)
    if not mime:
        mime = 'application/octet-stream'
    with open(path, 'rb') as f:
        raw = f.read()
    return 'data:%s;base64,%s' % (mime, base64.b64encode(raw).decode())


class Ctx:
    """一次命令执行的上下文：API 实例 + 全局参数。"""
    api = None
    args = None


def get_api(server, cookie, user=None, password=None):
    """构建带 cookie 持久化的 API；未登录且给了凭据则先登录。"""
    a = ChatAppAPI(server)
    a.set_cookie_file(cookie)
    chk = a.check()
    if not chk.get('success'):
        if user and password:
            r = a.login(user, password)
            if not r.get('success'):
                die(r.get('error') or '登录失败')
            a.save_cookies()
        else:
            die('未登录。请先运行: ai_cli.py login --user U --pass P')
    return a


def call(a, path, params=None, method='POST', pretty=False):
    out(a._req(path, params, method=method), pretty)


def add_common(parser):
    parser.add_argument('--server', default=None, help='服务器地址，默认 $CHATAPP_SERVER 或 127.0.0.1:8080')
    parser.add_argument('--cookie', default=None, help='cookie 文件路径')
    parser.add_argument('--user', default=None, help='用户名（未登录时自动登录）')
    parser.add_argument('--pass', dest='password', default=None, help='密码')
    parser.add_argument('--pretty', action='store_true', help='缩进输出 JSON')
    return parser


def common_subparser(sub, name, help_text, handler):
    p = sub.add_parser(name, help=help_text)
    p.set_defaults(handler=handler)
    return p


# ============================================================
# 命令实现
# ============================================================
def cmd_login(a, args):
    r = a.login(args.user, args.password)
    if r.get('success'):
        a.save_cookies()
        r['logged_in_as'] = args.user
    out(r, args.pretty)


def cmd_register(a, args):
    r = a.register(args.user, args.password, lang=args.lang or 'en')
    if r.get('success'):
        a.save_cookies()
    out(r, args.pretty)


def cmd_logout(a, args):
    r = a.logout()
    a.save_cookies()
    out(r, args.pretty)


def cmd_me(a, args):
    chk = a.check()
    if not chk.get('success'):
        die('未登录')
    # 完整设置
    r = a._req('/api/settings.php?action=get_settings', method='GET')
    if r.get('success'):
        chk['settings'] = r.get('settings')
    out(chk, args.pretty)


# ---------- Settings ----------
def _toggle(a, args, col):
    call(a, '/api/settings.php', {'action': 'toggle_' + col}, args.pretty)


def cmd_settings_get(a, args):
    call(a, '/api/settings.php?action=get_settings', method='GET', pretty=args.pretty)


def cmd_settings_toggle(a, args):
    allowed = ['dnd', 'data_saver', 'auto_focus', 'searchable', 'searchable_by_uid',
               'notif_system', 'notif_banner', 'typing_visible',
               'stranger_invite_group', 'stranger_like', 'anyone_add_friend']
    if args.name not in allowed:
        die('未知开关: %s（可选: %s）' % (args.name, ', '.join(allowed)))
    _toggle(a, args, args.name)


def cmd_settings_privacy(a, args):
    call(a, '/api/settings.php', {'action': 'save_privacy',
                                  'searchable': args.searchable,
                                  'searchable_by_uid': args.searchable_by_uid}, args.pretty)


def cmd_settings_local_cache(a, args):
    call(a, '/api/settings.php', {'action': 'toggle_local_cache',
                                  'enabled': args.enabled}, args.pretty)


def cmd_settings_emoji(a, args):
    call(a, '/api/settings.php', {'action': 'save_emoji_settings',
                                  'panel_mode': args.panel_mode, 'chat_mode': args.chat_mode}, args.pretty)


def cmd_settings_timezone(a, args):
    call(a, '/api/settings.php', {'action': 'change_timezone', 'timezone': args.tz}, args.pretty)


def cmd_settings_language(a, args):
    call(a, '/api/settings.php', {'action': 'change_language', 'language': args.lang}, args.pretty)


def cmd_settings_name(a, args):
    call(a, '/api/settings.php', {'action': 'change_display_name', 'display_name': args.name}, args.pretty)


def cmd_settings_title(a, args):
    call(a, '/api/settings.php', {'action': 'change_custom_title', 'custom_title': args.title}, args.pretty)


def cmd_settings_gender(a, args):
    call(a, '/api/settings.php', {'action': 'save_gender', 'gender': args.gender}, args.pretty)


def cmd_settings_gender_privacy(a, args):
    call(a, '/api/settings.php', {'action': 'save_gender_privacy', 'privacy': args.privacy}, args.pretty)


def cmd_settings_birthday(a, args):
    call(a, '/api/settings.php', {'action': 'save_birthday', 'birthday': args.birthday}, args.pretty)


def cmd_settings_space_ears(a, args):
    call(a, '/api/settings.php', {'action': 'save_space_ears', 'enabled': args.enabled}, args.pretty)


def cmd_settings_password(a, args):
    call(a, '/api/settings.php', {'action': 'change_password',
                                  'current_password': args.current,
                                  'new_password': args.new}, args.pretty)


def cmd_settings_duress(a, args):
    call(a, '/api/settings.php', {'action': 'setup_duress',
                                  'current_password': args.current,
                                  'duress_password': args.duress or ''}, args.pretty)


def cmd_settings_avatar(a, args):
    call(a, '/api/settings.php', {'action': 'upload_avatar', 'avatar': data_uri(args.file)}, args.pretty)


# ---------- 黑名单 ----------
def cmd_block_list(a, args):
    call(a, '/api/settings.php?action=get_blocks', method='GET', pretty=args.pretty)


def cmd_block_add(a, args):
    call(a, '/api/settings.php', {'action': 'add_block', 'uid': args.uid}, args.pretty)


def cmd_block_remove(a, args):
    call(a, '/api/settings.php', {'action': 'remove_block', 'uid': args.uid}, args.pretty)


# ---------- 壁纸 / 空间封面 ----------
def cmd_bg_get(a, args):
    call(a, '/api/settings.php?action=get_background', method='GET', pretty=args.pretty)


def cmd_bg_upload(a, args):
    call(a, '/api/settings.php', {'action': 'upload_background', 'image': data_uri(args.file)}, args.pretty)


def cmd_bg_preset(a, args):
    call(a, '/api/settings.php', {'action': 'set_preset_background', 'name': args.name}, args.pretty)


def cmd_bg_remove(a, args):
    call(a, '/api/settings.php', {'action': 'remove_background'}, args.pretty)


def cmd_bg_privacy(a, args):
    call(a, '/api/settings.php', {'action': 'set_bg_privacy', 'privacy': args.privacy}, args.pretty)


def cmd_bg_blacklist(a, args):
    call(a, '/api/settings.php', {'action': 'set_bg_blacklist', 'blacklist': args.uids}, args.pretty)


def cmd_bg_whitelist(a, args):
    call(a, '/api/settings.php', {'action': 'set_bg_whitelist', 'whitelist': args.uids}, args.pretty)


def cmd_bg_no_friend(a, args):
    call(a, '/api/settings.php', {'action': 'set_bg_no_friend', 'no_friend': args.val}, args.pretty)


def cmd_bg_private(a, args):
    call(a, '/api/settings.php', {'action': 'upload_bg_private', 'image': data_uri(args.file)}, args.pretty)


def cmd_bg_private_set(a, args):
    call(a, '/api/settings.php', {'action': 'set_bg_private', 'private_image': args.ref}, args.pretty)


def cmd_profile_bg_get(a, args):
    call(a, '/api/settings.php?action=get_profile_bg', method='GET', pretty=args.pretty)


def cmd_profile_bg_upload(a, args):
    call(a, '/api/settings.php', {'action': 'upload_profile_bg', 'image': data_uri(args.file)}, args.pretty)


def cmd_profile_bg_remove(a, args):
    call(a, '/api/settings.php', {'action': 'remove_profile_bg'}, args.pretty)


def cmd_profile_bg_frame(a, args):
    call(a, '/api/settings.php', {'action': 'save_bg_framing',
                                  'pos_x': args.pos_x, 'pos_y': args.pos_y,
                                  'zoom': args.zoom, 'flip': args.flip}, args.pretty)


# ---------- 签名隐私 ----------
def cmd_sig_get(a, args):
    call(a, '/api/settings.php?action=get_sig_privacy', method='GET', pretty=args.pretty)


def cmd_sig_privacy(a, args):
    call(a, '/api/settings.php', {'action': 'set_sig_privacy', 'privacy': args.privacy}, args.pretty)


def cmd_sig_blacklist(a, args):
    call(a, '/api/settings.php', {'action': 'set_sig_blacklist', 'blacklist': args.uids}, args.pretty)


def cmd_sig_whitelist(a, args):
    call(a, '/api/settings.php', {'action': 'set_sig_whitelist', 'whitelist': args.uids}, args.pretty)


def cmd_sig_no_friend(a, args):
    call(a, '/api/settings.php', {'action': 'set_sig_no_friend', 'no_friend': args.val}, args.pretty)


def cmd_sig_hidden_text(a, args):
    call(a, '/api/settings.php', {'action': 'set_sig_hidden_text', 'hidden_text': args.text}, args.pretty)


# ---------- 发现 ----------
def cmd_discover(a, args):
    call(a, '/api/settings.php?' + urllib.parse.urlencode(
        {'action': 'discover', 'q': args.q or '', 'page': args.page}), method='GET', pretty=args.pretty)


# ---------- 私聊 ----------
def cmd_dm_send(a, args):
    p = {'action': 'send', 'recipient': args.user, 'message': args.message}
    if args.file:
        p['attachment'] = data_uri(args.file)
        p['filename'] = os.path.basename(args.file)
    if args.reply_to:
        p['reply_to'] = args.reply_to
    if args.md:
        p['md'] = '1'
    call(a, '/api/chat.php', p, args.pretty)


def cmd_dm_history(a, args):
    q = {'action': 'all', 'dm': args.user, 'limit': args.limit}
    if args.before:
        q['before'] = args.before
    call(a, '/api/chat.php?' + urllib.parse.urlencode(q), method='GET', pretty=args.pretty)


def cmd_dm_conversations(a, args):
    call(a, '/api/chat.php?action=conversations', method='GET', pretty=args.pretty)


def cmd_dm_unread(a, args):
    call(a, '/api/chat.php?action=unread_counts', method='GET', pretty=args.pretty)


def cmd_dm_fetch(a, args):
    q = {'action': 'fetch', 'after': args.after or 0}
    if args.dm:
        q['dm'] = args.dm
    call(a, '/api/chat.php?' + urllib.parse.urlencode(q), method='GET', pretty=args.pretty)


def cmd_dm_mark_read(a, args):
    call(a, '/api/chat.php', {'action': 'mark_read', 'from': args.user}, args.pretty)


def cmd_dm_revoke(a, args):
    call(a, '/api/chat.php', {'action': 'revoke', 'message_id': args.id}, args.pretty)


def cmd_dm_revoke_own(a, args):
    call(a, '/api/chat.php', {'action': 'revoke_own', 'message_id': args.id}, args.pretty)


def cmd_dm_search(a, args):
    q = {'action': 'search_messages', 'q': args.q, 'page': args.page}
    if args.dm:
        q['dm'] = args.dm
    if args.group_id:
        q['group_id'] = args.group_id
    call(a, '/api/chat.php?' + urllib.parse.urlencode(q), method='GET', pretty=args.pretty)


# ---------- 我的内容 ----------
def cmd_content_list(a, args):
    call(a, '/api/chat.php', {'action': 'my_content', 'type': args.type,
                              'limit': args.limit, 'offset': args.offset}, args.pretty)


# ---------- 群组 ----------
def cmd_group_list(a, args):
    call(a, '/api/group.php?action=list_my', method='GET', pretty=args.pretty)


def cmd_group_create(a, args):
    call(a, '/api/group.php', {'action': 'create', 'name': args.name}, args.pretty)


def cmd_group_info(a, args):
    call(a, '/api/group.php?action=info&group_id=%s' % args.gid, method='GET', pretty=args.pretty)


def cmd_group_members(a, args):
    call(a, '/api/group.php?action=members&group_id=%s' % args.gid, method='GET', pretty=args.pretty)


def cmd_group_send(a, args):
    p = {'action': 'send', 'group_id': args.gid, 'message': args.message}
    if args.file:
        p['attachment'] = data_uri(args.file)
        p['filename'] = os.path.basename(args.file)
    call(a, '/api/group.php', p, args.pretty)


def cmd_group_history(a, args):
    q = {'action': 'history', 'group_id': args.gid, 'limit': args.limit}
    if args.before:
        q['before'] = args.before
    call(a, '/api/group.php?' + urllib.parse.urlencode(q), method='GET', pretty=args.pretty)


def cmd_group_search(a, args):
    call(a, '/api/group.php?' + urllib.parse.urlencode(
        {'action': 'search', 'q': args.q, 'page': args.page}), method='GET', pretty=args.pretty)


def cmd_group_join(a, args):
    call(a, '/api/group.php', {'action': 'join_by_gid', 'group_id': args.gid}, args.pretty)


def cmd_group_request(a, args):
    call(a, '/api/group.php', {'action': 'request', 'group_id': args.gid}, args.pretty)


def cmd_group_pending(a, args):
    call(a, '/api/group.php?action=pending&group_id=%s' % args.gid, method='GET', pretty=args.pretty)


def cmd_group_approve(a, args):
    call(a, '/api/group.php', {'action': 'approve', 'request_id': args.rid}, args.pretty)


def cmd_group_reject(a, args):
    call(a, '/api/group.php', {'action': 'reject', 'request_id': args.rid}, args.pretty)


def cmd_group_invite(a, args):
    call(a, '/api/group.php', {'action': 'invite', 'group_id': args.gid,
                               'username': args.user}, args.pretty)


def cmd_group_kick(a, args):
    call(a, '/api/group.php', {'action': 'kick', 'group_id': args.gid,
                               'user_id': args.uid}, args.pretty)


def cmd_group_admin(a, args):
    call(a, '/api/group.php', {'action': 'set_admin', 'group_id': args.gid,
                               'user_id': args.uid}, args.pretty)


def cmd_group_unadmin(a, args):
    call(a, '/api/group.php', {'action': 'unset_admin', 'group_id': args.gid,
                               'user_id': args.uid}, args.pretty)


def cmd_group_rename(a, args):
    call(a, '/api/group.php', {'action': 'rename', 'group_id': args.gid,
                               'name': args.name}, args.pretty)


def cmd_group_announce(a, args):
    call(a, '/api/group.php', {'action': 'set_announcement', 'group_id': args.gid,
                               'announcement': args.text or ''}, args.pretty)


def cmd_group_visibility(a, args):
    call(a, '/api/group.php', {'action': 'set_visibility', 'group_id': args.gid,
                               'public': args.public}, args.pretty)


def cmd_group_transfer(a, args):
    call(a, '/api/group.php', {'action': 'transfer_owner', 'group_id': args.gid,
                               'user_id': args.uid}, args.pretty)


def cmd_group_mute(a, args):
    call(a, '/api/group.php', {'action': 'toggle_mute', 'group_id': args.gid}, args.pretty)


def cmd_group_unmute(a, args):
    call(a, '/api/group.php', {'action': 'toggle_mute', 'group_id': args.gid}, args.pretty)


def cmd_group_mute_member(a, args):
    call(a, '/api/group.php', {'action': 'mute_member', 'group_id': args.gid,
                               'user_id': args.uid}, args.pretty)


def cmd_group_unmute_member(a, args):
    call(a, '/api/group.php', {'action': 'unmute_member', 'group_id': args.gid,
                               'user_id': args.uid}, args.pretty)


def cmd_group_mute_all(a, args):
    call(a, '/api/group.php', {'action': 'mute_all', 'group_id': args.gid}, args.pretty)


def cmd_group_unmute_all(a, args):
    call(a, '/api/group.php', {'action': 'unmute_all', 'group_id': args.gid}, args.pretty)


def cmd_group_pin(a, args):
    call(a, '/api/group.php', {'action': 'toggle_pin', 'group_id': args.gid}, args.pretty)


def cmd_group_avatar(a, args):
    call(a, '/api/group.php', {'action': 'upload_avatar', 'group_id': args.gid,
                               'avatar': data_uri(args.file)}, args.pretty)


def cmd_group_leave(a, args):
    call(a, '/api/group.php', {'action': 'leave', 'group_id': args.gid}, args.pretty)


def cmd_group_dissolve(a, args):
    call(a, '/api/group.php', {'action': 'dissolve', 'group_id': args.gid}, args.pretty)


# ---------- 联系人 ----------
def cmd_contact_list(a, args):
    call(a, '/api/contacts.php?action=list', method='GET', pretty=args.pretty)


def cmd_contact_search(a, args):
    call(a, '/api/contacts.php?' + urllib.parse.urlencode(
        {'action': 'search', 'q': args.q}), method='GET', pretty=args.pretty)


def cmd_contact_add(a, args):
    p = {'action': 'send_request', 'username': args.user}
    if args.msg:
        p['msg'] = args.msg
    if args.note:
        p['note'] = args.note
    call(a, '/api/contacts.php', p, args.pretty)


def cmd_contact_force_add(a, args):
    call(a, '/api/contacts.php', {'action': 'force_add', 'username': args.user}, args.pretty)


def cmd_contact_pending(a, args):
    call(a, '/api/contacts.php?action=pending', method='GET', pretty=args.pretty)


def cmd_contact_accept(a, args):
    call(a, '/api/contacts.php', {'action': 'respond', 'username': args.user,
                                  'response': 'accept'}, args.pretty)


def cmd_contact_reject(a, args):
    call(a, '/api/contacts.php', {'action': 'respond', 'username': args.user,
                                  'response': 'reject'}, args.pretty)


def cmd_contact_remove(a, args):
    call(a, '/api/contacts.php', {'action': 'delete', 'username': args.user}, args.pretty)


def cmd_contact_nickname(a, args):
    call(a, '/api/contacts.php', {'action': 'change_nickname', 'username': args.user,
                                  'note': args.note}, args.pretty)


def cmd_contact_pin(a, args):
    call(a, '/api/contacts.php', {'action': 'toggle_pin', 'username': args.user}, args.pretty)


def cmd_contact_pin_self(a, args):
    call(a, '/api/contacts.php', {'action': 'toggle_pin_self'}, args.pretty)


# ---------- 朋友圈 (space.php) ----------
def cmd_moments_post(a, args):
    p = {'action': 'post', 'content': args.content, 'visibility': args.visibility}
    if args.to:
        p['visible_to'] = args.to
    call(a, '/api/space.php', p, args.pretty)


def cmd_moments_list(a, args):
    q = {'action': 'list'}
    if args.user:
        q['user'] = args.user
    if args.uid:
        q['uid'] = args.uid
    call(a, '/api/space.php?' + urllib.parse.urlencode(q), method='GET', pretty=args.pretty)


def cmd_moments_delete(a, args):
    call(a, '/api/space.php', {'action': 'delete', 'id': args.id}, args.pretty)


def cmd_moments_like(a, args):
    call(a, '/api/space.php', {'action': 'toggle_like', 'id': args.id}, args.pretty)


def cmd_moments_comment(a, args):
    p = {'action': 'add_comment', 'feed_id': args.feed_id, 'content': args.content}
    if args.parent:
        p['parent_id'] = args.parent
    call(a, '/api/space.php', p, args.pretty)


def cmd_moments_comments(a, args):
    call(a, '/api/space.php?action=list_comments&feed_id=%s' % args.feed_id,
         method='GET', pretty=args.pretty)


def cmd_moments_comment_del(a, args):
    call(a, '/api/space.php', {'action': 'delete_comment', 'id': args.id}, args.pretty)


def cmd_moments_message(a, args):
    call(a, '/api/space.php', {'action': 'add_message', 'to_uid': args.to_uid,
                               'content': args.content}, args.pretty)


def cmd_moments_messages(a, args):
    call(a, '/api/space.php?action=list_messages&to_uid=%s' % args.to_uid,
         method='GET', pretty=args.pretty)


def cmd_moments_message_del(a, args):
    call(a, '/api/space.php', {'action': 'delete_message', 'id': args.id}, args.pretty)


def cmd_moments_blog(a, args):
    p = {'action': 'add_blog', 'title': args.title, 'content': args.content,
         'visibility': args.visibility}
    if args.to:
        p['visible_to'] = args.to
    call(a, '/api/space.php', p, args.pretty)


def cmd_moments_blogs(a, args):
    q = {'action': 'list_blogs'}
    if args.user:
        q['user'] = args.user
    if args.uid:
        q['uid'] = args.uid
    call(a, '/api/space.php?' + urllib.parse.urlencode(q), method='GET', pretty=args.pretty)


def cmd_moments_blog_get(a, args):
    call(a, '/api/space.php?action=get_blog&id=%s' % args.id, method='GET', pretty=args.pretty)


def cmd_moments_blog_del(a, args):
    call(a, '/api/space.php', {'action': 'delete_blog', 'id': args.id}, args.pretty)


# ---------- 账号（危险操作）----------
def cmd_account_delete(a, args):
    if not args.yes:
        die('危险操作需确认，请加 --yes。mode: delete(仅删号)/revoke(仅清聊天记录)/delete_all(全部删除)')
    call(a, '/api/settings.php', {'action': 'delete_account', 'password': args.password,
                                  'mode': args.mode}, args.pretty)


# ============================================================
# argparse 装配
# ============================================================
def build_parser():
    p = argparse.ArgumentParser(
        prog='ai_cli.py',
        description='ChatApp AI CLI — 无头命令行客户端（JSON 输出）',
        formatter_class=argparse.RawDescriptionHelpFormatter)
    add_common(p)
    sub = p.add_subparsers(dest='command', metavar='命令')

    # --- auth ---
    q = common_subparser(sub, 'login', '登录并保存会话', cmd_login)
    q.add_argument('--user', required=True)
    q.add_argument('--pass', dest='password', required=True)
    q = common_subparser(sub, 'register', '注册', cmd_register)
    q.add_argument('--user', required=True)
    q.add_argument('--pass', dest='password', required=True)
    q.add_argument('--lang', default='en', help='语言: en/zh')
    common_subparser(sub, 'logout', '退出登录', cmd_logout)
    common_subparser(sub, 'me', '当前用户 + 全部设置', cmd_me)

    # --- settings ---
    s = sub.add_parser('settings', help='Settings 全部项目')
    s.add_argument('--server', default=None)
    s.add_argument('--cookie', default=None)
    s.add_argument('--user', default=None)
    s.add_argument('--pass', dest='password', default=None)
    s.add_argument('--pretty', action='store_true')
    ss = s.add_subparsers(dest='sub', metavar='子命令')
    def _sc(name, help_text, handler):
        c = ss.add_parser(name, help=help_text)
        c.set_defaults(handler=handler)
        return c
    _sc('get', '读取全部设置', cmd_settings_get)
    c = _sc('toggle', '切换开关', cmd_settings_toggle)
    c.add_argument('name')
    c = _sc('privacy', '可被搜索设置', cmd_settings_privacy)
    c.add_argument('searchable')
    c.add_argument('searchable_by_uid')
    c = _sc('local-cache', '本地缓存 0/1', cmd_settings_local_cache)
    c.add_argument('enabled')
    c = _sc('emoji', 'emoji 面板/聊天模式', cmd_settings_emoji)
    c.add_argument('panel_mode')
    c.add_argument('chat_mode')
    c = _sc('timezone', '时区 ±HH:MM', cmd_settings_timezone)
    c.add_argument('tz')
    c = _sc('language', '语言 en/zh/zh_egg/wyw/raw', cmd_settings_language)
    c.add_argument('lang')
    c = _sc('name', '修改昵称', cmd_settings_name)
    c.add_argument('name')
    c = _sc('title', '自定义头衔', cmd_settings_title)
    c.add_argument('title')
    c = _sc('gender', '性别 0女/1男/空清除', cmd_settings_gender)
    c.add_argument('gender')
    c = _sc('gender-privacy', '性别隐私 0所有人/1好友/2隐藏', cmd_settings_gender_privacy)
    c.add_argument('privacy')
    c = _sc('birthday', '生日 YYYY-MM-DD 或空清除', cmd_settings_birthday)
    c.add_argument('birthday')
    c = _sc('space-ears', '空间耳朵 0/1', cmd_settings_space_ears)
    c.add_argument('enabled')
    c = _sc('password', '修改密码 <当前密码> <新密码>', cmd_settings_password)
    c.add_argument('current')
    c.add_argument('new')
    c = _sc('duress', '设置/清除胁迫密码', cmd_settings_duress)
    c.add_argument('current')
    c.add_argument('duress', nargs='?', default='')
    c = _sc('avatar', '上传头像 <文件>', cmd_settings_avatar)
    c.add_argument('file')

    # --- block ---
    b = sub.add_parser('block', help='黑名单')
    add_common(b)
    bs = b.add_subparsers(dest='sub', metavar='子命令')
    bs.add_parser('list', help='黑名单列表').set_defaults(handler=cmd_block_list)
    c = bs.add_parser('add', help='添加 <uid>')
    c.add_argument('uid'); c.set_defaults(handler=cmd_block_add)
    c = bs.add_parser('remove', help='移除 <uid>')
    c.add_argument('uid'); c.set_defaults(handler=cmd_block_remove)

    # --- bg ---
    bg = sub.add_parser('bg', help='聊天壁纸')
    add_common(bg)
    bgs = bg.add_subparsers(dest='sub', metavar='子命令')
    bgs.add_parser('get', help='当前壁纸+预设').set_defaults(handler=cmd_bg_get)
    c = bgs.add_parser('upload', help='上传 <file>')
    c.add_argument('file'); c.set_defaults(handler=cmd_bg_upload)
    c = bgs.add_parser('preset', help='用预设 <name>')
    c.add_argument('name'); c.set_defaults(handler=cmd_bg_preset)
    bgs.add_parser('remove', help='移除壁纸').set_defaults(handler=cmd_bg_remove)
    c = bgs.add_parser('privacy', help='隐私 0黑/1白/2仅自己')
    c.add_argument('privacy'); c.set_defaults(handler=cmd_bg_privacy)
    c = bgs.add_parser('blacklist', help='黑名单 uid 逗号分隔')
    c.add_argument('uids'); c.set_defaults(handler=cmd_bg_blacklist)
    c = bgs.add_parser('whitelist', help='白名单 uid 逗号分隔')
    c.add_argument('uids'); c.set_defaults(handler=cmd_bg_whitelist)
    c = bgs.add_parser('no-friend', help='好友是否可见 0/1')
    c.add_argument('val'); c.set_defaults(handler=cmd_bg_no_friend)
    c = bgs.add_parser('private', help='上传「不可见时背景」<file>')
    c.add_argument('file'); c.set_defaults(handler=cmd_bg_private)
    c = bgs.add_parser('private-set', help='指定 private 图 (bgi/xxx 或 res/wallpaper/xxx)')
    c.add_argument('ref'); c.set_defaults(handler=cmd_bg_private_set)

    # --- profile-bg ---
    pb = sub.add_parser('profile-bg', help='个人空间封面')
    add_common(pb)
    pbs = pb.add_subparsers(dest='sub', metavar='子命令')
    pbs.add_parser('get', help='当前封面').set_defaults(handler=cmd_profile_bg_get)
    c = pbs.add_parser('upload', help='上传 <file>')
    c.add_argument('file'); c.set_defaults(handler=cmd_profile_bg_upload)
    pbs.add_parser('remove', help='移除封面').set_defaults(handler=cmd_profile_bg_remove)
    c = pbs.add_parser('frame', help='取景 pos_x pos_y zoom flip')
    c.add_argument('pos_x'); c.add_argument('pos_y'); c.add_argument('zoom'); c.add_argument('flip')
    c.set_defaults(handler=cmd_profile_bg_frame)

    # --- sig ---
    sg = sub.add_parser('sig', help='签名隐私')
    add_common(sg)
    sgs = sg.add_subparsers(dest='sub', metavar='子命令')
    sgs.add_parser('get', help='当前签名隐私').set_defaults(handler=cmd_sig_get)
    c = sgs.add_parser('privacy', help='隐私 0黑/1白/2仅自己')
    c.add_argument('privacy'); c.set_defaults(handler=cmd_sig_privacy)
    c = sgs.add_parser('blacklist', help='黑名单 uid 逗号分隔')
    c.add_argument('uids'); c.set_defaults(handler=cmd_sig_blacklist)
    c = sgs.add_parser('whitelist', help='白名单 uid 逗号分隔')
    c.add_argument('uids'); c.set_defaults(handler=cmd_sig_whitelist)
    c = sgs.add_parser('no-friend', help='好友可见 0/1')
    c.add_argument('val'); c.set_defaults(handler=cmd_sig_no_friend)
    c = sgs.add_parser('hidden-text', help='好友可见时隐藏文字')
    c.add_argument('text'); c.set_defaults(handler=cmd_sig_hidden_text)

    # --- discover ---
    d = common_subparser(sub, 'discover', '发现用户 [q]', cmd_discover)
    d.add_argument('q', nargs='?', default='')
    d.add_argument('--page', type=int, default=1)

    # --- dm ---
    dm = sub.add_parser('dm', help='私聊')
    add_common(dm)
    dms = dm.add_subparsers(dest='sub', metavar='子命令')
    c = dms.add_parser('send', help='发消息')
    c.add_argument('user'); c.add_argument('message')
    c.add_argument('--file'); c.add_argument('--reply-to', type=int)
    c.add_argument('--md', action='store_true')
    c.set_defaults(handler=cmd_dm_send)
    c = dms.add_parser('history', help='历史')
    c.add_argument('user'); c.add_argument('--limit', type=int, default=50)
    c.add_argument('--before', type=int)
    c.set_defaults(handler=cmd_dm_history)
    dms.add_parser('conversations', help='会话列表').set_defaults(handler=cmd_dm_conversations)
    dms.add_parser('unread', help='未读数').set_defaults(handler=cmd_dm_unread)
    c = dms.add_parser('fetch', help='增量拉取')
    c.add_argument('--after', type=int)
    c.add_argument('--dm')
    c.set_defaults(handler=cmd_dm_fetch)
    c = dms.add_parser('mark-read', help='标记已读')
    c.add_argument('user'); c.set_defaults(handler=cmd_dm_mark_read)
    c = dms.add_parser('revoke', help='撤回(120s内)')
    c.add_argument('id', type=int); c.set_defaults(handler=cmd_dm_revoke)
    c = dms.add_parser('revoke-own', help='撤回自己任意消息')
    c.add_argument('id', type=int); c.set_defaults(handler=cmd_dm_revoke_own)
    c = dms.add_parser('search', help='搜索消息')
    c.add_argument('q'); c.add_argument('--dm'); c.add_argument('--group-id', type=int)
    c.add_argument('--page', type=int, default=1)
    c.set_defaults(handler=cmd_dm_search)

    # --- content ---
    ct = common_subparser(sub, 'content', '我的内容(文件/图片/视频)', cmd_content_list)
    ct.add_argument('--type', default='all', help='all/photo/video/file/audio')
    ct.add_argument('--limit', type=int, default=100)
    ct.add_argument('--offset', type=int, default=0)

    # --- group ---
    g = sub.add_parser('group', help='群组')
    add_common(g)
    gs = g.add_subparsers(dest='sub', metavar='子命令')
    gs.add_parser('list', help='我的群组').set_defaults(handler=cmd_group_list)
    c = gs.add_parser('create', help='建群')
    c.add_argument('name'); c.set_defaults(handler=cmd_group_create)
    c = gs.add_parser('info', help='群信息')
    c.add_argument('gid', type=int); c.set_defaults(handler=cmd_group_info)
    c = gs.add_parser('members', help='成员')
    c.add_argument('gid', type=int); c.set_defaults(handler=cmd_group_members)
    c = gs.add_parser('send', help='发消息')
    c.add_argument('gid', type=int); c.add_argument('message')
    c.add_argument('--file'); c.set_defaults(handler=cmd_group_send)
    c = gs.add_parser('history', help='历史')
    c.add_argument('gid', type=int); c.add_argument('--limit', type=int, default=50)
    c.add_argument('--before', type=int)
    c.set_defaults(handler=cmd_group_history)
    c = gs.add_parser('search', help='搜索群')
    c.add_argument('q'); c.add_argument('--page', type=int, default=1)
    c.set_defaults(handler=cmd_group_search)
    c = gs.add_parser('join', help='加入(公开直加/私有申请)')
    c.add_argument('gid', type=int); c.set_defaults(handler=cmd_group_join)
    c = gs.add_parser('request', help='申请加入私有群')
    c.add_argument('gid', type=int); c.set_defaults(handler=cmd_group_request)
    c = gs.add_parser('pending', help='待审批请求')
    c.add_argument('gid', type=int); c.set_defaults(handler=cmd_group_pending)
    c = gs.add_parser('approve', help='批准请求')
    c.add_argument('rid', type=int); c.set_defaults(handler=cmd_group_approve)
    c = gs.add_parser('reject', help='拒绝请求')
    c.add_argument('rid', type=int); c.set_defaults(handler=cmd_group_reject)
    c = gs.add_parser('invite', help='邀请成员')
    c.add_argument('gid', type=int); c.add_argument('user')
    c.set_defaults(handler=cmd_group_invite)
    c = gs.add_parser('kick', help='踢人')
    c.add_argument('gid', type=int); c.add_argument('uid', type=int)
    c.set_defaults(handler=cmd_group_kick)
    c = gs.add_parser('admin', help='设管理员')
    c.add_argument('gid', type=int); c.add_argument('uid', type=int)
    c.set_defaults(handler=cmd_group_admin)
    c = gs.add_parser('unadmin', help='取消管理员')
    c.add_argument('gid', type=int); c.add_argument('uid', type=int)
    c.set_defaults(handler=cmd_group_unadmin)
    c = gs.add_parser('rename', help='改名')
    c.add_argument('gid', type=int); c.add_argument('name')
    c.set_defaults(handler=cmd_group_rename)
    c = gs.add_parser('announce', help='公告(空=清除)')
    c.add_argument('gid', type=int); c.add_argument('text', nargs='?', default='')
    c.set_defaults(handler=cmd_group_announce)
    c = gs.add_parser('visibility', help='公开 0/1')
    c.add_argument('gid', type=int); c.add_argument('public', type=int)
    c.set_defaults(handler=cmd_group_visibility)
    c = gs.add_parser('transfer', help='转让群主')
    c.add_argument('gid', type=int); c.add_argument('uid', type=int)
    c.set_defaults(handler=cmd_group_transfer)
    c = gs.add_parser('mute', help='本人静音')
    c.add_argument('gid', type=int); c.set_defaults(handler=cmd_group_mute)
    c = gs.add_parser('unmute', help='取消本人静音')
    c.add_argument('gid', type=int); c.set_defaults(handler=cmd_group_unmute)
    c = gs.add_parser('mute-member', help='禁言成员')
    c.add_argument('gid', type=int); c.add_argument('uid', type=int)
    c.set_defaults(handler=cmd_group_mute_member)
    c = gs.add_parser('unmute-member', help='解除禁言')
    c.add_argument('gid', type=int); c.add_argument('uid', type=int)
    c.set_defaults(handler=cmd_group_unmute_member)
    c = gs.add_parser('mute-all', help='全员禁言')
    c.add_argument('gid', type=int); c.set_defaults(handler=cmd_group_mute_all)
    c = gs.add_parser('unmute-all', help='解除全员禁言')
    c.add_argument('gid', type=int); c.set_defaults(handler=cmd_group_unmute_all)
    c = gs.add_parser('pin', help='置顶群')
    c.add_argument('gid', type=int); c.set_defaults(handler=cmd_group_pin)
    c = gs.add_parser('avatar', help='上传群头像')
    c.add_argument('gid', type=int); c.add_argument('file')
    c.set_defaults(handler=cmd_group_avatar)
    c = gs.add_parser('leave', help='退群')
    c.add_argument('gid', type=int); c.set_defaults(handler=cmd_group_leave)
    c = gs.add_parser('dissolve', help='解散群')
    c.add_argument('gid', type=int); c.set_defaults(handler=cmd_group_dissolve)

    # --- contact ---
    co = sub.add_parser('contact', help='联系人')
    add_common(co)
    cos = co.add_subparsers(dest='sub', metavar='子命令')
    cos.add_parser('list', help='联系人列表').set_defaults(handler=cmd_contact_list)
    c = cos.add_parser('search', help='搜索')
    c.add_argument('q'); c.set_defaults(handler=cmd_contact_search)
    c = cos.add_parser('add', help='加好友')
    c.add_argument('user'); c.add_argument('--msg'); c.add_argument('--note')
    c.set_defaults(handler=cmd_contact_add)
    c = cos.add_parser('force-add', help='强制加好友(仅root/admin)')
    c.add_argument('user'); c.set_defaults(handler=cmd_contact_force_add)
    cos.add_parser('pending', help='待处理请求').set_defaults(handler=cmd_contact_pending)
    c = cos.add_parser('accept', help='接受请求')
    c.add_argument('user'); c.set_defaults(handler=cmd_contact_accept)
    c = cos.add_parser('reject', help='拒绝请求')
    c.add_argument('user'); c.set_defaults(handler=cmd_contact_reject)
    c = cos.add_parser('remove', help='删除好友')
    c.add_argument('user'); c.set_defaults(handler=cmd_contact_remove)
    c = cos.add_parser('nickname', help='改备注')
    c.add_argument('user'); c.add_argument('note')
    c.set_defaults(handler=cmd_contact_nickname)
    c = cos.add_parser('pin', help='置顶好友')
    c.add_argument('user'); c.set_defaults(handler=cmd_contact_pin)
    cos.add_parser('pin-self', help='置顶自己').set_defaults(handler=cmd_contact_pin_self)

    # --- moments ---
    m = sub.add_parser('moments', help='朋友圈(个人空间)')
    add_common(m)
    ms = m.add_subparsers(dest='sub', metavar='子命令')
    c = ms.add_parser('post', help='发说说')
    c.add_argument('content'); c.add_argument('--visibility', type=int, default=0)
    c.add_argument('--to')
    c.set_defaults(handler=cmd_moments_post)
    c = ms.add_parser('list', help='拉取说说')
    c.add_argument('--user'); c.add_argument('--uid', type=int)
    c.set_defaults(handler=cmd_moments_list)
    c = ms.add_parser('delete', help='删除说说')
    c.add_argument('id', type=int); c.set_defaults(handler=cmd_moments_delete)
    c = ms.add_parser('like', help='点赞/取消')
    c.add_argument('id', type=int); c.set_defaults(handler=cmd_moments_like)
    c = ms.add_parser('comment', help='评论')
    c.add_argument('feed_id', type=int); c.add_argument('content')
    c.add_argument('--parent', type=int)
    c.set_defaults(handler=cmd_moments_comment)
    c = ms.add_parser('comments', help='评论列表')
    c.add_argument('feed_id', type=int); c.set_defaults(handler=cmd_moments_comments)
    c = ms.add_parser('comment-del', help='删除评论')
    c.add_argument('id', type=int); c.set_defaults(handler=cmd_moments_comment_del)
    c = ms.add_parser('message', help='留言板留言')
    c.add_argument('to_uid', type=int); c.add_argument('content')
    c.set_defaults(handler=cmd_moments_message)
    c = ms.add_parser('messages', help='留言板列表')
    c.add_argument('--to-uid', dest='to_uid', type=int, default=0)
    c.set_defaults(handler=cmd_moments_messages)
    c = ms.add_parser('message-del', help='删除留言')
    c.add_argument('id', type=int); c.set_defaults(handler=cmd_moments_message_del)
    c = ms.add_parser('blog', help='写日志')
    c.add_argument('title'); c.add_argument('content')
    c.add_argument('--visibility', type=int, default=0); c.add_argument('--to')
    c.set_defaults(handler=cmd_moments_blog)
    c = ms.add_parser('blogs', help='日志列表')
    c.add_argument('--user'); c.add_argument('--uid', type=int)
    c.set_defaults(handler=cmd_moments_blogs)
    c = ms.add_parser('blog-get', help='读日志')
    c.add_argument('id', type=int); c.set_defaults(handler=cmd_moments_blog_get)
    c = ms.add_parser('blog-del', help='删日志')
    c.add_argument('id', type=int); c.set_defaults(handler=cmd_moments_blog_del)

    # --- account ---
    ac = sub.add_parser('account', help='账号(危险)')
    add_common(ac)
    acs = ac.add_subparsers(dest='sub', metavar='子命令')
    c = acs.add_parser('delete', help='删除账号')
    c.add_argument('password')
    c.add_argument('--mode', default='delete', help='delete/revoke/delete_all')
    c.add_argument('--yes', action='store_true')
    c.set_defaults(handler=cmd_account_delete)

    return p


def main():
    parser = build_parser()
    args = parser.parse_args()
    handler = getattr(args, 'handler', None)
    if handler is None:
        parser.print_help()
        sys.exit(2)
    server = args.server or DEFAULT_SERVER
    cookie = args.cookie or DEFAULT_COOKIE
    # 确保 cookie 目录存在
    os.makedirs(os.path.dirname(cookie), exist_ok=True)
    # login/register 不需要已有会话
    if handler in (cmd_login, cmd_register):
        a = ChatAppAPI(server)
        a.set_cookie_file(cookie)
        handler(a, args)
        return
    a = get_api(server, cookie, args.user, args.password)
    handler(a, args)


if __name__ == '__main__':
    main()
