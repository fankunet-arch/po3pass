/**
 * 优惠卡（次卡）购买模块
 * 负责优惠卡列表、详情、购买流程和验证
 */

import { STATE } from '../state.js';
import { t, toast, fmtEUR } from '../utils.js';
import { findMember, unlinkMember } from './member.js';

let currentCard = null; // 当前选中的优惠卡
let pendingPurchaseCard = null; // 待购买的优惠卡（二次验证后使用）
let secondaryPhoneInput = null; // 二次验证的手机号

/**
 * 打开优惠卡列表
 */
export async function openDiscountCardsPanel() {
    const offcanvas = new bootstrap.Offcanvas(document.getElementById('discountCardsOffcanvas'));
    offcanvas.show();

    // 加载优惠卡列表
    await loadDiscountCardsList();
}

/**
 * 加载优惠卡列表
 */
async function loadDiscountCardsList() {
    const container = document.getElementById('discount_cards_list');
    container.innerHTML = '<div class="text-center p-4"><div class="spinner-border"></div></div>';

    try {
        const response = await fetch('api/pos_api_gateway.php?res=pass&act=list', {
            method: 'GET',
            credentials: 'same-origin'
        });

        if (!response.ok) {
            throw new Error('Failed to load discount cards');
        }

        const data = await response.json();

        if (data.status === 'success' && data.data && data.data.length > 0) {
            renderDiscountCardsList(data.data);
        } else {
            container.innerHTML = `<div class="text-center p-4 text-muted"><p data-i18n="no_discount_cards">${t('no_discount_cards')}</p></div>`;
        }
    } catch (error) {
        console.error('[discountCard] Failed to load cards:', error);
        container.innerHTML = `<div class="text-center p-4 text-danger"><p>加载失败，请稍后重试</p></div>`;
    }
}

/**
 * 渲染优惠卡列表
 */
function renderDiscountCardsList(cards) {
    const container = document.getElementById('discount_cards_list');
    container.innerHTML = '';

    cards.forEach(card => {
        const item = document.createElement('div');
        item.className = 'list-group-item list-group-item-action';
        item.style.cursor = 'pointer';
        item.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-1">${escapeHtml(card.name_zh || card.name)}</h6>
                    <small class="text-muted">${t('card_total_uses')}: ${card.total_uses}</small>
                </div>
                <div class="text-end">
                    <div class="fs-5 fw-bold text-brand">${fmtEUR(parseFloat(card.sale_price || 0))}</div>
                </div>
            </div>
        `;

        item.addEventListener('click', () => {
            showDiscountCardDetail(card);
        });

        container.appendChild(item);
    });
}

/**
 * 显示优惠卡详情
 */
function showDiscountCardDetail(card) {
    currentCard = card;

    // 填充详情
    document.getElementById('card_detail_name').textContent = card.name_zh || card.name;
    document.getElementById('card_detail_price').textContent = fmtEUR(parseFloat(card.sale_price || 0));
    document.getElementById('card_detail_total_uses').textContent = card.total_uses || 0;

    // 有效期
    const validityText = card.validity_days
        ? t('card_validity_days').replace('{days}', card.validity_days)
        : '-';
    document.getElementById('card_detail_validity').textContent = validityText;

    // 适用范围
    document.getElementById('card_detail_scope').textContent = t('card_scope_all');

    // 使用限制
    const limitsSection = document.getElementById('card_limits_section');
    const limitsText = [];
    if (card.max_uses_per_order && card.max_uses_per_order > 0) {
        limitsText.push(t('card_limit_per_order').replace('{count}', card.max_uses_per_order));
    }
    if (card.max_uses_per_day && card.max_uses_per_day > 0) {
        limitsText.push(t('card_limit_per_day').replace('{count}', card.max_uses_per_day));
    }

    if (limitsText.length > 0) {
        document.getElementById('card_detail_limits').textContent = limitsText.join('; ');
        limitsSection.style.display = '';
    } else {
        limitsSection.style.display = 'none';
    }

    // 备注
    document.getElementById('card_detail_notes').textContent = card.notes || '';

    // 显示详情 Modal
    const modal = new bootstrap.Modal(document.getElementById('discountCardDetailModal'));
    modal.show();
}

/**
 * 确认购买优惠卡（点击"确认购买"按钮）
 */
export async function confirmCardPurchase() {
    if (!currentCard) {
        toast('未选择优惠卡');
        return;
    }

    // 1. 检查订单环境
    const envCheck = checkOrderEnvironment();
    if (!envCheck.valid) {
        toast(envCheck.message);
        return;
    }

    // 2. 检查是否已登录会员
    if (!STATE.activeMember) {
        // 需要先登录会员
        toast(t('error_member_required'));

        // 关闭详情弹窗，引导用户到会员登录
        bootstrap.Modal.getInstance(document.getElementById('discountCardDetailModal'))?.hide();

        // 打开购物车侧边栏，显示会员登录区域
        const cartOffcanvas = new bootstrap.Offcanvas(document.getElementById('cartOffcanvas'));
        cartOffcanvas.show();

        // 聚焦到手机号输入框
        setTimeout(() => {
            document.getElementById('member_search_phone')?.focus();
        }, 500);

        return;
    }

    // 3. 进入二次验证流程
    pendingPurchaseCard = currentCard;
    showPhoneVerificationModal();
}

/**
 * 检查订单环境
 */
function checkOrderEnvironment() {
    // 1. 检查购物车是否包含其他商品
    if (STATE.cart && STATE.cart.length > 0) {
        return {
            valid: false,
            message: t('error_cart_has_products')
        };
    }

    // 2. 检查是否使用了优惠
    if (STATE.activeCouponCode ||
        (STATE.calculatedCart && STATE.calculatedCart.coupon_discount && STATE.calculatedCart.coupon_discount > 0) ||
        (STATE.calculatedCart && STATE.calculatedCart.points_discount && STATE.calculatedCart.points_discount > 0)) {
        return {
            valid: false,
            message: t('error_cart_has_promotions')
        };
    }

    return { valid: true };
}

/**
 * 显示手机号二次验证弹窗
 */
function showPhoneVerificationModal() {
    // 清空输入框和错误信息
    document.getElementById('phone_verification_input').value = '';
    document.getElementById('phone_verification_error').classList.add('d-none');

    // 关闭详情弹窗
    bootstrap.Modal.getInstance(document.getElementById('discountCardDetailModal'))?.hide();

    // 显示验证弹窗
    const modal = new bootstrap.Modal(document.getElementById('phoneVerificationModal'));
    modal.show();

    // 聚焦到输入框
    setTimeout(() => {
        document.getElementById('phone_verification_input')?.focus();
    }, 500);
}

/**
 * 处理手机号验证
 */
export async function handlePhoneVerification(event) {
    event.preventDefault();

    const inputPhone = sanitizePhoneInput(document.getElementById('phone_verification_input').value);
    const memberPhone = sanitizePhoneInput(STATE.activeMember?.phone_number || STATE.activeMember?.phone || '');

    // 清除错误信息
    const errorDiv = document.getElementById('phone_verification_error');
    errorDiv.classList.add('d-none');

    // 验证手机号是否匹配
    if (inputPhone !== memberPhone) {
        errorDiv.textContent = t('phone_mismatch_error');
        errorDiv.classList.remove('d-none');
        return;
    }

    // 验证通过，保存二次输入的手机号（原始格式）
    secondaryPhoneInput = document.getElementById('phone_verification_input').value.trim();

    // 关闭验证弹窗
    bootstrap.Modal.getInstance(document.getElementById('phoneVerificationModal'))?.hide();

    // 进入支付流程
    await proceedToPayment();
}

/**
 * 进入支付流程
 */
async function proceedToPayment() {
    if (!pendingPurchaseCard || !STATE.activeMember || !secondaryPhoneInput) {
        toast('购买信息丢失，请重新操作');
        return;
    }

    // 设置优惠卡购买模式
    STATE.purchasingDiscountCard = {
        ...pendingPurchaseCard,
        member_id: STATE.activeMember.id,
        secondary_phone_input: secondaryPhoneInput // 保存二次验证的手机号
    };

    // 设置支付总额
    STATE.payment = {
        total: parseFloat(pendingPurchaseCard.sale_price || 0),
        parts: []
    };

    // 打开支付弹窗（支付模块会检测 STATE.purchasingDiscountCard 并限制支付方式）
    const { openPaymentModal } = await import('./payment.js');
    openPaymentModal();
}

/**
 * 购卡成功后返回首页
 */
export function handlePurchaseDone() {
    // 关闭成功弹窗
    bootstrap.Modal.getInstance(document.getElementById('cardPurchaseSuccessModal'))?.hide();

    // 清空临时变量
    currentCard = null;
    pendingPurchaseCard = null;
    secondaryPhoneInput = null;

    // 关闭所有 Offcanvas
    document.querySelectorAll('.offcanvas.show').forEach(el => {
        const instance = bootstrap.Offcanvas.getInstance(el);
        if (instance) instance.hide();
    });

    toast(t('card_purchase_success'));
}

/**
 * 处理后端返回的动作指令
 * @param {Object} response - 后端返回的响应对象
 */
export function handleBackendActions(response) {
    if (!response || response.status !== 'success') {
        // 错误响应：不执行任何动作
        return;
    }

    const actions = response.data?.actions || [];

    // 检查是否包含成功动作
    if (actions.includes('SHOW_PASS_SUCCESS_PAGE')) {
        // 设置全局标志，表示需要在成功弹窗关闭后执行清理
        STATE.passPurchaseCleanupPending = true;

        // 显示成功弹窗
        const phone = response.data?.phone_masked || STATE.activeMember?.phone_number || '';
        document.getElementById('success_bound_phone').textContent = phone;

        const modal = new bootstrap.Modal('#cardPurchaseSuccessModal');
        modal.show();
    }
}

/**
 * 辅助函数：净化手机号输入
 */
function sanitizePhoneInput(value) {
    if (!value) return '';
    const str = String(value);
    let cleaned = str.replace(/[^\d+]/g, '');
    if (cleaned.includes('+')) {
        cleaned = '+' + cleaned.replace(/\+/g, '').replace(/[^\d]/g, '');
    }
    return cleaned.trim();
}

/**
 * 辅助函数：HTML转义
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * 初始化事件监听
 */
export function initDiscountCardEvents() {
    // 打开优惠卡列表
    document.getElementById('btn_open_discount_cards')?.addEventListener('click', openDiscountCardsPanel);

    // 确认购买
    document.getElementById('btn_confirm_card_purchase')?.addEventListener('click', confirmCardPurchase);

    // 手机号验证表单提交
    document.getElementById('phone_verification_form')?.addEventListener('submit', handlePhoneVerification);

    // 购卡成功后返回首页
    document.getElementById('btn_card_purchase_done')?.addEventListener('click', handlePurchaseDone);
}
