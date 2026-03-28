<?php
/**
 * ============================================================
 * 文件职责：评论区等级展示
 *
 * 方案：PHP 只往 <img> 上注入 data 属性，JS 负责所有视觉效果
 *   data-moe-uid     = 用户ID
 *   data-moe-frame   = 头像框图片URL（无则不加）
 *   data-moe-profile = 个人主页URL（无则不加）
 *
 * 这样完全不改 DOM 结构，主题的 overflow/layout 不受影响，
 * 昵称链接由 append_badge 单独处理。
 * ============================================================
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Moe_Comment_Display {

    public function __construct() {
        add_filter( 'get_avatar',              [ $this, 'inject_data_attrs' ], 10, 6 );
        add_filter( 'get_comment_author_link', [ $this, 'append_badge' ],      10, 3 );
    }

    // ── 缓存 ──────────────────────────────────────────────────
    private static $_cache   = [];   // uid → level
    private static $_pbase   = null; // 主页页面URL

    private static function _pbase() {
        if ( self::$_pbase === null ) {
            $pid = (int) get_option( 'moe_profile_page_id', 0 );
            self::$_pbase = $pid ? (string) get_permalink( $pid ) : '';
        }
        return self::$_pbase;
    }

    private static function _purl( $uid ) {
        $b = self::_pbase();
        return ( $b && $uid ) ? add_query_arg( 'moe_uid', (int) $uid, $b ) : '';
    }

    private static function _level( $uid ) {
        $uid = (int) $uid;
        if ( ! $uid ) return null;
        if ( ! isset( self::$_cache[ $uid ] ) ) {
            self::$_cache[ $uid ] = Moe_Levels::for_user( $uid );
        }
        return self::$_cache[ $uid ];
    }

    // ── uid 解析 ─────────────────────────────────────────────
    private static function _uid( $v ) {
        if ( is_numeric( $v ) && (int)$v > 0 ) return (int) $v;
        if ( $v instanceof WP_User )    return $v->ID;
        if ( $v instanceof WP_Comment ) return (int) $v->user_id;
        if ( is_string( $v ) && strpos( $v, '@' ) !== false ) {
            $u = get_user_by( 'email', $v );
            return $u ? $u->ID : 0;
        }
        return 0;
    }

    // ══════════════════════════════════════════════════════════
    //  给头像 <img> 注入 data-moe-* 属性
    //  不改任何标签结构，主题布局完全不受影响
    // ══════════════════════════════════════════════════════════
    public function inject_data_attrs( $avatar, $id_or_email, $size, $default, $alt, $args ) {
        $uid = self::_uid( $id_or_email );
        if ( ! $uid ) return $avatar;

        $attrs = ' data-moe-uid="' . $uid . '"';

        // 个人主页URL
        $purl = self::_purl( $uid );
        if ( $purl ) {
            $attrs .= ' data-moe-profile="' . esc_attr( $purl ) . '"';
        }

        // 头像框URL
        $level = self::_level( $uid );
        if ( $level && ! empty( $level['show_frame'] ) && ! empty( $level['frame_url'] ) ) {
            $attrs .= ' data-moe-frame="' . esc_attr( $level['frame_url'] ) . '"';
        }

        // 把 data 属性注入到 <img 后面（不改其他任何内容）
        return preg_replace( '/<img\b/', '<img' . $attrs, $avatar, 1 );
    }

    // ══════════════════════════════════════════════════════════
    //  昵称链接：改 href + 追加头衔徽章
    // ══════════════════════════════════════════════════════════
    public function append_badge( $link, $author = '', $comment_obj = null ) {
        // 解析 uid（兼容 WP_Comment 对象 / 整数ID / null）
        $uid = 0;
        if ( $comment_obj instanceof WP_Comment ) {
            $uid = (int) $comment_obj->user_id;
        } elseif ( is_numeric( $comment_obj ) && $comment_obj > 0 ) {
            $c   = get_comment( (int) $comment_obj );
            $uid = $c ? (int) $c->user_id : 0;
        }
        // fallback：全局 $comment
        if ( ! $uid ) {
            global $comment;
            if ( $comment instanceof WP_Comment ) $uid = (int) $comment->user_id;
        }
        if ( ! $uid ) return $link;

        // 改写 href → 个人主页，同时加 data-moe-profile 供 JS 事件拦截用
        $purl = self::_purl( $uid );
        if ( $purl ) {
            $link = preg_replace(
                '/\bhref=["\'][^"\']*["\']/i',
                'href="' . esc_url( $purl ) . '" data-moe-profile="' . esc_attr( $purl ) . '"',
                $link
            );
        }
        // $purl 为空时不加任何属性，保持原始链接不变

        // 追加头衔徽章
        $level = self::_level( $uid );
        if ( $level && ! empty( $level['show_badge'] ) ) {
            $link .= sprintf(
                '<span class="moe-badge" style="background:%s;color:#fff;">%s</span>',
                esc_attr( $level['badge_color'] ?? '#5bb8f5' ),
                esc_html( $level['title'] )
            );
        }

        return $link;
    }
}
