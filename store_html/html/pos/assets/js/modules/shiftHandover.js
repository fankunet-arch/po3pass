/**
 * shiftHandover.js - 班次交接完成模块
 * * 职责:
 * 1. 监听 'pos:eod-finished' 事件 (由 shift.js 触发)
 * 2. 显示“交接班完成”弹窗 (#eodCompletedModal)
 * 3. 回读数据库确认数据同步
 * 4. 绑定弹窗内的“打印”和“查看历史”按钮
 *
 * (逻辑从 eod.js 迁移而来)
 */

import { STATE } from '../state.js';
// 导入 eod.js 中的打印报告函数
import { handlePrintEodReport } from './eod.js'; 
// 导入 eodHistory.js 中的打开历史函数
import { openEodHistory } from './eodHistory.js'; 
import {
    t, fmtEUR, toast, safeT, getEl,
    apiGetJSON, setText, to2, escapeHtml
} from '../utils.js';

let currentReportId = null; // 本模块只在需要打印时才关心这个ID

/**
 * 确保“交接班完成”弹窗的 DOM 存在
 */
function ensureCompletedModal() {
    if (getEl('eodCompletedModal')) return;
    document.body.insertAdjacentHTML('beforeend', `
<div class="modal fade" id="eodCompletedModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content modal-sheet">
      <div class="modal-header">
        <h5 class="modal-title d-flex align-items-center gap-2">
          <i class="bi bi-check-circle-fill fs-4 text-success"></i>
          ${safeT('eod_done_title', '交接班完成')}
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="${safeT('close', '关闭')}"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-6 col-md-3">
            <div class="card card-sheet p-2"><div class="small text-muted">${safeT('eod_started', '开始时间')}</div><div id="eod_done_started" class="fs-6 fw-semibold">-</div></div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card card-sheet p-2"><div class="small text-muted">${safeT('eod_ended', '结束时间')}</div><div id="eod_done_ended" class="fs-6 fw-semibold">-</div></div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card card-sheet p-2"><div class="small text-muted">${safeT('eod_expected_cash', '系统应有现金')}</div><div id="eod_done_expected" class="fs-5">€0.00</div></div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card card-sheet p-2"><div class="small text-muted">${safeT('eod_counted_cash', '清点现金')}</div><div id="eod_done_counted" class="fs-5">€0.00</div></div>
          </div>
          <div class="col-12">
            <div class="alert d-flex justify-content-between align-items-center" id="eod_done_diff_wrap">
              <div class="fw-bold">${safeT('eod_cash_diff', '现金差异')}</div>
              <div class="fs-5 fw-bold" id="eod_done_diff">€0.00</div>
            </div>
          </div>
        </div>
        <div id="eod_done_sync_note" class="mt-2 small text-muted"></div>
      </div>
      <div class="modal-footer flex-wrap gap-2">
        <button type="button" id="btnEodCompletedPrint" class="btn btn-info" disabled>
          <i class="bi bi-printer me-2"></i>${safeT('eod_print_report', '打印交接班报告')}
        </button>
        <button type="button" id="btnEodCompletedHistory" class="btn btn-outline-secondary">
          ${safeT('eod_view_history', '查看历史')}
        </button>
        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">${safeT('close', '关闭')}</button>
      </div>
    </div>
  </div>
</div>`);
    // 绑定事件
    getEl('btnEodCompletedPrint')?.addEventListener('click', () => {
        if (currentReportId) {
            handlePrintEodReport(currentReportId);
        } else {
            toast('Error: Report ID not found for printing.');
        }
    });
    getEl('btnEodCompletedHistory')?.addEventListener('click', openEodHistory);
}

/**
 * 显示“交接班完成”弹窗
 * @param {object} eod - EOD 报告数据
 */
function showCompletedModal(eod) {
    ensureCompletedModal();
    setText('eod_done_started', escapeHtml(eod.started_at));
    setText('eod_done_ended', escapeHtml(eod.ended_at));
    setText('eod_done_expected', fmtEUR(eod.expected_cash ?? 0));
    setText('eod_done_counted', fmtEUR(eod.counted_cash ?? 0));
    const diff = to2(eod.cash_diff ?? 0);
    const diffEl = getEl('eod_done_diff');
    const wrapEl = getEl('eod_done_diff_wrap');
    if (diffEl && wrapEl) {
        diffEl.textContent = fmtEUR(diff);
        wrapEl.classList.remove('alert-success', 'alert-danger', 'alert-secondary');
        if (diff > 0) wrapEl.classList.add('alert-success');
        else if (diff < 0) wrapEl.classList.add('alert-danger');
        else wrapEl.classList.add('alert-secondary');
    }
    const note = getEl('eod_done_sync_note');
    if (note) note.textContent = '';
    const btnPrint = getEl('btnEodCompletedPrint');
    if (btnPrint) btnPrint.disabled = true;

    const m = bootstrap.Modal.getOrCreateInstance(getEl('eodCompletedModal'));
    m.show();
}

/**
 * 数据库同步确认（用于交接班完成弹窗）
 * @param {object} localEod - 从 shift.js 事件接收的 EOD 数据
 * @param {string|number} eodId - EOD 记录 ID
 * @returns {Promise<object>}
 */
async function confirmEodSynced(localEod, eodId) {
    try {
        let serverEod = null;

        if (eodId) {
            const j = await apiGetJSON(`./api/pos_api_gateway.php?res=eod&act=get&eod_id=${encodeURIComponent(eodId)}`);
            if (j.status === 'success' && j.data && j.data.item) {
                serverEod = j.data.item;
            }
        }
        if (!serverEod) {
            const j = await apiGetJSON('./api/pos_api_gateway.php?res=eod&act=list&limit=1');
            if (j.status === 'success' && Array.isArray(j.data?.items) && j.data.items.length > 0) {
                serverEod = j.data.items[0];
            }
        }
        if (!serverEod) return { status: 'unknown' };

        currentReportId = serverEod.id || null; // 存储ID以便打印

        const keys = ['starting_float', 'cash_sales', 'cash_in', 'cash_out', 'cash_refunds', 'expected_cash', 'counted_cash', 'cash_diff'];
        const mismatch = keys.some(k => to2(localEod[k]) !== to2(serverEod[k]));

        // 覆盖“完成弹窗”的显示为数据库最终值
        showCompletedModal({
            started_at: serverEod.started_at,
            ended_at: serverEod.ended_at,
            expected_cash: serverEod.expected_cash,
            counted_cash: serverEod.counted_cash,
            cash_diff: serverEod.cash_diff
        });

        return mismatch ? { status: 'mismatch', serverEod } : { status: 'ok' };
    } catch (e) {
        console.error(e);
        return { status: 'unknown', error: e };
    }
}

/**
 * 初始化：监听来自 shift.js 的广播
 */
export function initShiftHandoverListener() {
    document.addEventListener('pos:eod-finished', async (evt) => {
        const detail = evt.detail || {};
        const localEod = detail.eod || null;
        const eodId = detail.eod_id || null;
        if (!localEod) return;

        // 先关闭其它可能存在的弹窗
        document.querySelectorAll('.modal.show').forEach(el => {
            try { bootstrap.Modal.getInstance(el)?.hide(); } catch (_) { }
        });

        // 1. 显示“完成”大弹窗（即时反馈）
        showCompletedModal(localEod);

        // 2. 数据库同步确认（覆盖显示、开启打印按钮）
        const verified = await confirmEodSynced(localEod, eodId);
        const note = getEl('eod_done_sync_note');
        if (verified.status === 'ok') {
            if (note) { note.textContent = t('eod_synced_ok'); note.classList.remove('text-danger'); note.classList.add('text-success'); }
            getEl('btnEodCompletedPrint')?.removeAttribute('disabled');
        } else if (verified.status === 'mismatch') {
            if (note) { note.textContent = t('eod_synced_mismatch'); note.classList.remove('text-success'); note.classList.add('text-danger'); }
            getEl('btnEodCompletedPrint')?.removeAttribute('disabled');
        } else {
            if (note) { note.textContent = t('eod_synced_unknown'); note.classList.remove('text-success'); note.classList.add('text-danger'); }
        }
    });
}