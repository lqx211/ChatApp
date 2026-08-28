<?php
/** ChatApp · WebSocket 通讯模式（可在 WebSocket Settings 修改）
 *  local   : 本地回环（localhost / 127.0.0.1 / ::1）
 *  private : 私网 / 局域网（如 0.0.0.0 或 192.168.x.x）
 *  public  : 公网（如 wss.lqx211.com）
 *  前端按当前访问来源自动选择对应地址。 */
return [
    'local'   => '127.0.0.1:9090',
    'private' => '0.0.0.0:9090',
    'public'  => 'wss://wss.lqx211.com',
];
