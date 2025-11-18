/*
 * 文件名: /pos/assets/js/state.js
 * 描述: (已修改) 移除了 I18N 定义，从 i18n-pack.js 导入。
 */
/**
 * state.js — POS 全局状态 & UI 模式控制（稳定稳定版）
 * - APK 清缓存默认右手
 * - 左/右手切换：立即生效 + 同步旧键 + 触发旧监听
 * - 高峰模式：按钮/开关均可用
 * - 兼容旧键：POS_HAND_MODE / POS_LEFTY / POS_RIGHTY
 * - 额外桥接：点击包含 hand_mode 的整行也会触发切换（防止“选中了但未触发事件”）
 * Revision: 2.7.1 (B1.4 P2/P4 - Addon & Print I18N)
 */

//////////////////// I18N ////////////////////
// [重构] 从新的专用语言包导入 I18N 对象
import { I18N } from './i18n-pack.js';
// [重构] 重新导出 I18N，供 utils.js 等旧模块使用
export { I18N };

//////////////////// STATE ////////////////////
export const STATE = {
  active_category_key: null,
  cart: [],
  products: [],
  categories: [],
  addons: [],
  redemptionRules: [],
  printTemplates: {}, // --- CORE ADDITION: Store for print templates ---
  iceOptions: [], // (V2.2 GATING)
  sweetnessOptions: [], // (V2.2 GATING)
  sifDeclaration: '', // [GEMINI SIF_DR_FIX]
  tags_map: {}, // [B1.3 PASS]
  activeCouponCode: '',
  activeRedemptionRuleId: null,
  activePassSession: null, // [B1.4 PASS] 新增次卡会话状态
  purchasingDiscountCard: null, // [优惠卡购买] 当前正在购买的优惠卡
  passPurchaseCleanupPending: false, // [优惠卡购买] 成功后需要清理的标志
  calculatedCart: { cart: [], subtotal: 0, discount_amount: 0, final_total: 0 },
  payment: { total: 0, parts: [] },
  holdSortBy: 'time_desc',
  activeMember: null,
  lang: (typeof localStorage !== 'undefined' && localStorage.getItem('POS_LANG')) || 'zh',

  // 旧字段（兼容）
  lefty_mode:  (typeof localStorage !== 'undefined' && localStorage.getItem('POS_LEFTY')  === '1'),
  righty_mode: (typeof localStorage !== 'undefined' && localStorage.getItem('POS_RIGHTY') === '1'),
  hand_mode:   (typeof localStorage !== 'undefined' && (localStorage.getItem('POS_HAND_MODE') || 'right')),
  store_id: Number((typeof localStorage !== 'undefined' && localStorage.getItem('POS_STORE_ID')) || 1),
  points_redeemed: 0,

  // 新字段
  ui: { selected_category_id: null, search_text: '', hand: 'right', peak: false },
  flags: { loading: false },

  // [GHOST_SHIFT_FIX v5.2] 存储幽灵班次信息
  ghostShiftInfo: null
};
if (typeof window !== 'undefined') window.STATE = STATE;

//////////////////// LocalStorage helpers ////////////////////
const LS = {
  get(k){ try{ return localStorage.getItem(k); }catch(_){ return null; } },
  set(k,v){ try{ localStorage.setItem(k,v); }catch(_){} }
};

//////////////////// constants ////////////////////
const HAND_KEY = 'tt_pos_hand';
const PEAK_KEY = 'tt_pos_peak';

//////////////////// targets to apply classes ////////////////////
const TARGETS = [
  document.documentElement,
  document.body,
  document.querySelector('#app'),
  document.querySelector('#root'),
  document.querySelector('#posRoot'),
  document.querySelector('#page'),
  document.querySelector('#layout'),
  document.querySelector('#main'),
  document.querySelector('#mainContent'),
  document.querySelector('#pos_main'),
  document.querySelector('.pos-app')
].filter(Boolean);

//////////////////// Hand helpers ////////////////////
function syncLegacyHand(m){
  STATE.hand_mode   = m;
  STATE.lefty_mode  = (m === 'left');
  STATE.righty_mode = (m === 'right');
  LS.set('POS_HAND_MODE', m);
  LS.set('POS_LEFTY',  m === 'left'  ? '1' : '0');
  LS.set('POS_RIGHTY', m === 'right' ? '1' : '0');
}

function applyHand(mode){
  const m = (mode === 'left') ? 'left' : 'right';
  STATE.ui.hand = m;
  syncLegacyHand(m);

  const PAIRS = [
    ['hand-left','hand-right'],
    ['lefty','righty'],
    ['left-mode','right-mode'],
    ['lefty-mode','righty-mode'],
    ['pos-hand-left','pos-hand-right'],
    ['is-left','is-right'],
    ['layout-left','layout-right'],
    ['left-handed','right-handed'],
    ['left','right']
  ];
  const addSet = (el, addLeft) => {
    for (const [l,r] of PAIRS) { el.classList.remove(l, r); }
    for (const [l,r] of PAIRS) { el.classList.add(addLeft ? l : r); }
    el.setAttribute('data-hand', addLeft ? 'left' : 'right');
    el.setAttribute('data-lefty',  addLeft ? '1' : '0');
    el.setAttribute('data-righty', addLeft ? '0' : '1');
    el.style.setProperty('--hand-mode', addLeft ? 'left' : 'right');
    el.style.setProperty('--is-lefty',  addLeft ? '1' : '0');
    el.style.setProperty('--is-righty', addLeft ? '0' : '1');
  };
  for (const el of TARGETS) addSet(el, m === 'left');
  document.documentElement.setAttribute('data-hand', m);

  // 同步 UI 控件状态
  const sw = document.querySelector('#hand_switch,[data-hand-toggle="switch"],#right_hand_switch');
  if (sw && 'checked' in sw) sw.checked = (m === 'right');
  const rRight = document.querySelector('#hand_right,[data-hand="right"],input[name="hand_mode"][value="right"],#btn_right_hand,#hand_right_btn');
  const rLeft  = document.querySelector('#hand_left, [data-hand="left"], input[name="hand_mode"][value="left"],  #btn_left_hand,  #hand_left_btn');
  if (rRight && 'checked' in rRight) rRight.checked = (m === 'right');
  if (rLeft  && 'checked' in rLeft ) rLeft.checked  = (m === 'left');

  // 通知旧监听
  queueMicrotask(() => {
    try {
      const ev = new CustomEvent('pos:handchange', { detail: { mode: m }});
      window.dispatchEvent(ev); document.dispatchEvent(ev);
    } catch(_) {}
    ['#hand_switch', '#right_hand_switch'].forEach(sel => {
      const el = document.querySelector(sel);
      if (el) { try { el.dispatchEvent(new Event('change', { bubbles:true })); } catch(_){} }
    });
    void document.body?.offsetHeight;
  });

  // 二次重申，避免别的脚本“抢回”
  const reassert = () => {
    for (const el of TARGETS) addSet(el, m === 'left');
    document.documentElement.setAttribute('data-hand', m);
    try {
      if (typeof window.onHandModeChange === 'function') window.onHandModeChange(m);
      if (window.UI && typeof window.UI.applyHand === 'function') window.UI.applyHand(m);
    } catch(_) {}
  };
  requestAnimationFrame(reassert);
  setTimeout(reassert, 120);
}

export function setHand(mode, persist = true){
  const m = (mode === 'left') ? 'left' : 'right';
  applyHand(m);
  if (persist) {
    LS.set(HAND_KEY, m);
    LS.set('POS_HAND_MODE', m);
    LS.set('POS_LEFTY',  m === 'left'  ? '1' : '0');
    LS.set('POS_RIGHTY', m === 'right' ? '1' : '0');
  }
}
export function getHand(){ return STATE.ui.hand || STATE.hand_mode || 'right'; }

//////////////////// Peak helpers ////////////////////
function applyPeak(on){
  const flag = !!on;
  STATE.ui.peak = flag;
  for (const el of TARGETS){
    el.classList.toggle('contrast-boost', flag);
    el.setAttribute('data-peak', flag ? '1' : '0');
  }
  document.documentElement.setAttribute('data-peak', flag ? '1' : '0');

  const peakSw = document.querySelector('#setting_peak_mode, #peak_switch, [data-peak-toggle="switch"]');
  if (peakSw && 'checked' in peakSw) peakSw.checked = flag;

  queueMicrotask(() => {
    try {
      const ev = new CustomEvent('pos:peakchange', { detail: { on: flag }});
      window.dispatchEvent(ev); document.dispatchEvent(ev);
    } catch(_) {}
    void document.body?.offsetHeight;
  });
}
export function setPeak(on, persist = true){
  applyPeak(!!on);
  if (persist) LS.set(PEAK_KEY, on ? '1' : '0');
}
export function isPeak(){ return !!STATE.ui.peak; }

//////////////////// Init (默认右手) ////////////////////
const savedHand = (LS.get(HAND_KEY) || LS.get('POS_HAND_MODE') || '').toLowerCase();
applyHand((savedHand === 'left' || savedHand === 'right') ? savedHand : 'right');

const savedPeakNew = LS.get(PEAK_KEY);
const savedPeakOld = LS.get('POS_PEAK_MODE');
const initialPeakState = savedPeakNew !== null ? savedPeakNew === '1' : savedPeakOld === 'true';
applyPeak(initialPeakState);


//////////////////// Re-apply after DOM changes ////////////////////
const mo = new MutationObserver(() => {
  applyHand(getHand());
  applyPeak(isPeak());
});
mo.observe(document.documentElement, { childList: true, subtree: true });

//////////////////// Event bindings ////////////////////
// 1) 直接按钮/开关
document.addEventListener('click', (e) => {
  const handBtn = e.target.closest(
    '[data-hand="right"],[data-hand="left"],' +
    '[data-action="hand.right"],[data-action="hand.left"],[data-action="hand.toggle"],' +
    '[data-hand-toggle],' +
    '#btn_right_hand,#btn_left_hand,#hand_right_btn,#hand_left_btn'
  );
  if (!handBtn) return;
  const tag = (handBtn.tagName || '').toLowerCase();
  if (tag === 'button' || tag === 'a' || handBtn.getAttribute('role') === 'button') e.preventDefault();

  let next = getHand();
  if (handBtn.matches('[data-action="hand.toggle"],[data-hand-toggle]')) {
    next = (getHand() === 'right') ? 'left' : 'right';
  } else if (handBtn.matches('[data-hand="right"],[data-action="hand.right"],#btn_right_hand,#hand_right_btn')) {
    next = 'right';
  } else if (handBtn.matches('[data-hand="left"],[data-action="hand.left"],#btn_left_hand,#hand_left_btn')) {
    next = 'left';
  } else {
    next = (getHand() === 'right') ? 'left' : 'right';
  }
  setHand(next, true);
});

document.addEventListener('change', (e) => {
  const el = e.target;
  if (!(el instanceof Element)) return;

  if (el.matches('#hand_switch, #right_hand_switch, [data-hand-toggle="switch"]') && 'checked' in el) {
    const next = el.checked ? 'right' : 'left';
    setHand(next, true);
    return;
  }
  if (el.matches('input[name="hand_mode"], input[data-hand]')) {
    const val = (el.getAttribute('value') || el.getAttribute('data-hand') || '').toLowerCase();
    if (val === 'left' || val === 'right') setHand(val, true);
  }
});

// 2) **桥接**：点击“整行”也能触发 hand 切换（防止只改了样式没触发 change）
document.addEventListener('click', (e) => {
  const row = e.target.closest('.list-group-item, .form-check, .hand-option-row, [data-hand-row]');
  if (!row) return;
  const input = row.querySelector('input[name="hand_mode"]');
  if (!input) return;
  if (e.target !== input) {
    e.preventDefault();
    if (!input.checked) {
      input.checked = true;
      try { input.dispatchEvent(new Event('change', { bubbles: true })); } catch(_) {}
    } else {
      try { input.dispatchEvent(new Event('change', { bubbles: true })); } catch(_) {}
    }
  }
});

// Peak：按钮/开关
document.addEventListener('click', (e) => {
  const btn = e.target.closest(
    '[data-peak="on"],[data-peak="off"],[data-peak-toggle],' +
    '#btn_peak_on,#btn_peak_off,#btn_peak_toggle'
  );
  if (!btn) return;
  const tag = (btn.tagName || '').toLowerCase();
  if (tag === 'button' || tag === 'a' || btn.getAttribute('role') === 'button') e.preventDefault();

  const next = btn.hasAttribute('data-peak-toggle') || btn.matches('#btn_peak_toggle')
    ? !isPeak()
    : btn.matches('[data-peak="on"],#btn_peak_on');
  setPeak(next, true);
});

document.addEventListener('change', (e) => {
  const el = e.target;
  if (!(el instanceof Element)) return;

  if (el.matches('#setting_peak_mode, #peak_switch, [data-peak-toggle="switch"]') && 'checked' in el) {
    setPeak(!!el.checked, true);
    return;
  }
  if (el.matches('input[name="peak_mode"]')) {
    const v = (el.value || '').toLowerCase();
    setPeak(v === 'on' || v === 'true' || v === '1', true);
  }
});

// cross-tab（对 APK 无影响）
window.addEventListener?.('storage', (ev) => {
  if (ev.key === HAND_KEY && ev.newValue) applyHand(ev.newValue === 'left' ? 'left' : 'right');
  if (ev.key === PEAK_KEY) applyPeak(ev.newValue === '1');
});