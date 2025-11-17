<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TopTea POS - 登录</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/pos_login.css?v=<?php echo time(); ?>">
    <style>
        .lang-switch-footer .lang-flag { cursor: pointer; opacity: 0.5; transition: opacity 0.2s; display: inline-block; margin: 0 5px; }
        .lang-switch-footer .lang-flag.active { opacity: 1; transform: scale(1.1); }
        .lang-switch-footer .flag { width: 36px; height: 24px; border-radius: 3px; border: 1px solid rgba(0,0,0,.2); }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <h2 class="text-center mb-1 fw-bold"><span style="color: #ED7762;">TOPTEA</span> POS</h2>
            <h5 class="text-center text-muted mb-4" data-i18n-key="title_sub">点餐收银系统</h5>
            
            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-danger" role="alert" data-i18n-key="error_invalid_credentials">
                    无效的门店码、用户名或密码。
                </div>
            <?php endif; ?>

            <form action="api/pos_login_handler.php" method="POST">
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="store_code" name="store_code" placeholder="门店码" required>
                    <label for="store_code" data-i18n-key="label_store_code">门店码</label>
                </div>
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="username" name="username" placeholder="用户名" required>
                    <label for="username" data-i18n-key="label_username">用户名</label>
                </div>
                <div class="form-floating mb-4">
                    <input type="password" class="form-control" id="password" name="password" placeholder="密码" required>
                    <label for="password" data-i18n-key="label_password">密码</label>
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-brand btn-lg">
                        <i class="bi bi-box-arrow-in-right me-2"></i> <span data-i18n-key="btn_login">登 录</span>
                    </button>
                </div>
            </form>

            <div class="lang-switch-footer mt-4 text-center">
                <span class="lang-flag" data-lang="zh"><svg class="flag" viewBox="0 0 30 20"><rect fill="#DE2910" height="20" width="30"></rect><text fill="#FFDE00" font-size="8.5" x="6" y="8">★</text><text fill="#FFDE00" font-size="3.8" x="12.5" y="4.5">★</text><text fill="#FFDE00" font-size="3.8" x="14.5" y="8">★</text><text fill="#FFDE00" font-size="3.8" x="12.5" y="11.5">★</text><text fill="#FFDE00" font-size="3.8" x="9.8" y="9.5">★</text></svg></span>
                <span class="lang-flag" data-lang="es"><svg class="flag" viewBox="0 0 30 20"><rect fill="#AA151B" height="20" width="30"></rect><rect y="5" width="30" height="10" fill="#F1BF00"></rect></svg></span>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="assets/js/login.js?v=<?php echo time(); ?>"></script>
</body>
</html>