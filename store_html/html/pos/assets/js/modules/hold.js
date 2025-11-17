import { STATE } from '../state.js';
import { t, fmtEUR, toast } from '../utils.js';
import { calculatePromotions } from './cart.js';

/**
 * Version: 2.0.1
 * 打开挂单面板
 * 隐藏操作面板，显示挂单面板，并立即刷新列表
 */
export async function openHoldOrdersPanel() {
    const opsOffcanvas = bootstrap.Offcanvas.getInstance(document.getElementById('opsOffcanvas'));
    if (opsOffcanvas) {
        opsOffcanvas.hide();
    }
    
    // 确保在显示新面板之前，旧的已完全隐藏
    const opsOffcanvasEl = document.getElementById('opsOffcanvas');
    if (opsOffcanvasEl.classList.contains('show')) {
        await new Promise(resolve => opsOffcanvasEl.addEventListener('hidden.bs.offcanvas', resolve, { once: true }));
    }

    const holdOffcanvas = new bootstrap.Offcanvas(document.getElementById('holdOrdersOffcanvas'));
    holdOffcanvas.show();
    await refreshHeldOrdersList();
}

/**
 * Version: 2.0.1
 * 刷新挂起的订单列表
 * 从后端API获取数据并渲染到UI
 */
export async function refreshHeldOrdersList() {
    const listContainer = document.getElementById('held_orders_list');
    listContainer.innerHTML = '<div class="text-center p-4"><div class="spinner-border spinner-border-sm"></div></div>';
    try {
        // [FIX] 修复 API 路径
        const response = await fetch(`api/pos_api_gateway.php?res=hold&act=list&sort=${STATE.holdSortBy}`, { credentials: 'same-origin' });
        const result = await response.json();

        if (result.status === 'success') {
            if (!result.data || result.data.length === 0) {
                listContainer.innerHTML = `<div class="alert alert-sheet">${t('no_held_orders')}</div>`;
                return;
            }

            let html = '<div class="list-group list-group-flush">';
            result.data.forEach(order => {
                const noteDisplay = order.note ? order.note.replace(/</g, "&lt;").replace(/>/g, "&gt;") : `<em class="text-muted">(No Note)</em>`;
                const timeDisplay = new Date(order.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                html += `
                    <div class="list-group-item">
                        <div class="d-flex w-100 justify-content-between">
                            <div>
                                <h6 class="mb-1">${noteDisplay}</h6>
                                <small class="text-muted">${timeDisplay}</small>
                            </div>
                            <div class="text-end">
                                <strong class="d-block">${fmtEUR(order.total_amount)}</strong>
                                <button class="btn btn-sm btn-brand mt-1 restore-hold-btn" data-id="${order.id}">${t('restore')}</button>
                            </div>
                        </div>
                    </div>`;
            });
            html += '</div>';
            listContainer.innerHTML = html;
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        listContainer.innerHTML = `<div class="alert alert-danger">${error.message}</div>`;
    }
}

/**
 * Version: 2.0.1
 * 创建一个新的挂起单
 */
export async function createHoldOrder() {
    const noteInput = document.getElementById('hold_order_note_input');
    const note = noteInput.value.trim();

    // 强制要求填写备注
    if (!note) {
        toast(t('note_is_required') || '备注不能为空');
        noteInput.focus();
        return;
    }
    // 购物车不能为空
    if (STATE.cart.length === 0) {
        toast(t('tip_empty_cart'));
        bootstrap.Offcanvas.getInstance(document.getElementById('holdOrdersOffcanvas'))?.hide();
        return;
    }

    try {
        // [FIX] 修复 API 路径
        const response = await fetch('api/pos_api_gateway.php?res=hold&act=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ note: note, cart: STATE.cart }) // action 已在 URL 中
        });
        const result = await response.json();

        if (result.status === 'success') {
            toast('当前订单已挂起');
            // 清空购物车并更新UI
            STATE.cart = [];
            calculatePromotions(); 
            // 关闭挂单面板并清空输入框
            bootstrap.Offcanvas.getInstance(document.getElementById('holdOrdersOffcanvas'))?.hide();
            noteInput.value = '';
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        toast((t('hold_failed') || '挂单失败') + ': ' + error.message);
    }
}

/**
 * Version: 2.0.1
 * 恢复一个挂起的订单
 */
export async function restoreHeldOrder(id) {
    // 如果当前购物车不为空，则弹出系统原生确认框 (根据计划书2.1，未来应替换为自定义模态框)
    if (STATE.cart.length > 0) {
        if (!confirm('当前购物车非空，恢复挂起单将覆盖当前内容，确定吗？')) {
            return;
        }
    }

    try {
        // [FIX] 修复 API 路径
        const response = await fetch(`api/pos_api_gateway.php?res=hold&act=restore&id=${id}`, { credentials: 'same-origin' });
        const result = await response.json();

        if (result.status === 'success') {
            // 将购物车状态替换为恢复的订单数据
            STATE.cart = result.data;
            calculatePromotions();
            
            // 关闭挂单面板并给出提示
            bootstrap.Offcanvas.getInstance(document.getElementById('holdOrdersOffcanvas'))?.hide();
            toast('订单已恢复');
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        toast((t('restore_failed') || '恢复失败') + ': ' + error.message);
    }
}