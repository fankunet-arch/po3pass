// store_html/html/pos/assets/js/modules/member.js
// 会员模块（稳定内联提示：输入框下方 + DOM 重绘自动恢复 + 多制式请求回退）

import { STATE } from '../state.js';
import { t } from '../utils.js';
import { updateMemberUI } from '../ui.js';

/* ----------------- 基础工具 ----------------- */
const $ = (sel, root=document) => root.querySelector(sel);
const getEl = id => document.getElementById(id);
const hasBootstrap = () => typeof window.bootstrap !== 'undefined';
const toStr = v => (v === undefined || v === null) ? '' : String(v);

// 输入净化：把任何值转成仅含数字或首位“+”
function sanitizePhoneInput(v){
  if (v && typeof v === 'object' && ('target' in v || 'preventDefault' in v)) v = '';
  const s = toStr(v);
  let t = s.replace(/[^\d+]/g, '');
  if (t.includes('+')) t = '+' + t.replace(/\+/g,'').replace(/[^\d]/g,'');
  return t;
}

/* ----------------- 鲁棒定位：手机号输入框 & 查找按钮 ----------------- */
const PHONE_INPUT_SELECTORS = [
  '#member_search_phone',           // ← 你页面实际使用
  '#member_search_input',
  '#member-phone',
  '#member_input',
  'input[name="member_phone"]',
  '#cartOffcanvas input[type="tel"]',
  '#cartOffcanvas .member-search input',
  '#cartOffcanvas header ~ div input.form-control',
  '#cartOffcanvas input.form-control',
  'input[type="tel"]'
];
function getPhoneInput(){
  for (const sel of PHONE_INPUT_SELECTORS){
    const el = $(sel);
    if (el) return el;
  }
  return null;
}

const SEARCH_BTN_SELECTORS = [
  '#btn_find_member',
  '#member_search_btn',
  '[data-action="member.search"]',
  '#cartOffcanvas .member-search button',
  '#cartOffcanvas header ~ div button',
];
function isSearchButton(el){
  if (!el) return false;
  for (const sel of SEARCH_BTN_SELECTORS){ if (el.matches?.(sel)) return true; }
  const txt = toStr(el.textContent).trim().toLowerCase();
  return ['查找','搜索','search','buscar'].some(k => txt.includes(k));
}

/* ----------------- 稳定的提示条挂载 + 自动恢复 ----------------- */
let lastHint = null;         // 记录最后一次提示内容，DOM 被重绘时可恢复
let hintObserver = null;

function getAnchor(){
  const input = getPhoneInput();
  if (!input) return null;
  // 尽量插在 .input-group 后，结构更稳
  return input.closest('.input-group') || input;
}

function ensureInlineHost(){
  const anchor = getAnchor();
  if (!anchor) return null;

  let mount = getEl('member_hint_inline_mount');
  if (!mount || !mount.isConnected){
    mount = document.createElement('div');
    mount.id = 'member_hint_inline_mount';
    anchor.insertAdjacentElement('afterend', mount);
  }
  Object.assign(mount.style, { marginTop:'8px' });

  // 建立 DOM 观察，若提示被重绘清掉则自动恢复
  const root = anchor.parentElement || document.body;
  if (!hintObserver){
    hintObserver = new MutationObserver(() => {
      const m = getEl('member_hint_inline_mount');
      if (!m || !m.isConnected){
        // 重新挂载并恢复
        const a = getAnchor();
        if (!a) return;
        const newMount = document.createElement('div');
        newMount.id = 'member_hint_inline_mount';
        a.insertAdjacentElement('afterend', newMount);
        Object.assign(newMount.style, { marginTop:'8px' });
        if (lastHint) renderInlineHint(lastHint, true);
      }
    });
    hintObserver.observe(root, { childList: true, subtree: true });
  }

  return mount;
}

function clearInline(){
  const mount = getEl('member_hint_inline_mount');
  if (mount) mount.innerHTML = '';
}

// 渲染提示（type: info / warn / ok / danger；右侧可带按钮）
function renderInlineHint(opts, isRestore=false){
  lastHint = { ...opts }; // 记录状态以便恢复
  const mount = ensureInlineHost();
  if (!mount) return;

  const { text, type='info', actionText=null, onAction=null, extraRight=null } = opts;

  mount.innerHTML = '';

  const palette = {
    info:   { bg:'#e9f2ff', border:'#0b5ed733', fg:'#0b5ed7' },
    warn:   { bg:'#fff6e5', border:'#b76e0033', fg:'#b76e00' },
    ok:     { bg:'#e9fbf1', border:'#197a4233',  fg:'#197a42' },
    danger: { bg:'#ffebee', border:'#b0002033', fg:'#b00020' }
  };
  const c = palette[type] || palette.info;

  const row = document.createElement('div');
  Object.assign(row.style, {
    display:'flex', alignItems:'center', gap:'10px',
    borderRadius:'10px', padding:'10px 12px',
    background:c.bg, border:`1px solid ${c.border}`, color:c.fg
  });

  const msg = document.createElement('div');
  msg.style.flex = '1';
  msg.style.minWidth = '0';
  msg.style.fontSize = '14px';
  msg.textContent = toStr(text);
  row.appendChild(msg);

  if (actionText && typeof onAction === 'function'){
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = actionText;
    btn.className = 'btn btn-sm btn-danger';
    btn.style.whiteSpace = 'nowrap';
    btn.addEventListener('click', e => { e.preventDefault(); onAction(); });
    row.appendChild(btn);
  }

  if (extraRight){
    const wrap = document.createElement('div');
    wrap.appendChild(extraRight);
    row.appendChild(wrap);
  }

  mount.appendChild(row);
  // 恢复渲染时不需要动画
  if (!isRestore) row.style.transition = 'opacity .12s ease-out';
}

/* ----------------- Bootstrap 辅助 ----------------- */
const modalOf = (id, opts={backdrop:'static', keyboard:false}) => {
  const el = getEl(id);
  if (!el || !hasBootstrap()) return null;
  // [GEMINI FIX 2.1] 确保 modalOf 接收标准选项，并允许 keyboard:true
  const defaultOpts = { backdrop: 'static', keyboard: true };
  const finalOpts = { ...defaultOpts, ...opts };
  if (id === 'memberDetailModal') finalOpts.backdrop = true; // 详情弹窗允许点击外部关闭

  return bootstrap.Modal.getOrCreateInstance(el, finalOpts);
};
const offcanvasOf = id => {
  const el = getEl(id);
  if (!el || !hasBootstrap()) return null;
  return bootstrap.Offcanvas.getOrCreateInstance(el);
};
function detachToBody(id){
  const el = getEl(id);
  if (el && el.parentElement !== document.body) document.body.appendChild(el);
}
function forceVisibleModal(id){
  const modal = getEl(id); if (!modal) return;
  modal.classList.remove('fade');
  Object.assign(modal.style, { position:'fixed', inset:'0', display:'block', visibility:'visible', opacity:'1', zIndex:'1060' });
  const dlg = modal.querySelector('.modal-dialog');
  const content = modal.querySelector('.modal-content');
  if (dlg){
    Object.assign(dlg.style, {
      position:'fixed', left:'50%', top:'50%', transform:'translate(-50%,-50%)',
      width:'min(520px, calc(100vw - 32px))', maxWidth:'min(520px, calc(100vw - 32px))',
      display:'block', opacity:'1', zIndex:'1062'
    });
    dlg.classList.add('modal-dialog-centered');
  }
  if (content){
    Object.assign(content.style, { display:'block', width:'100%', visibility:'visible', opacity:'1', zIndex:'1063' });
  }
  document.querySelectorAll('.offcanvas-backdrop').forEach(n=>n.remove());
  document.body.classList.remove('offcanvas-backdrop');
}

/* ----------------- 打开创建会员 ----------------- */
export async function openMemberCreateModal(){
  const el = getEl('opsOffcanvas');
  const ops = offcanvasOf('opsOffcanvas');
  if (ops && el && el.classList.contains('show')) {
    await new Promise(r=>{ el.addEventListener('hidden.bs.offcanvas', r, {once:true}); ops.hide(); });
  }
  detachToBody('memberCreateModal');
  const m = modalOf('memberCreateModal'); if (m) m.show();
  forceVisibleModal('memberCreateModal');
}
export const showCreateMemberModal = openMemberCreateModal;

/* ----------------- [新增] 打开会员详情 ----------------- */
/**
 * [GEMINI FIX 2] 新增函数：打开会员详情弹窗
 */
export function openMemberDetailModal() {
  const member = STATE.activeMember;
  if (!member) {
    console.warn('openMemberDetailModal called without active member.');
    return;
  }
  
  // 辅助函数，安全地获取值
  const getVal = (key, fallback = 'N/A') => {
      const v = member[key] || member[key.toLowerCase()]; // 兼容 phone vs phone_number
      return v === undefined || v === null || v === '' ? fallback : v;
  }
  
  // 填充弹窗内容
  const name = (getVal('first_name', '') + ' ' + getVal('last_name', '')).trim();
  $('#memberDetailModal_Name').textContent = name || getVal('display_name', 'N/A');
  $('#memberDetailModal_Phone').textContent = getVal('phone_number') || getVal('phone');
  $('#memberDetailModal_Email').textContent = getVal('email');
  $('#memberDetailModal_Birthdate').textContent = getVal('birthdate');
  $('#memberDetailModal_Points').textContent = getVal('points_balance', 0);

  // 确保弹窗在 body 上
  detachToBody('memberDetailModal');
  // 创建并显示
  const m = modalOf('memberDetailModal', { backdrop: true, keyboard: true }); // 允许点击外部关闭
  if (m) m.show();
}


/* ----------------- API（JSON → x-www-form-urlencoded → GET 回退） ----------------- */
async function callMemberAPI(url, payload){
  // [FIX] 添加 credentials: 'same-origin' 到所有 fetch
  const credentials = { credentials: 'same-origin' };

  // 1) JSON
  try{
    const r = await fetch(url, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload), ...credentials });
    if (r.ok){ const j = await r.json().catch(()=>null); if (j && (j.status==='success' || j.ok)) return j.data || j.member || null; }
  }catch(_e){}

  // 2) x-www-form-urlencoded（很多传统 PHP 会要求这个）
  try{
    const body = new URLSearchParams(payload).toString();
    const r = await fetch(url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body, ...credentials });
    if (r.ok){ const j = await r.json().catch(()=>null); if (j && (j.status==='success' || j.ok)) return j.data || j.member || null; }
  }catch(_e){}

  // 3) GET
  try{
    const qs = new URLSearchParams(payload).toString();
    const r = await fetch(`${url}?${qs}`, { method:'GET', ...credentials });
    if (r.ok){ const j = await r.json().catch(()=>null); if (j && (j.status==='success' || j.ok)) return j.data || j.member || null; }
  }catch(_e){}

  return null;
}

/* ----------------- 关联/提示 ----------------- */
function linkMember(member){
  STATE.activeMember = member;
  updateMemberUI(member);

  const name = member?.name || member?.full_name || member?.display_name || (member?.phone ?? '');
  const vouchers =
    member?.coupon_count ??
    (Array.isArray(member?.vouchers) ? member.vouchers.length : undefined) ??
    (Array.isArray(member?.coupons) ? member.coupons.length : undefined) ??
    (Array.isArray(member?.available_coupons) ? member.available_coupons.length : 0);

  const right = document.createElement('button');
  right.className = 'btn btn-sm btn-outline-secondary';
  right.textContent = '解除关联';
  right.addEventListener('click', e => { e.preventDefault(); unlinkMember(); });

  renderInlineHint({
    text: `${toStr(name)} · 共 ${vouchers ?? 0} 张可用券`,
    type: 'ok',
    extraRight: right
  });

  // [GEMINI FIX 2] 关联会员时，显示“查看详情”按钮
  const btnDetail = getEl('btn_show_member_detail');
  if(btnDetail) btnDetail.style.display = 'inline-block';
}

/* ----------------- 导出：查找会员 ----------------- */
export async function findMember(phone){
  try{
    const inputEl = getPhoneInput();
    const raw = phone ?? (inputEl ? inputEl.value : '');
    const input = sanitizePhoneInput(raw);

    if (!input){
      renderInlineHint({
        text: t('member_search_placeholder'),
        type: 'warn',
        actionText: t('member_create'),
        onAction: async () => { await openMemberCreateModal(); $('#member_phone') && ($('#member_phone').value = ''); }
      });
      return null;
    }

    // [FIX] 修复 API 路径
    const payload = { phone: input }; // action 在 URL 中
    const endpoints = [
        'api/pos_api_gateway.php?res=member&act=find', 
        'api/member_handler.php' // 保留旧的回退
    ];
    let found = null;
    for (const url of endpoints){
      found = await callMemberAPI(url, payload);
      if (found) break;
    }

    if (found){
      linkMember(found);
      return found;
    } else {
      renderInlineHint({
        text: `${t('member_not_found')}: ${input}`,
        type: 'info',
        actionText: t('member_create'),
        onAction: async () => { await openMemberCreateModal(); $('#member_phone') && ($('#member_phone').value = input); }
      });
      STATE.activeMember = null;
      updateMemberUI(null);
      return null;
    }

  }catch(err){
    console.error('[member.findMember] error:', err);
    renderInlineHint({ text:'查询失败，请稍后重试', type:'danger' });
    return null;
  }
}

/* ----------------- 导出：创建会员 ----------------- */
export async function createMember(payload){
  try{
    let name, phone, email, first_name, last_name, birthdate;
    if (payload){
      name  = toStr(payload.name).trim(); // 兼容旧
      phone = sanitizePhoneInput(payload.phone ?? payload.phone_number);
      email = toStr(payload.email).trim();
      first_name = toStr(payload.first_name).trim();
      last_name = toStr(payload.last_name).trim();
      birthdate = toStr(payload.birthdate).trim();
    }else{
      phone = sanitizePhoneInput($('#member_phone')?.value);
      first_name = toStr($('#member_firstname')?.value).trim();
      last_name = toStr($('#member_lastname')?.value).trim();
      email = toStr($('#member_email')?.value).trim();
      birthdate = toStr($('#member_birthdate')?.value).trim();
    }
    if (!phone){
      renderInlineHint({ text:'手机号为必填', type:'warn' });
      return null;
    }

    // [FIX] 修复 API 路径
    const endpoints = [
        'api/pos_api_gateway.php?res=member&act=create', 
        'api/member_handler.php' // 保留旧的回退
    ];
    // 适配新后端的载荷
    const req = { data: { phone_number: phone, first_name, last_name, email, birthdate } };
    let created = null;
    for (const url of endpoints){
      created = await callMemberAPI(url, req);
      if (created) break;
    }

    // [GEMINI FIX 1] 移除静默的本地回退，改为抛出错误
    if (!created){
      // 本地回退 (已移除)
      // created = { id:'LOCAL-'+Date.now(), first_name, last_name, phone_number: phone, email, birthdate, points_balance:0, _local:true };
      
      // 新的错误提示
      console.error("Backend failed to create member.", req);
      renderInlineHint({ text:'创建会员失败：后端API未响应或返回错误。', type:'danger' });
      // 激活创建弹窗内的错误提示
      const errorBox = getEl('member_create_error');
      if(errorBox) {
          errorBox.textContent = '创建失败 (后端错误)。请检查网络或联系管理员。';
          errorBox.classList.remove('d-none');
      }
      return null;
    }

    // 成功后清除创建弹窗的错误
    const errorBox = getEl('member_create_error');
    if(errorBox) {
        errorBox.classList.add('d-none');
    }

    linkMember(created);
    const md = modalOf('memberCreateModal'); md && md.hide();
    return created;
  }catch(err){
    console.error('[member.createMember] error:', err);
    renderInlineHint({ text:'创建失败，请稍后重试', type:'danger' });
    return null;
  }
}

/* ----------------- 导出：解绑会员 ----------------- */
export function unlinkMember(){
  try{
    STATE.activeMember = null;
    updateMemberUI(null);
    renderInlineHint({
      text: '已解除会员关联',
      type: 'info',
      actionText: '添加用户',
      onAction: async () => { await openMemberCreateModal(); }
    });

    // [GEMINI FIX 2] 解除关联时，隐藏“查看详情”按钮
    const btnDetail = getEl('btn_show_member_detail');
    if(btnDetail) btnDetail.style.display = 'none';

  }catch(err){
    console.error('[member.unlinkMember] error:', err);
  }
}

/* ----------------- 事件绑定（鲁棒） ----------------- */
// 点击“查找”
document.addEventListener('click', (e)=>{
  const btn = e.target.closest('button, a');
  if (btn && isSearchButton(btn)){ e.preventDefault(); findMember(); }
});
// 输入框回车
document.addEventListener('keydown', (e)=>{
  const input = getPhoneInput();
  if (e.key === 'Enter' && input && e.target === input){
    e.preventDefault(); findMember();
  }
});
// 手动打开“创建会员”
document.addEventListener('click', (e)=>{
  const t = e.target.closest('#btn_open_member_create, [data-action="member.create"], #btn_member_create, #btn_show_create_member');
  if (t){ e.preventDefault(); openMemberCreateModal(); }
});
// 提交创建（兼容两个 id）
document.addEventListener('click', (e)=>{
  const t = e.target.closest('#btn_member_submit, [data-i18n="member_create_submit"]');
  if (t){ 
    e.preventDefault(); 
    // 确保在表单的 submit 按钮触发时，清空旧错误
    const errorBox = getEl('member_create_error');
    if(errorBox) errorBox.classList.add('d-none');
    
    createMember(); 
  }
});
// Offcanvas 重新展示时，恢复提示
document.addEventListener('shown.bs.offcanvas', ()=>{
  if (lastHint) renderInlineHint(lastHint, true);
});
// ESC 关闭弹窗
document.addEventListener('keydown', (e)=>{
  if (e.key === 'Escape'){
    const el = getEl('memberCreateModal');
    if (el && el.classList.contains('show')){
      hasBootstrap() && bootstrap.Modal.getInstance(el)?.hide();
    }
    // [GEMINI FIX 2] 允许 ESC 关闭详情弹窗
    const elDetail = getEl('memberDetailModal');
    if (elDetail && elDetail.classList.contains('show')){
      hasBootstrap() && bootstrap.Modal.getInstance(elDetail)?.hide();
    }
  }
});

// [GEMINI FIX 2] 新增：监听会员详情按钮
document.addEventListener('click', (e) => {
  const t = e.target.closest('#btn_show_member_detail');
  if (t) {
    e.preventDefault();
    openMemberDetailModal();
  }
});