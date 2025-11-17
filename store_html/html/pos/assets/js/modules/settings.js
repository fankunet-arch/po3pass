import { setHand, setPeak, getHand, isPeak } from '../state.js';

/**
 * 初始化设置面板UI以匹配当前状态。
 * 这个函数不再需要，因为 state.js 在加载时会自动处理UI同步。
 * 但为了以防万一有其他地方调用，我们保留一个空函数或一个简单的同步函数。
 */
export function initSettings() {
    // 同步UI控件的状态以匹配来自 state.js 的真实状态
    const currentHandMode = getHand(); // 'left' or 'right'
    const peakMode = isPeak();

    $('#setting_peak_mode').prop('checked', peakMode);
    
    if (currentHandMode === 'left') {
        $('#setting_lefty_mode').prop('checked', true);
    } else {
        $('#setting_righty_mode').prop('checked', true);
    }
}

/**
 * 当用户在设置面板中更改选项时，此函数被调用。
 * 它现在将调用 state.js 中的权威函数来更新全局状态。
 */
export function handleSettingChange() {
    // --- 高峰模式 ---
    const peakMode = $('#setting_peak_mode').is(':checked');
    setPeak(peakMode, true); // 调用 state.js 的 setPeak, true 表示持久化

    // --- 左/右手模式 ---
    const handModeValue = $('input[name="hand_mode"]:checked').val(); // "lefty-mode" or "righty-mode"
    // 从DOM值转换为 state.js 需要的 'left' 或 'right'
    const handMode = handModeValue === 'lefty-mode' ? 'left' : 'right';
    setHand(handMode, true); // 调用 state.js 的 setHand, true 表示持久化
}