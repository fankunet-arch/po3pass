/**
 * POS 交易模块
 * Revision: 1.1 (A3 UTC DISPLAY FIX)
 *
 * [A3 UTC DISPLAY FIX]:
 * - 修正了 `refreshTxnList` 和 `showTxnDetails` 中的 `new Date()` 调用。
 * - 之前: PHP 错误地发送了 `fmt_local`，导致 JS 意外“正确”工作。
 * - 修正: PHP 现在发送原始 UTC 字符串 (e.g., "2025-11-11 10:30:00")。
 * JS 必须使用 `new Date(string.replace(' ', 'T') + 'Z')` 来强制将其解析为 UTC，
 * 然后 `toLocaleString()` 才能正确转换为本地时间。
 */
import { t, fmtEUR, toast } from '../utils.js';
import { STATE } from '../state.js';

let refundConfirmModal = null;
let currentActionContext = null; 

export function initializeRefundModal(modalInstance) {
    refundConfirmModal = modalInstance;
    const confirmButton = document.getElementById('btn_confirm_refund_action');
    if (confirmButton) {
        confirmButton.addEventListener('click', () => {
            if (currentActionContext) {
                console.log(`Confirmed action: ${currentActionContext.actionType} for invoice ID: ${currentActionContext.invoiceId}`);
                toast(`模拟操作：${currentActionContext.actionType === 'cancel' ? t('cancel_invoice') : t('correct_invoice')} (ID: ${currentActionContext.invoiceId})`); 
                refundConfirmModal.hide();
                bootstrap.Modal.getInstance(document.getElementById('txnDetailModal'))?.hide();
                refreshTxnList(); 
            }
        });
    }
}

function requestRefundActionConfirmation(actionType, invoiceId, invoiceNumber) {
    if (!refundConfirmModal) {
        toast('错误：确认模态框未初始化');
        return;
    }
    currentActionContext = { actionType, invoiceId };
    const modalTitle = document.getElementById('refundConfirmModalLabel');
    const modalBody = document.getElementById('refundConfirmModalBody');
    const confirmButton = document.getElementById('btn_confirm_refund_action');

    if (actionType === 'cancel') {
        modalTitle.textContent = t('confirm_cancel_invoice_title');
        modalBody.textContent = t('confirm_cancel_invoice_body').replace('{invoiceNumber}', invoiceNumber);
        confirmButton.textContent = t('confirm_cancel_invoice_confirm');
        confirmButton.classList.remove('btn-warning');
        confirmButton.classList.add('btn-danger');
    } else if (actionType === 'correct') {
        modalTitle.textContent = t('confirm_correct_invoice_title');
        modalBody.textContent = t('confirm_correct_invoice_body').replace('{invoiceNumber}', invoiceNumber);
        confirmButton.textContent = t('confirm_correct_invoice_confirm');
        confirmButton.classList.remove('btn-danger');
        confirmButton.classList.add('btn-warning');
    }
    refundConfirmModal.show();
}


function setupDatePickers() {
    const startDateInput = document.getElementById('txn_start_date');
    const endDateInput = document.getElementById('txn_end_date');
    const today = new Date().toISOString().split('T')[0];

    startDateInput.max = today;
    endDateInput.max = today;

    function updateDateLimits() {
        const startDateValue = startDateInput.value;
        if (!startDateValue) return;

        const startDate = new Date(startDateValue);

        endDateInput.min = startDateValue;

        const maxEndDate = new Date(startDate);
        maxEndDate.setMonth(maxEndDate.getMonth() + 1);

        const finalMaxEndDate = new Date(Math.min(maxEndDate, new Date(today)));
        endDateInput.max = finalMaxEndDate.toISOString().split('T')[0];
    }

    function updateStartDateLimits() {
        const endDateValue = endDateInput.value;
        if(!endDateValue) return;

        const endDate = new Date(endDateValue);

        startDateInput.max = endDateValue;

        const minStartDate = new Date(endDate);
        minStartDate.setMonth(minStartDate.getMonth() - 1);
        startDateInput.min = minStartDate.toISOString().split('T')[0];
    }

    startDateInput.addEventListener('change', updateDateLimits);
    endDateInput.addEventListener('change', updateStartDateLimits);

    // --- START: CLICK-TO-OPEN-PICKER FIX ---
    const triggerPicker = (e) => {
        try {
            // This is the standard method to programmatically open the picker
            e.target.showPicker();
        } catch (error) {
            // showPicker() might not be supported in some older browsers.
            // In those cases, the default browser behavior will have to suffice.
            console.warn('Element.showPicker() is not supported in this browser.');
        }
    };
    startDateInput.addEventListener('click', triggerPicker);
    endDateInput.addEventListener('click', triggerPicker);
    // --- END: CLICK-TO-OPEN-PICKER FIX ---

    updateDateLimits();
    updateStartDateLimits();
}

function validateDateRange() {
    const startDateInput = document.getElementById('txn_start_date');
    const endDateInput = document.getElementById('txn_end_date');
    const startDate = startDateInput.value;
    const endDate = endDateInput.value;

    if (!startDate || !endDate) {
        toast(t('validation_select_dates'));
        return false;
    }

    const today = new Date();
    today.setHours(23, 59, 59, 999);

    const selectedStartDate = new Date(startDate);
    const selectedEndDate = new Date(endDate);

    if (selectedEndDate < selectedStartDate) {
        toast(t('validation_end_date_before_start'));
        return false;
    }
    if (selectedEndDate > today) {
        toast(t('validation_end_date_in_future'));
        return false;
    }

    const diffTime = Math.abs(selectedEndDate - selectedStartDate);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

    if (diffDays > 31) { 
        toast(t('validation_date_range_too_large'));
        return false;
    }

    return true;
}

export async function openTxnQueryPanel() {
    const opsOffcanvasEl = document.getElementById('opsOffcanvas');
    if (opsOffcanvasEl) {
        const opsOffcanvas = bootstrap.Offcanvas.getInstance(opsOffcanvasEl);
        if (opsOffcanvas) opsOffcanvas.hide();
    }

    const container = document.getElementById('txn_list_container');
    const txnQueryOffcanvasEl = document.getElementById('txnQueryOffcanvas');

    if (!container.querySelector('#txn_filter_form')) {
        const today = new Date().toISOString().split('T')[0];
        const filterHtml = `
            <div id="txn_filter_form" class="p-3 border-bottom">
                <div class="row g-2 align-items-end">
                    <div class="col">
                        <label for="txn_start_date" class="form-label small">${t('start_date')}</label>
                        <input type="date" class="form-control" id="txn_start_date" value="${today}">
                    </div>
                    <div class="col">
                        <label for="txn_end_date" class="form-label small">${t('end_date')}</label>
                        <input type="date" class="form-control" id="txn_end_date" value="${today}">
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-primary" id="btn_filter_txn">${t('query')}</button>
                    </div>
                </div>
            </div>
            <div id="txn_list_target"></div>
        `;
        container.innerHTML = filterHtml;

        document.getElementById('btn_filter_txn').addEventListener('click', () => {
            if (validateDateRange()) {
                refreshTxnList();
            }
        });

        setupDatePickers();
    }

    const txnQueryOffcanvas = new bootstrap.Offcanvas(txnQueryOffcanvasEl);

    txnQueryOffcanvasEl.addEventListener('shown.bs.offcanvas', () => {
        refreshTxnList();
    }, { once: true });

    txnQueryOffcanvas.show();
}

/**
 * [A3 UTC DISPLAY FIX]
 * 修正此函数以正确解析来自后端的原始 UTC 字符串。
 */
async function refreshTxnList() {
    const listTarget = document.getElementById('txn_list_target');
    const startDate = document.getElementById('txn_start_date').value;
    const endDate = document.getElementById('txn_end_date').value;

    listTarget.innerHTML = '<div class="text-center p-4"><div class="spinner-border spinner-border-sm"></div></div>';

    // [FIX] 修复 API 路径
    let apiUrl = `api/pos_api_gateway.php?res=transaction&act=list&start_date=${startDate}&end_date=${endDate}`;

    try {
        const response = await fetch(apiUrl, { credentials: 'same-origin' });
        if (!response.ok) { // Catches network-level errors (e.g., 404, 500)
            throw new Error(`Server error: ${response.statusText}`);
        }
        const result = await response.json();
        
        if (result.status === 'success') {
            if (!result.data || result.data.length === 0) {
                listTarget.innerHTML = `<div class="alert alert-sheet m-3">${t('no_transactions')}</div>`;
                return;
            }
            let html = '<div class="list-group list-group-flush">';
            result.data.forEach(txn => {
                // [A3 UTC DISPLAY FIX]
                // txn.issued_at 是 "2025-11-11 10:30:00.123456" (UTC, 来自DB)
                // 1. 强制解析为 UTC
                const utcString = String(txn.issued_at).replace(' ', 'T') + 'Z';
                const date = new Date(utcString);
                // 2. 转换为本地时间显示
                const time = date.toLocaleString(STATE.lang === 'zh' ? 'zh-CN' : 'es-ES', { hour12: false, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });

                const statusClass = txn.status === 'CANCELLED' ? 'text-danger' : '';
                const statusText = txn.status === 'CANCELLED' ? `(${t('cancelled')})` : '';
                const invoiceNumber = `${txn.series || ''}-${txn.number || txn.id}`;
                html += `<a href="#" class="list-group-item list-group-item-action txn-item" data-id="${txn.id}"><div class="d-flex w-100 justify-content-between"><h6 class="mb-1">${invoiceNumber} <small class="${statusClass}">${statusText}</small></h6><strong>${fmtEUR(txn.final_total)}</strong></div><small>${time}</small></a>`;
            });
            html += '</div>';
            listTarget.innerHTML = html;
        } else { 
            // Catches application-level errors (status: 'error')
            throw new Error(result.message || 'Failed to load transactions'); 
        }
    } catch (error) { 
        // Uniformly handles all errors and displays them in the UI
        console.error("Failed to refresh transaction list:", error);
        listTarget.innerHTML = `<div class="alert alert-danger m-3">${error.message}</div>`;
        toast(`Error: ${error.message}`); // Also show a toast notification
    }
}

/**
 * [A3 UTC DISPLAY FIX]
 * 修正此函数以正确解析来自后端的原始 UTC 字符串。
 */
export async function showTxnDetails(id) {
    const detailModalEl = document.getElementById('txnDetailModal');
    const detailModal = new bootstrap.Modal(detailModalEl);
    const modalTitleEl = document.getElementById('txn_detail_title');
    const modalBodyEl = document.getElementById('txn_detail_body');
    const modalFooterEl = document.getElementById('txn_detail_footer');

    modalTitleEl.textContent = `${t('loading')}...`;
    modalBodyEl.innerHTML = '<div class="text-center p-4"><div class="spinner-border"></div></div>';
    modalFooterEl.innerHTML = `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${t('close')}</button>`;
    detailModal.show();

    try {
        // [FIX] 修复 API 路径
        const response = await fetch(`api/pos_api_gateway.php?res=transaction&act=get_details&id=${id}`, { credentials: 'same-origin' });
        const result = await response.json();
        if (result.status === 'success') {
            const d = result.data;
            const invoiceNumber = `${d.series || ''}-${d.number || d.id}`;
            let itemsHtml = '';
            (d.items || []).forEach(item => {
                let customs = {};
                try { customs = JSON.parse(item.customizations) || {}; } catch(e) {}
                const customText = `I:${customs.ice || 'N/A'} S:${customs.sugar || 'N/A'} +:${(customs.addons || []).join(',')}`;
                itemsHtml += `<tr><td>${item.item_name || '?'} <small class="text-muted">(${item.variant_name || '?'})</small><br><small class="text-muted">${customText}</small></td><td>${item.quantity || 0}</td><td>${fmtEUR(item.unit_price)}</td><td>${fmtEUR((item.unit_price || 0) * (item.quantity || 0))}</td></tr>`;
            });

            const statusBadge = `<span class="badge text-bg-${d.status === 'CANCELLED' ? 'danger':'success'}">${t(d.status.toLowerCase())}</span>`;
            
            // [A3 UTC DISPLAY FIX]
            // d.issued_at 是 "2025-11-11 10:30:00.123456" (UTC, 来自DB)
            // 1. 强制解析为 UTC
            const utcString = String(d.issued_at).replace(' ', 'T') + 'Z';
            const date = new Date(utcString);
            // 2. 转换为本地时间显示
            const timeDisplay = date.toLocaleString(STATE.lang === 'zh' ? 'zh-CN' : 'es-ES', { hour12: false, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });

            const bodyHtml = `
                <p><strong>${t('invoice_number')}:</strong> ${invoiceNumber}</p>
                <p><strong>${t('time')}:</strong> ${timeDisplay}</p>
                <p><strong>${t('cashier')}:</strong> ${d.cashier_name || 'N/A'}</p>
                <p><strong>${t('status')}:</strong> ${statusBadge}</p>
                <hr>
                <h5>${t('item_list')}</h5>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>${t('item')}</th><th>${t('qty')}</th><th>${t('unit_price')}</th><th>${t('total_price')}</th></tr></thead>
                        <tbody>${itemsHtml || `<tr><td colspan="4" class="text-center text-muted">${t('no_items')}</td></tr>`}</tbody>
                    </table>
                </div>
                <hr>
                <div class="text-end">
                    <div><small>${t('subtotal')}:</small> ${fmtEUR(d.taxable_base)}</div>
                    <div><small>${t('vat')}:</small> ${fmtEUR(d.vat_amount)}</div>
                    <div class="fs-5 fw-bold">${t('total')}: ${fmtEUR(d.final_total)}</div>
                </div>`;

            modalTitleEl.textContent = `${t('invoice_details')}: ${invoiceNumber}`;
            modalBodyEl.innerHTML = bodyHtml;

            let footerHtml = `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${t('close')}</button>`;
            if (d.status === 'ISSUED') {
                footerHtml += `
                    <button type="button" class="btn btn-warning btn-correct-invoice" data-id="${d.id}" data-number="${invoiceNumber}">
                        <i class="bi bi-pencil-square"></i> ${t('correct_invoice')}
                    </button>
                    <button type="button" class="btn btn-danger btn-cancel-invoice" data-id="${d.id}" data-number="${invoiceNumber}">
                        <i class="bi bi-trash"></i> ${t('cancel_invoice')}
                    </button>
                `;
            }
            modalFooterEl.innerHTML = footerHtml;

        } else { throw new Error(result.message); }
    } catch (error) {
        modalBodyEl.innerHTML = `<div class="alert alert-danger">${error.message}</div>`;
        modalFooterEl.innerHTML = `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${t('close')}</button>`;
    }
}