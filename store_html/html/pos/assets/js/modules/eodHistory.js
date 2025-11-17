/**
 * eodHistory.js - EOD 历史记录模块
 * * 职责:
 * 1. 显示“交接班历史”弹窗 (#eodHistoryModal)
 * 2. 加载和渲染历史列表
 * 3. 处理列表内的“打印”按钮
 *
 * (逻辑从 eod.js 迁移而来)
 */

import { STATE } from '../state.js';
import { fetchEodPrintData } from '../api.js';
import { printReceipt } from './print.js';
// 导入 eod.js 中的打印报告函数 (作为 eod.js 的依赖)
import { handlePrintEodReport } from './eod.js'; 
import {
    t, fmtEUR, toast, safeT, getEl,
    apiGetJSON, setText, to2, escapeHtml
} from '../utils.js';

/**
 * 确保“历史弹窗”的 DOM 存在
 */
function ensureHistoryModal() {
    if (getEl('eodHistoryModal')) return;
    document.body.insertAdjacentHTML('beforeend', `
<div class="modal fade" id="eodHistoryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content modal-sheet">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>${safeT('eod_view_history', '交接班历史')}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="${safeT('close', '关闭')}"></button>
      </div>
      <div class="modal-body">
        <div id="eodHistoryLoading" class="text-center py-4">
          <div class="spinner-border"></div>
        </div>
        <div class="table-responsive d-none" id="eodHistoryWrap">
          <table class="table table-sm align-middle" id="eodHistoryTable">
            <thead>
              <tr>
                <th style="white-space:nowrap">开始时间</th>
                <th style="white-space:nowrap">结束时间</th>
                <th class="text-end">系统应有</th>
                <th class="text-end">清点现金</th>
                <th class="text-end">现金差异</th>
                <th class="text-end" style="white-space:nowrap">操作</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">${safeT('close', '关闭')}</button>
      </div>
    </div>
  </div>
</div>`);
}

/**
 * 渲染历史列表
 * @param {Array} items - EOD 记录数组
 */
function renderEodHistory(items) {
    const wrap = getEl('eodHistoryWrap');
    const loading = getEl('eodHistoryLoading');
    const tbody = getEl('eodHistoryTable')?.querySelector('tbody');
    if (!tbody || !wrap || !loading) return;
    
    tbody.innerHTML = '';
    items.forEach(row => {
        const diff = to2(row.cash_diff || 0);
        const diffClass = diff < 0 ? 'text-danger' : (diff > 0 ? 'text-success' : '');
        const tr = document.createElement('tr');
        tr.innerHTML = `
      <td>${escapeHtml(row.started_at)}</td>
      <td>${escapeHtml(row.ended_at)}</td>
      <td class="text-end">${fmtEUR(to2(row.expected_cash || 0))}</td>
      <td class="text-end">${fmtEUR(to2(row.counted_cash || 0))}</td>
      <td class="text-end fw-semibold ${diffClass}">${fmtEUR(diff)}</td>
      <td class="text-end">
        <button class="btn btn-outline-primary btn-sm btn-eod-row-print" data-id="${row.id}">
          <i class="bi bi-printer me-1"></i>打印
        </button>
      </td>`;
        tbody.appendChild(tr);
    });

    loading.classList.add('d-none');
    wrap.classList.remove('d-none');
}

/**
 * (已废弃) 旧的 loadEodList，仅用于 eod.js 内部
 * @deprecated
 */
export async function loadEodList() {
    try {
        const j = await apiGetJSON('./api/pos_api_gateway.php?res=eod&act=list&limit=20');
        if (j.status !== 'success') { toast(j.message || '加载交接班记录失败'); return; }
        // renderEodList(j.data?.items || []); // 不再由此函数渲染
    } catch (e) { toast('网络错误：' + e.message); }
}

/**
 * 打开并加载“历史”弹窗
 */
export async function openEodHistory() {
    ensureHistoryModal();
    const modal = bootstrap.Modal.getOrCreateInstance(getEl('eodHistoryModal'));
    getEl('eodHistoryLoading').classList.remove('d-none');
    getEl('eodHistoryWrap').classList.add('d-none');
    modal.show();

    try {
        const j = await apiGetJSON('./api/pos_api_gateway.php?res=eod&act=list&limit=50');
        if (j.status !== 'success') throw new Error(j.message || '加载失败');
        renderEodHistory(j.data?.items || []);
    } catch (e) {
        getEl('eodHistoryLoading').innerHTML =
            `<div class="alert alert-danger mb-0">加载失败：${e.message}</div>`;
    }
}

// 绑定列表内的“打印”按钮
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.btn-eod-row-print');
    if (!btn) return;
    const id = btn.getAttribute('data-id');
    btn.disabled = true;
    try {
        // 调用 eod.js 导出的打印函数
        await handlePrintEodReport(id); 
    } catch (err) {
        toast(`${safeT('print_failed', '打印失败')}：` + err.message);
    } finally {
        btn.disabled = false;
    }
});