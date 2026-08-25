<?php
/**
 * 共享底部版权栏（ChatApp）。
 * 各页面（modern/wp/login.php、errors/403.php、errors/404.php、index.php）
 * 通过 `include` 引用本文件 —— 改这一处，全站 footer（含样式/版权/版本号）一起更新。
 * 用 PHP include 而非 iframe：零额外 HTTP 请求、无 iframe 尺寸/焦点问题、共享同一会话。
 */
$__footerInfo = include dirname(__DIR__, 2) . '/config/info.php';
$__appVersion = is_array($__footerInfo) ? (string)($__footerInfo['version'] ?? '') : '';
?>
<style>
#footer{position:fixed;bottom:0;left:0;width:100%;height:46px;line-height:46px;text-align:center;z-index:0;font-size:14px;word-break:keep-all;white-space:nowrap;color:#fff;backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);background:rgba(0,0,0,0.25)}
#footer .f-left{position:absolute;left:24px;top:0}
#footer .f-right{position:absolute;right:24px;top:0}
#footer a{color:#fff;text-decoration:none}
#footer a:hover{color:rgb(57,159,255);text-decoration:underline}
@media (max-width:560px){#footer .c-hidden{display:none}}
@media (max-width:480px){#footer .hidden{display:none}}
</style>
<footer id="footer" class="blur">
    <div class="power">
        <span class="f-left">ChatApp</span>
        <span><span class="hidden">Copyright&nbsp;</span>&copy; 2026 <a href="//lqx211.com">铵铵</a> <span class="hidden">&amp;&nbsp;Made&nbsp;by <a href="https://github.com/lqx211" target="_blank">lqx211</a></span></span>
        <?php if ($__appVersion !== ''):?><span class="f-right">Version <?php echo htmlspecialchars($__appVersion);?></span><?php endif;?>
    </div>
</footer>
