<?php
/**
 * ============================================================
 * 文件职责：个人主页核心逻辑
 *   - 头像库管理（从管理员图集选择，可设积分消耗）
 *   - 昵称 / 密码 / 简介修改 AJAX
 *   - 消息通知（评论回复，7天内 / 最多20条）
 *   - 用户投稿文章列表
 * ============================================================
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Moe_Profile {

    public function __construct() {
        add_action( 'wp_ajax_moe_change_avatar',  [ $this, 'ajax_change_avatar' ] );
        add_action( 'wp_ajax_moe_update_profile', [ $this, 'ajax_update_profile' ] );
        add_action( 'wp_ajax_moe_mark_read',      [ $this, 'ajax_mark_read' ] );
    }

    // ══════════════════════════════════════════════════════════
    //  头像
    // ══════════════════════════════════════════════════════════

    /** 获取用户当前头像URL（自定义 > 默认设置 > 空） */
    public static function get_avatar_url( $uid ) {
        $url = get_user_meta( $uid, '_moe_avatar', true );
        return $url ?: get_option( 'moe_default_avatar', '' );
    }

    /** 获取管理员设置的可选头像图集 */
    public static function get_gallery() {
        $ids = json_decode( get_option( 'moe_avatar_gallery', '[]' ), true );
        if ( ! is_array( $ids ) || empty( $ids ) ) return [];
        $out = [];
        foreach ( $ids as $id ) {
            $id  = (int) $id;
            $url = wp_get_attachment_url( $id );
            if ( ! $url ) continue;
            $out[] = [
                'id'    => $id,
                'url'   => $url,
                'thumb' => wp_get_attachment_image_url( $id, [ 80, 80 ] ) ?: $url,
            ];
        }
        return $out;
    }

    public function ajax_change_avatar() {
        check_ajax_referer( 'moe_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'msg' => '请先登录' ] );

        $uid    = get_current_user_id();
        $att_id = (int) ( $_POST['att_id'] ?? 0 );
        $cost   = (int) get_option( 'moe_avatar_change_cost', 0 );
        $prefix = get_option( 'moe_prefix', '金币' );

        // 验证图片在图集内（严格整数比较）
        $gallery_ids = json_decode( get_option( 'moe_avatar_gallery', '[]' ), true );
        if ( ! in_array( $att_id, array_map( 'intval', (array) $gallery_ids ), true ) ) {
            wp_send_json_error( [ 'msg' => '无效的头像选择' ] );
        }

        if ( $cost > 0 ) {
            if ( ! Moe_Points::spend( $uid, $cost, 'avatar', "更换头像消耗{$cost}{$prefix}" ) ) {
                wp_send_json_error( [ 'msg' => "{$prefix}不足，需要 {$cost} 个" ] );
            }
        }

        $url = wp_get_attachment_url( $att_id );
        if ( ! $url ) wp_send_json_error( [ 'msg' => '头像图片不存在' ] );

        update_user_meta( $uid, '_moe_avatar', $url );
        wp_send_json_success( [ 'msg' => '头像更换成功！', 'url' => $url ] );
    }

    // ══════════════════════════════════════════════════════════
    //  资料修改
    // ══════════════════════════════════════════════════════════

    public function ajax_update_profile() {
        check_ajax_referer( 'moe_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'msg' => '请先登录' ] );

        $uid   = get_current_user_id();
        $field = sanitize_key( $_POST['field'] ?? '' );
        $value = sanitize_text_field( $_POST['value'] ?? '' );

        switch ( $field ) {
            case 'nickname':
                if ( mb_strlen( $value ) < 2 || mb_strlen( $value ) > 20 ) {
                    wp_send_json_error( [ 'msg' => '昵称长度2-20字符' ] );
                }
                wp_update_user( [ 'ID' => $uid, 'display_name' => $value ] );
                Moe_Points::clear_leaderboard_cache();
                wp_send_json_success( [ 'msg' => '昵称修改成功', 'value' => $value ] );

            case 'bio':
                if ( mb_strlen( $value ) > 200 ) {
                    wp_send_json_error( [ 'msg' => '简介不超过200字' ] );
                }
                update_user_meta( $uid, 'description', $value );
                wp_send_json_success( [ 'msg' => '简介修改成功', 'value' => $value ] );

            case 'password':
                // 密码不经过 sanitize_text_field（会破坏特殊字符），直接从原始 POST 读
                $new_pw  = $_POST['value']     ?? '';
                $confirm = $_POST['confirm']   ?? '';
                $old_pw  = $_POST['old_value'] ?? '';
                $user    = get_user_by( 'id', $uid );
                if ( ! $user || ! wp_check_password( $old_pw, $user->user_pass, $uid ) ) {
                    wp_send_json_error( [ 'msg' => '当前密码错误' ] );
                }
                if ( $new_pw !== $confirm ) {
                    wp_send_json_error( [ 'msg' => '两次密码不一致' ] );
                }
                if ( strlen( $new_pw ) < 6 ) {
                    wp_send_json_error( [ 'msg' => '新密码至少6位' ] );
                }
                wp_set_password( $new_pw, $uid );
                wp_send_json_success( [ 'msg' => '密码修改成功，请重新登录' ] );

            default:
                wp_send_json_error( [ 'msg' => '未知操作' ] );
        }
    }

    // ══════════════════════════════════════════════════════════
    //  消息通知
    // ══════════════════════════════════════════════════════════

    /** 新增一条回复通知，保留最新20条 */
    public static function add_notification( $to_uid, $from_uid, $comment_id, $post_id ) {
        if ( $to_uid === $from_uid ) return;

        global $wpdb;
        $tbl = "{$wpdb->prefix}moe_notifications";

        $wpdb->insert( $tbl, [
            'user_id'    => $to_uid,
            'from_uid'   => $from_uid,
            'type'       => 'reply',
            'comment_id' => $comment_id,
            'post_id'    => $post_id,
        ] );

        // 保留最新20条：先 COUNT 再条件 DELETE，避免子查询兼容性问题
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tbl} WHERE user_id = %d", $to_uid
        ) );
        if ( $count > 20 ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$tbl} WHERE user_id = %d ORDER BY id ASC LIMIT %d",
                $to_uid, $count - 20
            ) );
        }
    }

    /**
     * 获取用户最近7天通知
     * INNER JOIN wp_comments 自动过滤已删除/未审核的评论
     * 返回结果含 is_read 字段，调用方可同时统计未读数，无需额外查询
     */
    public static function get_notifications( $uid ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT n.*, u.display_name AS from_name
             FROM {$wpdb->prefix}moe_notifications n
             INNER JOIN {$wpdb->comments} c
                 ON n.comment_id = c.comment_ID AND c.comment_approved = '1'
             LEFT JOIN {$wpdb->users} u ON n.from_uid = u.ID
             WHERE n.user_id = %d
               AND n.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY n.id DESC",
            $uid
        ) );
    }

    /** AJAX：全部标记已读 */
    public function ajax_mark_read() {
        check_ajax_referer( 'moe_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error();
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}moe_notifications",
            [ 'is_read' => 1 ],
            [ 'user_id' => get_current_user_id(), 'is_read' => 0 ]
        );
        wp_send_json_success();
    }

    // ══════════════════════════════════════════════════════════
    //  投稿文章
    // ══════════════════════════════════════════════════════════

    public static function get_user_posts( $uid, $limit = 20 ) {
        return get_posts( [
            'author'         => $uid,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true, // 不统计总数，提升性能
        ] );
    }
}
