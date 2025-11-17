/**
 * availability.js - 商品估清 (Sold Out) 模块
 * * 职责:
 * 1. 打开估清面板 (openAvailabilityPanel)
 * 2. 切换商品估清状态 (handleAvailabilityToggle)
 * 3. 处理开班时的估清决策 (Keep/Reset)
 *
 * (逻辑从 main.js 迁移而来)
 */

import { STATE } from '../state.js';
import { t, toast } from '../utils.js';
import { fetchInitialData } from '../api.js';
import { renderCategories, renderProducts } from '../ui.js';
import { checkShiftStatus } from './shift.js';

// [新] 处理估清决策：保持
export async function handleSoldOutDecisionKeep() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('soldOutDecisionModal'));
    if (modal) modal.hide();
    // 刷新主界面（加载商品列表，此时估清状态仍然是上一班的）
    await fetchInitialData();
    // 重新渲染UI
    renderCategories();
    renderProducts();
    await checkShiftStatus(); // 确保UI解锁
    toast('已保持估清状态');
}

// [新] 处理估清决策：重置
export async function handleSoldOutDecisionReset() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('soldOutDecisionModal'));
    if (modal) modal.hide();
    
    try {
        // 1. 调用API重置
        // [FIX] 修复 API 路径
        const response = await fetch('api/pos_api_gateway.php?res=availability&act=reset_all', { 
            method: 'POST', // 改为 POST 
            credentials: 'same-origin' 
        });
        const result = await response.json();
        if (result.status !== 'success') throw new Error(result.message);
        
        // 2. 重新加载所有数据（此时 is_sold_out 将全部为 0）
        await fetchInitialData();
        // 重新渲染UI
        renderCategories();
        renderProducts();
        await checkShiftStatus(); // 确保UI解锁
        toast('所有商品已重新上架');
        
    } catch (error) {
        toast('重置失败: ' + error.message);
        await checkShiftStatus(); // 即使失败也要解锁UI
    }
}

// [新] 打开估清面板
export async function openAvailabilityPanel() {
    const opsOffcanvas = bootstrap.Offcanvas.getInstance(document.getElementById('opsOffcanvas'));
    if (opsOffcanvas) opsOffcanvas.hide();

    const modal = new bootstrap.Modal(document.getElementById('availabilityModal'));
    const container = document.getElementById('availability_list_container');
    container.innerHTML = '<div class="text-center p-4"><div class="spinner-border"></div></div>';
    modal.show();

    try {
        // [FIX] 修复 API 路径
        const response = await fetch('api/pos_api_gateway.php?res=availability&act=get_all', { credentials: 'same-origin' });
        const result = await response.json();
        if (result.status !== 'success') throw new Error(result.message);

        const items = result.data;
        if (items.length === 0) {
            container.innerHTML = '<div class="alert alert-info">没有可管理的商品。</div>';
            return;
        }

        let html = '';
        items.forEach(item => {
            const name = STATE.lang === 'es' ? item.name_es : item.name_zh;
            const isSoldOut = parseInt(item.is_sold_out, 10) === 1;
            html += `
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <span>
                        <span class="fw-bold">${name}</span>
                        <small class="text-muted d-block">${item.product_code || 'N/A'}</small>
                    </span>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" 
                               data-id="${item.menu_item_id}" 
                               ${isSoldOut ? 'checked' : ''}>
                        <label class="form-check-label">${isSoldOut ? t('availability_toggle_on') : t('availability_toggle_off')}</label>
                    </div>
                </div>
            `;
        });
        container.innerHTML = html;

    } catch (error) {
        container.innerHTML = `<div class="alert alert-danger">${error.message}</div>`;
    }
}

// [新] 处理估清切换
export async function handleAvailabilityToggle(event) {
    const toggle = event.target;
    const label = toggle.nextElementSibling;
    const menu_item_id = parseInt(toggle.dataset.id, 10);
    const is_sold_out = toggle.checked ? 1 : 0;

    toggle.disabled = true;
    if (label) label.textContent = '保存中...';

    try {
        // [FIX] 修复 API 路径
        const response = await fetch('api/pos_api_gateway.php?res=availability&act=toggle', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                // action: 'toggle', // action 在 URL 中
                menu_item_id: menu_item_id,
                is_sold_out: is_sold_out
            })
        });
        const result = await response.json();
        if (result.status !== 'success') throw new Error(result.message);

        // 成功，更新本地 STATE
        const product = STATE.products.find(p => p.id === menu_item_id);
        if (product) {
            product.is_sold_out = is_sold_out;
        }
        
        // 重新渲染主界面的产品网格
        renderProducts(); 

    } catch (error) {
        toast('更新失败: ' + error.message);
        toggle.checked = !is_sold_out; // 恢复原状
    } finally {
        toggle.disabled = false;
        if (label) label.textContent = is_sold_out ? t('availability_toggle_on') : t('availability_toggle_off');
    }
}