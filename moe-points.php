<?php
/**
 * Plugin Name: 萌积分 (Moe Points)
 * Plugin URI:  https://github.com/your-repo/moe-points
 * Description: 轻量级二次元风格积分系统，支持评论/签到/发文/注册/卡密/等级/排行榜等功能
 * Version:     1.0.0
 * Author:      MoePlugin
 * Text Domain: moe-points
 * Requires PHP: 7.4
 *
 * ============================================================
 * 文件职责：插件主入口
 *   - 定义全局常量（路径、URL、版本号）
 *   - 按顺序加载所有功能模块
 *   - 注册激活钩子（安装数据库）
 *   - 初始化各模块
 * ============================================================
 */

// 阻止直接访问（安全防护）
if ( ! defined( 'ABSPATH' ) ) exit;

// ── 全局常量 ────────────────────────────────────────────────
define( 'MOE_VER',  '1.1.0' );
define( 'MOE_PATH', plugin_dir_path( __FILE__ ) );  // 服务器绝对路径，末尾含 /
define( 'MOE_URL',  plugin_dir_url( __FILE__ ) );   // 网络访问URL，末尾含 /

// ── 加载核心模块（顺序很重要，被依赖的先加载）────────────────
require_once MOE_PATH . 'includes/class-database.php';
require_once MOE_PATH . 'includes/class-points.php';
require_once MOE_PATH . 'includes/class-levels.php';
require_once MOE_PATH . 'includes/class-unlock-guard.php';
require_once MOE_PATH . 'includes/class-profile.php';              // 个人主页核心
require_once MOE_PATH . 'includes/class-shortcode-profile.php';    // [gerzhu] 短代码
require_once MOE_PATH . 'includes/class-hooks.php';
require_once MOE_PATH . 'includes/class-shortcodes.php';
require_once MOE_PATH . 'includes/class-comment-display.php';

// ── 后台仅在管理员界面加载 ──────────────────────────────────
if ( is_admin() ) {
    require_once MOE_PATH . 'admin/class-admin.php';
}

// ── 激活插件时安装数据库 + 注册定时任务 ─────────────────────
register_activation_hook( __FILE__, function() {
    Moe_Database::install();
    Moe_Hooks::schedule_cron();
} );

// ── 停用插件时清理定时任务 ──────────────────────────────────
register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'moe_daily_cleanup' );
} );

// ── 插件加载完毕后初始化各模块 ──────────────────────────────
add_action( 'plugins_loaded', function() {
    new Moe_Hooks();
    new Moe_Shortcodes();
    new Moe_Shortcode_Profile();
    new Moe_Profile();
    new Moe_Comment_Display();
    if ( is_admin() ) {
        new Moe_Admin();
    }
} );
