<?php
/**
 * ============================================================
 * 文件职责：[ovo] 解锁防护系统
 *   第1次超限 → 静默封禁2天
 *   封禁期间尝试 → 提示"过于频繁"
 *   第2次超限 → 永久封号
 * ============================================================
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Moe_Unlock_Guard {

    // ── 主接口 ────────────────────────────────────────────────

    public static function check_before_unlock( $uid ) {
        $ban = self::get_active_ban( $uid );
        if ( $ban ) {
            if ( $ban->ban_type === 'permanent' ) {
                return [ 'ok' => false, 'msg' => '账号已被封禁，请联系管理员。' ];
            }
            return [ 'ok' => false, 'msg' => '过于频繁，已被限制，等待解封，再次频繁将封号！' ];
        }

        $over = self::is_over_limit( $uid );
        if ( $over ) {
            self::trigger_ban( $uid, $over );
            return [ 'ok' => false, 'msg' => '' ]; // 第一次超限静默失败
        }

        return [ 'ok' => true ];
    }

    public static function record_unlock( $uid, $post_id ) {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}moe_unlock_records", [
            'user_id' => $uid,
            'post_id' => $post_id,
        ] );
    }

    // ── 频率统计 ──────────────────────────────────────────────

    private static function is_over_limit( $uid ) {
        $level_data = Moe_Levels::for_user( $uid );
        $level_num  = (string) $level_data['level'];
        $all        = json_decode( get_option( 'moe_unlock_limits', '{}' ), true );
        $limits     = $all[ $level_num ] ?? [ 'h24' => 0, 'week' => 0, 'month' => 0 ];

        global $wpdb;
        $counts = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(unlocked_at >= NOW() - INTERVAL 24 HOUR) AS c24,
                SUM(unlocked_at >= NOW() - INTERVAL 7  DAY)  AS cwk,
                SUM(unlocked_at >= NOW() - INTERVAL 1  MONTH) AS cmo
             FROM {$wpdb->prefix}moe_unlock_records
             WHERE user_id = %d",
            $uid
        ) );

        $c24 = (int) ( $counts->c24 ?? 0 );
        $cwk = (int) ( $counts->cwk ?? 0 );
        $cmo = (int) ( $counts->cmo ?? 0 );
        $ttl = $level_data['title'];

        if ( $limits['h24']  > 0 && $c24 >= $limits['h24']  ) return "【{$ttl}】24h超限（上限{$limits['h24']}，已{$c24}）";
        if ( $limits['week'] > 0 && $cwk >= $limits['week'] ) return "【{$ttl}】周超限（上限{$limits['week']}，已{$cwk}）";
        if ( $limits['month']> 0 && $cmo >= $limits['month'] ) return "【{$ttl}】月超限（上限{$limits['month']}，已{$cmo}）";

        return false;
    }

    // ── 封禁执行 ──────────────────────────────────────────────

    private static function trigger_ban( $uid, $reason ) {
        global $wpdb;
        $tbl = "{$wpdb->prefix}moe_unlock_bans";

        $hist = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tbl} WHERE user_id = %d", $uid
        ) );

        if ( $hist === 0 ) {
            // 第1次：临时封禁2天
            // 用 current_time('mysql') 遵守 WP 时区设置
            $unban_at = gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS );
            $wpdb->insert( $tbl, [
                'user_id'         => $uid,
                'ban_type'        => 'temp',
                'violation_count' => 1,
                'ban_reason'      => $reason,
                'unban_at'        => $unban_at,
                'is_active'       => 1,
            ] );
        } else {
            // 第2次：永久封号
            $wpdb->insert( $tbl, [
                'user_id'         => $uid,
                'ban_type'        => 'permanent',
                'violation_count' => $hist + 1,
                'ban_reason'      => $reason . '（第二次超限，永久封号）',
                'unban_at'        => null,
                'is_active'       => 1,
            ] );
            $wpdb->update( $wpdb->users, [ 'user_status' => 2 ], [ 'ID' => $uid ] );
            update_user_meta( $uid, '_moe_permanently_banned', current_time( 'mysql' ) );
            WP_Session_Tokens::get_instance( $uid )->destroy_all();
        }
    }

    // ── 查询活跃封禁 ─────────────────────────────────────────

    private static function get_active_ban( $uid ) {
        global $wpdb;
        $tbl = "{$wpdb->prefix}moe_unlock_bans";

        $ban = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$tbl} WHERE user_id = %d AND is_active = 1 ORDER BY id DESC LIMIT 1",
            $uid
        ) );

        if ( ! $ban ) return null;

        // 临时封禁自动到期检查
        if ( $ban->ban_type === 'temp' && $ban->unban_at && strtotime( $ban->unban_at ) <= time() ) {
            $wpdb->update( $tbl, [ 'is_active' => 0 ], [ 'id' => $ban->id ] );
            return null;
        }

        return $ban;
    }

    // ── 后台管理接口 ─────────────────────────────────────────

    public static function get_ban_list( $per = 20, $offset = 0 ) {
        global $wpdb;
        $tbl = "{$wpdb->prefix}moe_unlock_bans";
        return [
            'rows'  => $wpdb->get_results( $wpdb->prepare(
                "SELECT b.*, u.display_name, u.user_login
                 FROM {$tbl} b
                 LEFT JOIN {$wpdb->users} u ON b.user_id = u.ID
                 ORDER BY b.id DESC LIMIT %d OFFSET %d",
                $per, $offset
            ) ),
            'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" ),
        ];
    }

    public static function unban( $ban_id ) {
        global $wpdb;
        $tbl = "{$wpdb->prefix}moe_unlock_bans";
        $ban = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $ban_id ) );
        if ( ! $ban ) return;
        $wpdb->update( $tbl, [ 'is_active' => 0 ], [ 'id' => $ban_id ] );
        if ( $ban->ban_type === 'permanent' ) {
            $wpdb->update( $wpdb->users, [ 'user_status' => 0 ], [ 'ID' => $ban->user_id ] );
            delete_user_meta( $ban->user_id, '_moe_permanently_banned' );
        }
    }

    public static function clear_all_bans() {
        global $wpdb;
        $tbl      = "{$wpdb->prefix}moe_unlock_bans";
        $perm_ids = $wpdb->get_col( "SELECT user_id FROM {$tbl} WHERE ban_type = 'permanent'" );
        foreach ( $perm_ids as $uid ) {
            $wpdb->update( $wpdb->users, [ 'user_status' => 0 ], [ 'ID' => $uid ] );
            delete_user_meta( $uid, '_moe_permanently_banned' );
        }
        $wpdb->query( "TRUNCATE TABLE {$tbl}" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}moe_unlock_records" );
    }
}
