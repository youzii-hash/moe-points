<?php
/**
 * ============================================================
 * 文件职责：数据库管理
 *   - 创建6张数据表（激活时）
 *   - 写入插件默认配置（仅首次激活，不覆盖已有）
 * ============================================================
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Moe_Database {

    public static function install() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();

        // 表1：用户积分
        $sql_points = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}moe_points (
            user_id      BIGINT(20)  NOT NULL,
            points       INT(11)     NOT NULL DEFAULT 0,
            last_login   DATE        DEFAULT NULL,
            last_checkin DATE        DEFAULT NULL,
            PRIMARY KEY (user_id)
        ) {$c};";

        // 表2：积分日志（idx_type 覆盖 today_earned / ever_earned_for_post 查询）
        $sql_log = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}moe_points_log (
            id          BIGINT(20)  NOT NULL AUTO_INCREMENT,
            user_id     BIGINT(20)  NOT NULL,
            points      INT(11)     NOT NULL,
            type        VARCHAR(50) NOT NULL,
            description TEXT,
            post_id     BIGINT(20)  NOT NULL DEFAULT 0,
            created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user     (user_id),
            KEY idx_type     (user_id, type, post_id),
            KEY idx_time     (created_at)
        ) {$c};";

        // 表3：卡密（UNIQUE 约束已自动建索引，无需额外 KEY idx_code）
        $sql_card = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}moe_cardkeys (
            id         BIGINT(20)  NOT NULL AUTO_INCREMENT,
            code       VARCHAR(32) NOT NULL,
            points     INT(11)     NOT NULL DEFAULT 0,
            status     TINYINT(1)  NOT NULL DEFAULT 0,
            used_by    BIGINT(20)  DEFAULT NULL,
            used_at    DATETIME    DEFAULT NULL,
            created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_code (code)
        ) {$c};";

        // 表4：解锁行为记录（频率统计）
        $sql_unlock_records = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}moe_unlock_records (
            id          BIGINT(20)  NOT NULL AUTO_INCREMENT,
            user_id     BIGINT(20)  NOT NULL,
            post_id     BIGINT(20)  NOT NULL DEFAULT 0,
            unlocked_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_time (user_id, unlocked_at)
        ) {$c};";

        // 表5：封禁记录
        $sql_bans = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}moe_unlock_bans (
            id              BIGINT(20)  NOT NULL AUTO_INCREMENT,
            user_id         BIGINT(20)  NOT NULL,
            ban_type        VARCHAR(20) NOT NULL DEFAULT 'temp',
            violation_count TINYINT(3)  NOT NULL DEFAULT 1,
            ban_reason      TEXT,
            banned_at       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            unban_at        DATETIME    DEFAULT NULL,
            is_active       TINYINT(1)  NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY idx_user_active (user_id, is_active)
        ) {$c};";

        // 表6：消息通知（idx_user_read 覆盖 get_notifications / unread_count）
        $sql_notifications = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}moe_notifications (
            id         BIGINT(20)  NOT NULL AUTO_INCREMENT,
            user_id    BIGINT(20)  NOT NULL,
            from_uid   BIGINT(20)  NOT NULL DEFAULT 0,
            type       VARCHAR(20) NOT NULL DEFAULT 'reply',
            comment_id BIGINT(20)  NOT NULL DEFAULT 0,
            post_id    BIGINT(20)  NOT NULL DEFAULT 0,
            is_read    TINYINT(1)  NOT NULL DEFAULT 0,
            created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_read (user_id, is_read, created_at)
        ) {$c};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_points );
        dbDelta( $sql_log );
        dbDelta( $sql_card );
        dbDelta( $sql_unlock_records );
        dbDelta( $sql_bans );
        dbDelta( $sql_notifications );

        update_option( 'moe_db_version', MOE_VER );
        self::set_defaults();
    }

    private static function set_defaults() {
        $defaults = [
            'moe_prefix'               => '金币',
            'moe_pts_comment'          => 2,
            'moe_pts_del_comment'      => 2,
            'moe_pts_post'             => 5,
            'moe_pts_register'         => 10,
            'moe_pts_login'            => 1,
            'moe_pts_checkin'          => 3,
            'moe_limit_post_daily'     => 20,
            'moe_limit_comment_daily'  => 10,
            'moe_ovo_cost'             => 3,
            'moe_level_required'       => 2,
            'moe_rank_count'           => 10,
            'moe_rank_title'           => '积分排行榜',
            'moe_float_ball'           => 1,
            'moe_notification'         => 1,
            'moe_log_cleanup'          => 'month',
            'moe_levels'               => wp_json_encode( [
                [ 'level' => 1, 'title' => '新手魔法少女', 'points' => 0,   'show_frame' => false, 'show_badge' => false, 'frame_url' => '', 'badge_color' => '#a8d8f0' ],
                [ 'level' => 2, 'title' => '入门魔法少女', 'points' => 100, 'show_frame' => true,  'show_badge' => true,  'frame_url' => '', 'badge_color' => '#7ec8e3' ],
                [ 'level' => 3, 'title' => '魔法少女导师', 'points' => 300, 'show_frame' => true,  'show_badge' => true,  'frame_url' => '', 'badge_color' => '#5bb8f5' ],
            ] ),
            'moe_restrict_admin'        => 1,
            'moe_default_avatar'        => '',
            'moe_avatar_gallery'        => '[]',
            'moe_avatar_change_cost'    => 0,
            'moe_author_card'           => 'bottom',
            'moe_profile_page_id'       => 0,
            'moe_unlock_expire_enabled' => 0,
            'moe_unlock_expire_days'    => 2,
            'moe_unlock_guard_enabled'  => 1,
            'moe_unlock_limits'         => wp_json_encode( [
                '1' => [ 'h24' => 10, 'week' => 50,  'month' => 150 ],
                '2' => [ 'h24' => 8,  'week' => 40,  'month' => 120 ],
                '3' => [ 'h24' => 5,  'week' => 25,  'month' => 80  ],
            ] ),
        ];

        foreach ( $defaults as $key => $value ) {
            if ( get_option( $key ) === false ) {
                add_option( $key, $value, '', 'no' ); // autoload=no 减少每次请求的内存
            }
        }
    }
}
