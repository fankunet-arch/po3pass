/**
 * print.js — POS 打印模块 (Phase 4.3 Refactor)
 * - 实现了 handlePrintJobs
 * - 实现了 getPrinterConfigByRole
 * - 依赖 STATE.storeConfig (由 api.js 注入)
 * - 最终调用 window.AndroidBridge.print(JSON.stringify(jobData))
 */
import { STATE } from '../state.js';
import { t } from '../utils.js';

/**
 * [PHASE 4.3] 新增：根据角色获取打印机配置
 * @param {string} role (e.g., 'POS_RECEIPT', 'POS_STICKER')
 * @returns {object|null} 打印机配置, 或 null (如果跳过)
 */
function getPrinterConfigByRole(role) {
    const cfg = STATE.storeConfig || {};
    
    if (role === 'POS_RECEIPT') {
        return { 
            type: cfg.pr_receipt_type || 'NONE', 
            ip: cfg.pr_receipt_ip || null, 
            port: cfg.pr_receipt_port || null, 
            mac: cfg.pr_receipt_mac || null 
        };
    }
    
    if (role === 'POS_STICKER') {
        return { 
            type: cfg.pr_sticker_type || 'NONE', 
            ip: cfg.pr_sticker_ip || null, 
            port: cfg.pr_sticker_port || null, 
            mac: cfg.pr_sticker_mac || null 
        };
    }
    
    // POS 端忽略 'KDS_PRINTER' 角色
    return null; 
}

/**
 * [PHASE 4.3] 重构：处理来自后端的打印任务列表
 * @param {Array<object>} jobs - 后端返回的打印任务数组
 */
export function handlePrintJobs(jobs) {
    if (!jobs || jobs.length === 0) {
        console.log("No print jobs received.");
        return;
    }

    if (typeof window.AndroidBridge === 'undefined' || typeof window.AndroidBridge.print === 'undefined') {
        console.warn('window.AndroidBridge.print is not defined. Skipping all hardware print jobs.');
        // 即使没有打印机，也显示模拟预览 (如果需要)
        // previewMockJobs(jobs); 
        return;
    }

    jobs.forEach(job => {
        const printerConfig = getPrinterConfigByRole(job.printer_role);

        if (!printerConfig || printerConfig.type === 'NONE') {
            console.warn(`Skipping print job for role: ${job.printer_role} (No printer configured)`);
            return; // <-- 跳过 KDS 任务或未配置的打印机
        }
        
        // 查找模板
        const template = STATE.printTemplates ? STATE.printTemplates[job.type] : null;
        if (!template) {
            console.error(`Print Error: Template for type '${job.type}' not found.`);
            return;
        }

        const jobData = {
            type: job.type,           // e.g., "RECEIPT", "CUP_STICKER"
            template: template,       // { content: [...], size: "..." }
            data: job.data,           // { pickup_number: "1001", ... }
            printer_config: printerConfig // { type: "WIFI", ip: "...", ... }
        };

        try {
            console.log("Sending print job to AndroidBridge:", jobData);
            window.AndroidBridge.print(JSON.stringify(jobData));
        } catch (e) {
            console.error("Error calling AndroidBridge.print:", e, jobData);
        }
    });
}


/**
 * 模拟打印 (用于EOD报告等旧逻辑)
 * @param {object} data - The data context (e.g., EOD report data).
 * @param {object} template - The template object { content: [], size: "..." }.
 */
export async function printReceipt(data, template) {
    if (!data || !template || !Array.isArray(template.content)) {
        console.error('Invalid data or template for printing.');
        toast(t('print_failed') + ': Invalid input');
        return;
    }

    // [PHASE 4.3] 适配：EOD 报告使用 'POS_RECEIPT' 角色
    const printerConfig = getPrinterConfigByRole('POS_RECEIPT');
    
    if (printerConfig && printerConfig.type !== 'NONE' && 
        window.AndroidBridge && typeof window.AndroidBridge.print === 'function') {
        
        // 发送到真实打印机
        const jobData = {
            type: "EOD_REPORT", // 假设类型
            template: template,
            data: data,
            printer_config: printerConfig
        };
        try {
            console.log("Sending EOD print job to AndroidBridge:", jobData);
            window.AndroidBridge.print(JSON.stringify(jobData));
        } catch (e) {
            console.error("Error calling AndroidBridge.print for EOD:", e, jobData);
            toast(t('print_failed') + ': ' + e.message);
        }

    } else {
        // 回退到模拟预览
        console.warn("EOD Print: No printer configured or Bridge unavailable. Falling back to simulation.");
        const formattedLines = template.content.map(cmd => parseCommand(cmd, data).join('\n')).join('\n');
        const output = formattedLines;
        console.log("--- PRINT SIMULATION (EOD) ---");
        console.log(output);
        console.log("------------------------------");

        const previewModalEl = document.getElementById('printPreviewModal');
        if (previewModalEl) {
            document.getElementById('printPreviewBody').textContent = output;
            const modal = bootstrap.Modal.getOrCreateInstance(previewModalEl);
            modal.show();
        } else {
            alert("Print Preview:\n\n" + output);
        }
    }
}

/**
 * 内部辅助函数：解析命令（用于模拟器）
 */
function parseCommand(command, data) {
    const lines = [];
    const type = command.type;
    const value = command.value || '';
    const align = command.align || 'left'; // left, center, right
    const size = command.size || 'normal'; // normal, double
    const key = command.key || '';
    const boldValue = command.bold_value || false;

    const replacePlaceholders = (text) => {
        if (!text || typeof text !== 'string') return '';
        return text.replace(/\{([\w_]+)\}/g, (match, key) => {
            return data[key] !== undefined ? data[key] : match;
        });
    };
    
    const alignText = (text, width = 40) => {
        const len = text.length;
        if (align === 'center') {
            const padding = Math.max(0, Math.floor((width - len) / 2));
            return ' '.repeat(padding) + text;
        } else if (align === 'right') {
            const padding = Math.max(0, width - len);
            return ' '.repeat(padding) + text;
        }
        return text; // left align is default
    };

    switch (type) {
        case 'text':
            lines.push(alignText(replacePlaceholders(value), size === 'double' ? 20 : 40));
            break;
        case 'kv':
            const replacedKey = replacePlaceholders(key);
            const replacedValue = replacePlaceholders(value);
            const kvText = `${replacedKey}: ${boldValue ? '**' : ''}${replacedValue}${boldValue ? '**' : ''}`;
            lines.push(kvText);
            break;
        case 'divider':
            lines.push((command.char || '-').repeat(40));
            break;
        case 'feed':
            for (let i = 0; i < (command.lines || 1); i++) lines.push('');
            break;
        case 'cut':
            lines.push('--- CUT ---');
            break;
        case 'qr_code':
            lines.push(`[QR Code: ${replacePlaceholders(value)}]`);
            break;
        case 'items_loop':
            lines.push("[--- Item Loop Start ---]");
            (data.items || []).forEach(item => {
                const itemData = { ...data, ...item }; // 合并数据
                (command.items || []).forEach(itemCmd => {
                    lines.push(...parseCommand(itemCmd, itemData));
                });
                lines.push("-".repeat(40));
            });
            lines.push("[--- Item Loop End ---]");
            break;
        default:
            lines.push(`[Unknown command type: ${type}]`);
    }
    return lines;
}

/**
 * 确保打印预览模态框存在
 */
export function initializePrintSimulator() {
    if (!document.getElementById('printPreviewModal')) {
       document.body.insertAdjacentHTML('beforeend', `
         <div class="modal fade" id="printPreviewModal" tabindex="-1" aria-labelledby="printPreviewModalLabel" aria-hidden="true">
           <div class="modal-dialog modal-dialog-scrollable">
             <div class="modal-content modal-sheet">
               <div class="modal-header">
                 <h5 class="modal-title" id="printPreviewModalLabel">${t('print_preview_title')}</h5>
                 <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="${t('close')}"></button>
               </div>
               <div class="modal-body" id="printPreviewBody" style="font-family: monospace; white-space: pre; font-size: 0.8rem;">
                 </div>
               <div class="modal-footer">
                 <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${t('close')}</button>
               </div>
             </div>
           </div>
         </div>`);
      }
}