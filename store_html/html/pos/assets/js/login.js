/**
 * TopTea POS - Login Page Logic (Bilingual)
 * Engineer: Gemini | Date: 2025-10-29
 */
$(function() {
    const I18N = {
        'zh': {
            title_sub: '点餐收银系统',
            label_store_code: '门店码',
            label_username: '用户名',
            label_password: '密码',
            btn_login: '登 录',
            error_invalid_credentials: '无效的门店码、用户名或密码。',
        },
        'es': {
            title_sub: 'Sistema de Caja',
            label_store_code: 'Código de Tienda',
            label_username: 'Usuario',
            label_password: 'Contraseña',
            btn_login: 'Iniciar Sesión',
            error_invalid_credentials: 'Código de tienda, usuario o contraseña no válidos.',
        }
    };

    function applyLang(lang) {
      localStorage.setItem("POS_LANG", lang);
      $('.lang-flag').removeClass('active').filter(`[data-lang="${lang}"]`).addClass('active');
      const translations = I18N[lang] || I18N['zh'];
      
      $('[data-i18n-key]').each(function() {
          const key = $(this).data('i18n-key');
          if (translations[key]) {
              // For labels, we need to target the text node inside
              if ($(this).is('label')) {
                  $(this).text(translations[key]);
              } else {
                  $(this).html(translations[key]);
              }
          }
      });

      // Also update placeholders if needed, though this page uses floating labels
      // Example: $('[data-i18n-placeholder-key]').attr('placeholder', translations[key]);
    }

    $('.lang-flag').on('click', function() {
        applyLang($(this).data('lang'));
    });

    // Initialize with saved or default language
    const savedLang = localStorage.getItem("POS_LANG") || "zh";
    applyLang(savedLang);
});