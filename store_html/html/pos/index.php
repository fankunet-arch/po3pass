<?php
/**
 * TopTea POS - Main Entry Point
 * Engineer: Gemini | Date: 2025-11-04
 * Revision: 1.3.2 (Ghost Shift Guardian)
 *
 * [GEMINI FIX 2025-11-16]
 * 1. 修复了 `require_once` 的致命路径错误。
 * - 错误路径: `'/../../../../pos_backend/core/pos_auth_core.php'` (4 个 ../)
 * - 正确路径: `'/../../../pos_backend/core/pos_auth_core.php'` (3 个 ../)
 * 2. [完善2] 为 `#member_info` 添加了 `#btn_show_member_detail` 按钮。
 * 3. [修复1] 为 `#memberCreateModal` 添加了 `#member_create_error` div。
 * 4. [完善2] 在 body 底部添加了 `#memberDetailModal` 的 HTML 框架。
 *
 * 1. Re-added data-i18n-key="force_start_body" to the modal paragraph.
 * shift.js and main.js will now coordinate translation and variable injection.
 * 2. Added language switcher dropdown to #forceStartShiftModal header for i18n consistency.
 * 3. Added new modal #forceStartShiftModal for ghost shift scenario.
 * 4. Added new modal #sifDeclarationModal at the end of the body.
 * 5. Added new button #btn_show_sif_declaration in settings panel.
 *
 * 1. 修复 #customizeOffcanvas 中的 DOM ID，使其与 ui.js 脚本匹配。
 * 2. 移除硬编码的冰量/糖量选项，为 Gating 逻辑让出容器。
 * 3. 移除 #opsOffcanvas 和 #settingsOffcanvas 上的 h-75 类。
 * 4. 为 #opsOffcanvas 和 #settingsOffcanvas 添加内联样式 style="height: auto;"
 * 5. 补全了 #member_section 的 HTML 结构，以匹配 ui.js 和 member.js 的选择器。
 * 6. 修复了购物车侧边栏总计区域的 ID (cart_subtotal_offcanvas, cart_discount, cart_final_total)。
 * 7. 修复了底部栏总计 ID 为 cart_subtotal 以便 ui.js 更新。
 */

// This MUST be the first include. It checks if the user is logged in.
// [GEMINI FIX 2025-11-16] 修复致命路径错误，从 4 个 '..' 改为 3 个
require_once realpath(__DIR__ . '/../../pos_backend/core/pos_auth_core.php');

$cache_version = time();
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>TopTea · POS 点餐台</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <script>window.SHIFT_POLICY = 'force_all';</script>
  <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <link href="./assets/pos.css?v=<?php echo $cache_version; ?>" rel="stylesheet">
</head>
<body class="lefty-mode">
  <nav class="navbar navbar-expand bg-surface fixed-top shadow-sm border-0">
    <div class="container-fluid gap-2">
      <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold" href="#">
        <span class="brand-dot"></span>TopTea POS<span class="badge bg-brand-soft text-brand fw-semibold ms-2" data-i18n="internal">Internal</span>
      </a>
      <div class="d-flex align-items-center ms-auto gap-3">
        <span id="pos_clock" class="navbar-text fw-bold">--:--:--</span>
        <span class="navbar-text text-muted">|</span>
        <span id="pos_store_name" class="navbar-text fw-bold"><?php echo htmlspecialchars($_SESSION['pos_store_name'] ?? 'Store'); ?></span>
        <span class="navbar-text text-muted">|</span>
        <div class="dropdown">
            <a href="#" class="navbar-text dropdown-toggle text-decoration-none" data-bs-toggle="dropdown">
                <i class="bi bi-person"></i> <?php echo htmlspecialchars($_SESSION['pos_display_name'] ?? 'User'); ?> <span class="badge bg-secondary fw-normal"><?php echo htmlspecialchars($_SESSION['pos_user_role'] ?? 'staff'); ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="logout.php" data-i18n="navbar_logout"><i class="bi bi-box-arrow-right me-2"></i>退出登录</a></li>
            </ul>
        </div>

        <div class="dropdown">
            <button class="btn btn-outline-ink btn-sm dropdown-toggle px-2" data-bs-toggle="dropdown" id="lang_toggle"><span class="flag">🇨🇳</span> <span data-i18n="lang_zh">中文</span></button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item active" href="#" data-lang="zh"><span class="flag">🇨🇳</span> 中文</a></li>
                <li><a class="dropdown-item" href="#" data-lang="es"><span class="flag">🇪🇸</span> Español</a></li>
            </ul>
        </div>
        <button class="btn btn-brand btn-sm" id="btn_sync" title="同步/刷新"><i class="bi bi-arrow-repeat"></i></button>
      </div>
    </div>
  </nav>

  <main class="container-fluid pos-container"><div class="row g-2"><div class="col-12"><div class="input-group search-box prominent"><span class="input-group-text"><i class="bi bi-search"></i></span><input type="text" class="form-control" id="search_input" placeholder="搜索饮品或拼音简称…" data-i18n-placeholder="placeholder_search"><button class="btn btn-outline-ink" id="clear_search"><i class="bi bi-x-lg"></i></button></div></div><div class="col-12"><div id="category_scroller" class="nav nav-pills flex-nowrap overflow-x-auto gap-2"></div></div></div><div class="row row-cols-5 g-2 mt-2" id="product_grid"></div></main>

  <div class="pos-bottombar border-0"><div class="container-fluid d-flex align-items-center justify-content-between" id="bottom_bar"><button class="btn btn-outline-ink d-flex align-items-center gap-2" data-bs-toggle="offcanvas" data-bs-target="#cartOffcanvas" id="btn_cart_open"><i class="bi bi-bag-check"></i> <span data-i18n="cart">购物车</span><span class="badge bg-brand-soft text-brand fw-semibold" id="cart_count">0</span></button>

  <div class="text-end me-auto ms-3 small text-muted d-none d-sm-block"><div data-i18n="total_before_discount">合计</div><div class="fs-5 text-ink fw-semibold" id="cart_subtotal">€0.00</div></div>

  <div class="d-flex gap-2 align-items-stretch"><button class="btn btn-outline-ink" data-bs-toggle="offcanvas" data-bs-target="#opsOffcanvas" id="btn_ops"><i class="bi bi-grid"></i> <span data-i18n="more">功能</span></button></div></div></div>

  <div class="offcanvas offcanvas-end offcanvas-sheet" tabindex="-1" id="cartOffcanvas">
    <div class="offcanvas-header border-0"><h5 class="offcanvas-title"><i class="bi bi-bag"></i> <span data-i18n="cart">购物车</span></h5><button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button></div>
    <div class="offcanvas-body p-0 d-flex flex-column">

      <div id="member_section" class="p-3 border-bottom border-sheet">
          <div id="member_search">
              <div class="input-group">
                  <input type="tel" class="form-control" id="member_search_phone" data-i18n-placeholder="member_search_placeholder" placeholder="输入会员手机号查找">
                  <button class="btn btn-outline-secondary" type="button" id="btn_find_member" data-i18n="member_find">查找</button>
              </div>
              <div class="mt-2">
                  <button class="btn btn-link p-0 text-decoration-none" id="btn_show_create_member">
                      <i class="bi bi-plus-circle"></i> <span data-i18n="member_create">创建新会员</span>
                  </button>
              </div>
          </div>
          <div id="member_info" style="display: none;">
              <div class="d-flex justify-content-between align-items-start">
                  <div>
                      <h6 class="mb-0 d-inline-block" id="member_name"></h6>
                      <button class="btn btn-link btn-sm p-0 ms-2" id="btn_show_member_detail" style="display: none;" title="查看详情">
                          <i class="bi bi-person-lines-fill"></i>
                      </button>
                      <small class="text-muted d-block" id="member_level"></small>
                  </div>
                  <button class="btn btn-sm btn-outline-danger" id="btn_unlink_member" data-i18n="member_unlink">解除关联</button>
              </div>
              <div class="mt-2">
                  <span data-i18n="member_points">积分</span>: <span class="fw-bold" id="member_points">0</span>
              </div>
              <div id="available_rewards_list" class="list-group list-group-flush mt-2"></div>
          </div>
      </div>
      <div id="cart_items" class="list-group list-group-flush flex-grow-1 overflow-y-auto"></div>

      <div class="p-3 border-top border-sheet mt-auto" id="cart_footer">
        <div class="input-group mb-3"><input type="text" class="form-control" id="coupon_code_input" placeholder="输入优惠码"><button class="btn btn-outline-secondary" type="button" id="apply_coupon_btn">应用</button></div>
        <div id="points_redemption_section" class="mb-3">
            <div class="input-group">
                <input type="number" class="form-control" id="points_to_redeem_input" data-i18n-placeholder="points_redeem_placeholder" placeholder="使用积分" disabled>
                <button class="btn btn-outline-secondary" type="button" id="apply_points_btn" data-i18n="points_apply_btn" disabled>应用</button>
            </div>
            <div class="form-text d-flex justify-content-between">
                <span data-i18n="points_rule">100积分 = 1€</span>
                <span id="points_feedback"></span>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center small text-muted">
            <span data-i18n="total_before_discount">合计</span>
            <span id="cart_subtotal_offcanvas">€0.00</span>
        </div>
        <div class="d-flex justify-content-between align-items-center small text-danger">
            <span data-i18n="eod_discounts">折扣总额</span>
            <span id="cart_discount">€0.00</span>
        </div>

        <div class="d-flex justify-content-between align-items-center fs-5 mt-2">
            <span class="fw-semibold" data-i18n="payable">应收</span>
            <span id="cart_final_total" class="fw-bold text-brand">€0.00</span>
        </div>

        <div class="d-flex gap-2 mt-3"><button class="btn btn-outline-secondary flex-grow-1" id="btn_hold_current_cart" data-i18n="hold_this">挂起此单</button><button class="btn btn-brand flex-grow-1" id="btn_cart_checkout"><i class="bi bi-credit-card-2-front"></i> <span id="btn_cart_checkout_label" data-i18n="go_checkout">去结账</span></button></div>
      </div>
    </div>
  </div>

  <div class="offcanvas offcanvas-bottom offcanvas-sheet" tabindex="-1" id="opsOffcanvas" style="height: auto;"><div class="offcanvas-header"><h5 class="offcanvas-title" data-i18n="ops_panel">功能面板</h5><button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button></div><div class="offcanvas-body"><div class="row g-3">
    <div class="col-6 col-md-3"><button class="btn btn-outline-ink w-100 py-3" id="btn_open_shift_end"><i class="bi bi-person-check d-block fs-2 mb-2"></i><span data-i18n="shift_handover">交接班</span></button></div>
    <div class="col-6 col-md-3"><button class="btn btn-outline-ink w-100 py-3" id="btn_open_txn_query"><i class="bi bi-clock-history d-block fs-2 mb-2"></i><span data-i18n="txn_query">交易查询</span></button></div>
    <div class="col-6 col-md-3"><button class="btn btn-outline-ink w-100 py-3" id="btn_open_eod"><i class="bi bi-calendar-check d-block fs-2 mb-2"></i><span data-i1m="eod">日结</span></button></div>
    <div class="col-6 col-md-3"><button class="btn btn-outline-ink w-100 py-3" id="btn_open_holds"><i class="bi bi-inboxes d-block fs-2 mb-2"></i><span data-i18n="holds">挂起单</span></button></div>
    <div class="col-6 col-md-3"><button class="btn btn-outline-ink w-100 py-3" id="btn_open_discount_cards"><i class="bi bi-credit-card-2-front d-block fs-2 mb-2"></i><span data-i18n="discount_cards">优惠卡</span></button></div>
    <div class="col-6 col-md-3"><button class="btn btn-outline-ink w-100 py-3" data-bs-toggle="offcanvas" data-bs-target="#settingsOffcanvas"><i class="bi bi-gear d-block fs-2 mb-2"></i><span data-i18n="settings">设置</span></button></div>
  </div></div></div>

  <div class="offcanvas offcanvas-end offcanvas-sheet" tabindex="-1" id="customizeOffcanvas">
    <div class="offcanvas-header"><h5 class="offcanvas-title" id="customize_title"></h5><button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button></div>
    <div class="offcanvas-body">
        <div class="mb-4">
            <h6 class="fw-bold" data-i18n="size">规格</h6>
            <div class="d-flex flex-wrap gap-2" id="variant_selector_list"></div>
        </div>
        <div class="mb-4">
            <h6 class="fw-bold" data-i18n="ice">冰量</h6>
            <div class="d-flex flex-wrap gap-2" id="ice_selector_list"></div>
        </div>
        <div class="mb-4">
            <h6 class="fw-bold" data-i18n="sugar">糖度</h6>
            <div class="d-flex flex-wrap gap-2" id="sugar_selector_list"></div>
        </div>
        <div class="mb-4">
            <h6 class="fw-bold" data-i18n="addons">加料</h6>
            <div class="d-flex flex-wrap gap-2" id="addon_list"></div>
        </div>
        <div class="mb-3">
            <label for="remark_input" class="form-label fw-bold" data-i18n="remark">备注（可选）</label>
            <input type="text" class="form-control" id="remark_input">
        </div>
    </div>
    <div class="offcanvas-footer p-3 border-top border-sheet">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <span data-i18n="curr_price">当前价格</span>
            <span class="fs-4 fw-bold text-brand" id="custom_item_price">€0.00</span>
        </div>
        <button class="btn btn-brand w-100" id="btn_add_to_cart" data-i18n="add_to_cart">加入购物车</button>
    </div>
</div>

  <div class="modal fade" id="orderSuccessModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false"><div class="modal-dialog modal-dialog-centered"><div class="modal-content modal-sheet"><div class="modal-body text-center p-4"><i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i><h3 class="mt-3" data-i18n="order_success">下单成功</h3><p class="text-muted" data-i18n="invoice_number">票号</p><h4 class="mb-3" id="success_invoice_number">--</h4><p class="text-muted small" data-i18n="qr_code_info">合规二维码内容 (TicketBAI/Veri*Factu)</p><div class="p-2 bg-light rounded border"><code id="success_qr_content" style="word-break: break-all;">-</code></div><button type="button" class="btn btn-brand w-100 mt-4" data-bs-dismiss="modal" data-i18n="new_order">开始新订单</button></div></div></div></div>

  <div class="modal fade" id="paymentModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false"><div class="modal-dialog modal-dialog-centered"><div class="modal-content modal-sheet"><div class="modal-header"><h5 class="modal-title" data-i18n="checkout">结账</h5><button type="button" class="btn-close" data-bs-dismiss="modal" id="btn_cancel_payment"></button></div><div class="modal-body p-4"><div class="row text-center mb-3"><div class="col"><small data-i18n="receivable">应收</small><div class="fs-4 fw-bold text-brand" id="payment_total_display">€0.00</div></div><div class="col"><small data-i18n="paid">已收</small><div class="fs-4 fw-bold" id="payment_paid_display">€0.00</div></div><div class="col"><small data-i18n="remaining">剩余</small><div class="fs-4 fw-bold text-info" id="payment_remaining_display">€0.00</div></div><div class="col"><small data-i18n="change">找零</small><div class="fs-4 fw-bold" id="payment_change_display">€0.00</div></div></div><div id="payment_parts_container" class="mb-3"></div>

  <div class="mb-3"><small class="text-muted">快捷现金</small><div class="d-flex flex-wrap gap-2 mt-1"><button class="btn btn-outline-secondary btn-quick-cash" data-value="5">€5</button><button class="btn btn-outline-secondary btn-quick-cash" data-value="10">€10</button><button class="btn btn-outline-secondary btn-quick-cash" data-value="20">€20</button><button class="btn btn-outline-secondary btn-quick-cash" data-value="50">€50</button></div></div>

  <div class="mb-2"><small class="text-muted" data-i18n="payment_methods_label">支付方式</small></div><div id="payment_method_selector" class="d-flex flex-wrap gap-2"><button class="btn btn-outline-primary btn-payment-method" data-pay-method="Cash"><i class="bi bi-cash-coin me-1"></i><span data-i18n="cash_payment">现金</span></button><button class="btn btn-outline-primary btn-payment-method" data-pay-method="Card"><i class="bi bi-credit-card me-1"></i><span data-i18n="card_payment">刷卡</span></button><button class="btn btn-outline-primary btn-payment-method" data-pay-method="Bizum" disabled><i class="bi bi-phone me-1"></i>Bizum</button><button class="btn btn-outline-primary btn-payment-method" data-pay-method="Platform"><i class="bi bi-qr-code me-1"></i><span data-i18n="platform_code">平台码</span></button></div></div><div class="modal-footer d-grid"><button type="button" id="btn_confirm_payment" class="btn btn-primary w-100">确认收款</button></div></div></div></div>
  <div id="payment_templates" class="d-none"><div class="payment-part card card-body mb-2" data-method="Cash"><div class="d-flex align-items-center mb-2"><span class="fw-bold"><i class="bi bi-cash-coin me-2"></i><span data-i18n="cash_payment">现金</span></span><button class="btn-close ms-auto remove-part-btn"></button></div><input type="number" class="form-control form-control-lg text-center payment-part-input" placeholder="0.00"></div><div class="payment-part card card-body mb-2" data-method="Card"><div class="d-flex align-items-center mb-2"><span class="fw-bold"><i class="bi bi-credit-card me-2"></i><span data-i18n="card_payment">刷卡</span></span><button class="btn-close ms-auto remove-part-btn"></button></div><input type="number" class="form-control form-control-lg text-center payment-part-input" placeholder="0.00"></div><div class="payment-part card card-body mb-2" data-method="Platform"><div class="d-flex align-items-center mb-2"><span class="fw-bold"><i class="bi bi-qr-code me-2"></i><span data-i18n="platform_code">平台码</span></span><button class="btn-close ms-auto remove-part-btn"></button></div><div class="row g-2"><div class="col-7"><label class="form-label small" data-i18n="platform_amount">收款金额</label><input type="number" class="form-control form-control-lg text-center payment-part-input" placeholder="0.00"></div><div class="col-5"><label class="form-label small" data-i18n="platform_ref">参考码</label><input type="text" class="form-control form-control-lg text-center payment-part-ref" placeholder="输入码"></div></div></div></div>

  <div class="offcanvas offcanvas-end offcanvas-sheet" tabindex="-1" id="holdOrdersOffcanvas">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title d-flex align-items-center gap-2"><i class="bi bi-inboxes"></i><span data-i18n="holds">挂起单</span></h5>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-sort-down"></i> <span data-i18n="sort_by_time">排序: 最近</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="#" data-sort="time_desc" data-i18n="sort_by_time">最近</a></li>
                <li><a class="dropdown-item" href="#" data-sort="amount_desc" data-i18n="sort_by_amount">金额</a></li>
            </ul>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
        <div class="input-group mb-3">
            <input type="text" class="form-control" id="hold_order_note_input" data-i18n-placeholder="hold_placeholder">
            <button class="btn btn-brand" type="button" id="btn_create_new_hold"><i class="bi bi-plus-circle"></i> <span data-i18n="create_hold">新建挂起单</span></button>
        </div>
        <p class="form-text mt-0 mb-3" data-i18n="hold_instruction"></p>
        <hr/>
        <div id="held_orders_list"></div>
    </div>
  </div>

  <div class="offcanvas offcanvas-bottom offcanvas-sheet" tabindex="-1" id="settingsOffcanvas" style="height: auto;"><div class="offcanvas-header"><h5 class="offcanvas-title" data-i18n="settings">设置</h5><button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button></div><div class="offcanvas-body"><div class="list-group"><div class="list-group-item"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="setting_peak_mode"><label class="form-check-label" for="setting_peak_mode" data-i18n="peak_mode">高峰模式 (对比增强)</label></div><small class="form-text text-muted" data-i18n="peak_mode_desc">左侧菜单变白，并在前方功能按钮保留返回图示，避免误操。</small></div><div class="list-group-item"><div class="form-check"><input class="form-check-input" type="radio" name="hand_mode" id="setting_lefty_mode" value="lefty-mode"><label class="form-check-label" for="setting_lefty_mode" data-i18n="lefty_mode">左手模式 (点菜按钮靠左)</label></div></div><div class="list-group-item"><div class="form-check"><input class="form-check-input" type="radio" name="hand_mode" id="setting_righty_mode" value="righty-mode"><label class="form-check-label" for="setting_righty_mode" data-i18n="righty_mode">右手模式 (点菜按钮靠右)</label></div></div>
  <div class="list-group-item"><button type="button" class="btn btn-link text-decoration-none px-0" id="btn_show_sif_declaration">查看合规性声明 (Ver Declaración Responsable)</button></div>
  </div></div></div>

  <div class="offcanvas offcanvas-end offcanvas-sheet" tabindex="-1" id="txnQueryOffcanvas"><div class="offcanvas-header"><h5 class="offcanvas-title" data-i18n="txn_query">交易查询</h5><button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button></div><div class="offcanvas-body p-0" id="txn_list_container"></div></div>

  <div class="modal fade" id="txnDetailModal" tabindex="-1"><div class="modal-dialog modal-dialog-scrollable"><div class="modal-content modal-sheet"><div class="modal-header"><h5 class="modal-title" id="txn_detail_title">票据详情</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="txn_detail_body"></div><div class="modal-footer" id="txn_detail_footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-i18n="close">关闭</button></div></div></div></div>

  <div class="modal fade" id="eodSummaryModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content modal-sheet"><div class="modal-header"><h5 class="modal-title" data-i18n="eod_title">今日日结报告</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="eod_summary_body"><div class="text-center p-4"><div class="spinner-border"></div></div></div><div class="modal-footer" id="eod_summary_footer"></div></div></div></div>
  <div class="modal fade" id="eodConfirmModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-dialog-centered"><div class="modal-content modal-sheet"><div class="modal-header"><h5 class="modal-title" data-i18n="eod_confirm_title">确认提交日结</h5></div><div class="modal-body"><p data-i18n="eod_confirm_body">提交后，今日日结数据将被存档且无法修改。请确认所有款项已清点完毕。</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-i18n="eod_confirm_cancel">取消</button><button type="button" class="btn btn-primary" id="btn_confirm_eod_final" data-i18n="eod_confirm_submit">确认提交</button></div></div></div></div>

  <div class="modal fade" id="memberCreateModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-dialog-centered"><div class="modal-content modal-sheet"><div class="modal-header"><h5 class="modal-title" data-i18n="member_create_title">创建新会员</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="form_create_member"><div class="mb-3"><label for="member_phone" class="form-label" data-i18n="member_phone">手机号</label><input type="tel" class="form-control" id="member_phone" required></div><div class="row g-2 mb-3"><div class="col-md"><label for="member_firstname" class="form-label" data-i18n="member_firstname">名字</label><input type="text" class="form-control" id="member_firstname"></div><div class="col-md"><label for="member_lastname" class="form-label" data-i18n="member_lastname">姓氏</label><input type="text" class="form-control" id="member_lastname"></div></div><div class="mb-3"><label for="member_email" class="form-label" data-i18n="member_email">邮箱</label><input type="email" class="form-control" id="member_email"></div><div class="mb-3"><label for="member_birthdate" class="form-label" data-i18n="member_birthdate">生日</label><input type="date" class="form-control" id="member_birthdate"></div>
  <div id="member_create_error" class="alert alert-danger d-none mt-3"></div>
  </form></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-i18n="cancel">取消</button><button type="submit" form="form_create_member" class="btn btn-primary" data-i18n="member_create_submit">创建并关联</button></div></div></div></div>

  <div class="modal fade" id="refundConfirmModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content modal-sheet">
        <div class="modal-header">
          <h5 class="modal-title" id="refundConfirmModalLabel">确认操作</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="refundConfirmModalBody">
          您确定要执行此操作吗？
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-i18n="cancel">取消</button>
          <button type="button" class="btn btn-primary" id="btn_confirm_refund_action">确认</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="startShiftModal" tabindex="-1" aria-labelledby="startShiftModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content modal-sheet">
        <div class="modal-header">
          <h5 class="modal-title" id="startShiftModalLabel" data-i18n="shift_start_title">开始当班 (Start Shift)</h5>
          <div class="dropdown ms-auto">
              <button class="btn btn-outline-secondary btn-sm dropdown-toggle px-2" data-bs-toggle="dropdown" id="lang_toggle_modal"><span class="flag">🇨🇳</span></button>
              <ul class="dropdown-menu dropdown-menu-end">
                  <li><a class="dropdown-item active" href="#" data-lang="zh"><span class="flag">🇨🇳</span> 中文</a></li>
                  <li><a class="dropdown-item" href="#" data-lang="es"><span class="flag">🇪🇸</span> Español</a></li>
              </ul>
          </div>
        </div>
        <div class="modal-body">
          <p data-i18n="shift_start_body">在开始销售前，请输入您钱箱中的初始备用金金额。</p>
          <form id="start_shift_form">
            <div class="form-floating">
              <input type="number" class="form-control" id="starting_float" placeholder="初始备用金" step="0.01" min="0" required>
              <label for="starting_float" data-i18n="shift_start_label">初始备用金 (€)</label>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="submit" form="start_shift_form" class="btn btn-primary w-100" data-i18n="shift_start_submit">确认并开始当班</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="forceStartShiftModal" tabindex="-1" aria-labelledby="forceStartShiftModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content modal-sheet">
        <div class="modal-header bg-warning">
          <h5 class="modal-title text-dark" id="forceStartShiftModalLabel" data-i18n="force_start_title">操作提醒：发现未结束的班次</h5>
          <div class="dropdown ms-auto">
              <button class="btn btn-outline-dark btn-sm dropdown-toggle px-2" data-bs-toggle="dropdown" id="lang_toggle_modal_force"><span class="flag">🇨🇳</span></button>
              <ul class="dropdown-menu dropdown-menu-end">
                  <li><a class="dropdown-item active" href="#" data-lang="zh"><span class="flag">🇨🇳</span> 中文</a></li>
                  <li><a class="dropdown-item" href="#" data-lang="es"><span class="flag">🇪🇸</span> Español</a></li>
              </ul>
          </div>
        </div>
        <div class="modal-body">
          <p id="force_start_body" data-i18n-key="force_start_body">系统检测到班次 (属于: {user}) 未正确交接。您必须强制结束该班次，才能开始您的新班次。</p>
          <form id="force_start_shift_form">
            <div class="form-floating">
              <input type="number" class="form-control" id="force_starting_float" placeholder="您的初始备用金" step="0.01" min="0" required>
              <label for="force_starting_float" data-i18n="force_start_label">您的初始备用金 (€)</label>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="submit" form="force_start_shift_form" class="btn btn-danger w-100" data-i18n="force_start_submit">强制交班并开始我的新班次</button>
        </div>
      </div>
    </div>
  </div>
  <div class="modal fade" id="endShiftModal" tabindex="-1" aria-labelledby="endShiftModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content modal-sheet">
        <div class="modal-header">
          <h5 class="modal-title" id="endShiftModalLabel" data-i18n="shift_end_title">交接班 / 结束当班 (End Shift)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6 border-end" id="end_shift_summary_body">
              </div>
            <div class="col-md-6">
              <form id="end_shift_form">
                <div class="mb-3">
                  <label for="counted_cash" class="form-label fs-5" data-i18n="shift_end_counted_cash_label">清点现金总额 (€)</label>
                  <input type="number" class="form-control form-control-lg" id="counted_cash" placeholder="0.00" step="0.01" min="0" required>
                </div>
                <hr>
                <div class="d-flex justify-content-between align-items-center">
                  <span class="fs-5" data-i18n="shift_end_cash_variance_label">现金差异:</span>
                  <span id="cash_variance_display" class="fs-4">€0.00</span>
                </div>
                <p class="form-text" data-i18n="shift_end_cash_variance_hint">
                  差异 = 清点现金 - 系统应有现金。负数表示短款。
                </p>
              </form>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-i18n="cancel">取消</button>
          <button type="submit" form="end_shift_form" class="btn btn-danger w-50">
            <i class="bi bi-printer me-2"></i><span data-i18n="shift_end_submit">确认交班并打印</span>
          </button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="eodResultModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">交接班已完成</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="small text-muted mb-2">
          <span id="eod_started_at"></span> → <span id="eod_ended_at"></span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <tbody>
              <tr><td>期初备用金</td><td class="text-end" id="eod_starting_float">€0.00</td></tr>
              <tr><td>现金销售</td><td class="text-end" id="eod_cash_sales">€0.00</td></tr>
              <tr><td>现金流入</td><td class="text-end" id="eod_cash_in">€0.00</td></tr>
              <tr><td>现金流出</td><td class="text-end" id="eod_cash_out">€0.00</td></tr>
              <tr><td>现金退款</td><td class="text-end" id="eod_cash_refunds">€0.00</td></tr>
              <tr class="table-light"><td>理论应有现金</td><td class="text-end" id="eod_expected_cash">€0.00</td></tr>
              <tr><td>清点现金</td><td class="text-end" id="eod_counted_cash">€0.00</td></tr>
              <tr class="table-light">
                <td>现金差异</td>
                <td class="text-end" id="eod_cash_diff">€0.00</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" id="btnViewEodHistory">查看交接班记录</button>
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">知道了</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="eodHistoryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">最近交接班记录</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="table-responsive">
          <table class="table table-sm" id="eodHistoryTable">
            <thead>
              <tr>
                <th>开始</th><th>结束</th>
                <th class="text-end">期初</th>
                <th class="text-end">现金销</th>
                <th class="text-end">流入</th>
                <th class="text-end">流出</th>
                <th class="text-end">退款</th>
                <th class="text-end">理论现金</th>
                <th class="text-end">清点现金</th>
                <th class="text-end">差异</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
		<button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-i18n="close">关闭</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="paymentConfirmModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">收款确认</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <div class="row text-center mb-3">
          <div class="col-4">
            <div class="text-muted small">应收</div>
            <div class="fs-5 fw-bold" id="pc-due">€0.00</div>
          </div>
          <div class="col-4">
            <div class="text-muted small">实收</div>
            <div class="fs-5 fw-bold" id="pc-paid">€0.00</div>
          </div>
          <div class="col-4">
            <div class="text-muted small">应找零</div>
            <div class="fs-5 fw-bold" id="pc-change">€0.00</div>
          </div>
        </div>

        <div class="border rounded p-2 mb-2">
          <div class="d-flex justify-content-between small text-muted">
            <span>收款方式</span><span>入账金额</span>
          </div>
          <div id="pc-methods"><div class="small text-muted">—</div></div>
        </div>

        <div id="pc-warning" class="alert alert-danger py-2 d-none">
          少收 <span id="pc-lack">€0.00</span>，请补齐后再提交。
        </div>
        <div id="pc-note" class="alert alert-info py-2 d-none">
          已包含找零 <span id="pc-note-change">€0.00</span>，系统将按应收金额入账。
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">返回修改</button>
        <button type="button" class="btn btn-primary" id="pc-confirm">确认入账并打印</button>
      </div>

    </div>
  </div>
</div>

<div class="modal fade" id="sifDeclarationModal" tabindex="-1" aria-labelledby="sifDeclarationModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content modal-sheet">
      <div class="modal-header">
        <h5 class="modal-title" id="sifDeclarationModalLabel">Declaración Responsable (SIF)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <pre id="sif_declaration_content" style="white-space: pre-wrap; word-wrap: break-word; font-size: 0.8rem;"></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

  <div class="modal fade" id="availabilityModal" tabindex="-1" aria-labelledby="availabilityModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content modal-sheet">
        <div class="modal-header">
          <h5 class="modal-title" id="availabilityModalLabel" data-i18n-key="availability_title">商品估清管理</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted" data-i18n-key="availability_desc">在此处关闭商品将使其在点餐界面显示为“估清”且不可点。此状态将在每日自动重置。</p>
          <div class="list-group" id="availability_list_container">
            <div class="text-center p-4"><div class="spinner-border"></div></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-i18n-key="close">关闭</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="soldOutDecisionModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content modal-sheet">
        <div class="modal-header">
          <h5 class="modal-title" data-i18n-key="availability_decision_title">交接班估清确认</h5>
        </div>
        <div class="modal-body">
          <p data-i18n-key="availability_decision_p1">上一班次有 <strong id="sold_out_snapshot_count">0</strong> 个商品处于“估清”状态。</p>
          <p data-i18n-key="availability_decision_p2">请选择如何处理这些商品的库存状态：</p>
        </div>
        <div class="modal-footer d-flex">
          <button type="button" class="btn btn-outline-secondary w-100" id="btn_sold_out_decision_keep">
            <strong data-i18n-key="availability_decision_keep">继续估清</strong>
            <small class="d-block" data-i18n-key="availability_decision_keep_desc">保持上一班次的状态</small>
          </button>
          <button type="button" class="btn btn-primary w-100" id="btn_sold_out_decision_reset">
            <strong data-i18n-key="availability_decision_reset">全部重新上架</strong>
            <small class="d-block" data-i18n-key="availability_decision_reset_desc">将所有商品设为可售</small>
          </button>
        </div>
      </div>
    </div>
  </div>

<div class="toast-container position-fixed bottom-0 end-0 p-3"><div id="sys_toast" class="toast" role="alert"><div class="toast-body" id="toast_msg"></div></div></div>

<div class="modal fade" id="memberDetailModal" tabindex="-1" aria-labelledby="memberDetailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content modal-sheet">
      <div class="modal-header">
        <h5 class="modal-title" id="memberDetailModalLabel">会员详情</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <h4 id="memberDetailModal_Name">N/A</h4>
        <ul class="list-group list-group-flush mt-3">
          <li class="list-group-item d-flex justify-content-between">
            <span>手机号:</span>
            <strong id="memberDetailModal_Phone">N/A</strong>
          </li>
          <li class="list-group-item d-flex justify-content-between">
            <span>邮箱:</span>
            <strong id="memberDetailModal_Email">N/A</strong>
          </li>
          <li class="list-group-item d-flex justify-content-between">
            <span>生日:</span>
            <strong id="memberDetailModal_Birthdate">N/A</strong>
          </li>
          <li class="list-group-item d-flex justify-content-between">
            <span>积分:</span>
            <strong id="memberDetailModal_Points">0</strong>
          </li>
        </ul>
        <hr>
        <p class="text-muted small">此为基础信息。可在此处扩展会员次卡、优惠券、消费历史等...</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>
<template id="addon_chip_template_standard">
    <div class="col-4 g-2">
        <div class="addon-chip" data-key="{KEY}" data-price="{PRICE_RAW}">
            {NAME}
            <small class="d-block text-muted">+{PRICE_FMT}</small>
        </div>
    </div>
</template>

<template id="addon_chip_template_pass_free">
    <div class="col-4 g-2">
        <div class="addon-chip addon-chip-pass-free" data-key="{KEY}" data-price="{PRICE_RAW}" data-pass-type="free">
            {NAME}
            <small class="d-block text-pass-free-price">
                <span class="price-original text-decoration-line-through">+{PRICE_FMT}</span>
                <span class="price-pass">GRATIS</span>
            </small>
        </div>
    </div>
</template>

<template id="addon_chip_template_pass_paid">
    <div class="col-4 g-2">
        <div class="addon-chip addon-chip-pass-paid" data-key="{KEY}" data-price="{PRICE_RAW}" data-pass-type="paid">
            {NAME}
            <small class="d-block text-pass-paid-price">PAGO EXTRA +{PRICE_FMT}</small>
        </div>
    </div>
</template>

<!-- 优惠卡列表 Offcanvas -->
<div class="offcanvas offcanvas-end offcanvas-sheet" tabindex="-1" id="discountCardsOffcanvas">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title"><i class="bi bi-credit-card-2-front"></i> <span data-i18n="discount_cards">优惠卡</span></h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body p-0">
    <div class="p-3 border-bottom border-sheet">
      <p class="small text-muted mb-0" data-i18n="discount_cards_desc">浏览可购买的优惠卡，点击查看详情。</p>
    </div>
    <div id="discount_cards_list" class="list-group list-group-flush">
      <div class="text-center p-4"><div class="spinner-border"></div></div>
    </div>
  </div>
</div>

<!-- 优惠卡详情 Modal -->
<div class="modal fade" id="discountCardDetailModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content modal-sheet">
      <div class="modal-header">
        <h5 class="modal-title" id="card_detail_name">-</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="card mb-3">
          <div class="card-body">
            <div class="row mb-2">
              <div class="col-6">
                <small class="text-muted" data-i18n="card_price">售价</small>
                <div class="fs-5 fw-bold text-brand" id="card_detail_price">€0.00</div>
              </div>
              <div class="col-6">
                <small class="text-muted" data-i18n="card_total_uses">总次数</small>
                <div class="fs-5 fw-bold" id="card_detail_total_uses">0</div>
              </div>
            </div>
            <div class="row mb-2">
              <div class="col-6">
                <small class="text-muted" data-i18n="card_validity">有效期</small>
                <div id="card_detail_validity">-</div>
              </div>
              <div class="col-6">
                <small class="text-muted" data-i18n="card_scope">适用范围</small>
                <div id="card_detail_scope">-</div>
              </div>
            </div>
            <div class="row" id="card_limits_section" style="display: none;">
              <div class="col-12">
                <small class="text-muted" data-i18n="card_limits">使用限制</small>
                <div id="card_detail_limits" class="small">-</div>
              </div>
            </div>
          </div>
        </div>

        <!-- 重要提示区 -->
        <div id="card_detail_important_notice" class="alert alert-warning border-warning">
          <h6 class="alert-heading fw-bold">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <span data-i18n="important_notice">重要提示</span>
          </h6>
          <ul class="mb-2 small">
            <li data-i18n="notice_non_transferable">本优惠卡仅限绑定手机号本人使用，不可转让。</li>
            <li data-i18n="notice_no_refund">购买后不支持退款，请在确认无误后再购买。</li>
          </ul>
          <hr class="my-2">
          <p class="mb-0 small fw-semibold" data-i18n="notice_remind_customer">请提醒顾客。</p>
        </div>

        <div id="card_detail_notes" class="small text-muted"></div>
      </div>
      <div class="modal-footer d-grid gap-2">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-i18n="back">返回</button>
        <button type="button" class="btn btn-primary" id="btn_confirm_card_purchase" data-i18n="confirm_purchase">确认购买</button>
      </div>
    </div>
  </div>
</div>

<!-- 二次验证手机号 Modal -->
<div class="modal fade" id="phoneVerificationModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content modal-sheet">
      <div class="modal-header bg-warning">
        <h5 class="modal-title text-dark">
          <i class="bi bi-shield-check me-2"></i>
          <span data-i18n="phone_verification_title">手机号二次验证</span>
        </h5>
      </div>
      <div class="modal-body">
        <div class="alert alert-info">
          <p class="mb-2" data-i18n="phone_verification_instruction_1">请与顾客当面核对手机号，并再次完整输入，以确认优惠卡权益归属。</p>
          <p class="mb-0 small" data-i18n="phone_verification_instruction_2">Por favor, verifique el número de teléfono con el cliente y vuelva a introducirlo para confirmar la titularidad de la tarjeta promocional.</p>
        </div>
        <form id="phone_verification_form">
          <div class="form-floating mb-3">
            <input type="tel" class="form-control form-control-lg" id="phone_verification_input" placeholder="输入手机号" required autocomplete="off">
            <label for="phone_verification_input" data-i18n="phone_verification_placeholder">请再次输入手机号</label>
          </div>
          <div id="phone_verification_error" class="alert alert-danger d-none"></div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-i18n="cancel">取消</button>
        <button type="submit" form="phone_verification_form" class="btn btn-primary" data-i18n="verify_and_continue">验证并继续</button>
      </div>
    </div>
  </div>
</div>

<!-- 购卡成功 Modal -->
<div class="modal fade" id="cardPurchaseSuccessModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content modal-sheet">
      <div class="modal-body text-center p-4">
        <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
        <h3 class="mt-3" data-i18n="card_purchase_success">优惠卡购买成功</h3>
        <div class="alert alert-info mt-3">
          <p class="mb-0">
            <span data-i18n="card_bound_to_phone">本次优惠卡已绑定手机号：</span>
            <strong id="success_bound_phone">XXXXXXXXXXX</strong>
          </p>
        </div>
        <button type="button" class="btn btn-brand w-100 mt-3" id="btn_card_purchase_done" data-i18n="back_to_home">返回首页</button>
      </div>
    </div>
  </div>
</div>

<!-- Pass Payment Modal -->
<div class="modal fade" id="passPaymentModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content modal-sheet">
      <div class="modal-header">
        <h5 class="modal-title" data-i18n="pass_payment_title">优惠卡支付</h5>
      </div>
      <div class="modal-body">
        <p data-i18n="pass_payment_instruction">购买优惠卡仅支持现金或银行卡支付。</p>
        <div class="d-grid gap-2">
          <button id="btn_pass_pay_cash" class="btn btn-outline-primary btn-lg" data-payment-method="cash">
            <i class="bi bi-cash-coin me-2"></i> <span data-i18n="cash_payment">现金</span>
          </button>
          <button id="btn_pass_pay_card" class="btn btn-outline-primary btn-lg" data-payment-method="card">
            <i class="bi bi-credit-card me-2"></i> <span data-i18n="card_payment">刷卡</span>
          </button>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-i18n="cancel">取消</button>
      </div>
    </div>
  </div>
</div>

<script type="module" src="./assets/js/main.js?v=<?php echo $cache_version; ?>"></script>

</body>
</html>