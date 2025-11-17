import { STATE, I18N } from './state.js';

export function t(key) {
    return (I18N[STATE.lang]?.[key] || I18N['zh'][key]) || key;
}

export function fmtEUR(n) {
    return `€${(Math.round(parseFloat(n) * 100) / 100).toFixed(2)}`;
}

export function toast(msg) {
    const t = new bootstrap.Toast('#sys_toast', { delay: 2500 });
    $('#toast_msg').text(msg);
    t.show();
}

/* * ========= 新增：从 eod.js 迁移的辅助函数 ========= 
 */

/**
 * 安全地获取翻译文本，提供回退
 * @param {string} key 
 * @param {string} fallback 
 * @returns {string}
 */
export function safeT(key, fallback){
  try{
    const v = t(key);
    return (!v || v === key) ? fallback : v;
  }catch(_){
    return fallback;
  }
}

/**
 * 健壮的欧式数字解析 (例如 "1,50" 或 "1.500,00")
 * @param {string | number} input 
 * @returns {number}
 */
export function parseEuroNumber(input){
  if(input == null) return NaN;
  let s = String(input).trim();
  s = s.replace(/[€\s]/g, '');
  s = s.replace(/[^0-9.,-]/g, '');
  if(s.includes(',') && !s.includes('.')){
    s = s.replace(/\./g, '');
    s = s.replace(',', '.');
  }else if(s.includes(',') && s.includes('.')){
    const lastComma = s.lastIndexOf(',');
    const lastDot   = s.lastIndexOf('.');
    const decIsComma = lastComma > lastDot;
    if(decIsComma){
      s = s.replace(/\./g, '');
      const i = s.lastIndexOf(',');
      s = s.slice(0,i).replace(/,/g,'') + '.' + s.slice(i+1);
    }else{
      s = s.replace(/,/g,'');
    }
  }else{
    s = s.replace(/,/g,'');
  }
  s = s.replace(/(?!^)-/g, '');
  const n = Number(s);
  return isFinite(n) ? n : NaN;
}

/**
 * 封装 GET 请求
 * @param {string} url 
 * @returns {Promise<any>}
 */
export async function apiGetJSON(url){
  const resp = await fetch(url, { credentials:'same-origin', cache: 'no-store' });
  let data = null; try{ data = await resp.json(); }catch(_){}
  if (!resp.ok) throw new Error(data?.message || `HTTP ${resp.status}`);
  return data;
}

/** DOM 辅助：获取元素 */
export function getEl(id){ return document.getElementById(id); }

/** DOM 辅助：设置元素文本 */
export function setText(id, val){ const el=document.getElementById(id); if(el) el.textContent = val; }

/** 格式化为2位小数 */
export function to2(n){ return Math.round(Number(n ?? 0)*100)/100; }

/** HTML 转义 */
export function escapeHtml(str){ return String(str ?? '').replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s])); }