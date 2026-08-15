<?php
require_once __DIR__ . '/../api/config.php';
chatapp_require_login();
if ($_SESSION['username'] !== 'admin') { header('Location: chat.php'); exit; }
$currentUser = chatapp_get_user();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>All Users - Admin</title><link rel="stylesheet" href="../css/global.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',sans-serif;background:#1a1a1a;color:#e0e0e0;height:100vh;display:flex}
.sidebar{width:200px;min-width:200px;background:#1e1e1e;border-right:1px solid #3a3a3a;display:flex;flex-direction:column;height:100vh;user-select:none}
.sidebar-profile{padding:14px;border-bottom:1px solid #3a3a3a;text-align:center}
.sa{width:44px;height:44px;border:1px solid #555;margin:0 auto 6px;overflow:hidden;background:#2a2a2a}
.sa img{width:100%;height:100%;object-fit:cover}
.sun{font-size:.8em;color:#ccc;font-weight:600}
.sidebar-nav{flex:1;overflow-y:auto;padding:4px 0}
.ng{margin-bottom:0}
.ngh{display:flex;align-items:center;justify-content:space-between;padding:8px 14px;cursor:pointer;color:#999;font-size:.78em;font-weight:600;text-decoration:none}
.ngh:hover{color:#ccc}
.ngh .ar{font-size:.7em;display:inline-block;transition:transform .2s}
.ngh .ar.op{transform:rotate(90deg)}
.ngb{display:none}
.ngb.op{display:block}
.csi{display:flex;align-items:center;gap:8px;padding:6px 14px 6px 24px;cursor:pointer;color:#888;font-size:.78em}
.csi:hover{background:#2a2a2a;color:#ccc}
.csi .ca{width:22px;height:22px;border:1px solid #444;background:#1e1e1e;overflow:hidden;flex-shrink:0}
.csi .ca img{width:100%;height:100%;object-fit:cover}
.csi .cn{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.na{padding:6px 14px 6px 24px;cursor:pointer;color:#777;font-size:.75em;text-decoration:none;display:block}
.na:hover{color:#aaa}
.sbox{padding:4px 14px 8px 24px}
.sbox input{width:100%;padding:6px 8px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-size:.72em;font-family:inherit;outline:none}
.sr{max-height:150px;overflow-y:auto}
.sri{display:flex;align-items:center;justify-content:space-between;padding:5px 14px 5px 24px;font-size:.72em;color:#999}
.sri .bt{background:#3a3a3a;border:1px solid #555;color:#ccc;padding:2px 8px;cursor:pointer;font-size:.85em;font-family:inherit}
.sri .bt:hover{background:#4a4a4a}
.pi{display:flex;align-items:center;gap:6px;padding:5px 14px 5px 24px;font-size:.72em;color:#999}
.pi .bt{background:#3a3a3a;border:1px solid #555;color:#ccc;padding:2px 8px;cursor:pointer;font-size:.85em;font-family:inherit}
.pi .bt.ac{background:#2a4a2a;border-color:#3a6a3a}
.pi .bt.rj{background:#4a2a2a;border-color:#6a3a3a}
.sidebar-footer{padding:8px 14px;border-top:1px solid #3a3a3a}
.sidebar-footer .ngh{padding:6px 0;font-size:.75em}

.main{flex:1;display:flex;flex-direction:column;height:100vh;background:#222;overflow-y:auto}
.header{background:#2a2a2a;padding:12px 20px;border-bottom:1px solid #3a3a3a;flex-shrink:0}
.header h2{font-size:1.05em;font-weight:600;color:#c0c0c0}
.toolbar{display:flex;gap:10px;padding:12px 20px;background:#262626;border-bottom:1px solid #3a3a3a;flex-shrink:0;align-items:center;flex-wrap:wrap}
.toolbar input[type="text"]{width:200px;padding:6px 10px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-size:.82em;font-family:inherit;outline:none}
.toolbar button{padding:6px 14px;background:#3a3a3a;border:1px solid #555;color:#ccc;cursor:pointer;font-size:.78em;font-family:inherit}
.toolbar button:hover{background:#4a4a4a}
.toolbar label{font-size:.75em;color:#999;display:flex;align-items:center;gap:6px;cursor:pointer}
.toolbar input[type="checkbox"]{accent-color:#888}
.table-wrap{flex:1;overflow-y:auto;padding:0 20px 20px}
table{width:100%;border-collapse:collapse;font-size:.78em}
th{text-align:left;padding:8px 10px;color:#aaa;font-weight:600;border-bottom:1px solid #3a3a3a;position:sticky;top:0;background:#222}
td{padding:7px 10px;border-bottom:1px solid #2e2e2e;color:#ccc}
tr:hover td{background:#2a2a2a}
.hash{max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:monospace;font-size:.72em;color:#888}
td.enabled .badge{padding:2px 8px;font-size:.7em;color:#fff}
.badge.on{background:#2a4a2a;border:1px solid #3a6a3a}
.badge.off{background:#4a2a2a;border:1px solid #6a3a3a}
.clickable{cursor:pointer;color:#6a9fd8}
.clickable:hover{text-decoration:underline}
.pagination{display:flex;justify-content:space-between;align-items:center;padding:12px 20px;background:#262626;border-top:1px solid #3a3a3a;flex-shrink:0;font-size:.78em;color:#888}
.pagination button{padding:5px 12px;background:#3a3a3a;border:1px solid #555;color:#ccc;cursor:pointer;font-family:inherit;font-size:.82em}
.pagination button:hover{background:#4a4a4a}
.modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.85);z-index:1000;justify-content:center;align-items:center}
.modal-overlay.active{display:flex}
.modal-box{background:#2a2a2a;border:1px solid #4a4a4a;padding:28px;width:380px;text-align:center}
.modal-box h3{color:#c0c0c0;margin-bottom:16px;font-size:1em}
.modal-actions{display:flex;flex-direction:column;gap:8px}
.modal-actions button,.modal-actions a{display:block;width:100%;padding:9px;text-align:center;font-size:.82em;cursor:pointer;font-family:inherit;border:1px solid #555;text-decoration:none}
.btn-m{background:#3a3a3a;color:#ccc}
.btn-m:hover{background:#4a4a4a}
.btn-danger{background:#4a2020;border-color:#5c2a2a;color:#e06060}
.btn-danger:hover{background:#5a2a2a}
</style>
</head>
<body>
<!-- IDENTICAL SIDEBAR as chat.php -->
<div class="sidebar">
 <div class="sidebar-profile">
  <div class="sa" id="sidebarAvatar"><?php if($currentUser['avatar']):?><img src="<?php echo htmlspecialchars(chatapp_avatar_url($currentUser['avatar'] ?? '', $currentUser['username'] ?? ''));?>"><?php endif;?></div>
  <div class="sun"><?php echo htmlspecialchars($currentUser['username']);?></div>
 </div>
 <div class="sidebar-nav">
  <div class="ng"><a href="chat.php" class="ngh" style="text-decoration:none"><span>Announcements</span></a></div>
   <div class="ng"><a href="users.php" class="ngh" style="text-decoration:none;color:#ccc;background:#2e2e2e;border-left:3px solid #888"><span>All Users</span></a></div>
   <div class="ng"><a href="#" class="ngh" style="text-decoration:none" onclick="showSecurityLogs();return false"><span>Security Logs</span></a></div>
  <div class="ng">
   <div class="ngh" onclick="toggleGroup('contactsGroup')"><span>Contacts</span><span class="ar op" id="arrow-contactsGroup">&#9654;</span></div>
   <div class="ngb op" id="body-contactsGroup">
    <div class="csi" onclick="openDm('<?php echo htmlspecialchars($currentUser['username']);?>')"><div class="ca" id="contactSelfAvatar"><?php if($currentUser['avatar']):?><img src="<?php echo htmlspecialchars(chatapp_avatar_url($currentUser['avatar'] ?? '', $currentUser['username'] ?? ''));?>"><?php endif;?></div><div class="cn"><?php echo htmlspecialchars($currentUser['username']);?> (me)</div></div>
    <div id="friendContacts"></div>
    <div id="pendingBadge" style="display:none"><div class="na" onclick="togglePending()" style="color:#e0a040">Friend Requests (<span id="pendingCount">0</span>)</div></div>
    <div id="pendingList" style="display:none"></div>
    <div class="na" onclick="toggleAddContact()">+ Add Contact</div>
    <div id="addContactBox" style="display:none"><div class="sbox"><input type="text" id="searchInput2" placeholder="Search username..." oninput="searchUsers2()" autocomplete="off"></div><div class="sr" id="searchResults2"></div></div>
   </div>
  </div>
  <div class="ng">
   <div class="ngh" onclick="toggleGroup('moreGroup')"><span>More</span><span class="ar" id="arrow-moreGroup">&#9654;</span></div>
   <div class="ngb" id="body-moreGroup"><a href="chat.php" class="na">Settings</a></div>
  </div>
 </div>
 <div class="sidebar-footer"><div class="ngh" onclick="logout()" style="cursor:pointer"><span>Logout</span></div></div>
</div>

<div class="main">
 <div class="header"><h2>All Users</h2></div>
 <div class="toolbar">
  <input type="text" id="searchInput" placeholder="Search username..." onkeydown="if(event.key==='Enter')search()">
  <button onclick="search()">Search</button>
  <label><input type="checkbox" id="regexToggle" onchange="search()"> Enable Regex</label>
  <button onclick="document.getElementById('searchInput').value='';document.getElementById('regexToggle').checked=false;search()" style="margin-left:auto">Clear</button>
 </div>
 <div class="table-wrap">
  <table>
   <thead><tr><th>Username</th><th>Password Hash</th><th>Status</th><th>Created</th></tr></thead>
   <tbody id="userTable"><tr><td colspan="4" style="text-align:center;color:#555">Loading...</td></tr></tbody>
  </table>
 </div>
 <div class="pagination" id="pagination">
  <span></span>
  <span></span>
 </div>
</div>

<div class="modal-overlay" id="userModal">
 <div class="modal-box">
  <h3 id="modalTitle">User: </h3>
  <div class="modal-actions" id="modalActions"></div>
  <button class="btn-m" onclick="closeModal()" style="margin-top:12px">Back</button>
 </div>
</div>

<div class="modal-overlay" id="passwordModal">
 <div class="modal-box">
  <h3>Change Password</h3>
  <div style="margin-bottom:12px"><input type="text" id="newPassword" placeholder="New password (min 4 chars)" style="width:100%;padding:8px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit"></div>
  <button class="btn-m" onclick="doChangePassword()">Save</button>
  <button class="btn-m" onclick="closePasswordModal()" style="margin-top:6px">Cancel</button>
 </div>
</div>

<script>
var U=<?php echo json_encode($currentUser['username']);?>;
var currentPage=1,selectedUser=null;

function toggleGroup(n){
 var b=document.getElementById('body-'+n),a=document.getElementById('arrow-'+n);
 b.classList.toggle('op');a.classList.toggle('op');
}
function eh(t){var d=document.createElement('div');d.appendChild(document.createTextNode(t));return d.innerHTML}

// --- Sidebar contacts (same as chat.php) ---
function loadContacts(){
 fetch('../api/contacts.php?action=list').then(r=>r.json()).then(function(d){
  var e=document.getElementById('friendContacts');
  if(d.success&&d.contacts.length>0){
   var h='';
   for(var i=0;i<d.contacts.length;i++){
    var c=d.contacts[i],a=c.avatar?'<img src="'+c.avatar+'">':'';
    h+='<div class="csi" onclick="window.location.href=\'chat.php?dm='+encodeURIComponent(c.username)+'\'"><div class="ca">'+a+'</div><div class="cn">'+eh(c.username)+'</div></div>';
   }
   e.innerHTML=h;
  }else e.innerHTML='';
 });
}
function loadPending(){
 fetch('../api/contacts.php?action=pending').then(r=>r.json()).then(function(d){
  if(d.success&&d.pending.length>0){
   document.getElementById('pendingBadge').style.display='block';
   document.getElementById('pendingCount').textContent=d.pending.length;
   var h='';
   for(var i=0;i<d.pending.length;i++){
    var p=d.pending[i];
    h+='<div class="pi"><span style="flex:1">'+eh(p.username)+'</span><button class="bt ac" onclick="respondRequest(\''+p.username+'\',\'accept\')">Accept</button><button class="bt rj" onclick="respondRequest(\''+p.username+'\',\'reject\')">Reject</button></div>';
   }
   document.getElementById('pendingList').innerHTML=h;
  }else{
   document.getElementById('pendingBadge').style.display='none';
   document.getElementById('pendingList').style.display='none';
  }
 });
}
function togglePending(){var e=document.getElementById('pendingList');e.style.display=e.style.display==='none'?'block':'none'}
function respondRequest(u,r){
 var f=new URLSearchParams();f.append('action','respond');f.append('username',u);f.append('response',r);
 fetch('../api/contacts.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:f.toString()}).then(r=>r.json()).then(function(d){if(d.success){loadPending();loadContacts()}});
}
function toggleAddContact(){
 var b=document.getElementById('addContactBox');b.style.display=b.style.display==='none'?'block':'none';
 if(b.style.display==='block')document.getElementById('searchInput2').focus();
 else{document.getElementById('searchResults2').innerHTML='';document.getElementById('searchInput2').value=''}
}
var ST2=null;
function searchUsers2(){
 clearTimeout(ST2);var q=document.getElementById('searchInput2').value.trim();
 if(q.length<1){document.getElementById('searchResults2').innerHTML='';return}
 ST2=setTimeout(function(){
  fetch('../api/contacts.php?action=search&q='+encodeURIComponent(q)).then(r=>r.json()).then(function(d){
   var e=document.getElementById('searchResults2');
   if(d.success&&d.users.length>0){
    var h='';
    for(var i=0;i<d.users.length;i++){
     var u=d.users[i],b='';
     if(u.relation==='accepted')b='<span style="color:#666">Friends</span>';
     else if(u.relation==='pending')b='<span style="color:#e0a040">Pending</span>';
     else b='<button class="bt" onclick="sendFriendRequest(\''+u.username+'\')">Add</button>';
     h+='<div class="sri"><span>'+eh(u.username)+'</span>'+b+'</div>';
    }
    e.innerHTML=h;
   }else e.innerHTML='<div class="sri"><span>No users found</span></div>';
  });
 },300);
}
function sendFriendRequest(u){
 var f=new URLSearchParams();f.append('action','send_request');f.append('username',u);
 fetch('../api/contacts.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:f.toString()}).then(r=>r.json()).then(function(d){if(d.success)searchUsers2();else alert('Something went wrong.')});
}

// --- DM redirect ---
function openDm(u){window.location.href='chat.php?dm='+encodeURIComponent(u)}

// --- User table ---
function search(p){
 currentPage=p||1;
 var q=document.getElementById('searchInput').value.trim(),re=document.getElementById('regexToggle').checked?'1':'0';
 fetch('../api/admin.php?action=list&search='+encodeURIComponent(q)+'&regex='+re+'&page='+currentPage)
  .then(r=>r.json()).then(function(d){
   if(!d.success)return;
   var t=document.getElementById('userTable'),h='';
   if(d.users.length===0)h='<tr><td colspan="4" style="text-align:center;color:#555">No users found.</td></tr>';
   else for(var i=0;i<d.users.length;i++){
    var u=d.users[i],badge=u.enabled?'<span class="badge on">Enabled</span>':'<span class="badge off">Disabled</span>';
    h+='<tr><td><span class="clickable" onclick="openUserModal(\''+u.username+'\','+u.enabled+')">'+eh(u.username)+'</span></td><td class="hash" title="'+eh(u.password)+'">'+eh(u.password)+'</td><td class="enabled">'+badge+'</td><td>'+eh(u.created_at)+'</td></tr>';
   }
   t.innerHTML=h;
   var tp=Math.ceil(d.total/d.per_page),pg='';
   if(currentPage>1)pg+='<button onclick="search('+(currentPage-1)+')">Prev</button> ';
   else pg+='<button disabled>Prev</button> ';
   if(currentPage<tp)pg+='<button onclick="search('+(currentPage+1)+')">Next</button>';
   else pg+='<button disabled>Next</button>';
   document.getElementById('pagination').innerHTML='<span>Showing '+(d.users.length>0?((currentPage-1)*d.per_page+1):0)+'-'+Math.min(currentPage*d.per_page,d.total)+' of '+d.total+' items</span><span>'+pg+'</span>';
  });
}

function openUserModal(u,enabled){
 selectedUser=u;
 document.getElementById('modalTitle').textContent='User: '+u;
 var h='';
 h+='<a href="#" class="btn-m" onclick="event.preventDefault();openPasswordModal()">Change Password</a>';
 h+='<a href="#" class="btn-m" onclick="event.preventDefault();loginAs()">Login as User</a>';
 if(u!=='admin')h+='<button class="btn-m" onclick="toggleUser()">'+(enabled?'Disable Account':'Enable Account')+'</button>';
 if(u!=='admin')h+='<button class="btn-m" onclick="clearDuressAdmin()"><?php echo t('btn_clear_duress','Clear Duress Password');?></button>';
 if(u!=='admin')h+='<button class="btn-danger" onclick="deleteUser()">Delete Account</button>';
 document.getElementById('modalActions').innerHTML=h;
 document.getElementById('userModal').classList.add('active');
}

function clearDuressAdmin(){
 if(!confirm('<?php echo htmlspecialchars(t('msg_duress_admin_clear','Clear duress password for %s? The user will need to set a new one themselves.'), ENT_QUOTES);?>'.replace('%s', selectedUser)))return;
 var f=new URLSearchParams();f.append('action','clear_duress');f.append('username',selectedUser);
 fetch('../api/admin.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:f.toString()}).then(r=>r.json()).then(function(d){if(d.success){alert('<?php echo htmlspecialchars(t('msg_duress_cleared','Duress password cleared.'), ENT_QUOTES);?>');closeModal();search(currentPage)}else alert('Something went wrong.')});
}

function closeModal(){document.getElementById('userModal').classList.remove('active');selectedUser=null}
function openPasswordModal(){document.getElementById('passwordModal').classList.add('active');document.getElementById('newPassword').value='';document.getElementById('newPassword').focus()}
function closePasswordModal(){document.getElementById('passwordModal').classList.remove('active')}

function doChangePassword(){
 var p=document.getElementById('newPassword').value;
 if(p.length<4){alert('Min 4 characters');return}
 var f=new URLSearchParams();f.append('action','change_password');f.append('username',selectedUser);f.append('new_password',p);
 fetch('../api/admin.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:f.toString()}).then(r=>r.json()).then(function(d){if(d.success){alert('Password changed.');closePasswordModal()}else alert('Something went wrong.')});
}
function toggleUser(){
 var f=new URLSearchParams();f.append('action','toggle');f.append('username',selectedUser);
 fetch('../api/admin.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:f.toString()}).then(r=>r.json()).then(function(d){if(d.success){closeModal();search(currentPage)}else alert('Something went wrong.')});
}
function deleteUser(){
 if(!confirm('Permanently delete '+selectedUser+'?'))return;
 var f=new URLSearchParams();f.append('action','delete');f.append('username',selectedUser);
 fetch('../api/admin.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:f.toString()}).then(r=>r.json()).then(function(d){if(d.success){closeModal();search(currentPage)}else alert('Something went wrong.')});
}
function loginAs(){
 var f=new URLSearchParams();f.append('action','login_as');f.append('username',selectedUser);
 fetch('../api/admin.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:f.toString()}).then(r=>r.json()).then(function(d){if(d.success)window.location.href='chat.php';else alert('Something went wrong.')});
}

async function logout(){try{await fetch('../api/auth.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=logout'})}catch(e){}window.location.href='login.php'}

// --- Security Logs ---
var secPage = 1, secView = false;
function showSecurityLogs(){
    secView = true; secPage = 1;
    document.querySelector('.header h2').textContent = 'Security Logs';
    document.querySelector('.toolbar').innerHTML = '<input type="text" id="secSearch" placeholder="Search logs..." onkeydown="if(event.key===\'Enter\')loadSecLogs(1)"><button onclick="loadSecLogs(1)">Search</button><button onclick="document.getElementById(\'secSearch\').value=\'\';loadSecLogs(1)" style="margin-left:auto">Clear</button>';
    document.querySelector('.table-wrap').innerHTML = '<table><thead><tr><th>Event</th><th>IP</th><th>Path</th><th>Details</th><th>Time</th></tr></thead><tbody id="secTable"><tr><td colspan="5" style="text-align:center;color:#555">Loading...</td></tr></tbody></table>';
    loadSecLogs(1);
}
function loadSecLogs(p){
    secPage = p || 1;
    var q = document.getElementById('secSearch') ? document.getElementById('secSearch').value.trim() : '';
    fetch('../api/admin.php?action=security_logs&page='+secPage+'&q='+encodeURIComponent(q))
     .then(r=>r.json()).then(function(d){
        if(!d.success) return;
        var h = '';
        if(d.logs.length === 0) h = '<tr><td colspan="5" style="text-align:center;color:#555">No entries.</td></tr>';
        else for(var i=0;i<d.logs.length;i++){
            var l = d.logs[i];
            h += '<tr><td>'+eh(l.event_type)+'</td><td>'+eh(l.ip_address||'')+'</td><td class="hash" title="'+eh(l.target_path||'')+'">'+eh(l.target_path||'')+'</td><td>'+eh((l.details||'').substring(0,120))+'</td><td>'+eh(l.created_at)+'</td></tr>';
        }
        document.getElementById('secTable').innerHTML = h;
        var tp = Math.ceil(d.total / d.per_page), pg = '';
        if(secPage > 1) pg += '<button onclick="loadSecLogs('+(secPage-1)+')">Prev</button> ';
        else pg += '<button disabled>Prev</button> ';
        if(secPage < tp) pg += '<button onclick="loadSecLogs('+(secPage+1)+')">Next</button>';
        else pg += '<button disabled>Next</button>';
        document.getElementById('pagination').innerHTML = '<span>Showing '+(d.logs.length>0?((secPage-1)*d.per_page+1):0)+'-'+Math.min(secPage*d.per_page,d.total)+' of '+d.total+' entries</span><span>'+pg+'</span>';
    });
}

// Init
search(1);loadContacts();loadPending();setInterval(function(){loadPending();loadContacts()},30000);
</script>
</body></html>