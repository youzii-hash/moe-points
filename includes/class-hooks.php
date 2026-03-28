<?php
/**
 * ============================================================
 * 文件职责：WordPress 事件钩子监听
 *   - 用户注册 / 登录 / 评论 / 删除评论 / 文章发布 → 积分
 *   - 评论回复通知（仅已审核评论）
 *   - 禁后台 / 自定义头像 / 文章作者卡片
 *   - 前端资源（filemtime 版本号，自动缓存破坏）
 *   - 悬浮球弹窗 HTML
 *   - AJAX：签到 / 解锁 / 卡密兑换
 *   - 定时清理日志 + 过期解锁记录
 * ============================================================
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Moe_Hooks {

    public function __construct() {
        add_action( 'user_register',          [ $this, 'on_register' ] );
        add_action( 'wp_login',               [ $this, 'on_login' ], 10, 2 );
        add_action( 'comment_post',           [ $this, 'on_comment' ], 10, 2 );
        add_action( 'comment_post',           [ $this, 'on_comment_notify' ], 20, 2 ); // priority 20，在 on_comment 之后
        add_action( 'delete_comment',         [ $this, 'on_delete_comment' ] );
        add_action( 'transition_post_status', [ $this, 'on_post_publish' ], 10, 3 );

        add_action( 'admin_init',         [ $this, 'restrict_admin_access' ] );
        add_filter( 'get_avatar_url',     [ $this, 'custom_avatar_url' ], 10, 3 );
        add_filter( 'the_content',        [ $this, 'inject_author_card' ] );

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
        add_action( 'wp_footer',          [ $this, 'render_float_ball' ] );

        add_action( 'wp_ajax_moe_checkin',     [ $this, 'ajax_checkin' ] );
        add_action( 'wp_ajax_moe_unlock_ovo',  [ $this, 'ajax_unlock_ovo' ] );
        add_action( 'wp_ajax_moe_redeem_card', [ $this, 'ajax_redeem_card' ] );

        add_action( 'moe_points_changed', [ 'Moe_Points', 'clear_leaderboard_cache' ] );
        add_action( 'moe_daily_cleanup',  [ $this, 'cleanup_logs' ] );
    }

    /** 激活时注册每日清理定时任务（仅调用一次） */
    public static function schedule_cron() {
        if ( ! wp_next_scheduled( 'moe_daily_cleanup' ) ) {
            wp_schedule_event( strtotime( 'tomorrow midnight' ), 'daily', 'moe_daily_cleanup' );
        }
    }

    // ══════════════════════════════════════════════════════════
    //  积分事件
    // ══════════════════════════════════════════════════════════

    public function on_register( $uid ) {
        $pts = (int) get_option( 'moe_pts_register', 10 );
        if ( $pts > 0 ) Moe_Points::add( $uid, $pts, 'register', '新用户注册奖励' );
    }

    /**
     * 每日登录奖励（原子UPSERT防并发双奖励）
     * rows_affected=0 → last_login=今天(no-op) → 今天已奖励过
     * rows_affected=1 → 新插入
     * rows_affected=2 → 更新了 last_login → 发放奖励
     * 注：依赖 MySQL 默认行为（非 CLIENT_FOUND_ROWS 模式）
     */
    public function on_login( $login, $user ) {
        $pts = (int) get_option( 'moe_pts_login', 1 );
        if ( $pts <= 0 ) return;

        global $wpdb;
        $uid   = (int) $user->ID;
        $today = current_time( 'Y-m-d' );

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}moe_points (user_id, points, last_login)
             VALUES (%d, 0, %s)
             ON DUPLICATE KEY UPDATE
               last_login = IF(last_login = VALUES(last_login), last_login, VALUES(last_login))",
            $uid, $today
        ) );

        if ( (int) $wpdb->rows_affected === 0 ) return; // 今天已登录过

        Moe_Points::add( $uid, $pts, 'login', '每日登录奖励' );
    }

    /** 发表评论奖励（每篇文章限一次 + 每日上限） */
    public function on_comment( $comment_id, $approved ) {
        if ( $approved !== 1 && $approved !== '1' ) return; // 只处理已审核评论

        $comment = get_comment( $comment_id );
        if ( ! $comment || ! $comment->user_id ) return;

        $pts = (int) get_option( 'moe_pts_comment', 2 );
        if ( $pts <= 0 ) return;

        $uid     = (int) $comment->user_id;
        $post_id = (int) $comment->comment_post_ID;

        if ( Moe_Points::ever_earned_for_post( $uid, 'comment', $post_id ) ) return;

        $daily_limit  = (int) get_option( 'moe_limit_comment_daily', 10 );
        $today_earned = Moe_Points::today_earned( $uid, 'comment' );
        if ( $daily_limit > 0 && $today_earned >= $daily_limit ) return;

        $actual = $daily_limit > 0 ? min( $pts, $daily_limit - $today_earned ) : $pts;
        if ( $actual <= 0 ) return;

        Moe_Points::add( $uid, $actual, 'comment', '评论文章《' . get_the_title( $post_id ) . '》', $post_id );
    }

    /**
     * 评论回复通知（priority 20，在 on_comment 之后执行）
     * 修复：同时检查 $approved 参数，垃圾/待审评论不触发通知
     */
    public function on_comment_notify( $comment_id, $approved ) {
        if ( $approved !== 1 && $approved !== '1' ) return; // 只有已审核评论才通知

        $comment = get_comment( $comment_id );
        if ( ! $comment || ! $comment->comment_parent ) return;

        $parent = get_comment( $comment->comment_parent );
        if ( ! $parent || ! $parent->user_id ) return;

        Moe_Profile::add_notification(
            (int) $parent->user_id,
            (int) $comment->user_id,
            (int) $comment_id,
            (int) $comment->comment_post_ID
        );
    }

    /** 评论被删除：扣积分 + 同步删除该评论的通知记录 */
    public function on_delete_comment( $comment_id ) {
        global $wpdb;

        // 先删通知（评论删了通知就没意义了）
        $wpdb->delete( "{$wpdb->prefix}moe_notifications", [ 'comment_id' => (int) $comment_id ] );

        $comment = get_comment( $comment_id );
        if ( ! $comment || ! $comment->user_id ) return;

        $deduct  = (int) get_option( 'moe_pts_del_comment', 2 );
        if ( $deduct <= 0 ) return;

        $uid     = (int) $comment->user_id;
        $post_id = (int) $comment->comment_post_ID;
        if ( ! Moe_Points::ever_earned_for_post( $uid, 'comment', $post_id ) ) return;

        Moe_Points::add( $uid, -$deduct, 'delete_comment', '评论被删除《' . get_the_title( $post_id ) . '》', $post_id );
    }

    /** 文章发布奖励（兼容投稿插件） */
    public function on_post_publish( $new_status, $old_status, $post ) {
        if ( $new_status !== 'publish' || $old_status === 'publish' ) return;
        if ( $post->post_type !== 'post' ) return;

        $pts = (int) get_option( 'moe_pts_post', 5 );
        if ( $pts <= 0 ) return;

        $uid = (int) $post->post_author;
        if ( ! $uid ) return;

        $daily_limit  = (int) get_option( 'moe_limit_post_daily', 20 );
        $today_earned = Moe_Points::today_earned( $uid, 'post' );
        if ( $daily_limit > 0 && $today_earned >= $daily_limit ) return;

        $actual = $daily_limit > 0 ? min( $pts, $daily_limit - $today_earned ) : $pts;
        if ( $actual <= 0 ) return;

        Moe_Points::add( $uid, $actual, 'post', "发布文章《{$post->post_title}》", $post->ID );
    }

    // ══════════════════════════════════════════════════════════
    //  禁后台 / 头像 / 作者卡片
    // ══════════════════════════════════════════════════════════

    public function restrict_admin_access() {
        if ( ! get_option( 'moe_restrict_admin', 1 ) ) return;
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;
        if ( ! is_user_logged_in() || current_user_can( 'manage_options' ) ) return;
        wp_safe_redirect( home_url() );
        exit;
    }

    /**
     * 自定义头像
     * 静态缓存：默认头像 option + 用户自定义头像 user_meta + 邮件→uid 映射
     * 整个请求内每个 uid/email 只查一次库
     */
    private static $_avatar_cache  = [];  // uid → custom_url
    private static $_email_uid_map = [];  // email → uid
    private static $_def_avatar    = null;

    public function custom_avatar_url( $url, $id_or_email, $args ) {
        if ( self::$_def_avatar === null ) {
            self::$_def_avatar = (string) get_option( 'moe_default_avatar', '' );
        }
        $def = self::$_def_avatar;

        $uid = 0;
        if ( is_numeric( $id_or_email ) ) {
            $uid = (int) $id_or_email;
        } elseif ( $id_or_email instanceof WP_User ) {
            $uid = $id_or_email->ID;
        } elseif ( $id_or_email instanceof WP_Comment ) {
            $uid = (int) $id_or_email->user_id;
        } elseif ( is_string( $id_or_email ) && strpos( $id_or_email, '@' ) !== false ) {
            $email = $id_or_email;
            if ( ! array_key_exists( $email, self::$_email_uid_map ) ) {
                $u = get_user_by( 'email', $email );
                self::$_email_uid_map[ $email ] = $u ? $u->ID : 0;
            }
            $uid = self::$_email_uid_map[ $email ];
        }

        if ( ! $uid ) return $def ?: $url;

        if ( ! array_key_exists( $uid, self::$_avatar_cache ) ) {
            self::$_avatar_cache[ $uid ] = (string) get_user_meta( $uid, '_moe_avatar', true );
        }
        return self::$_avatar_cache[ $uid ] ?: ( $def ?: $url );
    }

    /** 文章作者卡片（底部/顶部，仅单篇文章且在循环内） */
    public function inject_author_card( $content ) {
        if ( ! is_single() || is_admin() || ! in_the_loop() ) return $content;

        $pos = get_option( 'moe_author_card', 'bottom' );
        if ( $pos === 'none' ) return $content;

        $uid = (int) get_post_field( 'post_author', get_the_ID() );
        if ( ! $uid ) return $content;

        $user        = get_userdata( $uid );
        if ( ! $user ) return $content;

        $avatar_url  = Moe_Profile::get_avatar_url( $uid ) ?: get_avatar_url( $uid, [ 'size' => 60 ] );
        $bio         = (string) get_user_meta( $uid, 'description', true );
        $profile_pid = (int) get_option( 'moe_profile_page_id', 0 );
        $profile_url = $profile_pid
            ? add_query_arg( 'moe_uid', $uid, get_permalink( $profile_pid ) )
            : '#';

        $card = sprintf(
            '<a href="%s" class="moe-author-card">
                <img src="%s" class="moe-author-card-avatar" alt="%s">
                <div class="moe-author-card-info">
                    <div class="moe-author-card-name"><span class="moe-author-card-lbl">投稿者：</span>%s</div>
                    <div class="moe-author-card-bio"><span class="moe-author-card-lbl">简介：</span>%s</div>
                </div>
            </a>',
            esc_url( $profile_url ),
            esc_url( $avatar_url ),
            esc_attr( $user->display_name ),
            esc_html( $user->display_name ),
            esc_html( $bio ?: '暂无简介' )
        );

        return $pos === 'top' ? $card . $content : $content . $card;
    }

    // ══════════════════════════════════════════════════════════
    //  前端资源 & 悬浮球
    // ══════════════════════════════════════════════════════════

    public function enqueue_frontend() {
        // filemtime 作版本号：每次文件修改后浏览器自动拉新版
        wp_enqueue_style(  'moe-points-css', MOE_URL . 'assets/css/frontend.css', [], filemtime( MOE_PATH . 'assets/css/frontend.css' ) );
        wp_enqueue_style(  'moe-profile-css', MOE_URL . 'assets/css/profile.css', [], filemtime( MOE_PATH . 'assets/css/profile.css' ) );
        wp_enqueue_script( 'moe-points-js',  MOE_URL . 'assets/js/frontend.js', [ 'jquery' ], filemtime( MOE_PATH . 'assets/js/frontend.js' ), true );
        wp_enqueue_script( 'moe-profile-js', MOE_URL . 'assets/js/profile.js',  [ 'jquery', 'moe-points-js' ], filemtime( MOE_PATH . 'assets/js/profile.js' ), true );

        $uid = get_current_user_id();
        // get_permalink() 在非文章页返回 false，fallback 到当前URL
        $current_url = get_permalink() ?: ( home_url( $_SERVER['REQUEST_URI'] ?? '/' ) );
        wp_localize_script( 'moe-points-js', 'moeData', [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'moe_nonce' ),
            'prefix'      => get_option( 'moe_prefix', '金币' ),
            'loggedIn'    => (bool) is_user_logged_in(),
            'loginUrl'    => wp_login_url( $current_url ),
            'notifyQueue' => Moe_Points::pop_notifications( $uid ),
        ] );
    }

    public function render_float_ball() {
        if ( ! get_option( 'moe_float_ball', 1 ) || ! is_user_logged_in() ) return;

        $uid         = get_current_user_id();
        $prefix      = get_option( 'moe_prefix', '金币' );
        $leaderboard = Moe_Points::leaderboard( (int) get_option( 'moe_rank_count', 10 ) );
        $medals      = [ '🥇', '🥈', '🥉' ];
        ?>
        <button id="moe-ball" title="查看积分">✨</button>
        <div id="moe-overlay"></div>
        <div id="moe-panel" class="moe-panel-hidden" role="dialog">
            <div class="moe-panel-hd">
                <span class="moe-panel-title">🌸 <?php echo esc_html( get_option( 'moe_rank_title', '积分排行榜' ) ); ?></span>
                <button class="moe-panel-close" id="moe-panel-close" title="关闭">×</button>
            </div>
            <div class="moe-panel-bd">
                <div class="moe-rank-section">
                    <?php if ( empty( $leaderboard ) ) : ?>
                    <p class="moe-empty">暂无数据～</p>
                    <?php else : foreach ( $leaderboard as $i => $row ) : ?>
                    <div class="moe-rank-row">
                        <span class="moe-rank-no"><?php echo $medals[ $i ] ?? '#' . ( $i + 1 ); ?></span>
                        <span class="moe-rank-name"><?php echo esc_html( $row->display_name ); ?></span>
                        <span class="moe-rank-pts"><?php echo esc_html( $prefix . ' ' . $row->points ); ?></span>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
                <div class="moe-hr"></div>
                <div class="moe-my-info">
                    <div class="moe-my-row">
                        <span class="moe-my-label">💎 我的<?php echo esc_html( $prefix ); ?></span>
                        <span class="moe-my-val"><?php echo (int) Moe_Points::get( $uid ); ?></span>
                    </div>
                    <div class="moe-my-row">
                        <span class="moe-my-label">⭐ 等级头衔</span>
                        <span class="moe-my-val"><?php echo esc_html( Moe_Levels::for_user( $uid )['title'] ); ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    // ══════════════════════════════════════════════════════════
    //  AJAX
    // ══════════════════════════════════════════════════════════

    /** 签到（原子UPSERT防并发双签到） */
    public function ajax_checkin() {
        check_ajax_referer( 'moe_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'msg' => '请先登录～' ] );

        global $wpdb;
        $uid   = get_current_user_id();
        $today = current_time( 'Y-m-d' );
        $table = "{$wpdb->prefix}moe_points";

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table} (user_id, points, last_checkin)
             VALUES (%d, 0, %s)
             ON DUPLICATE KEY UPDATE
               last_checkin = IF(last_checkin = VALUES(last_checkin), last_checkin, VALUES(last_checkin))",
            $uid, $today
        ) );

        if ( (int) $wpdb->rows_affected === 0 ) {
            wp_send_json_error( [ 'msg' => '今天已经签到过啦 (｡•́︿•̀｡)' ] );
        }

        $pts    = (int) get_option( 'moe_pts_checkin', 3 );
        $prefix = get_option( 'moe_prefix', '金币' );
        Moe_Points::add( $uid, $pts, 'checkin', '每日签到' );

        wp_send_json_success( [
            'msg'    => "签到成功！{$prefix}+{$pts} (≧▽≦)",
            'points' => Moe_Points::get( $uid ),
        ] );
    }

    /** [ovo] 内容解锁 */
    public function ajax_unlock_ovo() {
        check_ajax_referer( 'moe_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'msg' => '请先登录～' ] );

        $uid     = get_current_user_id();
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        $prefix  = get_option( 'moe_prefix', '金币' );

        // 验证 post_id 为有效已发布文章
        if ( ! $post_id || get_post_status( $post_id ) !== 'publish' ) {
            wp_send_json_error( [ 'msg' => '无效的文章' ] );
        }

        $cost = (int) get_option( 'moe_ovo_cost', 3 );

        if ( get_option( 'moe_unlock_guard_enabled', 1 ) ) {
            $guard = Moe_Unlock_Guard::check_before_unlock( $uid );
            if ( ! $guard['ok'] ) {
                wp_send_json_error( [ 'msg' => $guard['msg'] ?: "{$prefix}不足，解锁失败。" ] );
            }
        }

        if ( Moe_Points::is_unlock_valid( $uid, $post_id ) ) {
            wp_send_json_success( [ 'msg' => '已解锁', 'unlocked' => true ] );
        }

        $title = get_the_title( $post_id ) ?: "文章#{$post_id}";
        if ( ! Moe_Points::spend( $uid, $cost, 'unlock_ovo', "解锁内容《{$title}》", $post_id ) ) {
            wp_send_json_error( [
                'msg' => "{$prefix}不足！需要 {$cost} 个{$prefix}，当前仅 " . Moe_Points::get( $uid ),
            ] );
        }

        Moe_Points::set_unlock( $uid, $post_id );
        if ( get_option( 'moe_unlock_guard_enabled', 1 ) ) {
            Moe_Unlock_Guard::record_unlock( $uid, $post_id );
        }

        wp_send_json_success( [ 'msg' => '解锁成功！', 'unlocked' => true ] );
    }

    /** 卡密兑换（原子UPDATE防并发抢兑） */
    public function ajax_redeem_card() {
        check_ajax_referer( 'moe_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'msg' => '请先登录～' ] );

        $uid  = get_current_user_id();
        $code = sanitize_text_field( $_POST['code'] ?? '' );
        if ( ! $code ) wp_send_json_error( [ 'msg' => '请输入卡密' ] );

        global $wpdb;
        $card = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, points FROM {$wpdb->prefix}moe_cardkeys WHERE code = %s AND status = 0",
            $code
        ) );
        if ( ! $card ) wp_send_json_error( [ 'msg' => '卡密无效或已被使用 (；´д｀)ゞ' ] );

        // 原子标记：WHERE status=0 防止并发抢兑
        $affected = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}moe_cardkeys
             SET status = 1, used_by = %d, used_at = %s
             WHERE id = %d AND status = 0",
            $uid, current_time( 'mysql' ), $card->id
        ) );
        if ( ! $affected ) wp_send_json_error( [ 'msg' => '卡密已被使用 (；´д｀)ゞ' ] );

        $prefix = get_option( 'moe_prefix', '金币' );
        Moe_Points::add( $uid, (int) $card->points, 'card', "兑换卡密：{$code}" );

        wp_send_json_success( [
            'msg'    => "兑换成功！获得{$prefix}×{$card->points} (≧▽≦)",
            'points' => Moe_Points::get( $uid ),
        ] );
    }

    // ══════════════════════════════════════════════════════════
    //  定时清理
    // ══════════════════════════════════════════════════════════

    public function cleanup_logs() {
        global $wpdb;

        $setting   = get_option( 'moe_log_cleanup', 'month' );
        $intervals = [
            'day'    => 'INTERVAL 1 DAY',
            'month'  => 'INTERVAL 1 MONTH',
            '3month' => 'INTERVAL 3 MONTH',
        ];
        if ( $setting !== 'never' && isset( $intervals[ $setting ] ) ) {
            $wpdb->query(
                "DELETE FROM {$wpdb->prefix}moe_points_log
                 WHERE created_at < DATE_SUB(NOW(), {$intervals[$setting]})"
            );
        }

        Moe_Points::cleanup_expired_unlocks();
    }
}
