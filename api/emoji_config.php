<?php
/**
 * ChatApp · 内置表情配置读取（api/emoji_config.php）
 *
 * 从 data/res/emoji/default_config.json 解析出内置表情清单。
 * 原来这段逻辑长在 api/emoji.php 里（_builtin_list），机器人/伪人那边也要用
 * （校验聊天记录里出现的「/代码」是不是真表情、给优化器出题），所以抽到这里，
 * 两边共用一份，避免各写一套解析。
 *
 * 纯函数、无副作用：被 emoji.php / bots.php 直接 require。
 */

if (!function_exists('chatapp_builtin_emojis')) {
    /** @return array<int, array{id:string,code:string,type:int,group:string,img:?string,img_dyn?:string,unicode?:string}> */
    function chatapp_builtin_emojis(): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        $path = __DIR__ . '/../data/res/emoji/default_config.json';
        if (!file_exists($path)) { $cache = []; return $cache; }
        $raw = json_decode((string)file_get_contents($path), true);
        $list = [];
        $dir = __DIR__ . '/../data/res/emoji/';
        // 表情文件放在网络盘（/Volumes/…）时 file_exists 偶尔会假阴性，
        // 一旦发现某个 PNG「不存在」，就扫一遍目录用作兵（不然列表会莫名其妙只剩 Unicode 那几个）
        $dirIds = null;
        $inDir = function (string $name) use ($dir, &$dirIds): bool {
            if (@file_exists($dir . $name)) return true;
            if ($dirIds === null) $dirIds = @scandir($dir) ?: [];
            return in_array($name, $dirIds, true);
        };
        foreach (($raw['normalPanelResult']['SysEmojiGroupList'] ?? []) as $g) {
            $gname = $g['groupName'] ?? 'Emoji';
            foreach ($g['SysEmojiList'] ?? [] as $e) {
                if (!empty($e['isHide'])) continue;
                $etype = (int)($e['emojiType'] ?? 0);
                $eid   = (string)($e['emojiId'] ?? '');
                $desc  = $e['describe'] ?? '';
                $entry = [
                    'id'    => $eid,
                    'code'  => $desc,
                    'type'  => $etype,
                    'group' => $gname,
                    'img'   => null,
                ];
                if ($etype === 4) {
                    // Unicode native emoji – no PNG
                    $entry['unicode'] = $eid;
                } else {
                    if ($eid !== '' && $inDir($eid . '.png')) {
                        $entry['img'] = 'data/res/emoji/' . $eid . '.png';
                        if ($inDir('s' . $eid . '.png')) {
                            $entry['img_dyn'] = 'data/res/emoji/s' . $eid . '.png';
                        }
                    } else {
                        continue; // missing PNG – skip
                    }
                }
                $list[] = $entry;
            }
        }
        $cache = $list;
        return $cache;
    }
}
