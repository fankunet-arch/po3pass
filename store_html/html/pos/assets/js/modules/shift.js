// TopTea POS · shift.js
// v2.1.0 (Ghost Shift Guardian - i18n & Variable Fix)
// - checkShiftStatus: Detects 'ghost_shift_detected', stores info in STATE.
// - Exports renderGhostShiftModalText() to render modal text.
// - main.js now calls this function on lang change.

import { toast, t } from '../utils.js';
import { STATE } from '../state.js'; // 导入 STATE

let startShiftModal = null;
let endShiftModal   = null;
let forceStartShiftModal = null;
let HAS_ACTIVE_SHIFT = false;

const POLICY = (window.SHIFT_POLICY || 'force_all');

/** 创建“不可关闭”的开班弹窗（static + keyboard:false，且拦截 hide 事件） */
function getNonClosableModal(modalId) {
  const el = document.getElementById(modalId);
  if (!el) return null;

  el.querySelector('.btn-close')?.classList.add('d-none');
  el.querySelector('[data-role="btn-cancel-start"]')?.classList.add('d-none');

  const m = bootstrap.Modal.getOrCreateInstance(el, { backdrop: 'static', keyboard: false });

  el.addEventListener('hide.bs.modal', (ev) => {
    if (POLICY !== 'optional' && !HAS_ACTIVE_SHIFT) {
      ev.preventDefault();
    }
  });

  return m;
}

/** * [GHOST_SHIFT_FIX v5.2]
 * 新的渲染函数，用于填充“强制开班”弹窗的文本。
 * 它可以被 checkShiftStatus 和 main.js (语言切换时) 重复调用。
 */
export function renderGhostShiftModalText() {
  const bodyEl = document.getElementById('force_start_body');
  if (!bodyEl) return; // 如果弹窗DOM不存在，则不执行

  // 从 STATE 中获取存储的幽灵班次信息
  const ghostInfo = STATE.ghostShiftInfo;
  
  // 获取当前语言的模板
  const template = t('force_start_body'); // "系统检测到班次 (属于: {user})..."
  
  if (ghostInfo) {
    // 替换占位符
    const userText = `${ghostInfo.userName} (${ghostInfo.startTime})`;
    bodyEl.textContent = template.replace('{user}', userText);
  } else {
    // 如果没有幽灵信息（例如，弹窗还未激活时切换了语言），也使用模板
    bodyEl.textContent = template.replace('{user}', '...');
  }
}

/** 初始化（在 main.js 的启动流程中调用） */
export function initializeShiftModals() {
  const startEl = document.getElementById('startShiftModal');
  const endEl   = document.getElementById('endShiftModal');
  const forceStartEl = document.getElementById('forceStartShiftModal');

  if (startEl && !startShiftModal) {
    startShiftModal = getNonClosableModal('startShiftModal');
  }
  if (forceStartEl && !forceStartShiftModal) {
    forceStartShiftModal = getNonClosableModal('forceStartShiftModal');
  }
  if (endEl && !endShiftModal) {
    endShiftModal = bootstrap.Modal.getOrCreateInstance(endEl);
  }

  const startForm = document.getElementById('start_shift_form');
  if (startForm) startForm.addEventListener('submit', handleStartShift);

  const forceStartForm = document.getElementById('force_start_shift_form');
  if (forceStartForm) forceStartForm.addEventListener('submit', handleForceStartShift);

  const endForm = document.getElementById('end_shift_form');
  if (endForm) endForm.addEventListener('submit', handleEndShift);
}

/** 启动时或状态变化时检查班次状态 */
export async function checkShiftStatus() {
  try {
    // [FIX] 修复 API 路径
    const resp = await fetch('./api/pos_api_gateway.php?res=shift&act=status', { credentials: 'same-origin' });
    const result = await resp.json();
    if (result.status !== 'success') {
      console.warn('checkShiftStatus error:', result.message);
      HAS_ACTIVE_SHIFT = false;
      return;
    }
    
    const data = result.data || {};
    HAS_ACTIVE_SHIFT = !!data.has_active_shift;

    if (!startShiftModal) startShiftModal = getNonClosableModal('startShiftModal');
    if (!forceStartShiftModal) forceStartShiftModal = getNonClosableModal('forceStartShiftModal');

    if (HAS_ACTIVE_SHIFT) {
      // 1. 已开班：确保所有开班弹窗都关闭
      STATE.ghostShiftInfo = null; // 清理幽灵信息
      try { startShiftModal?.hide(); } catch(_) {}
      try { forceStartShiftModal?.hide(); } catch(_) {}
      return;
    }

    // 2. 未开班，但检测到幽灵班次
    if (data.ghost_shift_detected) {
      // [GHOST_SHIFT_FIX v5.2]
      // 存储信息到 STATE
      STATE.ghostShiftInfo = {
        userName: data.ghost_shift_user_name || '未知',
        startTime: data.ghost_shift_start_time || '未知时间'
      };
      // 调用渲染函数
      renderGhostShiftModalText();
      
      try { startShiftModal?.hide(); } catch(_) {}
      forceStartShiftModal?.show();
      return;
    }

    // 3. 未开班，且无幽灵班次（正常开班流程）
    STATE.ghostShiftInfo = null; // 清理幽灵信息
    try { forceStartShiftModal?.hide(); } catch(_) {}
    startShiftModal?.show();

  } catch (err) {
    console.warn('checkShiftStatus network error:', err);
    HAS_ACTIVE_SHIFT = false;
    if (!startShiftModal) startShiftModal = getNonClosableModal('startShiftModal');
    startShiftModal?.show();
  }
}

/** 开始当班 (正常) */
export async function handleStartShift(e) {
  e.preventDefault();
  const input = document.getElementById('starting_float');
  const val = parseFloat(input?.value);
  if (isNaN(val) || val < 0) {
    toast(t('shift_start_invalid_float') || '请输入有效的初始备用金');
    return;
  }

  try {
    // [FIX] 修复 API 路径
    const resp = await fetch('./api/pos_api_gateway.php?res=shift&act=start', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ starting_float: val }) // action 已在 URL 中
    });
    const result = await resp.json();

    if (resp.ok && result.status === 'success') {
      HAS_ACTIVE_SHIFT = true;
      try { startShiftModal?.hide(); } catch(_) {}
      
      // [估清 需求3] 检查是否需要弹窗
      if (result.data && result.data.prompt_sold_out_decision === true) {
          document.getElementById('sold_out_snapshot_count').textContent = result.data.snapshot_item_count || 0;
          const decisionModal = new bootstrap.Modal(document.getElementById('soldOutDecisionModal'));
          decisionModal.show();
      } else {
          // 正常流程，直接刷新状态
          await checkShiftStatus(); 
      }
      
      toast(t('shift_start_success') || '已开始当班');
    } else {
      toast(`${t('shift_start_fail')}: ${result.message || `HTTP ${resp.status}`}`);
    }
  } catch (err) {
    toast(`${t('shift_start_fail')}: ${err.message}`);
  }
}

/** 强制开始新班次 (处理幽灵班次) */
export async function handleForceStartShift(e) {
    e.preventDefault();
    const input = document.getElementById('force_starting_float');
    const val = parseFloat(input?.value);
    if (isNaN(val) || val < 0) {
        toast(t('shift_start_invalid_float') || '请输入有效的初始备用金');
        return;
    }

    try {
        // [FIX] 修复 API 路径
        const resp = await fetch('./api/pos_api_gateway.php?res=shift&act=force_start', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ starting_float: val }) // action 已在 URL 中
        });
        const result = await resp.json();

        if (resp.ok && result.status === 'success') {
            HAS_ACTIVE_SHIFT = true;
            STATE.ghostShiftInfo = null; // 清理幽灵信息
            try { forceStartShiftModal?.hide(); } catch(_) {}
            
            // [估清 需求3] 检查是否需要弹窗
            if (result.data && result.data.prompt_sold_out_decision === true) {
                document.getElementById('sold_out_snapshot_count').textContent = result.data.snapshot_item_count || 0;
                const decisionModal = new bootstrap.Modal(document.getElementById('soldOutDecisionModal'));
                decisionModal.show();
            } else {
                // 正常流程，直接刷新状态
                await checkShiftStatus(); 
            }

            toast(t('shift_start_success') || '已开始当班');
        } else {
            toast(`${t('shift_start_fail')}: ${result.message || `HTTP ${resp.status}`}`);
        }
    } catch (err) {
        toast(`${t('shift_start_fail')}: ${err.message}`);
    }
}


/** 结束当班 / 交接班 */
export async function handleEndShift(e) {
  e.preventDefault();
  const input = document.getElementById('counted_cash');
  const val = parseFloat(input?.value);
  if (isNaN(val) || val < 0) {
    toast('请填写正确的清点现金金额');
    return;
  }

  try {
    // [FIX] 修复 API 路径
    const resp = await fetch('./api/pos_api_gateway.php?res=shift&act=end', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ counted_cash: val }) // action 已在 URL 中
    });
    const result = await resp.json();

    if (result.status === 'success') {
      const endEl = document.getElementById('endShiftModal');
      await new Promise((resolve) => {
        let done = false;
        const finish = () => { if (!done) { done = true; resolve(); } };
        if (endEl) endEl.addEventListener('hidden.bs.modal', finish, { once: true });
        setTimeout(finish, 900);
        try { endShiftModal?.hide(); } catch(_) { /* no-op */ }
      });

      const payload = result.data || {};
      document.dispatchEvent(new CustomEvent('pos:eod-finished', {
        detail: { eod: payload.eod, eod_id: payload.eod_id }
      }));

      HAS_ACTIVE_SHIFT = false;
      await checkShiftStatus();
    } else {
      toast(`结束当班失败：${result.message || 'Unknown error'}`);
    }
  } catch (err) {
    toast(`网络错误：${err.message}`);
  }
}

/** 可选：回调注册 */
export function onEodFinished(callback) {
  document.addEventListener('pos:eod-finished', (e) => callback(e.detail));
}