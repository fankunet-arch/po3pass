/*
 * 文件名: /pos/assets/js/main.js
 * 描述: 移除了语言点击事件中多余的按钮更新代码（已统一到 ui.js 的 applyI18N 中）。
 * [GEMINI 2025-11-16]
 * 1. (架构) 移除了所有 "商品估清" (Availability) 相关的函数定义，它们已迁移到 'modules/availability.js'。
 * 2. (架构) 新增了对 'modules/availability.js' 的导入。
 * 3. (错误) 移除了 'eod.js' 模块中 'submitEodReportFinal' 和 'handlePrintEodReport' 的冗余导入，因为 eod.js 模块内部已自行处理事件绑定。
 * 4. (错误) 移除了 'bindEvents' 中对 '#btn_confirm_eod_final' 和 '#btn_print_eod_report' 的僵尸/冗余事件绑定。
 *
 * [GEMINI 2025-11-16 EOD 瘦身]
 * 1. (瘦身) 导入 eod.js (只含Summary), eodHistory.js, shiftHandover.js
 * 2. (瘦身) 移除 eod.js 的 openEodConfirmationModal, submitEodReportFinal 导入 (它们已变为 eod.js 内部函数)
 * 3. (瘦身) 在 DOMContentLoaded 中调用 initShiftHandoverListener()
 */
import { STATE, I18N } from './state.js';
import { applyI18N, renderCategories, renderProducts, renderAddons, openCustomize, updateCustomizePrice, refreshCartUI, updateMemberUI } from './ui.js';
import { fetchInitialData, fetchPrintTemplates, fetchEodPrintData } from './api.js';
import { t, toast } from './utils.js';
import { addToCart, updateCartItem, calculatePromotions } from './modules/cart.js';
import { openPaymentModal, addPaymentPart, updatePaymentState, initiatePaymentConfirmation, handleQuickCash } from './modules/payment.js';
import { openHoldOrdersPanel, createHoldOrder, restoreHeldOrder, refreshHeldOrdersList } from './modules/hold.js';
// [GEMINI 瘦身] 只导入 eod.js 的入口函数
import { openEodModal } from './modules/eod.js';
// [GEMINI 瘦身] 导入新模块
import { initShiftHandoverListener } from './modules/shiftHandover.js';
import { openEodHistory } from './modules/eodHistory.js'; // openEodHistory 由 shiftHandover 内部调用，但注册到 Ops 面板也很好
import { openTxnQueryPanel, showTxnDetails, initializeRefundModal } from './modules/transactions.js';
import { handleSettingChange } from './modules/settings.js';
import { findMember, unlinkMember, showCreateMemberModal, createMember } from './modules/member.js';
import { initializePrintSimulator, printReceipt } from './modules/print.js';
// [GHOST_SHIFT_FIX v5.2] 导入 handleForceStartShift 和 renderGhostShiftModalText
import { checkShiftStatus, initializeShiftModals, handleStartShift, handleForceStartShift, renderGhostShiftModalText } from './modules/shift.js';
// [GEMINI 架构] 导入新的估清模块
import { openAvailabilityPanel, handleAvailabilityToggle, handleSoldOutDecisionKeep, handleSoldOutDecisionReset } from './modules/availability.js';
// [优惠卡购买] 导入优惠卡模块
import { initDiscountCardEvents } from './modules/discountCard.js';

console.log("Modules imported successfully in main.js");

// [GHOST_SHIFT_FIX v5.2] I18N 文本已移至 state.js
// [重构] 移除了 I18N_NS 和 Object.assign(...) 逻辑。
// 所有字符串现在统一由 state.js (从 i18n-pack.js) 导入。


/**
 * Starts a clock to update the time in the navbar every second.
 */
function startClock() {
    const clockEl = document.getElementById('pos_clock');
    if (!clockEl) return;

    function tick() {
        clockEl.textContent = new Date().toLocaleTimeString('es-ES', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        });
    }
    tick();
    setInterval(tick, 1000);
}


function showUnclosedEodOverlay(unclosedDate) {
    const existingOverlay = document.getElementById('eod-block-overlay');
    if (existingOverlay) existingOverlay.remove();
    const overlay = document.createElement('div');
    overlay.id = 'eod-block-overlay';
    overlay.style.position = 'fixed';
    overlay.style.inset = '0';
    overlay.style.zIndex = '1060'; /* 比 Bootstrap Modal Backdrop 高，比 Modal Content 低一点 */
    overlay.style.backgroundColor = 'rgba(0, 0, 0, 0.65)'; /* 更暗的背景 */
    overlay.style.display = 'flex';
    overlay.style.alignItems = 'center';
    overlay.style.justifyContent = 'center';
    overlay.style.padding = '1rem';
    overlay.style.backdropFilter = 'blur(3px)'; /* 增加模糊效果 */

    overlay.innerHTML = `
        <div class="eod-block-content" style="background-color: var(--surface-1, #fff); color: var(--ink, #111); border-radius: 0.8rem; box-shadow: 0 8px 30px rgba(0,0,0,0.2); width: 100%; max-width: 500px; overflow: hidden;">
            <div class="eod-block-header" style="background-color: #ffc107; color: #000; padding: 0.8rem 1rem; font-size: 1.1rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                <i class="bi bi-exclamation-triangle-fill" style="font-size: 1.3rem;"></i>
                <span data-i18n-key="unclosed_eod_title">${t('unclosed_eod_title')}</span>
            </div>
            <div class="eod-block-body" style="padding: 1.5rem; text-align: center;">
                <h4 style="margin-bottom: 0.75rem; font-weight: 600;" data-i18n-key="unclosed_eod_header">${t('unclosed_eod_header')}</h4>
                <p style="margin-bottom: 0.5rem;" data-i18n-key="unclosed_eod_message">${t('unclosed_eod_message').replace('{date}', `<strong>${unclosedDate}</strong>`)}</p>
                <p class="text-muted small" style="margin-bottom: 0.5rem; color: #6c757d;" data-i18n-key="unclosed_eod_instruction">${t('unclosed_eod_instruction')}</p>
            </div>
            <div class="eod-block-footer" style="padding: 0.8rem 1rem; background-color: var(--surface-2, #f1f1f1); border-top: 1px solid var(--border, #ccc); display: flex; justify-content: space-between; gap: 0.5rem;">
                <button type="button" class="btn btn-secondary" disabled data-i18n-key="unclosed_eod_force_button">${t('unclosed_eod_force_button')}</button>
                <button type="button" class="btn btn-primary" id="btn_eod_now_overlay" data-i18n-key="unclosed_eod_button">${t('unclosed_eod_button')}</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);

    const btnEodNow = document.getElementById('btn_eod_now_overlay');
    if (btnEodNow) {
        btnEodNow.addEventListener('click', () => {
            overlay.remove();
            openEodModal();
        });
    }
}


function bindEvents() {
  console.log("Binding events...");

  const $document = $(document);

  // --- Language & Sync (using delegation) ---
  $document.on('click', '.dropdown-menu [data-lang]', function(e) {
      e.preventDefault();
      const newLang = $(this).data('lang');

      $('.dropdown-menu [data-lang]').removeClass('active');
      $(`.dropdown-menu [data-lang="${newLang}"]`).addClass('active');

      STATE.lang = newLang;
      localStorage.setItem('POS_LANG', STATE.lang);

      // 1. 翻译所有带 [data-i18n-key] 的元素
      // [FIX 4-4.png] applyI18N 现在会自动更新所有语言按钮的文本
      applyI18N();

      // 2. 重新渲染动态内容
      renderCategories();
      renderProducts();
      refreshCartUI();
      renderAddons();
      updateMemberUI();

      // 3. [GHOST_SHIFT_FIX v5.2] 重新渲染幽灵班次弹窗的 {user} 变量
      renderGhostShiftModalText();

      // [FIX 4-4.png] 移除以下代码，逻辑已移至 applyI18N
      // const langText = t(`lang_${newLang}`);
      // const flag = newLang === 'zh' ? '🇨🇳' : '🇪🇸';
      // 4. 更新所有语言切换按钮的显示
      // $('#lang_toggle').html(`<span class="flag">${flag}</span> ${langText}`);
      // $('#lang_toggle_modal').html(`<span class="flag">${flag}</span>`);
      // $('#lang_toggle_modal_force').html(`<span class="flag">${flag}</span>`);
   });

  $document.on('click', '#btn_sync', function() {
      $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
      initApplication().finally(() => $(this).prop('disabled', false).html('<i class="bi bi-arrow-repeat"></i>'));
  });

  // --- Product & Customization ---
  $document.on('click', '#category_scroller .nav-link', function() { STATE.active_category_key = $(this).data('cat'); renderCategories(); renderProducts(); });
  $document.on('input', '#search_input', renderProducts);
  $document.on('click', '#clear_search', () => { $('#search_input').val('').trigger('input'); });
  $document.on('click', '.product-card', function() { openCustomize($(this).data('id')); });
  $document.on('change', 'input[name="variant_selector"]', updateCustomizePrice);
  $document.on('click', '#addon_list .addon-chip', function() { $(this).toggleClass('active'); updateCustomizePrice(); });
  $document.on('change', 'input[name="ice"], input[name="sugar"]', updateCustomizePrice);
  $document.on('click', '#btn_add_to_cart', addToCart);

  // --- Cart ---
  $('#cartOffcanvas').on('show.bs.offcanvas', () => { calculatePromotions(); updateMemberUI(); });
  $document.on('click', '#cart_items [data-act]', function() { updateCartItem($(this).data('id'), $(this).data('act')); });
  $document.on('click', '#apply_coupon_btn', () => calculatePromotions(true));
  $document.on('click', '#apply_points_btn', () => calculatePromotions());

  // --- Payment ---
  $document.on('click', '#btn_cart_checkout', openPaymentModal);
  $document.on('click', '#btn_confirm_payment', initiatePaymentConfirmation);
  $document.on('click', '[data-pay-method]', function() { addPaymentPart($(this).data('pay-method')); });
  $document.on('click', '.remove-part-btn', function() { $(this).closest('.payment-part').remove(); updatePaymentState(); });
  $document.on('input', '.payment-part-input', updatePaymentState);
  // NEW: Event listener for quick cash buttons
  $document.on('click', '.btn-quick-cash', function() { handleQuickCash($(this).data('value')); });


  // --- Ops Panel & Modals ---
  $document.on('click', '#btn_open_eod', openEodModal);
  $document.on('click', '#btn_open_holds', openHoldOrdersPanel);
  $document.on('click', '#btn_open_txn_query', openTxnQueryPanel);
  $document.on('click', '#btn_open_shift_end', () => { new bootstrap.Modal(document.getElementById('endShiftModal')).show(); });

  // --- Hold ---
  $document.on('click', '#btn_hold_current_cart', function() { if (STATE.cart.length === 0) { toast(t('tip_empty_cart')); return; } bootstrap.Offcanvas.getInstance('#cartOffcanvas')?.hide(); setTimeout(() => $('#hold_order_note_input').focus(), 400); });
  $document.on('click', '#btn_create_new_hold', createHoldOrder);
  $document.on('click', '.restore-hold-btn', function(e) { e.preventDefault(); restoreHeldOrder($(this).data('id')); });
  $document.on('click', '#holdOrdersOffcanvas .dropdown-item', function(e) { e.preventDefault(); STATE.holdSortBy = $(this).data('sort'); const sortKey = STATE.holdSortBy === 'time_desc' ? 'sort_by_time' : 'sort_by_amount'; $('#holdOrdersOffcanvas .dropdown-toggle').html(`<i class="bi bi-sort-down"></i> ${t(sortKey)}`); refreshHeldOrdersList(); });

  // --- EOD ---
  // [GEMINI 瘦身] 移除了所有 eod.js 的内部事件绑定

  // --- Txn Query & Refund/Cancel ---
  $document.on('click', '.txn-item', function(e) { e.preventDefault(); showTxnDetails($(this).data('id')); });
  $document.on('click', '.btn-cancel-invoice', function() { const id = $(this).data('id'); const num = $(this).data('number'); requestRefundActionConfirmation('cancel', id, num); });
  $document.on('click', '.btn-correct-invoice', function() { const id = $(this).data('id'); const num = $(this).data('number'); requestRefundActionConfirmation('correct', id, num); });

  // --- Member ---
  $document.on('click', '#btn_find_member', findMember);
  $document.on('click', '#btn_unlink_member', unlinkMember);
  // [FIX 2.3] 修正创建按钮监听，传递 null 作为 memberData
  $document.on('click', '#member_section .btn-create-member, #btn_show_create_member', function(e) { e.preventDefault(); showCreateMemberModal($('#member_search_phone').val(), null); });
  // [FIX 2.3] 新增详情按钮监听
  $document.on('click', '#btn_edit_member', function(e) { e.preventDefault(); if (STATE.activeMember) { showCreateMemberModal(null, STATE.activeMember); } });

  $document.on('submit', '#form_create_member', function(e) {
      e.preventDefault();
      // [FIX 2.3] 检查按钮类型，防止在“详情”模式下提交
      const submitBtn = $(this).find('button[type="submit"]');
      if (submitBtn.length === 0) { // 如果按钮被改成了 type="button" (详情模式)
          return;
      }
      createMember({ phone_number: $('#member_phone').val(), first_name: $('#member_firstname').val(), last_name: $('#member_lastname').val(), email: $('#member_email').val(), birthdate: $('#member_birthdate').val() });
  });

  // --- [B1.4 PASS] START: Bind Pass Session Buttons ---
  $document.on('click', '.btn-start-pass-redeem', function() {
      const passId = parseInt($(this).data('pass-id'));
      if (!STATE.activeMember || !STATE.activeMember.passes) return;

      const pass = STATE.activeMember.passes.find(p => p.pass_id === passId);
      if (pass) {
          STATE.activePassSession = pass;
          STATE.cart = []; // 清空购物车
          calculatePromotions(); // 刷新购物车UI (会调用 refreshCartUI)
          updateMemberUI();      // 刷新会员UI (显示“正在核销”)
          renderCategories();    // 刷新分类 (禁用)
          renderProducts();      // 刷新产品 (只显示白名单)
          toast(t('pass_session_toast_enter'));
          bootstrap.Offcanvas.getInstance('#cartOffcanvas')?.hide();
      }
  });

  $document.on('click', '#btn_exit_pass_mode', function() {
      STATE.activePassSession = null;
      STATE.cart = []; // 清空购物车
      calculatePromotions(); // 刷新购物车UI
      updateMemberUI();      // 刷新会员UI (恢复正常)
      renderCategories();    // 刷新分类 (启用)
      renderProducts();      // 刷新产品 (恢复正常)
      toast(t('pass_session_toast_exit'));
  });
  // --- [B1.4 PASS] END ---

  // --- [GEMINI GHOST_SHIFT_FIX] START: Robust Shift Management Event Binding ---
  $document.on('submit', '#start_shift_form', handleStartShift);
  $document.on('submit', '#force_start_shift_form', handleForceStartShift);
  // --- [GEMINI GHOST_SHIFT_FIX] END ---

  // --- Settings ---
  $('#settingsOffcanvas input').on('change', handleSettingChange);

  // --- [GEMINI SIF_DR_FIX] START: Bind SIF Declaration Button ---
  $document.on('click', '#btn_show_sif_declaration', function() {
      const modalEl = document.getElementById('sifDeclarationModal');
      const contentEl = document.getElementById('sif_declaration_content');
      if (modalEl && contentEl) {
          contentEl.textContent = STATE.sifDeclaration || 'Declaración no cargada o no definida.';
          const modal = new bootstrap.Modal(modalEl);
          modal.show();
      } else {
          toast('Error: SIF Modal not found.');
      }
  });
  // --- [GEMINI SIF_DR_FIX] END ---

  // --- [估清 需求 1 & 3] ---
  $document.on('click', '#btn_open_availability_panel', openAvailabilityPanel);
  $document.on('change', '#availability_list_container .form-check-input', handleAvailabilityToggle);
  $document.on('click', '#btn_sold_out_decision_keep', handleSoldOutDecisionKeep);
  $document.on('click', '#btn_sold_out_decision_reset', handleSoldOutDecisionReset);
  // --- [估清] 结束 ---

  // Pass purchase success modal cleanup
  const cardPurchaseSuccessModal = document.getElementById('cardPurchaseSuccessModal');
  if (cardPurchaseSuccessModal) {
      cardPurchaseSuccessModal.addEventListener('hidden.bs.modal', () => {
          if (STATE.pendingPassReset) {
              unlinkMember();
              STATE.cart = [];
              calculatePromotions();
              STATE.pendingPassReset = false;
              // Navigate back to home by reloading the page
              window.location.reload();
          }
      });
  }


  console.log("Event bindings complete.");
}

// [GEMINI 架构] 移除所有估清函数 (handleSoldOutDecisionKeep, handleSoldOutDecisionReset,
// openAvailabilityPanel, handleAvailabilityToggle)，它们已迁移到 'modules/availability.js'

async function initApplication() {
    console.log("initApplication started.");
    try {
        console.log("Checking EOD status...");
        // [FIX] 修复 API 路径
        const eodStatusResponse = await fetch('./api/pos_api_gateway.php?res=eod&act=check_status', { credentials: 'same-origin' });
        const eodStatusResult = await eodStatusResponse.json();
        console.log("EOD status result:", eodStatusResult);

        if (eodStatusResult.status === 'success' && eodStatusResult.data.previous_day_unclosed) {
            STATE.unclosedEodDate = eodStatusResult.data.unclosed_date;
            showUnclosedEodOverlay(eodStatusResult.data.unclosed_date);
            console.log("Previous EOD unclosed. Blocking UI.");
            return;
        }
        STATE.unclosedEodDate = null;
        console.log("EOD check passed or not required.");

        console.log("Fetching initial data...");
        // [GEMINI SIF_DR_FIX] START: Store SIF declaration from API
        // Await the fetch so we can access its result
        const initialDataResult = await fetchInitialData();

        // Check the result and store the declaration text in our global STATE
        if (initialDataResult && initialDataResult.data && initialDataResult.data.sif_declaration) {
            STATE.sifDeclaration = initialDataResult.data.sif_declaration;
        } else {
            console.warn('SIF Declaration not found in data loader response.');
            STATE.sifDeclaration = 'Error: Declaración no cargada.'; // Set error text
        }
        // [GEMINI SIF_DR_FIX] END
        console.log("Initial data fetched (or attempted). STATE after fetch:", JSON.parse(JSON.stringify(STATE)));

        // --- CORE FIX: Removed the fatal error check for empty products/categories ---

        console.log("Essential data check skipped (as per fix), allowing empty stores.");

		const opsBody = document.querySelector('#opsOffcanvas .offcanvas-body');
		if (opsBody) {
			// [修复问题1] 修正了 估清按钮 的 span，添加了 data-i18n
			opsBody.innerHTML = `<div class="row g-3">
				<div class="col-6 col-md-3"><button class="btn btn-outline-ink w-100 py-3" id="btn_open_shift_end"><i class="bi bi-person-check d-block fs-2 mb-2"></i><span data-i18n="shift_handover">交接班</span></button></div>
				<div class="col-6 col-md-3"><button class="btn btn-outline-ink w-100 py-3" id="btn_open_txn_query"><i class="bi bi-clock-history d-block fs-2 mb-2"></i><span data-i18n="txn_query">交易查询</span></button></div>
				<div class="col-6 col-md-3"><button class="btn btn-outline-ink w-100 py-3" id="btn_open_eod"><i class="bi bi-calendar-check d-block fs-2 mb-2"></i><span data-i18n="eod">日结</span></button></div>
				<div class="col-6 col-md-3"><button class="btn btn-outline-ink w-100 py-3" id="btn_open_holds"><i class="bi bi-inboxes d-block fs-2 mb-2"></i><span data-i18n="holds">挂起单</span></button></div>

				<div class="col-6 col-md-3"><button class="btn btn-outline-ink w-100 py-3" id="btn_open_availability_panel"><i class="bi bi-slash-circle d-block fs-2 mb-2"></i><span data-i18n="availability_panel">商品估清</span></button></div>

				<div class="col-6 col-md-3"><button class="btn btn-outline-ink w-100 py-3" data-bs-toggle="offcanvas" data-bs-target="#settingsOffcanvas"><i class="bi bi-gear d-block fs-2 mb-2"></i><span data-i18n="settings">设置</span></button></div>
			  </div>`;
		}


        console.log("Applying I18N...");
        applyI18N();
        console.log("Updating Member UI...");
        updateMemberUI();
        console.log("Rendering Categories...");
        renderCategories();
        console.log("Rendering Products...");
        renderProducts();
        console.log("Rendering Addons...");
        renderAddons();
        console.log("Refreshing Cart UI...");
        refreshCartUI();
        console.log("Initializing Print Simulator...");
        initializePrintSimulator();
        console.log("Initializing Refund Modal...");
        const refundModalEl = document.getElementById('refundConfirmModal');
        if (refundModalEl) {
             const modalInstance = new bootstrap.Modal(refundModalEl);
             initializeRefundModal(modalInstance);
             console.log("Refund modal initialized.");
        } else {
             console.error("Refund confirmation modal element not found!");
        }

        console.log("POS Initialized Successfully.");

        await checkShiftStatus();

    } catch (error) {
        console.error("Fatal Error during initialization:", error);
        const errorDiv = document.createElement('div');
        errorDiv.className = 'alert alert-danger m-5';
        errorDiv.innerHTML = `<strong>Fatal Error:</strong> Could not initialize POS. ${error.message}. Please try refreshing. Check console for details.`;
        document.body.innerHTML = '';
        document.body.appendChild(errorDiv);
        document.body.style.backgroundColor = '#f8d7da';
    } finally {
        console.log("initApplication finished.");
    }
}

// --- Main Execution ---
document.addEventListener('DOMContentLoaded', () => {
    initializeShiftModals();
    // [GEMINI 瘦身] 启动交接班完成弹窗的监听器
    initShiftHandoverListener();
    bindEvents();
    // [优惠卡购买] 初始化优惠卡事件
    initDiscountCardEvents();
    initApplication();
    startClock();
});
