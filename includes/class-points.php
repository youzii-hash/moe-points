<?php
/**
 * ============================================================
 * 文件职责：积分核心操作
 *   - 读取 / 增加 / 消费积分（原子SQL，请求级缓存）
 *   - 日志记录、通知队列（静态缓存 option，避免重复 get_option）
 *   - 排行榜（5分钟 transient 缓存）
 *   - 解锁时效（静态缓存 user_meta，避免重复查库）
 * ============================================================
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Moe_Points {

    private static $_pts       = [];    // uid → points
    private static $_unlock    = [];    // "uid_pid" → timestamp|''
    private static $_notif_on  = null;  // moe_notification option 缓存
    private static $_prefix    = null;  // moe_prefix option 缓存

    // ══════════════════════════════════════════════════════════
    //  读取
    // ══════════════════════════════════════════════════════════

    public static function get( $uid ) {
        $uid = (int) $uid;
        if ( isset( self::$_pts[ $uid ] ) ) return self::$_pts[ $uid ];
        global $wpdb;
        $pts = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT points FROM {$wpdb->prefix}moe_points WHERE user_id = %d", $uid
        ) );
        return self::$_pts[ $uid ] = $pts;
    }

    // ══════════════════════════════════════════════════════════
    //  写入
    // ══════════════════════════════════════════════════════════

    /** 增加积分（新用户自动插入，积分不低于0） */
    public static function add( $uid, $amount, $type, $description = '', $post_id = 0 ) {
        global $wpdb;
        $uid = (int) $uid;

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}moe_points (user_id, points)
             VALUES (%d, GREATEST(0, %d))
             ON DUPLICATE KEY UPDATE points = GREATEST(0, points + %d)",
            $uid, $amount, $amount
        ) );
        unset( self::$_pts[ $uid ] );

        $wpdb->insert( "{$wpdb->prefix}moe_points_log", [
            'user_id'     => $uid,
            'points'      => $amount,
            'type'        => $type,
            'description' => $description,
            'post_id'     => (int) $post_id,
        ] );

        self::_push_notify( $uid, $description, $amount );
        do_action( 'moe_points_changed', $uid, $amount, $type );
    }

    /** 消费积分（原子扣除，余额不足返回 false） */
    public static function spend( $uid, $amount, $type, $description, $post_id = 0 ) {
        global $wpdb;
        $uid = (int) $uid;

        $ok = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}moe_points
             SET points = points - %d
             WHERE user_id = %d AND points >= %d",
            $amount, $uid, $amount
        ) );
        if ( ! $ok ) return false;
        unset( self::$_pts[ $uid ] );

        $wpdb->insert( "{$wpdb->prefix}moe_points_log", [
            'user_id'     => $uid,
            'points'      => -$amount,
            'type'        => $type,
            'description' => $description,
            'post_id'     => (int) $post_id,
        ] );

        self::_push_notify( $uid, $description, -$amount );
        do_action( 'moe_points_changed', $uid, -$amount, $type );
        return true;
    }

    /**
     * 通知队列写入（add/spend 共用）
     * option 值静态缓存，整个请求只读一次
     */
    private static function _push_notify( $uid, $description, $amount ) {
        if ( self::$_notif_on === null ) {
            self::$_notif_on = (bool) get_option( 'moe_notification', 1 );
            self::$_prefix   = (string) get_option( 'moe_prefix', '金币' );
        }
        if ( ! self::$_notif_on ) return;

        $sign  = $amount >= 0 ? "+{$amount}" : (string) $amount;
        $queue = get_user_meta( $uid, '_moe_notify_queue', true );
        $queue = is_array( $queue ) ? $queue : [];
        $queue[] = $description . '，' . self::$_prefix . $sign;
        update_user_meta( $uid, '_moe_notify_queue', $queue );
    }

    // ══════════════════════════════════════════════════════════
    //  查询辅助
    // ══════════════════════════════════════════════════════════

    /** 今日某类型已获积分（范围条件命中 idx_time 索引，避免 DATE() 全扫） */
    public static function today_earned( $uid, $type ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(points), 0)
             FROM {$wpdb->prefix}moe_points_log
             WHERE user_id = %d AND type = %s AND points > 0
               AND created_at >= CURDATE()
               AND created_at <  CURDATE() + INTERVAL 1 DAY",
            $uid, $type
        ) );
    }

    /** 是否曾在某篇文章获得过某类型积分 */
    public static function ever_earned_for_post( $uid, $type, $post_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}moe_points_log
             WHERE user_id = %d AND type = %s AND points > 0 AND post_id = %d
             LIMIT 1",
            $uid, $type, $post_id
        ) );
    }

    /** 排行榜（5分钟 transient 缓存） */
    public static function leaderboard( $limit = 10 ) {
        $limit = max( 1, (int) $limit );
        $key   = 'moe_lb_' . $limit;
        $cache = get_transient( $key );
        if ( $cache !== false ) return $cache;

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.user_id, p.points, u.display_name
             FROM {$wpdb->prefix}moe_points p
             LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
             ORDER BY p.points DESC LIMIT %d",
            $limit
        ) );

        set_transient( $key, $rows, 5 * MINUTE_IN_SECONDS );
        return $rows;
    }

    /** 积分或昵称变动后清除排行榜缓存 */
    public static function clear_leaderboard_cache() {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_moe_lb_%'
                OR option_name LIKE '_transient_timeout_moe_lb_%'"
        );
    }

    // ══════════════════════════════════════════════════════════
    //  通知队列（前端弹 toast 用）
    // ══════════════════════════════════════════════════════════

    public static function pop_notifications( $uid ) {
        if ( ! $uid ) return [];
        $queue = get_user_meta( $uid, '_moe_notify_queue', true );
        if ( ! is_array( $queue ) || empty( $queue ) ) return [];
        delete_user_meta( $uid, '_moe_notify_queue' );
        return $queue;
    }

    // ══════════════════════════════════════════════════════════
    //  解锁时效
    // ══════════════════════════════════════════════════════════

    /** 读取解锁时间戳，带静态缓存避免重复查 user_meta */
    private static function _get_unlock_ts( $uid, $post_id ) {
        $k = "{$uid}_{$post_id}";
        if ( ! array_key_exists( $k, self::$_unlock ) ) {
            self::$_unlock[ $k ] = get_user_meta( $uid, "_moe_ovo_{$post_id}", true );
        }
        return self::$_unlock[ $k ];
    }

    /** 解锁是否有效（未过期） */
    public static function is_unlock_valid( $uid, $post_id ) {
        $ts = self::_get_unlock_ts( $uid, $post_id );
        if ( ! $ts ) return false;
        if ( ! get_option( 'moe_unlock_expire_enabled', 0 ) ) return true;
        if ( $ts == 1 ) return false; // 旧数据，功能开启后视为过期
        $days    = max( 1, (int) get_option( 'moe_unlock_expire_days', 2 ) );
        $expires = (int) $ts + $days * DAY_IN_SECONDS;
        return time() < $expires;
    }

    /**
     * 是否有解锁记录（无论是否过期）
     * 供短代码判断"上次解锁已过期"提示，复用静态缓存无额外查库
     */
    public static function has_unlock_record( $uid, $post_id ) {
        $ts = self::_get_unlock_ts( $uid, $post_id );
        return ! empty( $ts );
    }

    /** 记录解锁时间戳，同步更新缓存 */
    public static function set_unlock( $uid, $post_id ) {
        $ts = time();
        update_user_meta( $uid, "_moe_ovo_{$post_id}", $ts );
        self::$_unlock[ "{$uid}_{$post_id}" ] = $ts;
    }

    /** 解锁剩余时间描述（复用缓存，无额外查库） */
    public static function unlock_remaining( $uid, $post_id ) {
        if ( ! get_option( 'moe_unlock_expire_enabled', 0 ) ) return '';
        $ts = self::_get_unlock_ts( $uid, $post_id );
        if ( ! $ts || $ts == 1 ) return '';
        $days   = max( 1, (int) get_option( 'moe_unlock_expire_days', 2 ) );
        $remain = (int) $ts + $days * DAY_IN_SECONDS - time();
        if ( $remain <= 0 ) return '';
        $d = floor( $remain / DAY_IN_SECONDS );
        $h = floor( ( $remain % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
        return $d > 0 ? "{$d}天{$h}小时" : "{$h}小时";
    }

    /** 每日 cron：清理过期解锁 user_meta */
    public static function cleanup_expired_unlocks() {
        if ( ! get_option( 'moe_unlock_expire_enabled', 0 ) ) return;
        $days      = max( 1, (int) get_option( 'moe_unlock_expire_days', 2 ) );
        $threshold = time() - $days * DAY_IN_SECONDS;
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta}
             WHERE meta_key LIKE '_moe_ovo_%%'
               AND meta_value > 1
               AND CAST(meta_value AS UNSIGNED) < %d",
            $threshold
        ) );
    }
}
