<?php
/**
 * ============================================================
 * 文件职责：后台管理（所有后台页面都在这里）
 *
 *  子菜单页面：
 *    moe-points         → 基本设置（积分数值、开关、排行榜配置）
 *    moe-points-levels  → 等级设置（增删改等级、头衔、所需积分）
 *    moe-points-users   → 用户积分管理（搜索用户、手动调整积分）
 *    moe-points-logs    → 积分日志（查看、清理）
 *    moe-points-cards   → 卡密管理（批量生成、查看使用情况）
 * ============================================================
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Moe_Admin {

    public function __construct() {
        add_action( 'admin_menu',              [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts',   [ $this, 'enqueue_assets' ] );

        // 表单提交处理（使用 admin_post 处理，安全且规范）
        add_action( 'admin_post_moe_save_settings',    [ $this, 'save_settings' ] );
        add_action( 'admin_post_moe_save_levels',      [ $this, 'save_levels' ] );
        add_action( 'admin_post_moe_save_profile_cfg', [ $this, 'save_profile_cfg' ] );
        add_action( 'admin_post_moe_save_ulimits',     [ $this, 'save_unlock_limits' ] );
        add_action( 'admin_post_moe_unban',            [ $this, 'handle_unban' ] );
        add_action( 'admin_post_moe_clear_bans',       [ $this, 'handle_clear_bans' ] );
        add_action( 'admin_post_moe_clear_logs',       [ $this, 'clear_logs' ] );
        add_action( 'admin_post_moe_clear_checkin',    [ $this, 'clear_checkin' ] );
        add_action( 'admin_post_moe_gen_cards',        [ $this, 'generate_cards' ] );
        add_action( 'admin_post_moe_del_cards',        [ $this, 'delete_cards' ] );

        // AJAX：手动调整用户积分
        add_action( 'wp_ajax_moe_admin_set_points', [ $this, 'ajax_set_points' ] );
    }

    // ── 注册菜单 ─────────────────────────────────────────────
    public function register_menus() {
        add_menu_page(
            '萌积分设置', '✨ 萌积分',
            'manage_options', 'moe-points',
            [ $this, 'page_settings' ],
            'dashicons-star-filled', 30
        );
        add_submenu_page( 'moe-points', '基本设置',  '基本设置',    'manage_options', 'moe-points',            [ $this, 'page_settings' ] );
        add_submenu_page( 'moe-points', '等级设置',  '等级设置',    'manage_options', 'moe-points-levels',     [ $this, 'page_levels' ] );
        add_submenu_page( 'moe-points', '个人主页',  '👤 个人主页', 'manage_options', 'moe-points-profile',    [ $this, 'page_profile' ] );
        add_submenu_page( 'moe-points', '解锁限制',  '🛡 解锁限制', 'manage_options', 'moe-points-ulimit',     [ $this, 'page_unlock_limits' ] );
        add_submenu_page( 'moe-points', '封禁管理',  '🚫 封禁管理', 'manage_options', 'moe-points-bans',       [ $this, 'page_bans' ] );
        add_submenu_page( 'moe-points', '用户积分',  '用户积分',    'manage_options', 'moe-points-users',      [ $this, 'page_users' ] );
        add_submenu_page( 'moe-points', '积分日志',  '积分日志',    'manage_options', 'moe-points-logs',       [ $this, 'page_logs' ] );
        add_submenu_page( 'moe-points', '卡密管理',  '卡密管理',    'manage_options', 'moe-points-cards',      [ $this, 'page_cards' ] );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'moe-points' ) === false ) return;
        wp_enqueue_style(  'moe-admin-css', MOE_URL . 'assets/css/admin.css', [], MOE_VER );
        wp_enqueue_script( 'moe-admin-js',  MOE_URL . 'assets/js/admin.js', [ 'jquery' ], MOE_VER, true );
        wp_localize_script( 'moe-admin-js', 'moeAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'moe_admin_nonce' ),
        ] );
    }

    // ══════════════════════════════════════════════════════════
    //  页面一：基本设置
    // ══════════════════════════════════════════════════════════
    public function page_settings() {
        $saved = isset( $_GET['saved'] );
        $checkin_cleared = isset( $_GET['checkin_cleared'] );
        ?>
        <div class="wrap moe-wrap">
            <h1>✨ 萌积分 · 基本设置</h1>
            <?php if ( $saved ) echo '<div class="notice notice-success is-dismissible"><p>设置已保存！</p></div>'; ?>
            <?php if ( $checkin_cleared ) echo '<div class="notice notice-success is-dismissible"><p>✅ 签到记录已清空，所有用户可重新签到！</p></div>'; ?>

            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                <input type="hidden" name="action" value="moe_save_settings">
                <?php wp_nonce_field( 'moe_save_settings_nonce' ); ?>

                <!-- 积分前缀 -->
                <div class="moe-card">
                    <h2>🏷️ 积分前缀</h2>
                    <table class="form-table">
                        <tr>
                            <th>积分前缀名称</th>
                            <td>
                                <input type="text" name="moe_prefix" value="<?php echo esc_attr( get_option('moe_prefix','金币') ); ?>" class="regular-text">
                                <p class="description">如：金币、硬币、积分、星星 —— 显示在所有积分数字前</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- 积分数值设置 -->
                <div class="moe-card">
                    <h2>💰 积分数值设置</h2>
                    <table class="form-table">
                        <?php
                        $fields = [
                            'moe_pts_register'    => [ '新用户注册', '用户注册账号时一次性获得' ],
                            'moe_pts_login'       => [ '每日登录', '每天首次登录获得（同一天只奖励一次）' ],
                            'moe_pts_checkin'     => [ '每日签到 [qiandao]', '点击签到按钮获得，每天只能签到一次' ],
                            'moe_pts_comment'     => [ '发表评论', '每篇文章只能通过评论获得一次' ],
                            'moe_pts_del_comment' => [ '评论被删除（扣除）', '评论被管理员删除时扣除的积分数' ],
                            'moe_pts_post'        => [ '发布文章', '文章从草稿/待审发布时奖励（含投稿插件草稿）' ],
                        ];
                        foreach ( $fields as $key => [$label, $desc] ) :
                        ?>
                        <tr>
                            <th><?php echo esc_html( $label ); ?></th>
                            <td>
                                <input type="number" name="<?php echo $key; ?>" value="<?php echo (int) get_option( $key ); ?>" class="small-text" min="0"> 个积分
                                <p class="description"><?php echo esc_html( $desc ); ?></p>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>

                <!-- 🧪 测试工具 -->
                <div class="moe-card" style="border-color:#f0c040;background:#fffdf0">
                    <h2>🧪 测试工具</h2>
                    <table class="form-table">
                        <tr>
                            <th>清空所有签到记录</th>
                            <td>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline"
                                      onsubmit="return confirm('确认清空所有用户的签到记录？（仅清 last_checkin 字段，积分不变）')">
                                    <input type="hidden" name="action" value="moe_clear_checkin">
                                    <?php wp_nonce_field('moe_clear_checkin_nonce'); ?>
                                    <button type="submit" class="button">🗑 清空签到记录</button>
                                </form>
                                <p class="description">清空后所有用户可重新签到，方便测试。仅清除签到日期，不影响积分。</p>
                            </td>
                        </tr>
                    </table>
                </div>

                                <!-- 每日上限 -->
                <div class="moe-card">
                    <h2>📊 每日上限</h2>
                    <table class="form-table">
                        <tr>
                            <th>每日发文章积分上限</th>
                            <td>
                                <input type="number" name="moe_limit_post_daily" value="<?php echo (int) get_option('moe_limit_post_daily', 20); ?>" class="small-text" min="0"> 积分
                                <p class="description">设为 0 表示无上限</p>
                            </td>
                        </tr>
                        <tr>
                            <th>每日评论积分上限</th>
                            <td>
                                <input type="number" name="moe_limit_comment_daily" value="<?php echo (int) get_option('moe_limit_comment_daily', 10); ?>" class="small-text" min="0"> 积分
                                <p class="description">设为 0 表示无上限</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- 积分解锁时效 -->
                <div class="moe-card">
                    <h2>⏱ 积分解锁时效</h2>
                    <table class="form-table">
                        <tr>
                            <th>开启解锁时效</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="moe_unlock_expire_enabled" value="1"
                                        <?php checked( get_option('moe_unlock_expire_enabled', 0), 1 ); ?>>
                                    启用后，用户解锁 [ovo] 内容将有时间限制，过期需重新付积分解锁
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>解锁有效天数</th>
                            <td>
                                <input type="number" name="moe_unlock_expire_days"
                                    value="<?php echo (int) get_option('moe_unlock_expire_days', 2); ?>"
                                    class="small-text" min="1" max="3650"> 天
                                <p class="description">从用户解锁时刻起计算，超过此天数后内容重新锁定</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- 短代码设置 -->
                <div class="moe-card">
                    <h2>🔒 短代码设置</h2>
                    <table class="form-table">
                        <tr>
                            <th>[ovo] 解锁所需积分</th>
                            <td>
                                <input type="number" name="moe_ovo_cost" value="<?php echo (int) get_option('moe_ovo_cost', 3); ?>" class="small-text" min="1"> 积分
                                <p class="description">使用 [ovo]内容[/ovo] 包裹需要付费查看的内容；也可在短代码中单独设置 [ovo cost="10"]</p>
                            </td>
                        </tr>
                        <tr>
                            <th>[666qwq] 默认所需等级</th>
                            <td>
                                <select name="moe_level_required">
                                    <?php foreach ( Moe_Levels::titles_map() as $num => $title ) : ?>
                                    <option value="<?php echo $num; ?>" <?php selected( get_option('moe_level_required', 2), $num ); ?>>
                                        等级<?php echo $num; ?>：<?php echo esc_html( $title ); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">也可在短代码中单独指定 [666qwq level="3"]</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- 排行榜设置 -->
                <div class="moe-card">
                    <h2>🏆 排行榜设置 [dengji]</h2>
                    <table class="form-table">
                        <tr>
                            <th>排行榜标题</th>
                            <td><input type="text" name="moe_rank_title" value="<?php echo esc_attr( get_option('moe_rank_title','积分排行榜') ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th>显示前几名</th>
                            <td><input type="number" name="moe_rank_count" value="<?php echo (int) get_option('moe_rank_count', 10); ?>" class="small-text" min="1" max="100"></td>
                        </tr>
                    </table>
                </div>

                <!-- 功能开关 -->
                <div class="moe-card">
                    <h2>⚙️ 功能开关</h2>
                    <table class="form-table">
                        <tr>
                            <th>悬浮球</th>
                            <td>
                                <label><input type="checkbox" name="moe_float_ball" value="1" <?php checked( get_option('moe_float_ball', 1), 1 ); ?>> 开启右下角悬浮球（登录用户可见）</label>
                            </td>
                        </tr>
                        <tr>
                            <th>积分变动通知</th>
                            <td>
                                <label><input type="checkbox" name="moe_notification" value="1" <?php checked( get_option('moe_notification', 1), 1 ); ?>> 开启右上角 Toast 提示（如：签到成功，金币+3）</label>
                            </td>
                        </tr>
                        <tr>
                            <th>日志自动清理</th>
                            <td>
                                <select name="moe_log_cleanup">
                                    <?php $cur = get_option('moe_log_cleanup','month'); ?>
                                    <option value="never"  <?php selected($cur,'never');  ?>>永不清理</option>
                                    <option value="day"    <?php selected($cur,'day');    ?>>保留1天</option>
                                    <option value="month"  <?php selected($cur,'month');  ?>>保留1个月</option>
                                    <option value="3month" <?php selected($cur,'3month'); ?>>保留3个月</option>
                                </select>
                                <p class="description">每天凌晨自动执行一次清理</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button( '💾 保存设置' ); ?>
            </form>
        </div>
        <?php
    }

    public function save_settings() {
        check_admin_referer( 'moe_save_settings_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '权限不足' );

        $options = [
            'moe_prefix', 'moe_pts_register', 'moe_pts_login', 'moe_pts_checkin',
            'moe_pts_comment', 'moe_pts_del_comment', 'moe_pts_post',
            'moe_limit_post_daily', 'moe_limit_comment_daily',
            'moe_ovo_cost', 'moe_unlock_expire_days', 'moe_level_required',
            'moe_rank_title', 'moe_rank_count', 'moe_log_cleanup',
        ];
        foreach ( $options as $key ) {
            if ( isset( $_POST[ $key ] ) ) {
                update_option( $key, sanitize_text_field( $_POST[ $key ] ) );
            }
        }
        // 复选框（未勾选时 POST 不传值，需特殊处理）
        update_option( 'moe_float_ball',              isset( $_POST['moe_float_ball'] ) ? 1 : 0 );
        update_option( 'moe_notification',            isset( $_POST['moe_notification'] ) ? 1 : 0 );
        update_option( 'moe_unlock_expire_enabled',   isset( $_POST['moe_unlock_expire_enabled'] ) ? 1 : 0 );

        wp_redirect( admin_url( 'admin.php?page=moe-points&saved=1' ) );
        exit;
    }

    // ══════════════════════════════════════════════════════════
    //  页面二：等级设置
    // ══════════════════════════════════════════════════════════
    public function page_levels() {
        $saved  = isset( $_GET['saved'] );
        $levels = Moe_Levels::all();
        ?>
        <div class="wrap moe-wrap">
            <h1>⭐ 萌积分 · 等级设置</h1>
            <?php if ( $saved ) echo '<div class="notice notice-success is-dismissible"><p>等级设置已保存！</p></div>'; ?>
            <p class="description">
                设置积分等级、头衔名称和评论区展示效果。<br>
                <strong>头像框：</strong>上传一张透明 PNG 图片（建议正方形），将叠加显示在用户头像上方。<br>
                <strong>头衔徽章：</strong>在评论区用户名旁显示彩色小标签，可单独设置每个等级的背景色。
            </p>

            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="moe_save_levels">
                <?php wp_nonce_field( 'moe_save_levels_nonce' ); ?>

                <?php foreach ( $levels as $i => $l ) :
                    $lnum        = (int) $l['level'];
                    $show_frame  = ! empty( $l['show_frame'] );
                    $show_badge  = ! empty( $l['show_badge'] );
                    $frame_url   = esc_url( $l['frame_url']   ?? '' );
                    $badge_color = esc_attr( $l['badge_color'] ?? '#5bb8f5' );
                ?>
                <div class="moe-card moe-level-card" id="moe-level-card-<?php echo $i; ?>">
                    <h2 style="display:flex;align-items:center;justify-content:space-between">
                        <span>等级 <?php echo $lnum; ?> · <?php echo esc_html( $l['title'] ); ?></span>
                        <button type="button" class="button button-link-delete moe-del-level-card" data-idx="<?php echo $i; ?>">🗑 删除此等级</button>
                    </h2>

                    <!-- 基本信息 -->
                    <input type="hidden" name="levels[<?php echo $i; ?>][idx]" value="<?php echo $i; ?>">
                    <table class="form-table" style="margin-bottom:0">
                        <tr>
                            <th>等级编号</th>
                            <td><input type="number" name="levels[<?php echo $i; ?>][level]" value="<?php echo $lnum; ?>" class="small-text" min="1" required></td>
                        </tr>
                        <tr>
                            <th>头衔名称</th>
                            <td><input type="text" name="levels[<?php echo $i; ?>][title]" value="<?php echo esc_attr($l['title']); ?>" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th>所需积分（≥）</th>
                            <td><input type="number" name="levels[<?php echo $i; ?>][points]" value="<?php echo (int)$l['points']; ?>" class="small-text" min="0" required></td>
                        </tr>
                    </table>

                    <hr style="border-color:#e8f4fb;margin:16px 0 12px">

                    <!-- 头像框 -->
                    <table class="form-table" style="margin-bottom:0">
                        <tr>
                            <th>显示头像框</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="levels[<?php echo $i; ?>][show_frame]" value="1" <?php checked( $show_frame ); ?>>
                                    在评论区显示头像框
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>头像框图片</th>
                            <td>
                                <?php if ( $frame_url ) : ?>
                                <div style="margin-bottom:8px">
                                    <img src="<?php echo $frame_url; ?>" style="width:64px;height:64px;object-fit:contain;border:1px solid #ddd;border-radius:4px;background:#f5f5f5;padding:2px">
                                    <label style="margin-left:8px;color:#c00;font-size:12px">
                                        <input type="checkbox" name="levels[<?php echo $i; ?>][remove_frame]" value="1"> 删除图片
                                    </label>
                                </div>
                                <input type="hidden" name="levels[<?php echo $i; ?>][frame_url]" value="<?php echo $frame_url; ?>">
                                <?php endif; ?>
                                <input type="file" name="levels_frame[<?php echo $i; ?>]" accept="image/png,image/gif,image/webp">
                                <p class="description">建议上传透明背景 PNG，正方形，尺寸100×100px以上</p>
                            </td>
                        </tr>

                        <!-- 头衔徽章 -->
                        <tr>
                            <th>显示头衔徽章</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="levels[<?php echo $i; ?>][show_badge]" value="1" <?php checked( $show_badge ); ?>>
                                    在评论区名字旁显示头衔名称徽章
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>徽章背景色</th>
                            <td>
                                <input type="color" name="levels[<?php echo $i; ?>][badge_color]" value="<?php echo $badge_color; ?>">
                                <span style="display:inline-block;margin-left:8px;padding:2px 10px;border-radius:20px;font-size:12px;color:#fff;background:<?php echo $badge_color; ?>"><?php echo esc_html($l['title']); ?></span>
                                <p class="description">徽章文字颜色固定为白色，建议选深色系背景</p>
                            </td>
                        </tr>
                    </table>
                </div>
                <?php endforeach; ?>

                <!-- 添加新等级 -->
                <div class="moe-card" id="moe-new-levels-wrap"></div>
                <template id="moe-level-tpl">
                    <div class="moe-card moe-level-card">
                        <h2>新等级</h2>
                        <table class="form-table" style="margin-bottom:0">
                            <tr><th>等级编号</th><td><input type="number" name="levels[__IDX__][level]"  class="small-text" min="1" required></td></tr>
                            <tr><th>头衔名称</th><td><input type="text"   name="levels[__IDX__][title]"  class="regular-text" required></td></tr>
                            <tr><th>所需积分（≥）</th><td><input type="number" name="levels[__IDX__][points]" class="small-text" min="0" value="0" required></td></tr>
                        </table>
                        <hr style="border-color:#e8f4fb;margin:16px 0 12px">
                        <table class="form-table" style="margin-bottom:0">
                            <tr><th>显示头像框</th><td><label><input type="checkbox" name="levels[__IDX__][show_frame]" value="1"> 在评论区显示头像框</label></td></tr>
                            <tr><th>头像框图片</th><td><input type="file" name="levels_frame[__IDX__]" accept="image/png,image/gif,image/webp"><p class="description">建议上传透明背景PNG，正方形</p></td></tr>
                            <tr><th>显示头衔徽章</th><td><label><input type="checkbox" name="levels[__IDX__][show_badge]" value="1"> 在评论区名字旁显示头衔徽章</label></td></tr>
                            <tr><th>徽章背景色</th><td><input type="color" name="levels[__IDX__][badge_color]" value="#5bb8f5"></td></tr>
                        </table>
                        <p style="margin-top:12px"><button type="button" class="button button-link-delete moe-del-level-card">🗑 删除此等级</button></p>
                    </div>
                </template>

                <p>
                    <button type="button" id="moe-add-level" class="button">➕ 添加等级</button>
                </p>

                <?php submit_button( '💾 保存等级设置' ); ?>
            </form>
        </div>
        <?php
    }

    public function save_levels() {
        check_admin_referer( 'moe_save_levels_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '权限不足' );

        $raw    = $_POST['levels'] ?? [];
        $files  = $_FILES['levels_frame'] ?? [];
        $levels = [];

        foreach ( $raw as $i => $item ) {
            $level  = (int) ( $item['level']  ?? 0 );
            $title  = sanitize_text_field( $item['title'] ?? '' );
            $points = (int) ( $item['points'] ?? 0 );
            if ( ! $level || ! $title ) continue;

            // 处理头像框图片上传
            $frame_url = sanitize_url( $item['frame_url'] ?? '' );

            // 用户勾选删除图片
            if ( ! empty( $item['remove_frame'] ) ) {
                $frame_url = '';
            }

            // 处理新上传的图片
            if ( ! empty( $files['name'][ $i ] ) && $files['error'][ $i ] === UPLOAD_ERR_OK ) {
                $file_data = [
                    'name'     => $files['name'][ $i ],
                    'type'     => $files['type'][ $i ],
                    'tmp_name' => $files['tmp_name'][ $i ],
                    'error'    => $files['error'][ $i ],
                    'size'     => $files['size'][ $i ],
                ];
                $upload = wp_handle_upload( $file_data, [ 'test_form' => false ] );

                if ( ! empty( $upload['url'] ) ) {
                    $frame_url = $upload['url'];
                }
            }

            $levels[] = [
                'level'       => $level,
                'title'       => $title,
                'points'      => $points,
                'show_frame'  => ! empty( $item['show_frame'] ),
                'show_badge'  => ! empty( $item['show_badge'] ),
                'frame_url'   => $frame_url,
                'badge_color' => sanitize_hex_color( $item['badge_color'] ?? '#5bb8f5' ) ?: '#5bb8f5',
            ];
        }

        update_option( 'moe_levels', wp_json_encode( $levels ) );
        Moe_Levels::flush_cache(); // 清除请求级缓存

        wp_redirect( admin_url( 'admin.php?page=moe-points-levels&saved=1' ) );
        exit;
    }

    // ══════════════════════════════════════════════════════════
    //  页面三：用户积分管理
    // ══════════════════════════════════════════════════════════
    public function page_users() {
        global $wpdb;
        $prefix = get_option( 'moe_prefix', '金币' );

        // 搜索
        $search = sanitize_text_field( $_GET['s'] ?? '' );
        $paged  = max( 1, (int)( $_GET['paged'] ?? 1 ) );
        $per    = 20;
        $offset = ( $paged - 1 ) * $per;

        // 构建查询
        $where  = $search
            ? $wpdb->prepare( "WHERE u.user_login LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s",
                "%{$search}%", "%{$search}%", "%{$search}%" )
            : '';

        $users = $wpdb->get_results( "
            SELECT u.ID, u.display_name, u.user_login,
                   COALESCE(p.points, 0) AS points
            FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->prefix}moe_points p ON u.ID = p.user_id
            {$where}
            ORDER BY points DESC
            LIMIT {$per} OFFSET {$offset}
        " );

        $total = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} u {$where}" );
        $pages = ceil( $total / $per );

        ?>
        <div class="wrap moe-wrap">
            <h1>👤 萌积分 · 用户积分管理</h1>

            <!-- 搜索框 -->
            <form method="get">
                <input type="hidden" name="page" value="moe-points-users">
                <p class="search-box">
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="搜索用户名/昵称/邮箱">
                    <button type="submit" class="button">🔍 搜索</button>
                </p>
            </form>

            <!-- 用户列表 -->
            <div class="moe-card">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>用户ID</th>
                            <th>用户名</th>
                            <th>昵称</th>
                            <th><?php echo esc_html( $prefix ); ?>数量</th>
                            <th>快捷调整</th>
                            <th>手动设置</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $users as $u ) : ?>
                    <tr>
                        <td><?php echo $u->ID; ?></td>
                        <td><?php echo esc_html( $u->user_login ); ?></td>
                        <td><?php echo esc_html( $u->display_name ); ?></td>
                        <td><strong><?php echo $u->points; ?></strong></td>
                        <td>
                            <!-- 快捷按钮：+10 / -10 可在设置中修改（这里固定+10/-10作为示例） -->
                            <button class="button moe-quick-pts" data-uid="<?php echo $u->ID; ?>" data-delta="10">+10</button>
                            <button class="button moe-quick-pts" data-uid="<?php echo $u->ID; ?>" data-delta="-10">-10</button>
                            <button class="button moe-quick-pts" data-uid="<?php echo $u->ID; ?>" data-delta="50">+50</button>
                            <button class="button moe-quick-pts" data-uid="<?php echo $u->ID; ?>" data-delta="-50">-50</button>
                        </td>
                        <td>
                            <input type="number" class="small-text moe-pts-input" data-uid="<?php echo $u->ID; ?>" value="<?php echo $u->points; ?>">
                            <button class="button button-primary moe-pts-set" data-uid="<?php echo $u->ID; ?>">设置</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- 分页 -->
                <?php if ( $pages > 1 ) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links( [
                            'base'    => add_query_arg( 'paged', '%#%' ),
                            'format'  => '',
                            'current' => $paged,
                            'total'   => $pages,
                        ] );
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <p id="moe-pts-feedback" class="moe-feedback"></p>
        </div>
        <?php
    }

    /** AJAX：后台直接设置用户积分 */
    public function ajax_set_points() {
        check_ajax_referer( 'moe_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '权限不足' );

        $uid   = (int) ( $_POST['uid'] ?? 0 );
        $mode  = sanitize_key( $_POST['mode'] ?? 'set' );  // set | delta
        $value = (int) ( $_POST['value'] ?? 0 );

        if ( ! $uid ) wp_send_json_error( '用户ID无效' );

        $operator = wp_get_current_user()->display_name;

        if ( $mode === 'delta' ) {
            // 相对调整（+/- delta）
            Moe_Points::add( $uid, $value, 'admin',
                "管理员({$operator})" . ( $value >= 0 ? "增加" : "扣除" ) . abs( $value ) . "积分"
            );
        } else {
            // 绝对设置（直接写入数据库）
            global $wpdb;
            $new = max( 0, $value );
            $old = Moe_Points::get( $uid );
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}moe_points (user_id, points) VALUES (%d, %d)
                 ON DUPLICATE KEY UPDATE points = %d",
                $uid, $new, $new
            ) );
            // 记录日志
            $wpdb->insert( "{$wpdb->prefix}moe_points_log", [
                'user_id'     => $uid,
                'points'      => $new - $old,
                'type'        => 'admin',
                'description' => "管理员({$operator})直接设置积分为{$new}",
            ] );
        }

        wp_send_json_success( [ 'points' => Moe_Points::get( $uid ) ] );
    }

    // ══════════════════════════════════════════════════════════
    //  页面四：积分日志
    // ══════════════════════════════════════════════════════════
    public function page_logs() {
        global $wpdb;
        $prefix = get_option( 'moe_prefix', '金币' );
        $paged  = max( 1, (int)( $_GET['paged'] ?? 1 ) );
        $per    = 30;
        $offset = ( $paged - 1 ) * $per;

        // 类型筛选
        $filter_type = sanitize_key( $_GET['type'] ?? '' );
        $where = $filter_type ? $wpdb->prepare( "WHERE l.type = %s", $filter_type ) : '';

        $logs = $wpdb->get_results( "
            SELECT l.*, u.display_name
            FROM {$wpdb->prefix}moe_points_log l
            LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID
            {$where}
            ORDER BY l.id DESC
            LIMIT {$per} OFFSET {$offset}
        " );
        $total = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}moe_points_log l {$where}" );
        $pages = ceil( $total / $per );

        // 类型标签映射
        $type_labels = [
            'register'       => '注册奖励',
            'login'          => '每日登录',
            'checkin'        => '每日签到',
            'comment'        => '发表评论',
            'delete_comment' => '评论删除',
            'post'           => '发布文章',
            'unlock_ovo'     => '积分解锁',
            'card'           => '卡密兑换',
            'admin'          => '管理员调整',
        ];

        ?>
        <div class="wrap moe-wrap">
            <h1>📋 萌积分 · 积分日志</h1>

            <!-- 筛选 + 清空 -->
            <div class="moe-log-toolbar">
                <form method="get" style="display:inline">
                    <input type="hidden" name="page" value="moe-points-logs">
                    <select name="type">
                        <option value="">全部类型</option>
                        <?php foreach ( $type_labels as $k => $v ) : ?>
                        <option value="<?php echo $k; ?>" <?php selected( $filter_type, $k ); ?>><?php echo esc_html( $v ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button">筛选</button>
                </form>

                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline;margin-left:20px"
                      onsubmit="return confirm('确认清空全部日志？此操作不可撤销！')">
                    <input type="hidden" name="action" value="moe_clear_logs">
                    <?php wp_nonce_field( 'moe_clear_logs_nonce' ); ?>
                    <button type="submit" class="button button-link-delete">🗑 清空全部日志</button>
                </form>
            </div>

            <div class="moe-card">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>时间</th>
                            <th>用户</th>
                            <th>变动</th>
                            <th>类型</th>
                            <th>说明</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $logs as $log ) :
                        $delta_class = $log->points >= 0 ? 'moe-plus' : 'moe-minus';
                        $sign = $log->points >= 0 ? '+' : '';
                        $type_label = $type_labels[ $log->type ] ?? $log->type;
                    ?>
                    <tr>
                        <td><?php echo esc_html( $log->created_at ); ?></td>
                        <td><?php echo esc_html( $log->display_name ?: "UID:{$log->user_id}" ); ?></td>
                        <td><span class="<?php echo $delta_class; ?>"><?php echo "{$sign}{$log->points}"; ?></span></td>
                        <td><span class="moe-tag"><?php echo esc_html( $type_label ); ?></span></td>
                        <td>
                            <?php echo esc_html( $log->description ); ?>
                            <?php if ( $log->post_id ) : ?>
                            <a href="<?php echo get_permalink( $log->post_id ); ?>" target="_blank" class="moe-link">📄</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ( empty( $logs ) ) : ?>
                    <tr><td colspan="5" style="text-align:center">暂无日志记录</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>

                <!-- 分页 -->
                <?php if ( $pages > 1 ) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php echo paginate_links( [
                            'base'    => add_query_arg( 'paged', '%#%' ),
                            'format'  => '',
                            'current' => $paged,
                            'total'   => $pages,
                        ] ); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function clear_logs() {
        check_admin_referer( 'moe_clear_logs_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '权限不足' );
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}moe_points_log" );
        wp_redirect( admin_url( 'admin.php?page=moe-points-logs&cleared=1' ) );
        exit;
    }

    public function clear_checkin() {
        check_admin_referer( 'moe_clear_checkin_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '权限不足' );
        global $wpdb;
        // 只清 last_checkin 字段，积分和登录日期不动
        $wpdb->query( "UPDATE {$wpdb->prefix}moe_points SET last_checkin = NULL" );
        wp_redirect( admin_url( 'admin.php?page=moe-points&checkin_cleared=1' ) );
        exit;
    }

    // ══════════════════════════════════════════════════════════
    //  页面：解锁频率限制设置
    // ══════════════════════════════════════════════════════════
    public function page_unlock_limits() {
        $saved  = isset( $_GET['saved'] );
        $levels = Moe_Levels::all();
        $enabled = (int) get_option( 'moe_unlock_guard_enabled', 1 );

        // 读取现有限制配置
        $all_limits = json_decode( get_option( 'moe_unlock_limits', '{}' ), true );
        if ( ! is_array( $all_limits ) ) $all_limits = [];
        ?>
        <div class="wrap moe-wrap">
            <h1>🛡️ 萌积分 · 解锁频率限制</h1>
            <p class="description">
                针对 <code>[ovo]</code> 积分解锁内容功能，按用户等级设置解锁次数上限，防止恶意批量搬运。<br>
                <strong>超限惩罚规则：</strong>
                第1次超限 → 静默封禁解锁功能2天（用户不会收到提示）；
                封禁期间再次尝试 → 显示提示"过于频繁…"；
                第2次超限 → 永久封禁WP账号。
            </p>

            <?php if ( $saved ) echo '<div class="notice notice-success is-dismissible"><p>设置已保存！</p></div>'; ?>

            <!-- 总开关（独立小表单，保存即时生效）-->
            <div class="moe-card" style="<?php echo $enabled ? '' : 'border-color:#e0e0e0;background:#fafafa'; ?>">
                <h2>⚡ 解锁防护总开关</h2>
                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                    <input type="hidden" name="action" value="moe_save_ulimits">
                    <?php wp_nonce_field( 'moe_save_ulimits_nonce' ); ?>
                    <input type="hidden" name="only_switch" value="1"><!-- 标记只保存开关 -->
                    <table class="form-table" style="margin:0">
                        <tr>
                            <th style="padding-top:0">防护功能</th>
                            <td style="padding-top:0">
                                <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                                    <input type="checkbox" name="moe_unlock_guard_enabled" value="1"
                                           <?php checked( $enabled, 1 ); ?> style="width:18px;height:18px">
                                    <span>
                                        <strong><?php echo $enabled ? '✅ 已开启' : '⭕ 已关闭'; ?></strong>
                                        — 关闭后不再统计解锁次数、不触发封禁，也不写入行为记录表，完全不占用额外资源
                                    </span>
                                </label>
                            </td>
                        </tr>
                    </table>
                    <p style="margin-top:12px">
                        <button type="submit" class="button button-primary">保存开关</button>
                    </p>
                </form>
            </div>

            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                <input type="hidden" name="action" value="moe_save_ulimits">
                <?php wp_nonce_field( 'moe_save_ulimits_nonce' ); ?>

                <?php if ( empty( $levels ) ) : ?>
                <div class="notice notice-warning"><p>请先在「等级设置」中添加等级，再配置解锁限制。</p></div>
                <?php else : ?>

                <?php if ( ! $enabled ) : ?>
                <div class="notice notice-info" style="margin:0 0 16px"><p>⭕ 防护功能当前已关闭，以下限制配置不会生效，但可以提前填好，开启后立即生效。</p></div>
                <?php endif; ?>

                <?php foreach ( $levels as $l ) :
                    $ln  = (string) $l['level'];
                    $lim = $all_limits[ $ln ] ?? [ 'h24' => 10, 'week' => 50, 'month' => 150 ];
                ?>
                <div class="moe-card">
                    <h2>等级 <?php echo (int)$l['level']; ?> · <?php echo esc_html( $l['title'] ); ?>（≥ <?php echo (int)$l['points']; ?> 积分）</h2>
                    <table class="form-table">
                        <tr>
                            <th>24 小时内最大解锁次数</th>
                            <td>
                                <input type="number"
                                       name="limits[<?php echo $ln; ?>][h24]"
                                       value="<?php echo (int)($lim['h24'] ?? 0); ?>"
                                       class="small-text" min="0">
                                <span class="description">次&nbsp;&nbsp;（0 = 不限制）</span>
                            </td>
                        </tr>
                        <tr>
                            <th>一 周 内最大解锁次数</th>
                            <td>
                                <input type="number"
                                       name="limits[<?php echo $ln; ?>][week]"
                                       value="<?php echo (int)($lim['week'] ?? 0); ?>"
                                       class="small-text" min="0">
                                <span class="description">次&nbsp;&nbsp;（0 = 不限制）</span>
                            </td>
                        </tr>
                        <tr>
                            <th>一个月内最大解锁次数</th>
                            <td>
                                <input type="number"
                                       name="limits[<?php echo $ln; ?>][month]"
                                       value="<?php echo (int)($lim['month'] ?? 0); ?>"
                                       class="small-text" min="0">
                                <span class="description">次&nbsp;&nbsp;（0 = 不限制）</span>
                            </td>
                        </tr>
                    </table>
                </div>
                <?php endforeach; ?>

                <?php submit_button( '💾 保存限制设置' ); ?>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }

    public function save_unlock_limits() {
        check_admin_referer( 'moe_save_ulimits_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '权限不足' );

        // 无论是只保存开关还是完整保存，都更新开关值
        update_option( 'moe_unlock_guard_enabled', isset( $_POST['moe_unlock_guard_enabled'] ) ? 1 : 0 );

        // only_switch=1 时只保存开关，不动限制数值
        if ( empty( $_POST['only_switch'] ) ) {
            $raw    = $_POST['limits'] ?? [];
            $result = [];
            foreach ( $raw as $level_num => $vals ) {
                $result[ sanitize_key( $level_num ) ] = [
                    'h24'   => max( 0, (int)( $vals['h24']   ?? 0 ) ),
                    'week'  => max( 0, (int)( $vals['week']  ?? 0 ) ),
                    'month' => max( 0, (int)( $vals['month'] ?? 0 ) ),
                ];
            }
            update_option( 'moe_unlock_limits', wp_json_encode( $result ) );
        }

        wp_redirect( admin_url( 'admin.php?page=moe-points-ulimit&saved=1' ) );
        exit;
    }

    // ══════════════════════════════════════════════════════════
    //  页面：封禁管理
    // ══════════════════════════════════════════════════════════
    public function page_bans() {
        $unbanned = isset( $_GET['unbanned'] );
        $cleared  = isset( $_GET['cleared'] );

        $paged  = max( 1, (int)( $_GET['paged'] ?? 1 ) );
        $per    = 20;
        $offset = ( $paged - 1 ) * $per;

        $result = Moe_Unlock_Guard::get_ban_list( $per, $offset );
        $rows   = $result['rows'];
        $total  = $result['total'];
        $pages  = ceil( $total / $per );
        ?>
        <div class="wrap moe-wrap">
            <h1>🚫 萌积分 · 封禁管理</h1>

            <?php if ( $unbanned ) echo '<div class="notice notice-success is-dismissible"><p>已成功解封！</p></div>'; ?>
            <?php if ( $cleared )  echo '<div class="notice notice-success is-dismissible"><p>已清空所有封禁记录，并恢复相关账号。</p></div>'; ?>

            <!-- 说明 -->
            <div class="moe-card" style="background:#fff8e6;border-color:#f0c040">
                <strong>封禁等级说明：</strong>
                <ul style="margin:.5em 0 0 1.2em;list-style:disc">
                    <li><strong>临时封禁（temp）</strong>：第1次超限，封禁解锁功能2天，账号正常可用，用户不知情</li>
                    <li><strong>永久封号（permanent）</strong>：第2次超限，WP账号被禁用，无法登录</li>
                </ul>
            </div>

            <!-- 清空按钮 -->
            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="margin-bottom:16px"
                  onsubmit="return confirm('确认清空所有封禁记录？同时会恢复所有被永久封号的账号！')">
                <input type="hidden" name="action" value="moe_clear_bans">
                <?php wp_nonce_field( 'moe_clear_bans_nonce' ); ?>
                <button type="submit" class="button button-link-delete">🗑 清空全部封禁记录（同时恢复账号）</button>
            </form>

            <!-- 封禁列表 -->
            <div class="moe-card">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>用户</th>
                            <th>封禁类型</th>
                            <th>触发次数</th>
                            <th>状态</th>
                            <th>封禁时间</th>
                            <th>解封时间</th>
                            <th>原因</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $rows as $row ) :
                        $is_perm  = $row->ban_type === 'permanent';
                        $type_tag = $is_perm
                            ? '<span class="moe-tag moe-tag-used">永久封号</span>'
                            : '<span class="moe-tag" style="background:#fff3e0;color:#e65100">临时封禁</span>';
                        $status_tag = $row->is_active
                            ? '<span class="moe-tag moe-tag-used">封禁中</span>'
                            : '<span class="moe-tag moe-tag-new">已解封</span>';
                        $unban_time = $row->unban_at ?: ( $is_perm ? '永久' : '—' );
                    ?>
                    <tr>
                        <td><?php echo $row->id; ?></td>
                        <td>
                            <?php echo esc_html( $row->display_name ?: "UID:{$row->user_id}" ); ?>
                            <br><small style="color:#999"><?php echo esc_html( $row->user_login ?? '' ); ?></small>
                        </td>
                        <td><?php echo $type_tag; ?></td>
                        <td style="text-align:center"><?php echo (int)$row->violation_count; ?></td>
                        <td><?php echo $status_tag; ?></td>
                        <td><?php echo esc_html( $row->banned_at ); ?></td>
                        <td><?php echo esc_html( $unban_time ); ?></td>
                        <td style="max-width:220px;font-size:12px;word-break:break-all"><?php echo esc_html( $row->ban_reason ); ?></td>
                        <td>
                            <?php if ( $row->is_active ) : ?>
                            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline">
                                <input type="hidden" name="action"  value="moe_unban">
                                <input type="hidden" name="ban_id"  value="<?php echo $row->id; ?>">
                                <?php wp_nonce_field( 'moe_unban_nonce' ); ?>
                                <button type="submit" class="button button-small">✅ 解封</button>
                            </form>
                            <?php else : ?>
                            <span style="color:#999">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="9" style="text-align:center;padding:20px">暂无封禁记录 ✨</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>

                <!-- 分页 -->
                <?php if ( $pages > 1 ) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php echo paginate_links( [
                            'base'    => add_query_arg( 'paged', '%#%' ),
                            'format'  => '',
                            'current' => $paged,
                            'total'   => $pages,
                        ] ); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function handle_unban() {
        check_admin_referer( 'moe_unban_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '权限不足' );
        $ban_id = (int) ( $_POST['ban_id'] ?? 0 );
        if ( $ban_id ) Moe_Unlock_Guard::unban( $ban_id );
        wp_redirect( admin_url( 'admin.php?page=moe-points-bans&unbanned=1' ) );
        exit;
    }

    public function handle_clear_bans() {
        check_admin_referer( 'moe_clear_bans_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '权限不足' );
        Moe_Unlock_Guard::clear_all_bans();
        wp_redirect( admin_url( 'admin.php?page=moe-points-bans&cleared=1' ) );
        exit;
    }

    // ══════════════════════════════════════════════════════════
    // ══════════════════════════════════════════════════════════
    // ══════════════════════════════════════════════════════════
    //  页面：个人主页设置
    // ══════════════════════════════════════════════════════════
    public function page_profile() {
        $saved       = isset( $_GET['saved'] );
        $pages       = get_pages();
        $gallery_ids = json_decode( get_option('moe_avatar_gallery','[]'), true ) ?: [];
        wp_enqueue_media();
        ?>
        <div class="wrap moe-wrap">
            <h1>👤 萌积分 · 个人主页设置</h1>
            <?php if($saved) echo '<div class="notice notice-success is-dismissible"><p>设置已保存！</p></div>'; ?>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="moe_save_profile_cfg">
                <?php wp_nonce_field('moe_save_profile_cfg_nonce'); ?>

                <div class="moe-card">
                    <h2>⚙️ 基本设置</h2>
                    <table class="form-table">
                        <tr>
                            <th>禁止用户访问WP后台</th>
                            <td><label><input type="checkbox" name="moe_restrict_admin" value="1" <?php checked(get_option('moe_restrict_admin',1),1); ?>>
                            开启后普通用户登录跳转首页，无法进入 /wp-admin（AJAX不受影响）</label></td>
                        </tr>
                        <tr>
                            <th>个人主页绑定页面</th>
                            <td>
                                <select name="moe_profile_page_id">
                                    <option value="0">— 请选择 —</option>
                                    <?php foreach($pages as $p): ?>
                                    <option value="<?php echo $p->ID; ?>" <?php selected(get_option('moe_profile_page_id',0),$p->ID); ?>><?php echo esc_html($p->post_title); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">选择放置了 [gerzhu] 短代码的页面，用于生成"查看TA主页"的链接</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="moe-card">
                    <h2>🖼️ 默认头像</h2>
                    <table class="form-table">
                        <tr>
                            <th>默认头像</th>
                            <td>
                                <?php $def = get_option('moe_default_avatar',''); ?>
                                <?php if($def): ?>
                                <img src="<?php echo esc_url($def); ?>" style="width:60px;height:60px;border-radius:50%;display:block;margin-bottom:8px">
                                <label><input type="checkbox" name="remove_default_avatar" value="1"> 删除（恢复WP默认）</label><br>
                                <input type="hidden" name="moe_default_avatar_current" value="<?php echo esc_attr($def); ?>">
                                <?php endif; ?>
                                <input type="file" name="moe_default_avatar_file" accept="image/*" style="margin-top:8px">
                                <p class="description">未设置自定义头像的用户显示此图片</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="moe-card">
                    <h2>🎨 可选头像图集</h2>
                    <p class="description">用户只能从此图集中选择头像，不能自行上传，防止恶意文件攻击。</p>
                    <table class="form-table">
                        <tr>
                            <th>更换头像积分消耗</th>
                            <td>
                                <input type="number" name="moe_avatar_change_cost" value="<?php echo (int)get_option('moe_avatar_change_cost',0); ?>" class="small-text" min="0"> 积分
                                <p class="description">0 = 免费更换</p>
                            </td>
                        </tr>
                        <tr>
                            <th>图集管理</th>
                            <td>
                                <div id="moe-gallery-preview" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">
                                    <?php foreach($gallery_ids as $gid):
                                        $gimg = wp_get_attachment_image_url((int)$gid,[60,60]);
                                        if(!$gimg) continue;
                                    ?>
                                    <div style="position:relative">
                                        <img src="<?php echo esc_url($gimg); ?>" style="width:60px;height:60px;object-fit:cover;border-radius:8px;border:1px solid #ddd">
                                        <label title="删除" style="position:absolute;top:-5px;right:-5px;cursor:pointer;background:#e74c3c;color:#fff;border-radius:50%;width:18px;height:18px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:bold">
                                            <input type="checkbox" name="remove_gallery[]" value="<?php echo (int)$gid; ?>" style="display:none" onclick="this.parentElement.parentElement.style.opacity=this.checked?'0.3':'1'"> ×
                                        </label>
                                        <input type="hidden" name="gallery_ids[]" value="<?php echo (int)$gid; ?>">
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="button" id="moe-add-gallery-btn">📷 从媒体库添加</button>
                                <input type="hidden" id="moe-gallery-new-ids" name="gallery_new_ids" value="">
                                <p class="description">点击图片右上角 × 标记删除；建议上传正方形图片</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="moe-card">
                    <h2>📇 文章作者卡片</h2>
                    <table class="form-table">
                        <tr>
                            <th>显示位置</th>
                            <td>
                                <?php $cp = get_option('moe_author_card','bottom'); ?>
                                <label><input type="radio" name="moe_author_card" value="none"   <?php checked($cp,'none');   ?>> 不显示</label>&nbsp;&nbsp;
                                <label><input type="radio" name="moe_author_card" value="top"    <?php checked($cp,'top');    ?>> 文章顶部</label>&nbsp;&nbsp;
                                <label><input type="radio" name="moe_author_card" value="bottom" <?php checked($cp,'bottom'); ?>> 文章底部</label>
                                <p class="description">在单篇文章页显示作者小卡片，点击进入作者公开主页</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button('💾 保存个人主页设置'); ?>
            </form>
        </div>
        <script>
        jQuery(function($){
            var frame;
            $('#moe-add-gallery-btn').on('click',function(){
                if(frame){frame.open();return;}
                frame=wp.media({title:'选择头像图集',multiple:true,library:{type:'image'}});
                frame.on('select',function(){
                    var ids=frame.state().get('selection').map(function(a){return a.id;});
                    var cur=$('#moe-gallery-new-ids').val();
                    var merged=cur?cur.split(',').concat(ids):ids;
                    $('#moe-gallery-new-ids').val([...new Set(merged)].join(','));
                    frame.state().get('selection').each(function(a){
                        var th=a.attributes.sizes&&a.attributes.sizes.thumbnail?a.attributes.sizes.thumbnail.url:a.attributes.url;
                        $('#moe-gallery-preview').append('<div><img src="'+th+'" style="width:60px;height:60px;object-fit:cover;border-radius:8px;border:1px dashed #5bb8f5"></div>');
                    });
                });
                frame.open();
            });
        });
        </script>
        <?php
    }

    public function save_profile_cfg() {
        check_admin_referer('moe_save_profile_cfg_nonce');
        if ( ! current_user_can('manage_options') ) wp_die('权限不足');

        update_option('moe_restrict_admin',     isset($_POST['moe_restrict_admin']) ? 1 : 0);
        update_option('moe_profile_page_id',    (int)($_POST['moe_profile_page_id'] ?? 0));
        update_option('moe_avatar_change_cost', (int)($_POST['moe_avatar_change_cost'] ?? 0));
        update_option('moe_author_card',        sanitize_key($_POST['moe_author_card'] ?? 'bottom'));

        // 默认头像
        if ( ! empty($_POST['remove_default_avatar']) ) {
            update_option('moe_default_avatar', '');
        } elseif ( ! empty($_FILES['moe_default_avatar_file']['name']) ) {
            $up = wp_handle_upload($_FILES['moe_default_avatar_file'], ['test_form' => false]);
            if ( ! empty($up['url']) ) update_option('moe_default_avatar', $up['url']);
        } elseif ( ! empty($_POST['moe_default_avatar_current']) ) {
            update_option('moe_default_avatar', sanitize_url($_POST['moe_default_avatar_current']));
        }

        // 头像图集
        $existing  = array_map('intval', $_POST['gallery_ids']  ?? []);
        $to_remove = array_map('intval', $_POST['remove_gallery'] ?? []);
        $kept      = array_diff($existing, $to_remove);
        $new_raw   = trim($_POST['gallery_new_ids'] ?? '');
        $new_ids   = $new_raw ? array_map('intval', explode(',', $new_raw)) : [];
        $final     = array_values(array_unique(array_merge($kept, $new_ids)));
        update_option('moe_avatar_gallery', wp_json_encode($final));

        wp_redirect(admin_url('admin.php?page=moe-points-profile&saved=1'));
        exit;
    }

    public function page_cards() {
        global $wpdb;
        $prefix  = get_option( 'moe_prefix', '金币' );
        $paged   = max( 1, (int)( $_GET['paged'] ?? 1 ) );
        $per     = 30;
        $offset  = ( $paged - 1 ) * $per;

        // 状态筛选
        $filter  = isset( $_GET['status'] ) ? (int)$_GET['status'] : -1;
        $where   = $filter >= 0 ? $wpdb->prepare( "WHERE status = %d", $filter ) : '';

        $cards = $wpdb->get_results( "
            SELECT c.*, u.display_name
            FROM {$wpdb->prefix}moe_cardkeys c
            LEFT JOIN {$wpdb->users} u ON c.used_by = u.ID
            {$where}
            ORDER BY c.id DESC
            LIMIT {$per} OFFSET {$offset}
        " );
        $total = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}moe_cardkeys {$where}" );
        $pages = ceil( $total / $per );

        $gen_msg = $_GET['gen'] ?? '';
        ?>
        <div class="wrap moe-wrap">
            <h1>🎫 萌积分 · 卡密管理</h1>

            <?php if ( $gen_msg ) echo '<div class="notice notice-success is-dismissible"><p>成功生成 ' . (int)$gen_msg . ' 张卡密！</p></div>'; ?>

            <!-- 生成卡密表单 -->
            <div class="moe-card">
                <h2>批量生成卡密</h2>
                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                    <input type="hidden" name="action" value="moe_gen_cards">
                    <?php wp_nonce_field( 'moe_gen_cards_nonce' ); ?>
                    <table class="form-table">
                        <tr>
                            <th>积分数额</th>
                            <td>
                                <input type="number" name="card_points" value="100" class="small-text" min="1"> 个<?php echo esc_html( $prefix ); ?>
                            </td>
                        </tr>
                        <tr>
                            <th>生成数量</th>
                            <td>
                                <input type="number" name="card_count" value="10" class="small-text" min="1" max="500"> 张
                            </td>
                        </tr>
                    </table>
                    <button type="submit" class="button button-primary">✨ 批量生成</button>
                </form>
            </div>

            <!-- 卡密列表 -->
            <div class="moe-card">
                <!-- 筛选 -->
                <div style="margin-bottom:12px">
                    <a href="<?php echo admin_url('admin.php?page=moe-points-cards'); ?>"
                       class="button <?php echo $filter < 0 ? 'button-primary' : ''; ?>">全部</a>
                    <a href="<?php echo admin_url('admin.php?page=moe-points-cards&status=0'); ?>"
                       class="button <?php echo $filter === 0 ? 'button-primary' : ''; ?>">未使用</a>
                    <a href="<?php echo admin_url('admin.php?page=moe-points-cards&status=1'); ?>"
                       class="button <?php echo $filter === 1 ? 'button-primary' : ''; ?>">已使用</a>

                    <!-- 批量删除未使用卡密 -->
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;margin-left:20px"
                          onsubmit="return confirm('确认删除全部未使用卡密？')">
                        <input type="hidden" name="action" value="moe_del_cards">
                        <?php wp_nonce_field('moe_del_cards_nonce'); ?>
                        <button type="submit" class="button button-link-delete">🗑 删除全部未使用</button>
                    </form>
                </div>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>卡密</th>
                            <th><?php echo esc_html($prefix); ?>数额</th>
                            <th>状态</th>
                            <th>使用者</th>
                            <th>使用时间</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $cards as $c ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $c->code ); ?></code></td>
                        <td><?php echo (int)$c->points; ?></td>
                        <td><?php echo $c->status ? '<span class="moe-tag moe-tag-used">已使用</span>' : '<span class="moe-tag moe-tag-new">未使用</span>'; ?></td>
                        <td><?php echo $c->display_name ? esc_html( $c->display_name ) : '—'; ?></td>
                        <td><?php echo $c->used_at ?: '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ( empty( $cards ) ) : ?>
                    <tr><td colspan="5" style="text-align:center">暂无卡密</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>

                <?php if ( $pages > 1 ) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php echo paginate_links( [
                            'base'    => add_query_arg( 'paged', '%#%' ),
                            'format'  => '',
                            'current' => $paged,
                            'total'   => $pages,
                        ] ); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function generate_cards() {
        check_admin_referer( 'moe_gen_cards_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '权限不足' );

        global $wpdb;
        $points = max( 1, (int)( $_POST['card_points'] ?? 100 ) );
        $count  = min( 500, max( 1, (int)( $_POST['card_count'] ?? 10 ) ) );
        $table  = "{$wpdb->prefix}moe_cardkeys";
        $gen    = 0;

        for ( $i = 0; $i < $count; $i++ ) {
            // 生成唯一32位大写卡密
            do {
                $code = strtoupper( substr( md5( uniqid( mt_rand(), true ) ), 0, 16 ) );
                $exists = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE code = %s", $code ) );
            } while ( $exists );

            $wpdb->insert( $table, [ 'code' => $code, 'points' => $points ] );
            $gen++;
        }

        wp_redirect( admin_url( "admin.php?page=moe-points-cards&gen={$gen}" ) );
        exit;
    }

    public function delete_cards() {
        check_admin_referer( 'moe_del_cards_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '权限不足' );
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}moe_cardkeys WHERE status = 0" );
        wp_redirect( admin_url( 'admin.php?page=moe-points-cards&deleted=1' ) );
        exit;
    }
}
