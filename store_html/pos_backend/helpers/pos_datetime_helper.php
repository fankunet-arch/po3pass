<?php
/**
 * Toptea POS - 统一时间助手 (A2 UTC SYNC)
 * 职责: 提供 UTC 时间与 Madrid 本地日窗转换。
 * Version: 1.0.1
 * Date: 2025-11-09
 *
 * [GEMINI SUPER-ENGINEER FIX (Error 1)]:
 * - 修复了 to_utc_window 的函数签名和逻辑，使其与 KDS 助手 (kds_datetime_helper.php) 保持一致。
 * - 原签名 (string $local_date, string $tz) 错误，且无法处理单日查询 (null)。
 * - 新签名 (string $local_date_from, ?string $local_date_to = null, string $timezone = APP_DEFAULT_TIMEZONE)
 * - 这解决了 pos_registry.php 中 handle_check_eod_status 调用此函数时的 ArgumentCountError (500 错误)。
 */

if (!defined('APP_DEFAULT_TIMEZONE')) {
    define('APP_DEFAULT_TIMEZONE', 'Europe/Madrid');
}

if (!function_exists('utc_now')) {
    /**
     * 获取当前的 UTC DateTime 对象。
     * @return DateTime
     */
    function utc_now(): DateTime {
        return new DateTime('now', new DateTimeZone('UTC'));
    }
}

if (!function_exists('fmt_local')) {
    /**
     * 将一个 UTC DateTime 对象或 UTC 字符串转换为指定时区的本地化时间字符串。
     *
     * @param string|DateTime|null $utc_datetime UTC 时间 (字符串或 DateTime 对象)
     * @param string $format (可选) PHP DateTime 格式化字符串
     * @param string $timezone (可选) 目标时区
     * @return string|null 格式化后的本地时间，如果输入无效则返回 null
     */
    function fmt_local($utc_datetime, string $format = 'Y-m-d H:i:s', string $timezone = APP_DEFAULT_TIMEZONE): ?string {
        if (!$utc_datetime) {
            return null;
        }
        
        try {
            if ($utc_datetime instanceof DateTime) {
                $dt = clone $utc_datetime;
            } else {
                // 假设输入的字符串是 UTC
                $dt = new DateTime($utc_datetime, new DateTimeZone('UTC'));
            }
            
            $dt->setTimezone(new DateTimeZone($timezone));
            return $dt->format($format);
            
        } catch (Exception $e) {
            error_log("fmt_local error: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('to_utc_window')) {
    /**
     * [GEMINI FIX 1] 逻辑和签名已从 kds_datetime_helper.php 同步
     *
     * 将本地时区的开始和结束日期字符串转换为 UTC DateTime 对象数组。
     * 用于数据库查询 (BETWEEN $utc_start AND $utc_end)。
     *
     * @param string $local_date_from 本地开始日期 (e.g., "2025-11-09")
     * @param string|null $local_date_to (可选) 本地结束日期 (e.g., "2025-11-10")。如果为 null，则结束时间为开始日期的 23:59:59。
     * @param string $timezone (可选) 本地时区
     * @return array [DateTime $utc_start, DateTime $utc_end]
     */
    function to_utc_window(string $local_date_from, ?string $local_date_to = null, string $timezone = APP_DEFAULT_TIMEZONE): array {
        
        $tz = new DateTimeZone($timezone);
        $utc = new DateTimeZone('UTC');

        try {
            // 处理开始时间
            $dt_start = new DateTime($local_date_from . ' 00:00:00', $tz);
            
            // 处理结束时间
            if ($local_date_to === null || $local_date_to === $local_date_from) {
                // 单日查询
                $dt_end = new DateTime($local_date_from . ' 23:59:59.999999', $tz);
            } else {
                // 日期范围查询
                $dt_end = new DateTime($local_date_to . ' 23:59:59.999999', $tz);
            }

            // 转换为 UTC
            $dt_start->setTimezone($utc);
            $dt_end->setTimezone($utc);

            return [$dt_start, $dt_end];

        } catch (Exception $e) {
            error_log("to_utc_window error: " . $e->getMessage());
            // 返回一个安全的、无效的范围
            return [utc_now(), utc_now()];
        }
    }
}