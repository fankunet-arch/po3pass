import { STATE } from './state.js';
import { toast } from './utils.js';

// [PHASE 4.2] 统一定义网关
const POS_API_GATEWAY = './api/pos_api_gateway.php';

async function apiCall(url, options = {}) {
    try {
        // CORE FIX: Add credentials to all API calls to send session cookies
        const fetchOptions = {
            ...options,
            credentials: 'same-origin'
        };

        const response = await fetch(url, fetchOptions);
        if (!response.ok) {
            let errorData;
            try {
                errorData = await response.json();
            } catch (e) {
                throw new Error(`Server returned error: ${response.status}`);
            }
            throw new Error(errorData.message || 'Unknown server error');
        }
        return response.json();
    } catch (error) {
        console.error(`API call to ${url} failed:`, error);
        toast(`Network Error: ${error.message}`);
        throw error;
    }
}

export async function fetchInitialData() {
    // [PHASE 4.2] 路由到网关
    const result = await apiCall(`${POS_API_GATEWAY}?res=data&act=load`);
    
    if (result.status === 'success') {
        // [PHASE 4.1.A] 保存门店配置
        STATE.storeConfig = result.data.store_config || {};
        
        STATE.products = result.data.products;
        STATE.categories = result.data.categories;
        STATE.addons = result.data.addons;
        STATE.redemptionRules = result.data.redemption_rules || [];
        
        // (V2.2 GATING) Populate master lists
        STATE.iceOptions = result.data.ice_options || [];
        STATE.sweetnessOptions = result.data.sweetness_options || [];
        
        // [GEMINI SIF_DR_FIX]
        STATE.sifDeclaration = result.data.sif_declaration || '';

        // [B1.3 PASS] 新增：保存商品标签映射
        STATE.tags_map = result.data.tags_map || {};

        if (!STATE.active_category_key && STATE.categories.length > 0) {
            STATE.active_category_key = STATE.categories[0].key;
        }
    }
    // --- CORE ADDITION: Fetch print templates after main data ---
    await fetchPrintTemplates();
    
    // [GEMINI SIF_DR_FIX] Return the result so main.js can access it
    return result;
}

/**
 * Version: 2.2.0
 * Fetches all available print templates from the backend.
 * [PHASE 4.2] 路由到网关
 */
export async function fetchPrintTemplates() {
    try {
        // [PHASE 4.2] 路由到网关
        const result = await apiCall(`${POS_API_GATEWAY}?res=print&act=get_templates`);
        if (result.status === 'success') {
            STATE.printTemplates = result.data || {};
            console.log('Print templates loaded:', STATE.printTemplates);
        } else {
             console.error('Failed to load print templates:', result.message);
             STATE.printTemplates = {}; // Ensure it's an empty object on failure
        }
    } catch (error) {
        console.error('Network error fetching print templates:', error);
        STATE.printTemplates = {}; // Ensure it's an empty object on network error
    }
}

/**
 * Version: 2.2.0
 * Fetches the specific data required for printing an EOD report.
 * [PHASE 4.2] 路由到网关
 */
export async function fetchEodPrintData(reportId) {
    // [PHASE 4.2] 路由到网关
    const result = await apiCall(`${POS_API_GATEWAY}?res=print&act=get_eod_data&report_id=${reportId}`);
    if (result.status === 'success') {
        return result.data;
    } else {
        throw new Error(result.message || 'Failed to fetch EOD print data');
    }
}


export async function calculatePromotionsAPI(payload) {
    // [PHASE 4.2] 路由到网关
    const result = await apiCall(`${POS_API_GATEWAY}?res=cart&act=calculate`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    return result.data;
}

export async function submitOrderAPI(paymentPayload) {
     const payload = {
        cart: STATE.cart,
        coupon_code: STATE.activeCouponCode,
        redemption_rule_id: STATE.activeRedemptionRuleId,
        member_id: STATE.activeMember ? STATE.activeMember.id : null,
        payment: paymentPayload,
        points_redeemed: STATE.calculatedCart.points_redemption ? STATE.calculatedCart.points_redemption.points_redeemed : 0,
        points_discount: STATE.calculatedCart.points_redemption ? STATE.calculatedCart.points_redemption.discount_amount : 0
    };
    // [PHASE 4.2] 路由到网关
    return await apiCall(`${POS_API_GATEWAY}?res=order&act=submit`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
}

/**
 * [B1.3 PASS] 新增：提交次卡售卡订单
 */
export async function submitPassPurchaseAPI(paymentPayload) {
     const payload = {
        cart: STATE.cart,
        member_id: STATE.activeMember ? STATE.activeMember.id : null,
        payment: paymentPayload,
        // 售卡时，促销结果应为0 (由后端 PromotionEngine 保证)
        promo_result: STATE.calculatedCart 
    };
    // [B1.3] 路由到次卡售卡网关
    return await apiCall(`${POS_API_GATEWAY}?res=pass&act=purchase`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
}

/**
 * [B1.4 P3] 新增：提交次卡核销订单
 */
export async function submitPassRedemptionAPI(paymentPayload) {
    // P3 幂等性要求
    const idempotencyKey = crypto.randomUUID();
    
    const payload = {
       cart: STATE.cart,
       member_id: STATE.activeMember ? STATE.activeMember.id : null,
       payment: paymentPayload,
       
       // P3 核销专属字段
       pass_id: STATE.activePassSession.pass_id, // 告知后端要用哪张卡
       idempotency_key: idempotencyKey, // 幂等键
       
       // P3 费用（由 P2 calculatePassRedemptionTotals 计算得出）
       // 后端会重新校验，但前端提交有助于调试
       extra_charge_total: STATE.calculatedCart.final_total 
   };
   
   // [B1.4] 路由到次卡核销网关
   return await apiCall(`${POS_API_GATEWAY}?res=pass&act=redeem`, {
       method: 'POST',
       headers: { 'Content-Type': 'application/json' },
       body: JSON.stringify(payload)
   });
}