/**
 * cart.js — 购物车逻辑 (添加, 更新, 计算)
 *
 * [GEMINI A1.jpg FIX 2.0 - JS]
 * 1. (问题 1) 修复 addToCart，将产品名称(title)存入 cart item
 * 2. 修复 addToCart，使其能正确关闭 customizeOffcanvas
 *
 * [GEMINI 购物车参数修复 V3.0]
 * 1. (问题 4) 修复 addToCart，将 variant_name (zh/es), title (zh/es) 存入 cart item
 *
 * [GEMINI SUPER-ENGINEER FIX (Error 1.C)]:
 * 1. 修复 addToCart，使其能正确读取 KDS 配方 SKU (product_sku) 和杯型 (cup_code)
 *
 * [B1.4 P2]:
 * 1. 新增 calculatePassRedemptionTotals() (P2 核心)
 * 2. 修改 calculatePromotions() 以在核销模式下调用 P2 核心
 * 3. 修改 addToCart() 以在核销模式下应用 P2 规则（免费/付费加料）
 * 4. 修改 updateCartItem() 以在核销模式下禁用（暂定）
 */
import { STATE } from '../state.js';
import { refreshCartUI, updateMemberUI } from '../ui.js';
import { calculatePromotionsAPI } from '../api.js';
import { t, fmtEUR, toast } from '../utils.js';

/**
 * 将当前定制面板中的商品添加到购物车
 */
export function addToCart() {
    const $canvas = $('#customizeOffcanvas');
    const product = $canvas.data('product');
    if (!product) {
        console.error("addToCart: No product data found on canvas.");
        return;
    }

    const selectedVariantId = parseInt($('input[name="variant_selector"]:checked').val());
    const variant = product.variants.find(v => v.id === selectedVariantId);
    if (!variant) {
        console.error("addToCart: No variant selected or found.");
        return;
    }

    const selectedAddons = [];
    $('#addon_list .addon-chip.active').each(function () {
        selectedAddons.push($(this).data('key'));
    });

    const isPassMode = STATE.activePassSession !== null;
    let unitPrice = parseFloat(variant.price_eur); // 基础价格
    let addonsForCart = []; // [B1.4 P2]
    
    // [B1.4 P2] START: 核销模式下的加料定价
    if (isPassMode) {
        // 在核销模式下，商品基础价格为 0
        unitPrice = 0.0; 
        
        const freeLimit = STATE.storeConfig.global_free_addon_limit || 0;
        let freeAddonsApplied = 0;

        selectedAddons.forEach(addonKey => {
            const addon = STATE.addons.find(a => a.key === addonKey);
            if (!addon) return;

            const addonTags = addon.tags || [];
            let price = 0.0; // 默认为0

            if (addonTags.includes('paid_addon')) {
                // 1. 明确是“收费加料”
                price = parseFloat(addon.price_eur);
            } else if (addonTags.includes('free_addon')) {
                // 2. 是“免费加料”，检查上限
                if (freeLimit === 0 || freeAddonsApplied < freeLimit) {
                    // 2a. 在上限内，价格为 0
                    price = 0.0;
                    freeAddonsApplied++;
                } else {
                    // 2b. 超出上限，按原价收费
                    price = parseFloat(addon.price_eur);
                }
            }
            // 3. 如果既不是 'paid'也不是 'free'，则按普通商品逻辑（在次卡模式下视为收费）
            else {
                 price = parseFloat(addon.price_eur);
            }

            addonsForCart.push({
                key: addonKey,
                price: price
            });
            unitPrice += price; // 累加到单价中
        });
        
    } 
    // [B1.4 P2] END: 核销模式
    else {
        // [B1.4 P2] 普通模式：按原价计入
        selectedAddons.forEach(addonKey => {
            const addon = STATE.addons.find(a => a.key === addonKey);
            if (addon) {
                const price = parseFloat(addon.price_eur);
                addonsForCart.push({
                    key: addonKey,
                    price: price
                });
                unitPrice += price;
            }
        });
    }


    const cartItem = {
        id: Date.now().toString(), // 购物车唯一ID
        product_id: product.id,
        variant_id: variant.id,
        // [GEMINI V3.0 修复] 存储双语名称
        title: STATE.lang === 'es' ? product.title_es : product.title_zh,
        title_zh: product.title_zh,
        title_es: product.title_es,
        variant_name: STATE.lang === 'es' ? variant.name_es : variant.name_zh,
        variant_name_zh: variant.name_zh,
        variant_name_es: variant.name_es,
        // [GEMINI FIX 1.C] 存储 KDS SKU 和 Cup Code
        product_code: variant.recipe_sku, // KDS 配方 P-Code (e.g., "A1")
        cup_code: variant.cup_code,       // KDS 杯型 A-Code (e.g., "1")
        
        // [B1.4 P2] 存储基础价格（用于后续重算）和最终单价
        base_price_eur: parseFloat(variant.price_eur), // 不含加料的规格价格
        unit_price_eur: unitPrice, // 包含加料的最终单价
        
        qty: 1,
        ice: $('input[name="ice"]:checked').val() || null,
        sugar: $('input[name="sugar"]:checked').val() || null,
        // [B1.4 P2] addons 结构变更
        addons: addonsForCart, // 存储 {key, price} 对象数组
        remark: $('#remark_input').val() || ''
    };

    STATE.cart.push(cartItem);
    
    // [GEMINI A1.jpg FIX 2]
    const offcanvasInstance = bootstrap.Offcanvas.getInstance('#customizeOffcanvas');
    if (offcanvasInstance) {
        offcanvasInstance.hide();
    }
    
    // 重新计算总价
    calculatePromotions();
}

/**
 * 更新购物车项目（数量或删除）
 */
export function updateCartItem(itemId, action) {
    
    // [B1.4 P2] 在核销模式下，暂时禁止增减数量（未来可优化）
    if (STATE.activePassSession) {
        toast('请在退出核销模式后再调整数量。', true); // TODO: I18N
        // (但允许删除)
        if (action !== 'del') {
             return;
        }
    }

    const itemIndex = STATE.cart.findIndex(item => item.id === itemId);
    if (itemIndex === -1) return;

    if (action === 'del') {
        STATE.cart.splice(itemIndex, 1);
    } else if (action === 'inc') {
        STATE.cart[itemIndex].qty++;
    } else if (action === 'dec') {
        STATE.cart[itemIndex].qty--;
        if (STATE.cart[itemIndex].qty === 0) {
            STATE.cart.splice(itemIndex, 1);
        }
    }

    // 重新计算总价
    calculatePromotions();
}


/**
 * [B1.4 P2] 新增：计算次卡核销模式下的总价
 * 在此模式下，总价 = extra_charge_total
 */
function calculatePassRedemptionTotals(cart) {
    let subtotal = 0.0;
    let extraChargeTotal = 0.0; // P2 核心：只计算加价

    const freeLimit = STATE.storeConfig.global_free_addon_limit || 0;
    
    cart.forEach(item => {
        // 1. 获取商品基础价（不含加料）
        const basePrice = parseFloat(item.base_price_eur);
        subtotal += basePrice * item.qty; // Subtotal 仍按原价计算
        
        // 2. 检查商品是否为“可核销饮品”
        const itemTags = STATE.tags_map[item.product_id] || [];
        const isEligibleDrink = itemTags.includes('pass_eligible_beverage');

        let itemExtraCharge = 0.0;
        
        if (isEligibleDrink) {
            // 2a. 商品可核销，基础价为 0。计算加料费。
            let freeAddonsAppliedThisItem = 0;
            
            (item.addons || []).forEach(addon => {
                const addonDef = STATE.addons.find(a => a.key === addon.key);
                if (!addonDef) return;
                
                const addonTags = addonDef.tags || [];
                
                if (addonTags.includes('paid_addon')) {
                    // 明确是“收费加料”，计入加价
                    itemExtraCharge += parseFloat(addon.price);
                } else if (addonTags.includes('free_addon')) {
                    // 是“免费加料”，检查上限
                    if (freeLimit === 0 || freeAddonsAppliedThisItem < freeLimit) {
                        // 在上限内，不计费
                        freeAddonsAppliedThisItem++;
                    } else {
                        // 超出上限，计入加价
                        itemExtraCharge += parseFloat(addon.price);
                    }
                } else {
                    // 非 'paid' 或 'free' 的加料，在核销模式下默认计费
                    itemExtraCharge += parseFloat(addon.price);
                }
            });

        } else {
            // 2b. 商品不可核销（例如：单独购买的蛋糕），按原价计入加价
            itemExtraCharge = parseFloat(item.unit_price_eur);
        }
        
        // 累加此商品 * 数量 的总加价
        extraChargeTotal += itemExtraCharge * item.qty;
    });

    return {
        cart: cart,
        subtotal: subtotal,
        discount_amount: 0.0, // 核销模式无折扣
        final_total: extraChargeTotal, // 最终应付 = 额外加价
        points_redemption: { points_redeemed: 0, discount_amount: 0.0 } // 核销模式无积分抵扣
    };
}


/**
 * 计算购物车总价（应用促销）
 * [B1.4 P2] 修改：增加核销模式分流
 */
export async function calculatePromotions(isCouponApplyAttempt = false) {
    // [B1.4 P2] START: 核销模式分流
    if (STATE.activePassSession) {
        // 如果在核销模式中，使用 P2 逻辑计算总价 (extra_charge_total)
        // 这个计算是纯前端的，不需要 API
        const passTotals = calculatePassRedemptionTotals(STATE.cart);
        STATE.calculatedCart = passTotals;
        // 立即刷新UI
        refreshCartUI();
        updateMemberUI();
        return; // 结束
    }
    // [B1.4 P2] END: 核销模式分流

    // --- 以下是普通订单模式 ---

    // 1. 从UI收集积分
    const pointsToRedeemInput = $('#points_to_redeem_input');
    let pointsToRedeem = parseInt(pointsToRedeemInput.val(), 10) || 0;
    if (pointsToRedeem < 0) pointsToRedeem = 0;

    // 2. 检查积分兑换和优惠券是否冲突
    if (STATE.activeRedemptionRuleId && STATE.activeCouponCode) {
        toast(t('redemption_incompatible'), true);
        // 优先保留积分兑换，清除优惠券
        STATE.activeCouponCode = '';
        $('#coupon_code_input').val('');
    }

    // 3. 准备API负载
    const payload = {
        cart: STATE.cart,
        coupon_code: STATE.activeCouponCode,
        member_id: STATE.activeMember ? STATE.activeMember.id : null,
        points_to_redeem: pointsToRedeem
    };

    // 4. 调用API
    try {
        const result = await calculatePromotionsAPI(payload);
        
        // 5. 更新状态
        STATE.calculatedCart = result;
        STATE.cart = result.cart; // API 可能会更新购物车项（例如应用了折扣）
        
        // 6. 处理反馈
        const couponFeedback = result.cart.find(item => item.applied_promo_type === 'COUPON');
        if (isCouponApplyAttempt) {
            if (couponFeedback) {
                toast(t('coupon_applied'));
            } else {
                toast(t('coupon_not_valid'), true);
                STATE.activeCouponCode = ''; // 清除无效的优惠券
                $('#coupon_code_input').val('');
            }
        }
        
        // 7. 更新积分反馈
        const pointsFeedback = result.points_redemption;
        const $pointsFeedbackEl = $('#points_feedback');
        if (pointsFeedback && pointsFeedback.points_redeemed > 0) {
            $pointsFeedbackEl.text(t('points_feedback_applied', {
                points: pointsFeedback.points_redeemed,
                amount: pointsFeedback.discount_amount
            })).removeClass('text-danger').addClass('text-success');
        } else if (pointsToRedeem > 0) {
            $pointsFeedbackEl.text(t('points_feedback_not_enough')).removeClass('text-success').addClass('text-danger');
        } else {
            $pointsFeedbackEl.text('').removeClass('text-success text-danger');
        }

    } catch (error) {
        console.error("Promotion calculation failed:", error);
        toast(error.message, true);
        // 如果计算失败，重置为无折扣状态
        STATE.calculatedCart = {
            cart: STATE.cart,
            subtotal: STATE.cart.reduce((acc, item) => acc + (item.unit_price_eur * item.qty), 0),
            discount_amount: 0,
            final_total: STATE.cart.reduce((acc, item) => acc + (item.unit_price_eur * item.qty), 0),
            points_redemption: { points_redeemed: 0, discount_amount: 0.0 }
        };
    }

    // 8. 刷新UI
    refreshCartUI();
    updateMemberUI(); // 确保积分兑换规则UI也刷新
}