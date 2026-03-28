<?php
/**
 * ============================================================
 * 文件职责：等级头衔系统
 *   - 从后台配置读取等级列表（带请求级静态缓存）
 *   - 根据积分计算用户当前等级
 * ============================================================
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Moe_Levels {

    /** 请求级缓存，避免同一请求多次 get_option + usort */
    private static $_all = null;

    /**
     * 获取所有等级配置，按所需积分升序
     */
    public static function all() {
        if ( self::$_all !== null ) return self::$_all;
        $raw    = get_option( 'moe_levels', '[]' );
        $levels = json_decode( $raw, true );
        if ( ! is_array( $levels ) || empty( $levels ) ) {
            return self::$_all = [];
        }
        usort( $levels, fn( $a, $b ) => (int)$a['points'] <=> (int)$b['points'] );
        return self::$_all = $levels;
    }

    /**
     * 后台保存等级后必须清除缓存
     */
    public static function flush_cache() {
        self::$_all = null;
    }

    /**
     * 根据积分获取对应等级
     */
    public static function by_points( $points ) {
        $current = [ 'level' => 1, 'title' => '新手', 'points' => 0, 'show_frame' => false, 'show_badge' => false, 'frame_url' => '', 'badge_color' => '#a8d8f0' ];
        foreach ( self::all() as $l ) {
            if ( (int)$points >= (int)$l['points'] ) $current = $l;
        }
        return $current;
    }

    /** 根据用户ID获取等级 */
    public static function for_user( $uid ) {
        return self::by_points( Moe_Points::get( $uid ) );
    }

    /** 根据等级编号查找 */
    public static function by_num( $level_num ) {
        foreach ( self::all() as $l ) {
            if ( (int)$l['level'] === (int)$level_num ) return $l;
        }
        return null;
    }

    /** 等级编号 → 标题映射（后台下拉框用） */
    public static function titles_map() {
        $map = [];
        foreach ( self::all() as $l ) {
            $map[ (int)$l['level'] ] = esc_html( $l['title'] );
        }
        return $map;
    }
}
