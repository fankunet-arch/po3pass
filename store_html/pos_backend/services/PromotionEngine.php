<?php
/**
 * Toptea POS - 促销引擎 (V2)
 *
 * [B1.3.1 PASS]: Modified applyPromotions to disable discounts if cart contains pass products (purchase or redeem).
 * [GEMINI FIX 2025-11-10-v4] Corrected all SQL column names to match the database schema (e.g., promo_is_active, promo_code).
 */

class PromotionEngine {
    private $pdo;
    private $promotions;
    private $coupons;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->loadActiveRules();
    }

    private function loadActiveRules() {
        // 修复 1: 将 Y-m_d 改为 Y-m-d
        $today = date('Y-m-d H:i:s');
        
        // 1. 加载所有激活的自动促销
        $stmt_promo = $this->pdo->prepare("
            SELECT * FROM pos_promotions 
            WHERE promo_is_active = 1 
              AND (promo_start_date IS NULL OR promo_start_date <= :today_start)
              AND (promo_end_date IS NULL OR promo_end_date >= :today_end)
              AND promo_code IS NULL
            ORDER BY promo_priority DESC, id ASC
        ");
        // 修复 2: 为两个占位符分别提供值
        $stmt_promo->execute([
            ':today_start' => $today,
            ':today_end' => $today
        ]);
        $this->promotions = $stmt_promo->fetchAll(PDO::FETCH_ASSOC);

        // 2. 预加载所有激活的优惠券
        $stmt_coupon = $this->pdo->prepare("
            SELECT * FROM pos_promotions 
            WHERE promo_is_active = 1 
              AND (promo_start_date IS NULL OR promo_start_date <= :today_start)
              AND (promo_end_date IS NULL OR promo_end_date >= :today_end)
              AND promo_code IS NOT NULL
        ");
        // 修复 3: 为两个占位符分别提供值
        $stmt_coupon->execute([
            ':today_start' => $today,
            ':today_end' => $today
        ]);
        $this->coupons = [];
        foreach ($stmt_coupon->fetchAll(PDO::FETCH_ASSOC) as $rule) {
            $this->coupons[strtoupper($rule['promo_code'])] = $rule;
        }
    }
    
    // 辅助函数：计算购物车原始小计
    private function calculateSubtotal(array $cart): float {
        $subtotal = 0.0;
        foreach ($cart as $item) {
            $subtotal += (float)($item['unit_price_eur'] ?? 0) * (int)($item['qty'] ?? 1);
        }
        return round($subtotal, 2);
    }

    // 辅助函数：格式化返回结果
    private function formatResult(array $cart, float $subtotal, float $discountAmount): array {
        $finalTotal = max(0.0, round($subtotal - $discountAmount, 2));
        return [
            'cart' => $cart,
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'final_total' => $finalTotal,
        ];
    }
    
    // 辅助函数：检查购物车是否包含次卡相关商品
    private function cartContainsPassProducts(array $cart): bool {
        if (empty($cart)) return false;
        
        // 依赖: pos_repo::get_cart_item_tags (必须已加载)
        if (!function_exists('get_cart_item_tags')) {
             error_log("FATAL: PromotionEngine requires pos_repo::get_cart_item_tags()");
             return false; // 兜底，允许折扣
        }
        
        $menu_item_ids = array_map(fn($item) => $item['product_id'] ?? null, $cart);
        $tags_map = get_cart_item_tags($this->pdo, $menu_item_ids);
        
        foreach ($tags_map as $item_id => $tags) {
            if (in_array('pass_product', $tags, true)) {
                return true; // 包含售卡商品
            }
            if (in_array('pass_eligible_beverage', $tags, true)) {
                return true; // 包含核销饮品
            }
        }
        return false;
    }


    /**
     * 应用促销和优惠券到购物车
     *
     * @param array $cart 购物车数组
     * @param string|null $couponCode 用户输入的优惠券代码
     * @return array [ 'cart' => $updatedCart, 'subtotal' => float, 'discount_amount' => float, 'final_total' => float ]
     */
    public function applyPromotions(array $cart, ?string $couponCode): array {
        $subtotal = $this->calculateSubtotal($cart);
        $totalDiscount = 0.0;
        
        // [B1.3.1 PASS] 规则 B. 业务规则: 售卡/核销订单禁止任何优惠
        if ($this->cartContainsPassProducts($cart)) {
            // 如果购物车包含次卡售卖或核销商品，立即返回 0 折扣
            return $this->formatResult($cart, $subtotal, 0.0);
        }
        // [B1.3.1] END

        // 1. (占位) 处理自动促销 (TODO: Phase 5)
        // ...
        
        // 2. (占位) 处理优惠券 (TODO: Phase 5)
        if ($couponCode) {
            $code = strtoupper($couponCode);
            if (isset($this->coupons[$code])) {
                $rule = $this->coupons[$code];
                // (此处应有复杂的规则应用逻辑)
                
                // 示例：应用一个 1.5€ 的折扣
                // $totalDiscount += 1.50; 
            }
        }

        // 3. (占位) 刷新购物车内项目价格
        foreach ($cart as &$item) {
            if (!isset($item['final_price'])) {
                $item['final_price'] = (float)($item['unit_price_eur'] ?? 0);
            }
        }

        return $this->formatResult($cart, $subtotal, $totalDiscount);
    }
}