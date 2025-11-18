import { STATE } from '../state.js';
import { t, fmtEUR, toast } from '../utils.js';
// [B1.3] 导入 submitPassPurchaseAPI
// [B1.4 P3] 导入 submitPassRedemptionAPI
import { submitOrderAPI, submitPassPurchaseAPI, submitPassRedemptionAPI } from '../api.js';
import { calculatePromotions } from './cart.js';
import { unlinkMember } from './member.js';
// [PHASE 4.3] 导入新的打印处理器
import { handlePrintJobs } from './print.js';

let paymentConfirmModal = null;

/**
 * 入口：打开结账弹窗
 * 核心修改：不再默认添加现金行，支付区域初始为空。
 * [优惠卡购买] 检测优惠卡购买模式，限制支付方式
 */
export function openPaymentModal() {
    // [优惠卡购买] 特殊处理：不检查购物车
    if (!STATE.purchasingDiscountCard && STATE.cart.length === 0) {
        toast(t('tip_empty_cart'));
        return;
    }

    // [优惠卡购买] 如果是优惠卡购买模式，使用优惠卡的total
    const finalTotal = STATE.purchasingDiscountCard
        ? parseFloat(STATE.payment.total)
        : (parseFloat(STATE.calculatedCart.final_total) || 0);

    STATE.payment = { total: finalTotal, parts: [] };

    // 清空支付区域
    $('#payment_parts_container').empty();

    // [优惠卡购买] 限制支付方式
    restrictPaymentMethods();

    updatePaymentState(); // 更新一次UI确保金额显示正确

    bootstrap.Offcanvas.getInstance('#cartOffcanvas')?.hide();
    new bootstrap.Modal('#paymentModal').show();
}

/**
 * [优惠卡购买] 限制支付方式
 */
function restrictPaymentMethods() {
    const methodSelector = $('#payment_method_selector');
    if (!methodSelector.length) return;

    if (STATE.purchasingDiscountCard) {
        // 优惠卡购买模式：只显示现金和银行卡
        methodSelector.find('[data-pay-method]').each(function() {
            const method = $(this).data('pay-method');
            if (method === 'Cash' || method === 'Card') {
                $(this).prop('disabled', false).show();
            } else {
                $(this).prop('disabled', true).hide();
            }
        });
    } else {
        // 普通模式：显示所有支付方式
        methodSelector.find('[data-pay-method]').each(function() {
            const method = $(this).data('pay-method');
            // Bizum 保持禁用
            if (method === 'Bizum') {
                $(this).prop('disabled', true);
            } else {
                $(this).prop('disabled', false).show();
            }
        });
    }
}

/**
 * UI更新：根据输入框金额刷新“应收/已收/剩余/找零”
 */
export function updatePaymentState() {
    let totalPaid = 0;
    $('#payment_parts_container .payment-part-input').each(function () {
        totalPaid += parseFloat($(this).val()) || 0;
    });
    const totalReceivable = STATE.payment.total;
    const remaining = totalReceivable - totalPaid;
    const change = remaining < 0 ? -remaining : 0;
    
    $('#payment_total_display').text(fmtEUR(totalReceivable));
    $('#payment_paid_display').text(fmtEUR(totalPaid));
    $('#payment_remaining_display').text(fmtEUR(remaining > 0 ? remaining : 0));
    $('#payment_change_display').text(fmtEUR(change));
    
    // 只有在实收金额大于等于应收金额时，才启用确认按钮
    // [B1.4 P3] 核销模式下，即使 extra_charge 为 0 (total 0)，也允许结账 (totalPaid >= totalReceivable)
    $('#btn_confirm_payment').prop('disabled', totalPaid < totalReceivable - 0.001); // 允许 0.001 的浮点误差
}

/**
 * 添加新的支付方式行
 * 核心修改：添加行时不再自动填充金额。
 */
export function addPaymentPart(method) {
    const $newPart = $(`#payment_templates .payment-part[data-method="${method}"]`).clone();
    
    // 清空默认值
    $newPart.find('.payment-part-input').val('');

    $('#payment_parts_container').append($newPart);
    $newPart.find('.payment-part-input').focus();
    updatePaymentState();
}

/**
 * 新功能：处理快捷现金按钮点击
 */
export function handleQuickCash(value) {
    let $cashInputs = $('#payment_parts_container .payment-part[data-method="Cash"] .payment-part-input');
    
    if ($cashInputs.length === 0) {
        // 如果没有现金输入框，则创建一个
        addPaymentPart('Cash');
        $cashInputs = $('#payment_parts_container .payment-part[data-method="Cash"] .payment-part-input');
    }
    
    // 将金额填入最后一个现金输入框并触发更新
    const $lastCashInput = $cashInputs.last();
    $lastCashInput.val(parseFloat(value).toFixed(2));
    $lastCashInput.trigger('input'); 
}

/**
 * 核心功能：打开最终收款确认弹窗
 */
export function initiatePaymentConfirmation(event) {
    event.preventDefault();

    // 1. 先关闭当前的结账弹窗，解决叠加问题
    const paymentModal = bootstrap.Modal.getInstance(document.getElementById('paymentModal'));
    if (paymentModal) {
        paymentModal.hide();
    }

    const paymentParts = [];
    let totalPaid = 0;
    let cashTendered = 0;

    // 2. 收集所有支付信息
    $('#payment_parts_container .payment-part').each(function () {
        const $part = $(this);
        const method = $part.data('method');
        const amount = parseFloat($part.find('.payment-part-input').val()) || 0;
        if (amount > 0) {
            const partData = { method: method, amount: amount };
            if (method === 'Platform') {
                partData.reference = $part.find('.payment-part-ref').val().trim();
            }
            paymentParts.push(partData);
            totalPaid += amount;
            if (method === 'Cash') {
                cashTendered += amount;
            }
        }
    });

    const finalTotal = STATE.payment.total;
    const change = Math.max(0, totalPaid - finalTotal);
    const lack = Math.max(0, finalTotal - totalPaid);

    // 3. 准备并显示最终确认弹窗
    if (!paymentConfirmModal) {
        paymentConfirmModal = new bootstrap.Modal(document.getElementById('paymentConfirmModal'));
    }

    $('#pc-due').text(fmtEUR(finalTotal));
    $('#pc-paid').text(fmtEUR(totalPaid));
    $('#pc-change').text(fmtEUR(change));

    const $methodsContainer = $('#pc-methods').empty();
    if (paymentParts.length > 0) {
        paymentParts.forEach(p => {
            let bookedAmount = p.amount;
            // 核心逻辑：如果是现金且有找零，入账金额需要减去相应部分的找零
            if (p.method === 'Cash' && change > 0 && cashTendered > 0) {
                const cashPortion = p.amount / cashTendered;
                const changeToDeduct = change * cashPortion;
                bookedAmount = Math.max(0, p.amount - changeToDeduct);
            }
            $methodsContainer.append(`<div class="d-flex justify-content-between py-1 border-bottom small"><span>${p.method} ${p.reference ? `(${p.reference})` : ''}</span><span class="fw-semibold">${fmtEUR(bookedAmount)}</span></div>`);
        });
    } else {
        // [B1.4 P3] 允许0元支付（纯核销）
        if (finalTotal === 0) {
             $methodsContainer.html('<div class="small text-muted">— (Pago 0.00€) —</div>');
        } else {
             $methodsContainer.html('<div class="small text-muted">—</div>');
        }
    }

    if (lack > 0) {
        $('#pc-warning').removeClass('d-none').find('#pc-lack').text(fmtEUR(lack));
        $('#pc-note').addClass('d-none');
        $('#pc-confirm').prop('disabled', true);
    } else {
        $('#pc-warning').addClass('d-none');
        if (change > 0) {
            $('#pc-note').removeClass('d-none').find('#pc-note-change').text(fmtEUR(change));
        } else {
            $('#pc-note').addClass('d-none');
        }
        $('#pc-confirm').prop('disabled', false);
    }
    
    // 4. 绑定最终提交事件
    $('#pc-confirm').off('click').on('click', function() {
        paymentConfirmModal.hide();
        submitOrder(); 
    });

    // 监听返回修改按钮，重新打开结账窗口
    $('#paymentConfirmModal [data-bs-dismiss="modal"]').off('click').on('click', function() {
        paymentModal.show();
    });

    paymentConfirmModal.show();
}

/**
 * [REMOVED] Old pass_product flow disabled.
 * Pass purchases now ONLY go through discountCard.js (Flow A).
 * This function is kept as a stub to avoid breaking references but always returns false.
 */
function isPassPurchase(cart, tagsMap) {
    // Legacy pass_product flow is disabled.
    // All pass purchases must go through the dedicated Discount Card UI.
    return false;
}


/**
 * [B1.4 P3] 辅助函数：计算购物车中用于核销的总次数
 */
function getRedemptionItemCount(cart, tagsMap) {
    let count = 0;
    if (!cart || cart.length === 0) return 0;
    
    for (const item of cart) {
        const itemTags = tagsMap[item.product_id] || [];
        // 只有标记为 'pass_eligible_beverage' 的商品才计入核销次数
        if (itemTags.includes('pass_eligible_beverage')) {
            count += item.qty;
        }
    }
    return count;
}

/**
 * 最终提交订单到后端
 * [B1.3 PASS] 修改：增加售卡分流逻辑
 * [B1.4 P3] 修改：增加核销分流逻辑 (P3 核心)
 */
export async function submitOrder() {
    const checkoutBtn = $('#btn_confirm_payment');
    checkoutBtn.prop('disabled', true).html(`<span class="spinner-border spinner-border-sm me-2"></span>${t('submitting_order')}`);

    const paymentParts = [];
    let totalPaid = 0;
    $('#payment_parts_container .payment-part').each(function () {
        const $part = $(this);
        const method = $part.data('method');
        const amount = parseFloat($part.find('.payment-part-input').val()) || 0;
        // [B1.4 P3] 即使金额为0，也要收集支付方式（用于0元支付）
        // 但如果金额为0，我们只在总额也为0时保留它
        if (amount > 0 || STATE.payment.total === 0) {
            const partData = { method: method, amount: amount };
            if (method === 'Platform') {
                partData.reference = $part.find('.payment-part-ref').val().trim();
            }
            paymentParts.push(partData);
            totalPaid += amount;
        }
    });

    const paymentPayload = {
        total: STATE.payment.total,
        paid: totalPaid,
        change: totalPaid - STATE.payment.total > 0 ? totalPaid - STATE.payment.total : 0,
        summary: paymentParts
    };

    try {
        // [优惠卡购买] 如果是优惠卡购买模式，验证支付方式
        if (STATE.purchasingDiscountCard) {
            const hasInvalidMethod = paymentParts.some(p =>
                p.method !== 'Cash' && p.method !== 'Card'
            );
            if (hasInvalidMethod) {
                throw new Error(t('error_payment_method_invalid'));
            }
        }

        // [UNIFIED FLOW] 结账三向分流
        // Flow A: Discount Card Purchase (via discountCard.js)
        // Flow B: Pass Redemption (via activePassSession)
        // Flow C: Normal Orders

        let result;
        const isDiscountCardPurchase = STATE.purchasingDiscountCard !== null;
        const isRedemption = STATE.activePassSession !== null;

        if (isDiscountCardPurchase) {
            // --- Flow A: Discount Card Purchase (ONLY valid pass purchase flow) ---
            const card = STATE.purchasingDiscountCard;

            const purchaseData = {
                member_id: card.member_id,
                secondary_phone_input: card.secondary_phone_input, // 二次验证的手机号
                cart_item: {
                    sku: card.sale_sku,
                    name: card.name_zh || card.name,
                    qty: 1,
                    price: parseFloat(card.sale_price || 0),
                    total: parseFloat(card.sale_price || 0)
                },
                payment: paymentPayload,
                promo_result: STATE.calculatedCart // 传递促销结果用于验证
            };

            const response = await fetch('api/pos_api_gateway.php?res=pass&act=purchase', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(purchaseData),
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error('售卡请求失败');
            }

            result = await response.json();

        } else if (isRedemption) {
            // --- Flow B: Pass Redemption ---
            if (!STATE.activeMember) {
                throw new Error('核销次卡必须先关联会员。'); // 防御性检查
            }

            // [B1.4 P3] P3 验证逻辑
            const pass = STATE.activePassSession;
            const itemsToRedeemCount = getRedemptionItemCount(STATE.cart, STATE.tags_map);

            if (itemsToRedeemCount === 0) {
                throw new Error('购物车中没有可用于核销的饮品。');
            }
            if (itemsToRedeemCount > pass.remaining_uses) {
                throw new Error(`次卡剩余次数不足 (剩余 ${pass.remaining_uses}, 需 ${itemsToRedeemCount})`);
            }
            if (pass.max_uses_per_order > 0 && itemsToRedeemCount > pass.max_uses_per_order) {
                throw new Error(`单笔订单核销上限为 ${pass.max_uses_per_order} 次 (当前 ${itemsToRedeemCount} 次)`);
            }
            if (pass.daily_uses_remaining !== null && itemsToRedeemCount > pass.daily_uses_remaining) {
                throw new Error(`今日剩余核销上限为 ${pass.daily_uses_remaining} 次 (当前 ${itemsToRedeemCount} 次)`);
            }

            // 验证通过，调用核销 API
            result = await submitPassRedemptionAPI(paymentPayload);

        } else {
            // --- Flow C: Normal Orders ---
            // Legacy pass_product flow is DISABLED.
            // If cart contains pass_product items, they should have been blocked at add-to-cart.
            result = await submitOrderAPI(paymentPayload);
        }
        // END: 结账三向分流

        if (result.status === 'success') {
            bootstrap.Modal.getInstance('#paymentModal')?.hide();

            // [优惠卡购买] 如果是优惠卡购买，使用专用的成功处理
            if (isDiscountCardPurchase) {
                // 导入并调用 handleBackendActions
                const { handleBackendActions } = await import('./discountCard.js');
                handleBackendActions(result);

                return; // 不再显示普通的成功弹窗
            }

            // [B1.4 P4] START: 成功弹窗定制 (P4)
            if (isRedemption) {
                // 核销成功
                $('#orderSuccessModal [data-i18n="order_success"]').text('核销成功'); // TODO: I18N
                
                if (result.data.invoice_number_vr) {
                    // 仅有 VR (0元加价)
                    $('#orderSuccessModal [data-i18n="invoice_number"]').text('核销凭证 (VR)'); // TODO: I18N
                    $('#success_invoice_number').show().text(result.data.invoice_number_vr);
                    $('#orderSuccessModal [data-i18n="qr_code_info"]').hide();
                    $('#success_qr_content').closest('div').hide();
                } else {
                    // 有加价发票 (TP)
                    $('#orderSuccessModal [data-i18n="invoice_number"]').text('加价票号 (TP)'); // TODO: I18N
                    $('#success_invoice_number').show().text(result.data.invoice_number_tp);
                    $('#orderSuccessModal [data-i18n="qr_code_info"]').show();
                    $('#success_qr_content').closest('div').show().find('code').text(result.data.qr_content_tp);
                }

            } else if (isPurchase) {
                // 售卡成功
                $('#orderSuccessModal [data-i18n="order_success"]').text('售卡成功'); // TODO: I18N
                $('#orderSuccessModal [data-i18n="invoice_number"]').text('售卡凭证 (VR)'); // TODO: I18N
                $('#success_invoice_number').show().text(result.data.voucher_number || 'N/A');
                $('#orderSuccessModal [data-i18n="qr_code_info"]').hide();
                $('#success_qr_content').closest('div').hide();
                
            } else if (result.data.invoice_number === 'NO_INVOICE') {
                // 普通订单 (未开票)
                $('#orderSuccessModal [data-i18n="order_success"]').text(t('payment_success'));
                $('#orderSuccessModal [data-i18n="invoice_number"]').hide();
                $('#success_invoice_number').hide();
                $('#orderSuccessModal [data-i18n="qr_code_info"]').hide();
                $('#success_qr_content').closest('div').hide();
            } else {
                // 普通订单 (已开票)
                $('#orderSuccessModal [data-i18n="order_success"]').text(t('order_success'));
                $('#orderSuccessModal [data-i18n="invoice_number"]').show();
                $('#success_invoice_number').show().text(result.data.invoice_number);
                $('#orderSuccessModal [data-i18n="qr_code_info"]').show();
                $('#success_qr_content').closest('div').show().find('code').text(result.data.qr_content);
            }
            // [B1.4 P4] END: 弹窗定制
            
            new bootstrap.Modal('#orderSuccessModal').show();

            // [PHASE 4.3] 调用打印任务处理器
            if (result.data.print_jobs) {
                handlePrintJobs(result.data.print_jobs);
            }

            // Reset state after successful order
            STATE.cart = [];
            STATE.activeCouponCode = '';
            STATE.activePassSession = null; // [B1.4 P3] 退出核销模式
            $('#coupon_code_input').val('');
            unlinkMember();
            calculatePromotions();
        } else {
            throw new Error(result.message || 'Unknown server error.');
        }
    } catch (error) {
        console.error('Failed to submit order:', error);
        toast((t('order_submit_failed') || '订单提交失败') + ': ' + error.message);
    } finally {
        checkoutBtn.prop('disabled', false).html(t('confirm_payment'));
    }
}