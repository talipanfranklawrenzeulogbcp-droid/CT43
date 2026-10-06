/* Great Solomon Manpower Services Inc. Core Transaction 4 - single combined JavaScript file */

/* Preserve the user's exact viewport position across normal form updates.
   Server-side POST/redirect/GET flows stay unchanged; this only restores the
   scroll position after the browser loads the resulting page. */
(function(){
  const KEY='ct4_pending_scroll_position_v1';
  function save(){
    try{
      sessionStorage.setItem(KEY,JSON.stringify({
        x:window.scrollX||0,
        y:window.scrollY||0,
        path:window.location.pathname,
        time:Date.now()
      }));
    }catch(_){}
  }
  /* Use a second listener so defaultPrevented can be checked after submit handlers. */
  document.addEventListener('submit',function(e){
    const form=e.target;
    if(!form || (form.target && form.target!=='_self')) return;
    queueMicrotask(function(){
      if(e.defaultPrevented) return;
      const method=(form.getAttribute('method')||'get').toLowerCase();
      if(method!=='get' && method!=='post') return;
      try{
        const action=new URL(form.getAttribute('action')||window.location.href,window.location.href);
        if(action.origin!==window.location.origin) return;
      }catch(_){ return; }
      save();
    });
  });
  function restore(){
    try{
      const raw=sessionStorage.getItem(KEY);
      if(!raw)return;
      const state=JSON.parse(raw);
      if(!state || Date.now()-Number(state.time||0)>15000)return sessionStorage.removeItem(KEY);
      sessionStorage.removeItem(KEY);
      const restoreNow=()=>{
        window.scrollTo({left:Number(state.x)||0,top:Number(state.y)||0,behavior:'auto'});
      };
      /* Run after DOM/layout and after native #anchor handling. */
      requestAnimationFrame(()=>requestAnimationFrame(restoreNow));
    }catch(_){}
  }
  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',restore,{once:true});
  }else{
    restore();
  }
})();

document.addEventListener('DOMContentLoaded', () => {
  if(window.FEEDBACK_SENT){ setTimeout(showFeedbackSentModal, 100); }
  initNotificationWatcher();
  const toggle=document.getElementById('sidebarToggle');
  const sidebar=document.getElementById('sidebar');
  const backdrop=document.getElementById('sidebar-backdrop');
  if(toggle&&sidebar){toggle.addEventListener('click',()=>{sidebar.classList.toggle('open');backdrop&&backdrop.classList.toggle('show');});}
  if(backdrop){backdrop.addEventListener('click',()=>{sidebar?.classList.remove('open');backdrop.classList.remove('show');});}
  document.addEventListener('keydown',e=>{if(e.key==='Escape'){document.getElementById('modalRoot')?.replaceChildren();sidebar?.classList.remove('open');backdrop?.classList.remove('show');}});
  document.querySelectorAll('input[type="number"]').forEach(i=>i.addEventListener('wheel',e=>e.preventDefault(),{passive:false}));
  document.querySelectorAll('form').forEach(form=>form.addEventListener('submit',()=>{const btn=form.querySelector('button[type="submit"],button:not([type])');if(btn){btn.disabled=true;btn.dataset.originalText=btn.innerHTML;btn.innerHTML='<span class="material-symbols-outlined">hourglass_top</span> Processing...';setTimeout(()=>{btn.disabled=false;btn.innerHTML=btn.dataset.originalText||'Submit';},4000);}}));
});
function showModal(title,body){const root=document.getElementById('modalRoot');if(!root)return;root.innerHTML=`<div class="gw-modal-backdrop" onclick="if(event.target===this)closeModal()"><div class="gw-modal"><div class="gw-modal-head"><strong>${escapeHtml(title)}</strong><button class="gw-modal-close" onclick="closeModal()"><span class="material-symbols-outlined">close</span></button></div><div class="gw-modal-body"><p style="font-size:12px;line-height:1.7;color:#64748b">${escapeHtml(body)}</p><div style="display:flex;justify-content:flex-end;margin-top:20px"><button class="gw-btn primary" onclick="closeModal()">Continue</button></div></div></div></div>`;}
function closeModal(){document.getElementById('modalRoot')?.replaceChildren();}
function escapeHtml(v){return String(v).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));}

function toggleUserMenu(){
 const m=document.getElementById('userMenu'); if(!m)return;
 const opening=!m.classList.contains('open');
 m.classList.toggle('open');
 if(opening) refreshUserMenuNotifications();
}
document.addEventListener('click',e=>{
 const wrap=document.querySelector('.user-menu-wrap');
 if(wrap && !wrap.contains(e.target)) document.getElementById('userMenu')?.classList.remove('open');
});

function initNotificationWatcher(){
 const userButton=document.querySelector('.gw-user-button');
 if(!userButton || !window.CURRENT_USER?.role)return;
 let lastKnownId=0;
 const seeded=[...(window.ADMIN_NOTIFICATIONS||[]),...(window.STAFF_NOTIFICATIONS||[])];
 if(seeded.length) lastKnownId=Math.max(...seeded.map(n=>Number(n.id)||0));
 const updateBadge=(count)=>{
   let badge=userButton?.querySelector('.user-notification-badge');
   const menuCount=document.querySelector('.user-menu-notification-count');
   if(count>0){
     if(!badge){
       badge=document.createElement('span');
       badge.className='user-notification-badge';
       userButton.appendChild(badge);
     }
     badge.textContent=count>99?'99+':String(count);
     badge.setAttribute('aria-label',`${count} unread notification${count===1?'':'s'}`);
     if(menuCount)menuCount.textContent=count>99?'99+':String(count);
   }else{
     badge?.remove();
     menuCount?.remove();
   }
 };
 const check=()=>fetch(`${window.APP_BASE||''}/includes/notifications_status.php`,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'})
   .then(r=>r.ok?r.json():null).then(data=>{
     if(!data?.ok)return;
     updateBadge(Number(data.count)||0);
     const id=Number(data.latest?.id)||0;
     if(id>lastKnownId)lastKnownId=id;
   }).catch(()=>{});
 check();
 window.__notificationWatcher=setInterval(check,15000);
}

async function refreshUserMenuNotifications(){
 const box=document.getElementById('userMenuNotifications');
 if(!box)return;
 try{
   const r=await fetch(`${window.APP_BASE||''}/includes/notifications.php?action=list`,{
     credentials:'same-origin',
     headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'
   });
   const d=await r.json();
   if(!r.ok || !d?.ok || !Array.isArray(d.items))return;
   const allowed=new Set(['feedback','feedback_reply','data_transfer','file_release','file_request']);
   const notes=d.items.filter(n=>allowed.has(String(n.type||''))).slice(0,5);
   const count=Number(d.unread)||0;
   let badge=document.querySelector('.gw-user-button .user-notification-badge');
   if(count>0){
     if(!badge){
       badge=document.createElement('span'); badge.className='user-notification-badge';
       document.querySelector('.gw-user-button')?.appendChild(badge);
     }
     badge.textContent=count>99?'99+':String(count);
     badge.setAttribute('aria-label',`${count} unread notification${count===1?'':'s'}`);
   }else badge?.remove();
   const iconFor=n=>['feedback','feedback_reply'].includes(String(n.type||''))?'feedback':(['file_release','file_request'].includes(String(n.type||''))?'folder_open':'sync');
   const labelFor=n=>['feedback','feedback_reply'].includes(String(n.type||''))?'Feedback':'Data / File Tracking';
   const items=notes.map(n=>{
     const unread=Number(n.is_read)===0;
     return `<button type="button" class="user-menu-notification-item ${unread?'unread':''}" onclick="showNotificationModal()">
       <span class="user-menu-notification-icon"><span class="material-symbols-outlined">${iconFor(n)}</span></span>
       <span class="user-menu-notification-copy"><strong>${escapeHtml(n.title||labelFor(n))}</strong><small>${escapeHtml(labelFor(n))} · ${escapeHtml(n.message||'')}</small></span>
       ${unread?'<span class="user-menu-notification-dot" aria-label="Unread"></span>':''}
     </button>`;
   }).join('');
   box.innerHTML=`<div class="user-menu-notifications-head"><div><strong>Notifications</strong><span>Feedback and new data/files</span></div>${count>0?`<span class="user-menu-notification-count">${count>99?'99+':count}</span>`:''}</div>${items||'<div class="user-menu-notifications-empty"><span class="material-symbols-outlined">notifications_none</span><span>No feedback or new data/files.</span></div>'}${items?'<button type="button" class="user-menu-notifications-view" onclick="showNotificationModal()">View all notifications</button>':''}`;
 }catch(_){}
}

function feedbackStatusBadge(status){
 const s=String(status||'New'); const cls=s.toLowerCase().replace(/\s+/g,'-');
 return `<span class="feedback-status-badge ${cls}">${escapeHtml(s)}</span>`;
}
function feedbackPriorityBadge(priority){return `<span class="feedback-priority-badge ${String(priority||'Medium').toLowerCase()}">${escapeHtml(priority||'Medium')}</span>`;}
function feedbackThreadCard(thread,isAdmin){
 const msgs=Array.isArray(thread.messages)?thread.messages:[];
 const last=msgs.length?msgs[msgs.length-1]:null;
 const owner=thread.owner_name||'Staff';
 const actions=isAdmin
  ? `<button type="button" class="gw-btn primary" onclick="showFeedbackThread(${Number(thread.id)})"><span class="material-symbols-outlined">forum</span>View</button><button type="button" class="gw-btn btn-danger" onclick="deleteFeedbackThread(${Number(thread.id)})"><span class="material-symbols-outlined">delete</span>Delete</button>`
  : `<button type="button" class="gw-btn primary" onclick="showFeedbackThread(${Number(thread.id)})"><span class="material-symbols-outlined">forum</span>View</button><button type="button" class="gw-btn btn-danger" onclick="deleteStaffFeedback(${Number(thread.id)})"><span class="material-symbols-outlined">undo</span>Unsend</button>`;
 return `<div class="notification-card feedback-thread-card" data-feedback-thread="${Number(thread.id)}"><div class="notification-card-head"><div><strong>${escapeHtml(thread.subject||'Feedback')}</strong><span>${escapeHtml(owner)} · ${escapeHtml(thread.category||'General Feedback')}</span></div><small>${renderDate(thread.updated_at||thread.created_at)}</small></div><div class="feedback-thread-meta">${feedbackStatusBadge(thread.status)} ${feedbackPriorityBadge(thread.priority)} <span>${Number(thread.message_count||msgs.length||0)} message${Number(thread.message_count||msgs.length||0)===1?'':'s'}</span></div><p>${escapeHtml((last?.message||thread.last_message||'').slice(0,300))}${(last?.message||thread.last_message||'').length>300?'…':''}</p><div class="notification-actions">${actions}</div></div>`;
}
function adminFeedbackThreads(){return Array.isArray(window.FEEDBACK_THREADS)?window.FEEDBACK_THREADS.filter(t=>!t.archived_at):[];}
function renderAdminFeedbackInterface(selectedId=null){
 const root=document.getElementById('adminFeedbackWorkspace'),stats=document.getElementById('adminFeedbackStats'); if(!root)return;
 const threads=adminFeedbackThreads();
 const counts={all:threads.length,new:0,review:0,replied:0,resolved:0,urgent:0};
 threads.forEach(t=>{
   if(t.status==='New')counts.new++;
   else if(t.status==='In Review')counts.review++;
   else if(t.status==='Replied')counts.replied++;
   else if(t.status==='Resolved')counts.resolved++;
   if(['High','Critical'].includes(String(t.priority)))counts.urgent++;
 });
 if(stats) stats.innerHTML=`
   <div class="feedback-stat-card total"><span class="material-symbols-outlined">forum</span><div><strong>${counts.all}</strong><span>Total active</span></div></div>
   <div class="feedback-stat-card attention"><span class="material-symbols-outlined">mark_email_unread</span><div><strong>${counts.new}</strong><span>Needs review</span></div></div>
   <div class="feedback-stat-card review"><span class="material-symbols-outlined">pending_actions</span><div><strong>${counts.review}</strong><span>In review</span></div></div>
   <div class="feedback-stat-card replied"><span class="material-symbols-outlined">forum</span><div><strong>${counts.replied}</strong><span>Awaiting employee</span></div></div>
   <div class="feedback-stat-card urgent"><span class="material-symbols-outlined">priority_high</span><div><strong>${counts.urgent}</strong><span>High / critical</span></div></div>`;
 if(!threads.length){
   root.innerHTML='<div class="admin-feedback-empty"><span class="material-symbols-outlined">forum</span><strong>No employee feedback yet</strong><p>New feedback submitted by employees will appear here.</p></div>';return;
 }
 const current=threads.find(t=>Number(t.id)===Number(selectedId))||threads[0];
 const cards=threads.map(t=>{
   const last=(t.messages||[]).slice(-1)[0];
   const needsAttention=t.status==='New'||(t.status==='In Review'&&last?.sender_role==='Staff');
   const priority=String(t.priority||'Medium');
   return `<button type="button" class="admin-feedback-item ${Number(t.id)===Number(current.id)?'active':''} ${needsAttention?'needs-attention':''}" data-admin-feedback-item="${Number(t.id)}" data-admin-feedback-search="${escapeHtml([t.subject,t.owner_name,t.category,t.priority,t.status,last?.message||''].join(' ').toLowerCase())}" data-admin-feedback-status="${escapeHtml(t.status)}" data-admin-feedback-category="${escapeHtml(t.category||'General Feedback')}" data-admin-feedback-priority="${escapeHtml(priority)}" onclick="renderAdminFeedbackInterface(${Number(t.id)})">
     <div class="admin-feedback-item-top"><strong>${escapeHtml(t.subject||'Feedback')}</strong><span class="feedback-item-badges">${needsAttention?'<span class="feedback-unread-dot" title="Needs attention"></span>':''}${feedbackStatusBadge(t.status)}</span></div>
     <div class="admin-feedback-item-meta"><span>${escapeHtml(t.owner_name||'Staff')}</span><span>${escapeHtml(t.category||'General Feedback')}</span>${feedbackPriorityBadge(priority)}</div>
     <p>${escapeHtml((last?.message||'').slice(0,110))}${(last?.message||'').length>110?'…':''}</p>
     <small>${last?.sender_role==='Staff'?'Employee update · ':''}${renderDate(t.updated_at||t.created_at)}</small>
   </button>`;
 }).join('');
 const messages=(current.messages||[]).map(m=>`<div class="admin-feedback-message ${Number(m.sender_user_id)===Number(current.user_id)?'employee':'admin'}"><div class="admin-feedback-message-head"><strong>${escapeHtml(m.sender_name||'User')}</strong><span>${escapeHtml(m.sender_role||'')}</span><small>${renderDate(m.created_at)}</small></div><p>${escapeHtml(m.message||'')}</p></div>`).join('');
 const lastMessage=(current.messages||[]).slice(-1)[0];
 const guidance=lastMessage?.sender_role==='Staff'
   ? 'Review the employee message and use the reply box below to respond.'
   : 'This conversation already contains an administrator reply. You can send another reply at any time.';
 root.innerHTML=`<div class="admin-feedback-list">
   <div class="admin-feedback-list-head"><div><strong>Feedback Inbox</strong><span>${threads.length} active thread${threads.length===1?'':'s'}</span></div><span class="material-symbols-outlined">inbox</span></div>
   <div class="admin-feedback-inbox-tools">
     <div class="feedback-search-wrap"><span class="material-symbols-outlined">search</span><input id="adminFeedbackSearch" type="search" placeholder="Search subject, employee or message..." oninput="filterAdminFeedbackWorkspace()" aria-label="Search feedback"></div>
     <select id="adminFeedbackFilter" onchange="filterAdminFeedbackWorkspace()" aria-label="Filter by status"><option value="all">All statuses</option><option value="New">New</option><option value="In Review">In Review</option><option value="Replied">Replied</option><option value="Resolved">Resolved</option></select>
     <select id="adminFeedbackCategoryFilter" onchange="filterAdminFeedbackWorkspace()" aria-label="Filter by category"><option value="all">All categories</option><option>Bug / System Problem</option><option>Suggestion</option><option>Complaint</option><option>Security Concern</option><option>Data Problem</option><option>General Feedback</option></select>
     <select id="adminFeedbackPriorityFilter" onchange="filterAdminFeedbackWorkspace()" aria-label="Filter by priority"><option value="all">All priorities</option><option>Critical</option><option>High</option><option>Medium</option><option>Low</option></select>
   </div>
   <div class="admin-feedback-items">${cards}</div>
 </div>
 <div class="admin-feedback-detail">
   <div class="admin-feedback-detail-head">
     <div class="admin-feedback-detail-title"><div class="feedback-detail-kicker">FEEDBACK THREAD #${Number(current.id)}</div><h3>${escapeHtml(current.subject||'Feedback')}</h3><div class="admin-feedback-detail-meta"><span><span class="material-symbols-outlined">person</span>${escapeHtml(current.owner_name||'Staff')}</span><span><span class="material-symbols-outlined">category</span>${escapeHtml(current.category||'General Feedback')}</span>${feedbackPriorityBadge(current.priority)}${feedbackStatusBadge(current.status)}</div></div>
     <div class="admin-feedback-status-actions"><button type="button" class="gw-btn btn-danger" onclick="deleteFeedbackThread(${Number(current.id)})"><span class="material-symbols-outlined">delete</span>Delete</button></div>
   </div>
   <div class="admin-feedback-context"><span class="material-symbols-outlined">info</span><span>${escapeHtml(guidance)}</span></div>
   <div class="admin-feedback-conversation">${messages||'<div class="notification-empty"><strong>No messages</strong></div>'}</div>
   <form class="admin-feedback-reply-form" onsubmit="submitAdminFeedbackReply(event,${Number(current.id)})">
     <label for="adminFeedbackReplyInput">Reply to employee</label>
     <textarea id="adminFeedbackReplyInput" name="reply" maxlength="3000" required placeholder="Type your reply here..."></textarea>
     <div class="admin-feedback-reply-actions"><small>Maximum 3,000 characters</small><button type="submit" class="gw-btn primary"><span class="material-symbols-outlined">send</span>Send Reply</button></div>
   </form>
 </div>`;
 const chat=root.querySelector('.admin-feedback-conversation'); if(chat)chat.scrollTop=chat.scrollHeight;
}
function renderFeedbackDataTable(){
 const body=document.getElementById('feedbackDataTableBody'); if(!body)return;
 const isAdmin=window.CURRENT_USER?.role==='Administrator';
 const threads=Array.isArray(window.FEEDBACK_THREADS)?window.FEEDBACK_THREADS:[];
 if(!threads.length){body.innerHTML=`<tr><td colspan="${isAdmin?6:5}" class="feedback-table-empty"><span class="material-symbols-outlined">forum</span><strong>${isAdmin?'No employee feedback yet':'No feedback submitted yet'}</strong><span>${isAdmin?'Employee feedback will appear here when submitted.':'Submit feedback to start a conversation with the administrator.'}</span></td></tr>`;return;}
 body.innerHTML=threads.map(t=>{
   const last=Array.isArray(t.messages)&&t.messages.length?t.messages[t.messages.length-1]:null;
   const actions=isAdmin
     ? `<button type="button" class="gw-btn primary" onclick="showFeedbackThread(${Number(t.id)})"><span class="material-symbols-outlined">forum</span>View</button><button type="button" class="gw-btn btn-danger" onclick="deleteFeedbackThread(${Number(t.id)})"><span class="material-symbols-outlined">delete</span>Delete</button>`
     : `<button type="button" class="gw-btn primary" onclick="showFeedbackThread(${Number(t.id)})"><span class="material-symbols-outlined">forum</span>View</button><button type="button" class="gw-btn btn-danger" onclick="deleteStaffFeedback(${Number(t.id)})"><span class="material-symbols-outlined">undo</span>Unsend</button>`;
   const ownerCell=isAdmin?`<td><div class="feedback-table-person">${escapeHtml(t.owner_name||'Employee')}</div></td>`:'';
   return `<tr>${ownerCell}<td><div class="feedback-table-subject">${escapeHtml(t.subject||'Feedback')}</div><div class="feedback-detail-kicker">${escapeHtml(t.category||'General Feedback')} · ${feedbackPriorityBadge(t.priority)}</div></td><td><div class="feedback-table-preview">${escapeHtml(last?.message||t.last_message||'No message')}</div><div class="feedback-detail-kicker">${escapeHtml(last?.sender_name||t.last_sender_name||'User')}</div></td><td>${feedbackStatusBadge(t.status)}</td><td class="feedback-table-date">${renderDate(t.updated_at||t.created_at)}</td><td><div class="feedback-table-actions">${actions}</div></td></tr>`;
 }).join('');
}
async function refreshFeedbackDataTable(){
 const body=document.getElementById('feedbackDataTableBody'); if(!body)return;
 try{
   const controller=new AbortController(); const timer=setTimeout(()=>controller.abort(),8000);
   const r=await fetch(`${window.APP_BASE||''}/includes/feedback_threads.php`,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store',signal:controller.signal});
   clearTimeout(timer);
   const d=await r.json().catch(()=>null);
   if(!r.ok||!d?.ok||!Array.isArray(d.threads)) throw new Error(d?.message||'Unable to load feedback.');
   window.FEEDBACK_THREADS=d.threads;
   renderFeedbackDataTable();
   if(document.getElementById('adminFeedbackWorkspace')) renderAdminFeedbackInterface();
 }catch(e){
   console.error('Feedback data load failed',e);
   try{renderFeedbackDataTable();}catch(_){}
   if(body && body.querySelector('.feedback-table-loading')){
     const isAdmin=window.CURRENT_USER?.role==='Administrator';
     body.innerHTML=`<tr><td colspan="${isAdmin?6:5}" class="feedback-table-empty"><span class="material-symbols-outlined">error_outline</span><strong>Unable to load feedback</strong><span>Please refresh the page and try again.</span></td></tr>`;
   }
 }
}
async function deleteStaffFeedback(threadId){
 const id=Number(threadId)||0;
 if(!id||window.CURRENT_USER?.role!=='Staff')return;
 const t=(window.FEEDBACK_THREADS||[]).find(x=>Number(x.id)===id); if(!t)return;
 if(!confirm(`Delete “${t.subject||'this feedback'}” and the conversation? This cannot be undone.`))return;
 const body=new URLSearchParams({action:'staff_delete',thread_id:String(id),csrf_token:String(window.CSRF_TOKEN||''),return_to:window.location.pathname+window.location.search});
 try{
   const r=await fetch(`${window.APP_BASE||''}/includes/feedback.php`,{method:'POST',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()});
   const d=await r.json().catch(()=>({ok:false,message:'Invalid server response.'}));
   if(!r.ok||!d.ok)throw new Error(d.message||'Unable to delete feedback.');
   window.FEEDBACK_THREADS=(window.FEEDBACK_THREADS||[]).filter(x=>Number(x.id)!==id);
   closeModal(); refreshFeedbackDataTable();
 }catch(e){alert(e.message||'Unable to delete feedback.');}
}

function filterAdminFeedbackWorkspace(){
 const search=(document.getElementById('adminFeedbackSearch')?.value||'').trim().toLowerCase();
 const status=document.getElementById('adminFeedbackFilter')?.value||'all';
 const category=document.getElementById('adminFeedbackCategoryFilter')?.value||'all';
 const priority=document.getElementById('adminFeedbackPriorityFilter')?.value||'all';
 document.querySelectorAll('[data-admin-feedback-item]').forEach(card=>{
   const hay=card.getAttribute('data-admin-feedback-search')||'';
   const cardStatus=card.getAttribute('data-admin-feedback-status')||'';
   const cardCategory=card.getAttribute('data-admin-feedback-category')||'';
   const cardPriority=card.getAttribute('data-admin-feedback-priority')||'';
   card.style.display=((!search||hay.includes(search))&&(status==='all'||cardStatus===status)&&(category==='all'||cardCategory===category)&&(priority==='all'||cardPriority===priority))?'':'';
 });
 const items=[...document.querySelectorAll('[data-admin-feedback-item]')];
 const visible=items.filter(x=>x.style.display!=='none');
 const empty=document.querySelector('.admin-feedback-filter-empty');
 if(empty)empty.remove();
 if(!visible.length&&items.length){
   const holder=document.querySelector('.admin-feedback-items');
   if(holder)holder.insertAdjacentHTML('beforeend','<div class="admin-feedback-filter-empty"><span class="material-symbols-outlined">search_off</span><strong>No matching feedback</strong><p>Try a different search term or filter.</p></div>');
 }
}
async function reloadFeedbackThreads(){
 try{
   const r=await fetch(`${window.APP_BASE||''}/includes/feedback_threads.php`,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'});
   const d=await r.json().catch(()=>null);
   if(r.ok&&d?.ok&&Array.isArray(d.threads)){window.FEEDBACK_THREADS=d.threads;return true;}
 }catch(_e){}
 return false;
}
// Single place that sends an administrator reply, so every Reply button behaves the same.
function showAdminFeedbackModal(){
 document.getElementById('userMenu')?.classList.remove('open');
 if(window.CURRENT_USER?.role!=='Administrator')return;
 const root=document.getElementById('modalRoot'); if(!root)return;
 root.innerHTML=`<div class="gw-modal-backdrop" onclick="if(event.target===this)closeModal()"><div class="gw-modal feedback-modal admin-feedback-modal"><div class="gw-modal-head"><div><strong>Employee Feedback</strong><small>View employee feedback and move unwanted feedback to the archive.</small></div><button class="gw-modal-close" onclick="closeModal()">×</button></div><div class="gw-modal-body"><div id="adminFeedbackStats" class="feedback-stat-grid"></div><div id="adminFeedbackWorkspace"><div class="data-storage-loading"><span class="material-symbols-outlined">progress_activity</span>Loading employee feedback...</div></div></div></div></div>`;
 fetch(`${window.APP_BASE||''}/includes/feedback_threads.php`,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'}).then(r=>r.json()).then(d=>{if(!d.ok)throw new Error(d.message||'Unable to load feedback.');window.FEEDBACK_THREADS=Array.isArray(d.threads)?d.threads:[];renderAdminFeedbackInterface();}).catch(e=>{const box=document.getElementById('adminFeedbackWorkspace');if(box)box.innerHTML=`<div class="notification-empty"><strong>Unable to load employee feedback.</strong><p>${escapeHtml(e.message||'Please try again.')}</p></div>`;});
}
function openAdminFeedbackInterface(){document.getElementById('admin-feedback-panel')?.scrollIntoView({behavior:'smooth',block:'start'});renderAdminFeedbackInterface();}
async function handleNotificationBell(){
 try{
   const r=await fetch(`${window.APP_BASE||''}/includes/feedback_threads.php`,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'});
   const d=await r.json();
   if(r.ok&&d.ok&&Array.isArray(d.threads))window.FEEDBACK_THREADS=d.threads;
 }catch(e){}
 const isStaff=window.CURRENT_USER?.role==='Staff';
 const notes=isStaff?(window.STAFF_NOTIFICATIONS||[]):(window.ADMIN_NOTIFICATIONS||[]);
 const feedbackNotes=notes.filter(n=>(n.type==='feedback')&&Number(n.is_read)===0);
 let target=null;
 if(feedbackNotes.length){
   const n=feedbackNotes.slice().sort((a,b)=>Number(b.id)-Number(a.id))[0];
   const threadId=Number(n.feedback_thread_id)||0;
   target=(window.FEEDBACK_THREADS||[]).find(t=>Number(t.id)===threadId);
   if(!target) target=(window.FEEDBACK_THREADS||[]).find(t=>Number(t.legacy_notification_id)===Number(n.id));
   if(!target && Number(n.reply_to_id)>0) target=(window.FEEDBACK_THREADS||[]).find(t=>Number(t.legacy_notification_id)===Number(n.reply_to_id));
 }
 if(!target && isStaff){
   target=(window.FEEDBACK_THREADS||[]).find(t=>Array.isArray(t.messages)&&t.messages.length&&String(t.messages[t.messages.length-1].sender_role||'').toLowerCase()==='administrator');
 }
 if(target){
   showFeedbackThread(Number(target.id));
   fetch(`${window.APP_BASE||''}/includes/mark_notifications_read.php`,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','X-CSRF-Token':window.CSRF_TOKEN||''},credentials:'same-origin'}).catch(()=>{});
   return;
 }
 showNotificationModal(false);
}
async function showNotificationModal(fresh=true){
 if(fresh){
   try{
     const r=await fetch(`${window.APP_BASE||''}/includes/feedback_threads.php`,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'});
     const d=await r.json();
     if(r.ok&&d.ok&&Array.isArray(d.threads)) window.FEEDBACK_THREADS=d.threads;
   }catch(e){}
 }
 const root=document.getElementById('modalRoot'); if(!root)return;
 const isStaff=window.CURRENT_USER?.role==='Staff'; const isAdmin=window.CURRENT_USER?.role==='Administrator';
 const threads=Array.isArray(window.FEEDBACK_THREADS)?window.FEEDBACK_THREADS:[];
 const notes=isStaff?(window.STAFF_NOTIFICATIONS||[]):(window.ADMIN_NOTIFICATIONS||[]);
 const title=isStaff?'Feedback & Notifications':'Employee Feedback';
 const subtitle=isStaff?'Track your feedback and administrator responses.':'View and manage feedback submitted by employees.';
 const transfer=notes.filter(n=>n.type==='data_transfer');
 // Dedicated feedback threads are the source of truth. Only show legacy
 // notifications that have not yet been migrated into a thread, preventing
 // duplicate cards in the notification bell.
 const feedbackNotes=notes.filter(n=>
   (n.type==='feedback') &&
   !(Number(n.feedback_thread_id)||0)
 );
 const activeThreads=threads;
 const counts={all:activeThreads.length,new:activeThreads.filter(t=>t.status==='New').length,review:activeThreads.filter(t=>t.status==='In Review').length,replied:activeThreads.filter(t=>t.status==='Replied').length,resolved:activeThreads.filter(t=>t.status==='Resolved').length};
 const filterHtml=isAdmin?`<div class="feedback-inbox-controls"><input id="feedbackSearch" class="feedback-search" type="search" placeholder="Search employee, subject, category..." oninput="filterFeedbackInbox()"/><select id="feedbackStatusFilter" onchange="filterFeedbackInbox()"><option value="all">All statuses (${counts.all})</option><option value="New">New (${counts.new})</option><option value="In Review">In Review (${counts.review})</option><option value="Replied">Replied (${counts.replied})</option><option value="Resolved">Resolved (${counts.resolved})</option></select></div>`:'';
 const cards=activeThreads.map(t=>feedbackThreadCard(t,isAdmin)).join('');
 const feedbackNotificationCards=feedbackNotes.map(n=>{
   const threadId=Number(n.feedback_thread_id)||0;
   const thread=threadId ? threads.find(t=>Number(t.id)===threadId) : null;
   const latest=thread && Array.isArray(thread.messages) && thread.messages.length ? thread.messages[thread.messages.length-1] : null;
   const isReply=thread && latest && String(latest.sender_role||'').toLowerCase()==='administrator';
   const unread=Number(n.is_read)===0;
   const viewLabel=isStaff && (isReply || n.type==='feedback') ? 'View Reply' : 'View Conversation';
   const viewAction=thread
     ? `showFeedbackThread(${threadId})`
     : `showNotificationDetails(${Number(n.id)})`;
   const deleteAction=isAdmin
     ? `<button type="button" class="gw-btn btn-danger feedback-notification-delete" onclick="event.stopPropagation();deleteFeedbackThread(${threadId})" ${thread?'':'disabled'}><span class="material-symbols-outlined">delete</span>Delete</button>`
     : '';
   return `<div class="notification-card feedback-notification-card ${unread?'unread':''}" data-notification-id="${Number(n.id)}" data-feedback-thread="${threadId}" role="button" tabindex="0" onclick="if(!event.target.closest('button')){${thread?`showFeedbackThread(${threadId})`:`showNotificationDetails(${Number(n.id)})`}}" onkeydown="if((event.key==='Enter'||event.key===' ')&&!event.target.closest('button')){event.preventDefault();${thread?`showFeedbackThread(${threadId})`:`showNotificationDetails(${Number(n.id)})`}}">
     <div class="notification-card-head">
       <div class="notification-card-title-row"><span class="feedback-notification-icon ${isReply?'reply':''}"><span class="material-symbols-outlined">${isReply?'reply':'feedback'}</span></span><div><strong>${escapeHtml(n.title||'Feedback')}</strong><span>${escapeHtml(n.sender_name||'Employee')} · ${escapeHtml(n.sender_role||'Staff')}</span></div></div>
       <small>${renderDate(n.created_at)}</small>
     </div>
     <div class="feedback-notification-status">${unread?'<span class="feedback-unread-pill"><span class="feedback-unread-dot"></span>New</span>':''}${isReply?'<span class="feedback-reply-pill"><span class="material-symbols-outlined">forum</span>Administrator replied</span>':''}</div>
     <p>${escapeHtml(n.message||'')}</p>
     ${thread?`<div class="notification-actions"><button type="button" class="gw-btn primary" onclick="showFeedbackThread(${threadId})"><span class="material-symbols-outlined">${isReply&&isStaff?'reply':'forum'}</span>${viewLabel}</button>${deleteAction}</div>`:''}
   </div>`;
 }).join('');
 const transferCards=transfer.map(n=>`<div class="notification-card ${Number(n.is_read)===0?'unread':''}"><div class="notification-card-head"><div><strong>${escapeHtml(n.title||'Notification')}</strong><span>${escapeHtml(n.sender_name||'System')} · ${escapeHtml(n.sender_role||'System')}</span></div><small>${renderDate(n.created_at)}</small></div><p>${escapeHtml(n.message||'')}</p></div>`).join('');
 root.innerHTML=`<div class="gw-modal-backdrop" onclick="if(event.target===this)closeModal()"><div class="gw-modal notifications-modal feedback-inbox-modal">
 <div class="gw-modal-head"><div><strong>${title}</strong><small>${subtitle}</small></div><button class="gw-modal-close" onclick="closeModal()" aria-label="Close">×</button></div>
 <div class="gw-modal-body"><div class="feedback-inbox-toolbar"><div><strong>${threads.length}</strong><span>${isAdmin?'employee feedback thread'+(threads.length===1?'':'s'):'active feedback thread'+(threads.length===1?'':'s')}</span></div><div class="feedback-notification-summary"><span><span class="material-symbols-outlined">mark_email_unread</span>${notes.filter(n=>Number(n.is_read)===0).length} new</span><span><span class="material-symbols-outlined">forum</span>${threads.filter(t=>Array.isArray(t.messages)&&t.messages.length&&String(t.messages[t.messages.length-1].sender_role||'').toLowerCase()==='administrator').length} replied</span></div></div>${filterHtml}
 <div id="feedbackThreadList" class="notification-list">${cards}${feedbackNotificationCards}${transferCards}${(!cards&&!feedbackNotificationCards&&!transferCards)?`<div class="notification-empty"><span class="material-symbols-outlined">feedback</span><strong>${isAdmin?'No employee feedback yet':'No feedback yet'}</strong><p>${escapeHtml(subtitle)}</p></div>`:''}</div><div class="record-actions"><button class="gw-btn primary" onclick="closeModal()">Close</button></div></div></div></div>`;
 if(isAdmin) filterFeedbackInbox();
 refreshFeedbackDataTable();
 fetch(`${window.APP_BASE||''}/includes/mark_notifications_read.php`,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','X-CSRF-Token':window.CSRF_TOKEN||''},credentials:'same-origin'}).catch(()=>{});
 [...(window.ADMIN_NOTIFICATIONS||[]),...(window.STAFF_NOTIFICATIONS||[])].forEach(n=>n.is_read=1);
 document.querySelectorAll('.notification-badge,.top-notification-badge,.user-notification-badge,.user-menu-notification-count').forEach(el=>el.remove());
}
function filterFeedbackInbox(){
 const list=document.getElementById('feedbackThreadList'); if(!list)return;
 const search=(document.getElementById('feedbackSearch')?.value||'').trim().toLowerCase();
 const status=document.getElementById('feedbackStatusFilter')?.value||'all';
 document.querySelectorAll('[data-feedback-thread]').forEach(card=>{
   const id=Number(card.getAttribute('data-feedback-thread'));
   const t=(window.FEEDBACK_THREADS||[]).find(x=>Number(x.id)===id);
   if(!t)return;
   const hay=[t.owner_name,t.subject,t.category,t.priority,t.status,t.last_message].join(' ').toLowerCase();
   card.style.display=((status==='all'||t.status===status) && (!search||hay.includes(search)))?'':'none';
 });
}
async function showFeedbackThread(threadId){
 const threads=Array.isArray(window.FEEDBACK_THREADS)?window.FEEDBACK_THREADS:[]; const t=threads.find(x=>Number(x.id)===Number(threadId)); if(!t)return;
 const root=document.getElementById('modalRoot'); if(!root)return; const isAdmin=window.CURRENT_USER?.role==='Administrator';
 const msgs=(t.messages||[]).map(m=>`<div class="feedback-bubble ${Number(m.sender_user_id)===Number(t.user_id)?'feedback-bubble-in':'feedback-bubble-out'}"><strong>${escapeHtml(m.sender_name||'User')} · ${escapeHtml(m.sender_role||'')}</strong><small>${renderDate(m.created_at)}</small><p>${escapeHtml(m.message||'')}</p></div>`).join('');
 const action=isAdmin ? `<button class="gw-btn btn-danger" onclick="deleteFeedbackThread(${Number(t.id)})"><span class="material-symbols-outlined">delete</span>Delete</button>` : `<button class="gw-btn btn-danger" onclick="deleteStaffFeedback(${Number(t.id)})"><span class="material-symbols-outlined">undo</span>Unsend Feedback</button>`;
 const replyBox=isAdmin ? `<form id="feedbackReplyForm" class="feedback-reply-form" onsubmit="submitAdminFeedbackReply(event,${Number(t.id)})"><label for="feedbackReplyInput">Reply to employee</label><textarea id="feedbackReplyInput" name="reply" maxlength="3000" required placeholder="Type your reply here..."></textarea><div class="feedback-reply-actions"><small>Maximum 3,000 characters</small><button class="gw-btn primary" type="submit"><span class="material-symbols-outlined">send</span>Send Reply</button></div></form>` : '';
 root.innerHTML=`<div class="gw-modal-backdrop" onclick="if(event.target===this)closeModal()"><div class="gw-modal feedback-modal feedback-thread-detail"><div class="gw-modal-head"><div><strong>${escapeHtml(t.subject||'Feedback')}</strong><small>${escapeHtml(t.owner_name||'Staff')} · ${escapeHtml(t.category||'General Feedback')} · ${feedbackStatusBadge(t.status)} ${feedbackPriorityBadge(t.priority)}</small></div><button class="gw-modal-close" onclick="closeModal()">×</button></div><div class="gw-modal-body"><div class="feedback-chat-scroll feedback-thread-history">${msgs||'<div class="notification-empty"><strong>No messages</strong></div>'}</div>${replyBox}<div class="record-actions">${action}<button class="gw-btn secondary" onclick="closeModal()">Close</button></div></div></div></div>`;
 if(isAdmin) setTimeout(()=>document.getElementById('feedbackReplyInput')?.focus(),50);
}
async function submitAdminFeedbackReply(event,threadId){
 event.preventDefault();
 if(window.CURRENT_USER?.role!=='Administrator')return;
 const form=event.currentTarget, input=form.querySelector('textarea[name="reply"]'), btn=form.querySelector('button[type="submit"]');
 const reply=(input?.value||'').trim(); if(!reply)return;
 if(btn){btn.disabled=true;btn.innerHTML='<span class="material-symbols-outlined">hourglass_top</span>Sending...';}
 try{
   const body=new URLSearchParams({action:'reply',thread_id:String(threadId),reply,csrf_token:String(window.CSRF_TOKEN||''),return_to:window.location.pathname+window.location.search});
   const r=await fetch(`${window.APP_BASE||''}/includes/feedback.php`,{method:'POST',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()});
   const d=await r.json().catch(()=>({ok:false,message:'Invalid server response.'}));
   if(!r.ok||!d.ok)throw new Error(d.message||'Unable to send reply.');
   await reloadFeedbackThreads();
   showFeedbackThread(Number(d.thread_id||threadId));
 }catch(e){
   alert(e.message||'Unable to send reply.');
   if(btn){btn.disabled=false;btn.innerHTML='<span class="material-symbols-outlined">send</span>Send Reply';}
 }
}
async function deleteFeedbackThread(threadId){
 const id=Number(threadId)||0;
 if(!id || window.CURRENT_USER?.role!=='Administrator')return;
 const t=(window.FEEDBACK_THREADS||[]).find(x=>Number(x.id)===id);
 if(!t)return;
 if(!confirm(`Delete “${t.subject||'this feedback'}” and its entire conversation? This cannot be undone.`))return;
 const body=new URLSearchParams({action:'delete_feedback',thread_id:String(id),csrf_token:String(window.CSRF_TOKEN||''),return_to:window.location.pathname+window.location.search});
 try{
   const r=await fetch(`${window.APP_BASE||''}/includes/feedback.php`,{method:'POST',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()});
   const d=await r.json().catch(()=>({ok:false,message:'Invalid server response.'}));
   if(!r.ok||!d.ok)throw new Error(d.message||'Unable to delete feedback.');
   window.FEEDBACK_THREADS=(window.FEEDBACK_THREADS||[]).filter(x=>Number(x.id)!==id);
   window.ADMIN_NOTIFICATIONS=(window.ADMIN_NOTIFICATIONS||[]).filter(n=>Number(n.feedback_thread_id)!==id);
   refreshFeedbackDataTable();
   closeModal();
   showNotificationModal(false);
 }catch(e){alert(e.message||'Unable to delete feedback.');}
}

async function deleteNotification(notificationId,side=null){
 const id=Number(notificationId)||0;if(!id)return; const isStaff=window.CURRENT_USER?.role==='Staff'; if(!isStaff)return; if(side==='admin')return; if(!confirm('Delete this notification from your account?'))return;
 const body=new URLSearchParams({action:'delete_notification',notification_id:String(id),csrf_token:String(window.CSRF_TOKEN||''),return_to:window.location.pathname+window.location.search});
 try{const r=await fetch(`${window.APP_BASE||''}/includes/feedback.php`,{method:'POST',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()}); const d=await r.json(); if(!r.ok||!d.ok)throw new Error(d.message||'Unable to delete notification.'); const key=isStaff?'STAFF_NOTIFICATIONS':'ADMIN_NOTIFICATIONS'; window[key]=(window[key]||[]).filter(n=>Number(n.id)!==id); showNotificationModal();}catch(e){alert(e.message||'Unable to delete notification.');}
}
function showFeedbackSentModal(){
 const root=document.getElementById('modalRoot');if(!root)return;root.innerHTML=`<div class="gw-modal-backdrop"><div class="gw-modal feedback-sent-modal"><div class="feedback-sent-icon"><span class="material-symbols-outlined">mark_email_read</span></div><div class="gw-modal-body feedback-sent-body"><strong>Success</strong><p>Your message has been sent successfully.</p><button class="gw-btn primary" onclick="closeModal()">Done</button></div></div></div>`;
}
function showFeedbackModal(){
 document.getElementById('userMenu')?.classList.remove('open'); const root=document.getElementById('modalRoot');if(!root)return; const user=window.CURRENT_USER||{name:'User',role:'Staff'}; const isAdmin=user.role==='Administrator';
 root.innerHTML=`<div class="gw-modal-backdrop" onclick="if(event.target===this)closeModal()"><div class="gw-modal feedback-modal"><div class="gw-modal-head"><div><strong>Send Feedback</strong><small>Help us improve the Great Solomon Manpower Services system.</small></div><button class="gw-modal-close" onclick="closeModal()">×</button></div><div class="gw-modal-body"><div class="feedback-intro"><span class="material-symbols-outlined">rate_review</span><div><strong>Your account details are automatic</strong><p>Your name and role are taken from the account currently signed in.</p></div></div><form method="post" action="${window.APP_BASE||''}/includes/feedback.php"><input type="hidden" name="csrf_token" value="${escapeHtml(window.CSRF_TOKEN||'')}"/><input type="hidden" name="return_to" value="${escapeHtml(window.location.pathname+window.location.search)}"/><input type="hidden" name="action" value="send"/><div class="feedback-account-grid"><div class="feedback-readonly-field"><label>Name</label><div class="feedback-readonly-value"><span class="material-symbols-outlined">person</span>${escapeHtml(user.name)}</div></div><div class="feedback-readonly-field"><label>Role</label><div class="feedback-readonly-value"><span class="material-symbols-outlined">badge</span>${escapeHtml(user.role)}</div></div></div><div class="feedback-form-grid"><div class="feedback-message-field"><label for="feedbackSubject">Subject <span class="field-required">*</span></label><input id="feedbackSubject" name="subject" maxlength="180" placeholder="Example: Unable to save a safety report" required/><div class="feedback-field-hint">Use a short title that helps the administrator recognize the issue.</div></div><div class="feedback-message-field"><label for="feedbackCategory">Category <span class="field-required">*</span></label><select id="feedbackCategory" name="category"><option>General Feedback</option><option>Bug / System Problem</option><option>Suggestion</option><option>Complaint</option><option>Security Concern</option><option>Data Problem</option></select></div><div class="feedback-message-field"><label for="feedbackPriority">Priority <span class="field-required">*</span></label><select id="feedbackPriority" name="priority"><option>Low</option><option selected>Medium</option><option>High</option><option>Critical</option></select></div></div><div id="feedbackCategoryGuide" class="feedback-category-guide"><span class="material-symbols-outlined">lightbulb</span><div><strong>Suggestion</strong><span>Share an improvement that could make the system easier, faster, or clearer to use.</span></div></div><div class="feedback-message-field"><div class="feedback-label-row"><label for="feedbackText">Your Feedback <span class="field-required">*</span></label><span id="feedbackTextCount">0 / 3000</span></div><textarea id="feedbackText" name="feedback" rows="7" required maxlength="3000" placeholder="Describe what happened, what you expected, and any useful steps to reproduce the problem..."></textarea><div class="feedback-helper">For system problems, include the page, action, error message, and what you expected to happen. Avoid entering passwords or other confidential credentials.</div></div><div class="record-actions"><button type="button" class="gw-btn secondary" onclick="closeModal()">Cancel</button><button class="gw-btn primary" type="submit"><span class="material-symbols-outlined">send</span>Send Feedback</button></div></form></div></div></div>`;
 const text=document.getElementById('feedbackText'),count=document.getElementById('feedbackTextCount'),category=document.getElementById('feedbackCategory'),guide=document.getElementById('feedbackCategoryGuide');
const categoryGuidance={
 'General Feedback':['General Feedback','Share a general observation, question, or experience with the system.'],
 'Bug / System Problem':['Bug / System Problem','Describe the page, action, error message, and steps that led to the problem.'],
 'Suggestion':['Suggestion','Share an improvement that could make the system easier, faster, or clearer to use.'],
 'Complaint':['Complaint','Explain the concern, its impact, and what outcome you would like the administrator to review.'],
 'Security Concern':['Security Concern','Report suspicious access, unexpected permissions, or other security-related behavior. Do not include passwords or biometric verification codes.'],
 'Data Problem':['Data Problem','Identify the affected record or module and explain what information appears incorrect or missing.']
};
const refreshGuide=()=>{if(!category||!guide)return;const g=categoryGuidance[category.value]||categoryGuidance['General Feedback'];guide.querySelector('strong').textContent=g[0];guide.querySelector('span:last-child').textContent=g[1];};
if(text&&count)text.addEventListener('input',()=>{count.textContent=`${text.value.length} / 3000`;});refreshGuide();category?.addEventListener('change',refreshGuide);
setTimeout(()=>document.getElementById('feedbackSubject')?.focus(),50);
}
function showTermsModal(){
 document.getElementById('userMenu')?.classList.remove('open');
 const root=document.getElementById('modalRoot'); if(!root)return;
 root.innerHTML=`<div class="gw-modal-backdrop" onclick="if(event.target===this)closeModal()"><div class="gw-modal terms-modal">
 <div class="gw-modal-head"><div><strong>Terms and Conditions</strong><small>Great Solomon Manpower Services Inc. — Core Transaction 4</small></div></div>
 <div class="gw-modal-body terms-body">
 <p>By accessing and using this system, you acknowledge that it is intended only for authorized Great Solomon Manpower Services Inc. administrators and staff. You are responsible for using your assigned account appropriately, keeping your password and verification information confidential, and ensuring that records you create or update are accurate and used only for legitimate company purposes. Sharing accounts, attempting to access another user's account, bypassing access controls, or using the system for unauthorized purposes is prohibited.</p>
 <p>The system processes personal and, where applicable, sensitive personal information. The company will handle such information in accordance with the <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong>, including its principles on transparency, legitimate purpose, proportionality, and appropriate protection of personal information. Users must not disclose, copy, download, or otherwise process personal information beyond what is authorized for their work responsibilities.</p>
 <p>Users must also use the system and its computer resources responsibly and must not perform unauthorized access, interception, alteration, deletion, disruption, introduction of malicious code, or other prohibited activity. The <strong>Cybercrime Prevention Act of 2012 (Republic Act No. 10175)</strong> addresses offenses involving the confidentiality, integrity, and availability of computer data and systems, and this system's security controls and audit records may be used to support legitimate security and compliance activities.</p>
 <p>For workplace health and safety records, users must enter and maintain information responsibly and support the company's safety processes. The <strong>Occupational Safety and Health Standards Law (Republic Act No. 11058)</strong> strengthens compliance with occupational safety and health standards and provides duties and protections relating to workplace hazards, safety programs, training, incident reporting, and worker safety. Records in this system should therefore be used only for authorized health, safety, welfare, and compliance purposes.</p>
 <p>Electronic records, messages, and transactions handled through this system may also be subject to the <strong>Electronic Commerce Act of 2000 (Republic Act No. 8792)</strong> and other applicable Philippine laws and regulations. By continuing to use the system, you agree to follow company policies, applicable laws, and authorized instructions; system activity may be logged for security, audit, operational, and compliance purposes. These terms describe system-use rules and are not a substitute for legal advice; applicable laws and regulations prevail where they conflict with these terms.</p>
 <div class="terms-note"><span class="material-symbols-outlined">verified_user</span><span>Use the system responsibly and report security, privacy, or data-quality concerns to the appropriate administrator.</span></div>
 <div class="terms-confirmation"><label class="auth-checkbox"><input type="checkbox" id="modalTermsCheckbox"><span>I have read and agree to these Terms and Conditions.</span></label><button type="button" class="gw-btn primary" id="modalTermsConfirm" disabled onclick="if(document.getElementById('modalTermsCheckbox')?.checked)closeModal()">Confirm Terms</button></div>
 </div></div></div>`;
 const box=document.getElementById('modalTermsCheckbox'), confirm=document.getElementById('modalTermsConfirm');
 box?.addEventListener('change',()=>{if(confirm)confirm.disabled=!box.checked;});
}
function showEditUserModal(id,name,email,role){
 const root=document.getElementById('modalRoot'); if(!root)return;
 root.innerHTML=`<div class="gw-modal-backdrop" onclick="if(event.target===this)closeModal()"><div class="gw-modal">
 <div class="gw-modal-head"><strong>Edit User Account</strong><button class="gw-modal-close" onclick="closeModal()">×</button></div>
 <div class="gw-modal-body"><form method="post" action="${window.location.pathname}">
 <input type="hidden" name="csrf_token" value="${escapeHtml(window.CSRF_TOKEN||'')}"><input type="hidden" name="action" value="edit_user"><input type="hidden" name="id" value="${escapeHtml(id)}">
 <div class="form-grid edit-user-fields">
 <div class="full"><label>New Name</label><input class="edit-user-input" name="name" value="${escapeHtml(name)}" required autocomplete="name"></div>
 <div class="full"><label>New Email</label><input class="edit-user-input" type="email" name="email" value="${escapeHtml(email)}" required autocomplete="email"></div>
 <div class="full"><label>New Role</label><select class="edit-user-input" name="role" required><option value="Administrator" ${role==="Administrator"?"selected":""}>Administrator</option><option value="Staff" ${role==="Staff"?"selected":""}>Staff</option></select></div>
 <div class="full"><label>New Password</label><input class="edit-user-input" type="password" name="password" minlength="6" placeholder="Leave blank to keep current password" autocomplete="new-password"></div></div>
 <div class="record-actions"><button type="button" class="gw-btn secondary" onclick="closeModal()">Cancel</button><button class="gw-btn primary"><span class="material-symbols-outlined">save</span>Save Changes</button></div>
 </form></div></div></div>`;
}

/* CT4 Gemini AI Assistant */
document.addEventListener('DOMContentLoaded',()=>{
 if(window.CURRENT_USER?.role==='Administrator') renderAdminFeedbackInterface();
 const form=document.getElementById('aiForm'), input=document.getElementById('aiInput'), messages=document.getElementById('aiMessages');
 if(!form||!input||!messages)return;
 const clearBtn=document.getElementById('aiClear');
 const base=window.APP_BASE||'';
 let history=[];
 try{history=JSON.parse(sessionStorage.getItem('ct4_ai_history')||'[]');}catch(e){history=[];}
 function esc(v){return String(v).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));}
 function renderText(v){return esc(v).replace(/\n/g,'<br>');}
 function addMessage(role,text){
   const row=document.createElement('div'); row.className='ai-message '+(role==='user'?'user':'assistant');
   row.innerHTML=role==='user'
    ? '<div class="ai-message-content"><strong>You</strong><p>'+renderText(text)+'</p></div>'
    : '<div class="ai-msg-icon"><span class="material-symbols-outlined">auto_awesome</span></div><div><strong>CT4 AI</strong><p>'+renderText(text)+'</p></div>';
   messages.appendChild(row); messages.scrollTop=messages.scrollHeight;
 }
 function save(){sessionStorage.setItem('ct4_ai_history',JSON.stringify(history.slice(-8)));}
 function setBusy(b){input.disabled=b;form.querySelector('button[type="submit"]').disabled=b;}
 function ask(text){
   text=(text||'').trim(); if(!text||input.disabled)return;
   addMessage('user',text);
   history.push({role:'user',text:text});
   save(); input.value=''; input.style.height='auto'; setBusy(true);
   const thinking=document.createElement('div'); thinking.className='ai-message assistant ai-thinking'; thinking.innerHTML='<div class="ai-msg-icon"><span class="material-symbols-outlined">auto_awesome</span></div><div><strong>CT4 AI</strong><p><span class="ai-dots">Thinking…</span></p></div>'; messages.appendChild(thinking); messages.scrollTop=messages.scrollHeight;
   const fd=new FormData(); fd.append('csrf_token',window.CSRF_TOKEN||''); fd.append('message',text); fd.append('history',JSON.stringify(history.slice(-8)));
   fetch(base+'/services/api/gemini.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json().catch(()=>({ok:false,error:'Invalid server response.'})).then(data=>({status:r.status,data})))
    .then(({data})=>{
      thinking.remove();
      if(data.ok){
        addMessage('assistant',data.answer); history.push({role:'model',text:data.answer}); save();
      }else addMessage('assistant','I could not answer that right now. '+(data.error||'Please try again.'));
    }).catch(()=>{thinking.remove();addMessage('assistant','The AI service could not be reached. Please check the server connection and Gemini environment configuration.');})
    .finally(()=>setBusy(false));
 }
 form.addEventListener('submit',e=>{e.preventDefault();ask(input.value);});
 input.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();ask(input.value);}});
 input.addEventListener('input',()=>{input.style.height='auto';input.style.height=Math.min(input.scrollHeight,150)+'px';});
 document.querySelectorAll('.ai-suggestions button').forEach(b=>b.addEventListener('click',()=>ask(b.dataset.prompt||'')));
 clearBtn?.addEventListener('click',()=>{history=[];sessionStorage.removeItem('ct4_ai_history');messages.innerHTML='<div class="ai-message assistant"><div class="ai-msg-icon"><span class="material-symbols-outlined">auto_awesome</span></div><div><strong>CT4 AI</strong><p>Chat cleared. What would you like to know about Core Transaction 4?</p></div></div>';});
});

/* CT4 global account tools: theme, data storage, logout confirmation, inactivity timer */
function formatBytes(bytes){
  bytes=Number(bytes)||0;
  if(bytes<1024) return bytes+' B';
  if(bytes<1024*1024) return (bytes/1024).toFixed(1)+' KB';
  if(bytes<1024*1024*1024) return (bytes/1024/1024).toFixed(1)+' MB';
  return (bytes/1024/1024/1024).toFixed(1)+' GB';
}
window.DATA_STORAGE_HAS_FILES=null;
function showDataStorageModal(){
  document.getElementById('userMenu')?.classList.remove('open');
  const root=document.getElementById('modalRoot'); if(!root)return;
  root.innerHTML=`<div class="gw-modal-backdrop data-storage-backdrop" onclick="if(event.target===this)closeModal()">
    <div class="gw-modal data-storage-modal">
      <div class="gw-modal-head"><div><strong>Data Storage</strong><small>Data/files received from other branches.</small></div><button class="gw-modal-close" onclick="closeModal()" aria-label="Close">×</button></div>
      <div class="gw-modal-body">
        <div class="data-storage-toolbar">
          <div><strong>DATA / FILES</strong><span>Stored data/files received from other branches.</span></div>
          <form id="dataStorageUploadForm" class="data-storage-upload" onsubmit="handleDataStorageUpload(event)">
            <input type="file" name="data_file" id="dataStorageFileInput" required>
            <input type="text" name="source_branch" placeholder="Branch / Source (optional)">
            <button class="gw-btn primary" type="submit" id="dataStorageUploadBtn"><span class="material-symbols-outlined">upload</span>UPLOAD</button>
            <button class="gw-btn secondary" type="button" onclick="showDownloadAllConfirm()"><span class="material-symbols-outlined">download</span>DOWNLOAD ALL</button>
          </form>
        </div>
        <div id="dataStorageList" class="data-storage-list"><div class="data-storage-loading"><span class="material-symbols-outlined">progress_activity</span>Loading stored files...</div></div>
      </div>
    </div>
  </div>`;
  loadDataStorageList();
}
function handleDataStorageUpload(e){
  e.preventDefault();
  const form=e.target;
  const fileInput=form.querySelector('input[type="file"]');
  if(!fileInput || !fileInput.files.length) return;
  const btn=document.getElementById('dataStorageUploadBtn');
  const originalHtml=btn?btn.innerHTML:'';
  if(btn){ btn.disabled=true; btn.innerHTML='<span class="material-symbols-outlined">hourglass_top</span> Uploading...'; }
  const fd=new FormData(form); fd.append('csrf_token',window.CSRF_TOKEN||'');
  fetch(`${window.APP_BASE||''}/includes/data_storage.php?action=upload`,{
    method:'POST',
    body:fd,
    credentials:'same-origin',
    headers:{'X-Requested-With':'XMLHttpRequest'}
  })
    .then(r=>r.json().catch(()=>({ok:false,error:'Server returned invalid response.'})))
    .then(data=>{
      if(btn){ btn.disabled=false; btn.innerHTML=originalHtml; }
      if(data.ok){
        window.DATA_STORAGE_HAS_FILES=null;
        form.reset();
        loadDataStorageList();
        showModal('Success','File uploaded and stored successfully.');
      } else {
        showModal('Upload Failed',data.error||'Unable to upload file.');
      }
    })
    .catch(err=>{
      if(btn){ btn.disabled=false; btn.innerHTML=originalHtml; }
      showModal('Upload Error',err.message||'Failed to communicate with server.');
    });
}
function loadDataStorageList(){
  fetch(`${window.APP_BASE||''}/includes/data_storage.php?action=list`,{credentials:'same-origin'})
    .then(r=>r.json()).then(data=>{
      const box=document.getElementById('dataStorageList'); if(!box)return;
      window.DATA_STORAGE_HAS_FILES=!!(data.ok && Array.isArray(data.items) && data.items.length);
      if(!window.DATA_STORAGE_HAS_FILES){
        box.innerHTML=`<div class="notification-empty"><span class="material-symbols-outlined">folder_off</span><strong>NO DATA/FILES STORED YET.</strong><p>DATA/FILES RECEIVED FROM OTHER BRANCHES WILL APPEAR HERE.</p></div>`; return;
      }
      box.innerHTML=data.items.map(f=>`<button type="button" class="data-storage-item" onclick="showStoredFile(${Number(f.id)},${JSON.stringify(String(f.file_name))},${JSON.stringify(String(f.file_type||''))})">
        <span class="data-storage-file-icon material-symbols-outlined">${String(f.file_type||'').startsWith('image/')?'image':'description'}</span>
        <span class="data-storage-file-main"><strong>${escapeHtml(f.file_name)}</strong><small>${escapeHtml(f.source_branch||'Other Branch')} · ${escapeHtml(formatBytes(f.file_size))}</small></span>
        <span class="material-symbols-outlined">chevron_right</span>
      </button>`).join('');
    }).catch(()=>{window.DATA_STORAGE_HAS_FILES=null;const box=document.getElementById('dataStorageList');if(box)box.innerHTML='<div class="notification-empty"><span class="material-symbols-outlined">error</span><strong>UNABLE TO LOAD DATA/FILES.</strong><p>PLEASE TRY AGAIN.</p></div>';});
}
function showStoredFile(id,name,type){
  const root=document.getElementById('modalRoot'); if(!root)return;
  const src=`${window.APP_BASE||''}/includes/data_storage.php?action=view&id=${encodeURIComponent(id)}`;
  const isImg = String(type||'').startsWith('image/') || /\.(jpe?g|png|gif|webp|svg)$/i.test(name);
  const isPdf = (type === 'application/pdf') || /\.pdf$/i.test(name);
  const isTxt = String(type||'').startsWith('text/') || /\.(txt|csv|log|json|xml|html)$/i.test(name);
  let previewContent = '';
  if (isImg) {
    previewContent = `<div style="display:flex;align-items:center;justify-content:center;height:100%;background:#f1f5f9;padding:12px"><img src="${src}" alt="${escapeHtml(name)}" style="max-width:100%;max-height:100%;object-fit:contain;border-radius:8px"></div>`;
  } else if (isPdf || isTxt) {
    previewContent = `<iframe src="${src}" title="${escapeHtml(name)}" style="width:100%;height:100%;border:0"></iframe>`;
  } else {
    previewContent = `<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:12px;color:#64748b;text-align:center;padding:24px"><span class="material-symbols-outlined" style="font-size:54px;color:#4f46e5">description</span><strong>${escapeHtml(name)}</strong><p style="margin:0;font-size:13px">Direct preview is not available for this file type (${escapeHtml(type||'binary')}).<br>You can safely download the file below to view it.</p></div>`;
  }
  root.innerHTML=`<div class="gw-modal-backdrop" onclick="if(event.target===this)showDataStorageModal()"><div class="gw-modal data-file-viewer-modal">
    <div class="gw-modal-head"><div><strong>${escapeHtml(name)}</strong><small>${escapeHtml(type||'Stored file')}</small></div><button class="gw-modal-close" onclick="showDataStorageModal()" aria-label="Back">×</button></div>
    <div class="gw-modal-body">
      <div class="data-file-preview">${previewContent}</div>
      <div class="record-actions"><button type="button" class="gw-btn secondary" onclick="showDownloadConfirm(${Number(id)},${JSON.stringify(String(name))})"><span class="material-symbols-outlined">download</span>DOWNLOAD</button><button type="button" class="gw-btn btn-danger" onclick="deleteStoredFile(${Number(id)},${JSON.stringify(String(name))})"><span class="material-symbols-outlined">delete</span>DELETE</button></div>
    </div>
  </div></div>`;
}
function deleteStoredFile(id,name){
  const fd=new FormData(); fd.append('csrf_token',window.CSRF_TOKEN||''); fd.append('id',id);
  if(!confirm('Delete '+name+'? The file will be moved to Archive and can be recovered later.')) return;
  fetch(`${window.APP_BASE||''}/includes/data_storage.php?action=delete`,{method:'POST',body:fd,credentials:'same-origin'})
   .then(r=>r.json()).then(data=>{if(!data.ok)throw new Error(data.error||'Delete failed.');window.DATA_STORAGE_HAS_FILES=null;showDataStorageModal();})
   .catch(e=>showModal('Delete failed',e.message));
}
function showDownloadConfirm(id,name){
  const root=document.getElementById('modalRoot'); if(!root)return;
  root.innerHTML=`<div class="gw-modal-backdrop"><div class="gw-modal confirmation-modal">
    <div class="gw-modal-head"><div><strong>DOWNLOAD CONFIRMATION</strong></div><button class="gw-modal-close" onclick="showStoredFile(${Number(id)},${JSON.stringify(String(name))},'Stored file')">×</button></div>
    <div class="gw-modal-body"><div class="confirmation-icon"><span class="material-symbols-outlined">download</span></div><p class="confirmation-text">ARE YOU SURE TO DOWNLOAD THE DATA/FILES</p>
      <div class="record-actions"><button class="gw-btn secondary" type="button" onclick="showStoredFile(${Number(id)},${JSON.stringify(String(name))},'Stored file')">NO</button><button class="gw-btn primary" type="button" onclick="window.location.href='${window.APP_BASE||''}/includes/data_storage.php?action=download&id=${Number(id)}'">YES, DOWNLOAD</button></div>
    </div>
  </div></div>`;
}
function showNoDownloadableFilesModal(){
  const root=document.getElementById('modalRoot'); if(!root)return;
  root.innerHTML=`<div class="gw-modal-backdrop"><div class="gw-modal confirmation-modal">
    <div class="gw-modal-head"><strong>DOWNLOAD ALL</strong><button class="gw-modal-close" onclick="showDataStorageModal()" aria-label="Close">×</button></div>
    <div class="gw-modal-body"><div class="confirmation-icon"><span class="material-symbols-outlined">folder_off</span></div>
      <p class="confirmation-text">THERE ARE NO DATA/FILES CAN BE DOWNLOAD</p>
      <div class="record-actions"><button class="gw-btn primary" type="button" onclick="showDataStorageModal()">OK</button></div>
    </div>
  </div></div>`;
}
function showDownloadAllConfirm(){
  if(window.DATA_STORAGE_HAS_FILES===false){ showNoDownloadableFilesModal(); return; }
  if(window.DATA_STORAGE_HAS_FILES===null){
    fetch(`${window.APP_BASE||''}/includes/data_storage.php?action=list`,{credentials:'same-origin'})
      .then(r=>r.json()).then(data=>{
        window.DATA_STORAGE_HAS_FILES=!!(data.ok && Array.isArray(data.items) && data.items.length);
        if(window.DATA_STORAGE_HAS_FILES) showDownloadAllConfirm();
        else showNoDownloadableFilesModal();
      }).catch(()=>showNoDownloadableFilesModal());
    return;
  }
  const root=document.getElementById('modalRoot'); if(!root)return;
  root.innerHTML=`<div class="gw-modal-backdrop"><div class="gw-modal confirmation-modal">
    <div class="gw-modal-head"><strong>DOWNLOAD ALL CONFIRMATION</strong><button class="gw-modal-close" onclick="showDataStorageModal()" aria-label="Close">×</button></div>
    <div class="gw-modal-body"><div class="confirmation-icon"><span class="material-symbols-outlined">download_for_offline</span></div><p class="confirmation-text">ARE YOU SURE TO DOWNLOAD ALL DATA/FILES</p>
      <div class="record-actions"><button class="gw-btn secondary" type="button" onclick="showDataStorageModal()">NO</button><button class="gw-btn primary" type="button" onclick="window.location.href='${window.APP_BASE||''}/includes/data_storage.php?action=download_all'">YES, DOWNLOAD ALL</button></div>
    </div>
  </div></div>`;
}
function showLogoutModal(){
  document.getElementById('userMenu')?.classList.remove('open');
  const root=document.getElementById('modalRoot'); if(!root)return;
  root.innerHTML=`<div class="gw-modal-backdrop"><div class="gw-modal confirmation-modal">
    <div class="gw-modal-head"><strong>LOGOUT CONFIRMATION</strong><button class="gw-modal-close" onclick="closeModal()" aria-label="Close">×</button></div>
    <div class="gw-modal-body"><div class="confirmation-icon"><span class="material-symbols-outlined">logout</span></div><p class="confirmation-text">ARE YOU SURE YOU WANT TO LOGOUT</p>
      <div class="record-actions"><button class="gw-btn secondary" type="button" onclick="closeModal()">NO</button><button class="gw-btn primary" type="button" onclick="window.location.href='${window.APP_BASE||''}/auth/logout.php'">YES</button></div>
    </div>
  </div></div>`;
}

/* Automatic logout: only authenticated users, exactly 5 minutes of inactivity.
   Login and Face ID pages do not expose CURRENT_USER and are therefore excluded. */
(function(){
  const LIMIT=5*60*1000;
  let lastActivity=Date.now(), timerId=null, lastMove=0;
  function logout(){ if(timerId)clearTimeout(timerId); window.location.href=`${window.APP_BASE||''}/auth/logout.php?reason=inactivity`; }
  function schedule(){ clearTimeout(timerId); timerId=setTimeout(logout,LIMIT); }
  function markActivity(){
    lastActivity=Date.now();
    schedule();
  }
  function init(){
    if(!window.CURRENT_USER || !window.CURRENT_USER.name) return;
    ['keydown','mousedown','touchstart','scroll','click','wheel','input','change','focus'].forEach(evt=>window.addEventListener(evt,markActivity,{passive:true}));
    window.addEventListener('mousemove',()=>{
      const now=Date.now(); if(now-lastMove>300){lastMove=now;markActivity();}
    },{passive:true});
    markActivity();
    setInterval(()=>{ if(Date.now()-lastActivity>=LIMIT) logout(); },10000);
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();

/* Generate module shortcuts from the module's actual content sections.
   Existing links are reused; duplicate shortcuts are never created. */
(function(){
  function slug(text){return String(text).toLowerCase().trim().replace(/&/g,'and').replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'').slice(0,70);}
  function initShortcuts(){
    const path=window.location.pathname||'';
    const modulePaths=['/modules/health_safety/','/modules/legal_compliance/','/modules/system_admin_security/','/modules/asset_equipment/'];
    if(!modulePaths.some(p=>path.includes(p))) return;
    const shell=document.querySelector('.page-shell'); if(!shell)return;
    const existingBar=shell.querySelector('.gw-quick-actions');
    const bar=existingBar||document.createElement('section');
    bar.className='gw-quick-actions module-auto-shortcuts';
    const used=new Set([...bar.querySelectorAll('a[href^="#"]')].map(a=>a.getAttribute('href')));
    const sections=[...shell.querySelectorAll(':scope > section')].filter(sec=>{
      const h=sec.querySelector('.gw-panel-head h2, h2');
      return h && !sec.classList.contains('gw-hero') && !sec.classList.contains('gw-stats') && !sec.classList.contains('gw-quick-actions');
    });
    sections.forEach(sec=>{
      const h=sec.querySelector('.gw-panel-head h2, h2'); if(!h)return;
      if(!sec.id) sec.id=slug(h.textContent);
      if(!sec.id)return;
      const href='#'+sec.id; if(used.has(href))return;
      used.add(href);
      const a=document.createElement('a'); a.href=href;
      a.innerHTML='<span class="material-symbols-outlined">shortcut</span>'+h.textContent.trim();
      bar.appendChild(a);
    });
    if(!existingBar && bar.children.length){ const hero=shell.querySelector('.gw-hero'); hero?.after(bar); }
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initShortcuts);else initShortcuts();
})();

/* Archive: deleted records/files are retained and can be recovered into data storage/database. */
function showArchiveModal(filter='all'){
 document.getElementById('userMenu')?.classList.remove('open');
 if(window.CURRENT_USER?.role!=='Administrator'){alert('Only administrators can manage the archive.');return;}
 const root=document.getElementById('modalRoot'); if(!root)return;
 root.innerHTML=`<div class="gw-modal-backdrop archive-backdrop" onclick="if(event.target===this)closeModal()"><div class="gw-modal archive-modal">
   <div class="gw-modal-head"><div><strong>Archive</strong><small>Deleted data is retained here before permanent deletion.</small></div><button class="gw-modal-close" onclick="closeModal()">×</button></div>
   <div class="gw-modal-body"><div class="archive-toolbar"><select id="archiveTypeFilter" onchange="showArchiveModal(this.value)"><option value="all" ${filter==='all'?'selected':''}>All archived data</option><option value="feedback" ${filter==='feedback'?'selected':''}>Employee Feedback</option><option value="file" ${filter==='file'?'selected':''}>Files</option><option value="record" ${filter==='record'?'selected':''}>Records</option></select><div class="archive-backup-actions"><button class="gw-btn secondary" type="button" onclick="backupArchive('all')"><span class="material-symbols-outlined">download</span>Backup All</button><button class="gw-btn secondary" type="button" onclick="backupArchive('feedback')"><span class="material-symbols-outlined">feedback</span>Backup Feedback</button></div></div><div id="archiveList" class="data-storage-list"><div class="data-storage-loading"><span class="material-symbols-outlined">progress_activity</span>Loading archive...</div></div></div>
 </div></div>`;
 fetch(`${window.APP_BASE||''}/includes/archive.php?action=list&type=${encodeURIComponent(filter)}`,{credentials:'same-origin',cache:'no-store'}).then(r=>r.json()).then(data=>{
   const box=document.getElementById('archiveList'); if(!box)return;
   if(!data.ok||!data.items?.length){box.innerHTML='<div class="notification-empty"><span class="material-symbols-outlined">inventory_2</span><strong>Archive is empty</strong><p>Deleted data in this category will appear here.</p></div>';return;}
   box.innerHTML=data.items.map(x=>`<div class="data-storage-item archive-item"><span class="data-storage-file-icon material-symbols-outlined">${x.item_type==='file'?'description':(x.item_type==='feedback'?'feedback':'dataset')}</span><span class="data-storage-file-main"><strong>${escapeHtml(x.item_name)}</strong><small>${escapeHtml(x.item_type)} · Deleted ${escapeHtml(x.deleted_at||'')}</small></span><div style="display:flex;gap:6px;align-items:center"><button class="gw-btn primary" type="button" onclick="recoverArchive(${Number(x.id)})"><span class="material-symbols-outlined">restore</span>Recover</button><button class="gw-btn btn-danger" type="button" onclick="deleteArchiveItem(${Number(x.id)},${JSON.stringify(String(x.item_name))})"><span class="material-symbols-outlined">delete_forever</span>Delete Permanently</button></div></div>`).join('');
 }).catch(()=>{const box=document.getElementById('archiveList');if(box)box.innerHTML='<div class="notification-empty"><strong>Unable to load archive.</strong><p>Please try again.</p></div>';});
}
function backupArchive(type='all'){
 if(window.CURRENT_USER?.role!=='Administrator')return;
 window.location.href=`${window.APP_BASE||''}/includes/archive.php?action=backup&type=${encodeURIComponent(type)}`;
}
function recoverArchive(id){
  const fd=new FormData(); fd.append('csrf_token',window.CSRF_TOKEN||''); fd.append('id',id); fd.append('action','recover');
  fetch(`${window.APP_BASE||''}/includes/archive.php?action=recover`,{method:'POST',body:fd,credentials:'same-origin'})
   .then(r=>r.json()).then(data=>{if(!data.ok)throw new Error(data.error||'Recovery failed.');window.DATA_STORAGE_HAS_FILES=null;showArchiveModal();})
   .catch(e=>showModal('Recovery failed',e.message));
}
function deleteArchiveItem(id,name){
  if(!confirm('Permanently delete "'+name+'"? This action cannot be undone.')) return;
  const fd=new FormData(); fd.append('csrf_token',window.CSRF_TOKEN||''); fd.append('id',id); fd.append('action','delete');
  fetch(`${window.APP_BASE||''}/includes/archive.php?action=delete`,{method:'POST',body:fd,credentials:'same-origin'})
   .then(r=>r.json()).then(data=>{if(!data.ok)throw new Error(data.error||'Delete failed.');showArchiveModal();})
   .catch(e=>showModal('Delete failed',e.message));
}

/* Module top navigation: enabled only on the four module pages. */
(function(){
  function initModuleTop(){
    const btn=document.getElementById('moduleTopButton');
    if(!btn)return;
    const toggle=()=>btn.classList.toggle('visible',window.scrollY>280);
    btn.addEventListener('click',()=>window.scrollTo({top:0,behavior:'smooth'}));
    window.addEventListener('scroll',toggle,{passive:true});
    toggle();
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initModuleTop);else initModuleTop();
})();




/* Global table usability: five visible rows, horizontal access to every column,
   and draggable column widths. */
(function initTableEnhancements(){
  function makeResizable(table){
    if(table.dataset.resizable==='1') return;
    table.dataset.resizable='1';
    table.querySelectorAll('thead th').forEach(th=>{
      const grip=document.createElement('span');
      grip.className='table-column-resizer'; grip.setAttribute('aria-hidden','true');
      th.appendChild(grip);
      let startX=0,startW=0;
      grip.addEventListener('mousedown',e=>{
        e.preventDefault(); e.stopPropagation();
        startX=e.clientX; startW=th.getBoundingClientRect().width;
        const move=ev=>{ const width=Math.max(80,startW+(ev.clientX-startX)); th.style.width=width+'px'; th.style.minWidth=width+'px'; };
        const up=()=>{document.removeEventListener('mousemove',move);document.removeEventListener('mouseup',up);};
        document.addEventListener('mousemove',move); document.addEventListener('mouseup',up);
      });
    });
  }
  function limitRows(table){
    if(table.dataset.rowLimitApplied==='1') return;
    table.dataset.rowLimitApplied='1';
    const rows=[...table.querySelectorAll('tbody > tr')].filter(r=>!r.querySelector('.empty'));
    rows.forEach((row,i)=>{if(i>=5)row.hidden=true;});
  }
  function init(){
    document.querySelectorAll('.data-table,.feedback-data-table').forEach(t=>{makeResizable(t);limitRows(t);});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
  window.initTableEnhancements=init;
})();

/* Convert inline update forms into centered modal editors without changing their
   POST actions or database workflow. */
(function initUpdatePopups(){
  function openFormModal(form){
    const root=document.getElementById('modalRoot'); if(!root)return;
    const clone=form.cloneNode(true);
    clone.removeAttribute('style');
    clone.classList.add('centered-update-form');
    root.innerHTML=`<div class="gw-modal-backdrop" onclick="if(event.target===this)closeModal()"><div class="gw-modal update-form-modal"><div class="gw-modal-head"><div><strong>Update Record</strong><small>Edit the selected record and save the existing database update.</small></div><button class="gw-modal-close" type="button" onclick="closeModal()">×</button></div><div class="gw-modal-body"></div></div></div>`;
    const body=root.querySelector('.gw-modal-body'); if(!body)return;
    body.appendChild(clone);
    clone.addEventListener('submit',()=>{const submit=clone.querySelector('button[type="submit"],button:not([type])');if(submit){submit.disabled=true;submit.innerHTML='<span class="material-symbols-outlined">hourglass_top</span>Saving…';}});
  }
  function init(){
    document.querySelectorAll('details').forEach(details=>{
      const form=details.querySelector('form');
      const action=form?.querySelector('input[name="action"]')?.value||'';
      if(!form || !/^update_/i.test(action) || details.dataset.updateModal==='1')return;
      details.dataset.updateModal='1';
      const summary=details.querySelector('summary'); if(!summary)return;
      summary.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();openFormModal(form);});
      form.hidden=true;
    });
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
