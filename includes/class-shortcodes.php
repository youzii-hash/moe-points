<?php
/**
 * ============================================================
 * 文件职责：短代码注册与输出
 *
 *  [qiandao]              → 签到按钮
 *  [ovo cost="N"]...[/ovo] → 积分解锁内容（支持时效）
 *  [666qwq level="N"]...[/666qwq] → 等级解锁内容
 *  [dengji]               → 积分排行榜 + 我的积分
 *  [duiovo]               → 卡密兑换界面
 * ============================================================
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Moe_Shortcodes {

    public function __construct() {
        add_shortcode( 'qiandao', [ $this, 'checkin_btn' ] );
        add_shortcode( 'ovo',     [ $this, 'points_content' ] );
        add_shortcode( '666qwq',  [ $this, 'level_content' ] );
        add_shortcode( 'dengji',  [ $this, 'leaderboard' ] );
        add_shortcode( 'duiovo',  [ $this, 'redeem_card' ] );
    }

    // ══════════════════════════════════════════════════════════
    //  [qiandao] 签到按钮
    // ══════════════════════════════════════════════════════════
    public function checkin_btn( $atts ) {
        $prefix = get_option( 'moe_prefix', '金币' );
        $pts    = (int) get_option( 'moe_pts_checkin', 3 );

        if ( ! is_user_logged_in() ) {
            return sprintf(
                '<div class="moe-checkin"><a href="%s" class="moe-btn moe-btn-primary">🔑 登录后签到</a></div>',
                esc_url( wp_login_url( get_permalink() ) )
            );
        }

        global $wpdb;
        $uid   = get_current_user_id();
        $today = current_time( 'Y-m-d' );
        $last  = $wpdb->get_var( $wpdb->prepare(
            "SELECT last_checkin FROM {$wpdb->prefix}moe_points WHERE user_id = %d", $uid
        ) );

        if ( $last === $today ) {
            return '<div class="moe-checkin"><button class="moe-btn moe-btn-done" disabled>✅ 今日已签到</button></div>';
        }

        return sprintf(
            '<div class="moe-checkin"><button class="moe-btn moe-btn-primary" id="moe-checkin-btn">🌟 每日签到（%s+%d）</button></div>',
            esc_html( $prefix ), $pts
        );
    }

    // ══════════════════════════════════════════════════════════
    //  [ovo]内容[/ovo] 积分解锁
    // ══════════════════════════════════════════════════════════
    public function points_content( $atts, $content = '' ) {
        $atts    = shortcode_atts( [ 'cost' => '' ], $atts );
        $cost    = ( $atts['cost'] !== '' ) ? (int) $atts['cost'] : (int) get_option( 'moe_ovo_cost', 3 );
        $prefix  = get_option( 'moe_prefix', '金币' );
        $post_id = (int) get_the_ID();

        if ( ! is_user_logged_in() ) {
            return $this->_locked_box(
                "🔒 此内容需要 <strong>{$cost}" . esc_html( $prefix ) . "</strong> 解锁",
                sprintf( '<a href="%s" class="moe-btn moe-btn-primary">点击登录</a>', esc_url( wp_login_url( get_permalink() ) ) )
            );
        }

        $uid = get_current_user_id();

        // 已解锁且未过期 → 显示内容
        if ( Moe_Points::is_unlock_valid( $uid, $post_id ) ) {
            $remaining = Moe_Points::unlock_remaining( $uid, $post_id );
            $tip = $remaining
                ? '<p class="moe-expire-tip">⏱ 解锁剩余有效期：' . esc_html( $remaining ) . '</p>'
                : '';
            return '<div class="moe-unlocked">' . $tip . do_shortcode( $content ) . '</div>';
        }

        // 判断是否曾解锁但已过期（复用静态缓存，无额外查库）
        $expired_tip = '';
        if ( get_option( 'moe_unlock_expire_enabled', 0 ) && Moe_Points::has_unlock_record( $uid, $post_id ) ) {
            $expired_tip = '（上次解锁已过期）';
        }

        // 未解锁 / 已过期 → 显示解锁按钮
        $my_pts = Moe_Points::get( $uid );
        if ( $my_pts >= $cost ) {
            $btn = sprintf(
                '<button class="moe-btn moe-btn-primary moe-unlock-btn" data-post="%d" data-cost="%d">使用 %d %s 解锁%s</button>',
                $post_id, $cost, $cost, esc_html( $prefix ), $expired_tip
            );
        } else {
            $btn = sprintf(
                '<button class="moe-btn moe-btn-disabled" disabled>%s不足（需%d，当前%d）%s</button>',
                esc_html( $prefix ), $cost, $my_pts, $expired_tip
            );
        }

        return $this->_locked_box(
            "🔒 此内容需要 <strong>{$cost}" . esc_html( $prefix ) . "</strong> 解锁",
            $btn
        );
    }

    // ══════════════════════════════════════════════════════════
    //  [666qwq level="N"]内容[/666qwq] 等级解锁
    // ══════════════════════════════════════════════════════════
    public function level_content( $atts, $content = '' ) {
        $atts         = shortcode_atts( [ 'level' => '' ], $atts );
        $required_num = ( $atts['level'] !== '' )
            ? (int) $atts['level']
            : (int) get_option( 'moe_level_required', 2 );

        $required_info  = Moe_Levels::by_num( $required_num );
        $required_title = $required_info ? $required_info['title'] : "等级{$required_num}";

        if ( ! is_user_logged_in() ) {
            return $this->_locked_box(
                "🔒 此内容需要达到 <strong>" . esc_html( $required_title ) . "</strong> 才能查看",
                sprintf( '<a href="%s" class="moe-btn moe-btn-primary">点击登录</a>', esc_url( wp_login_url( get_permalink() ) ) )
            );
        }

        $uid        = get_current_user_id();
        $user_level = Moe_Levels::for_user( $uid );

        if ( (int) $user_level['level'] >= $required_num ) {
            return '<div class="moe-unlocked">' . do_shortcode( $content ) . '</div>';
        }

        return $this->_locked_box(
            "🔒 此内容需要达到 <strong>" . esc_html( $required_title ) . "</strong> 才能查看",
            '<span class="moe-locked-sub">当前等级：' . esc_html( $user_level['title'] ) . '</span>'
        );
    }

    // ══════════════════════════════════════════════════════════
    //  [dengji] 积分排行榜 + 我的积分
    // ══════════════════════════════════════════════════════════
    public function leaderboard( $atts ) {
        $prefix = get_option( 'moe_prefix', '金币' );
        $title  = get_option( 'moe_rank_title', '积分排行榜' );
        $count  = (int) get_option( 'moe_rank_count', 10 );
        $list   = Moe_Points::leaderboard( $count );
        $medals = [ '🥇', '🥈', '🥉' ];

        ob_start();
        ?>
        <div class="moe-rank-box">
            <div class="moe-box-title">🏆 <?php echo esc_html( $title ); ?></div>
            <div class="moe-divider"></div>
            <div class="moe-rank-list">
                <?php foreach ( $list as $i => $row ) : ?>
                <div class="moe-rank-row">
                    <span class="moe-rank-no"><?php echo $medals[ $i ] ?? '#' . ( $i + 1 ); ?></span>
                    <span class="moe-rank-name"><?php echo esc_html( $row->display_name ); ?></span>
                    <span class="moe-rank-pts"><?php echo esc_html( "{$prefix}：{$row->points}" ); ?></span>
                </div>
                <?php endforeach; ?>
                <?php if ( empty( $list ) ) : ?>
                <p class="moe-empty">暂无数据～</p>
                <?php endif; ?>
            </div>
            <div class="moe-my-block">
                <?php if ( is_user_logged_in() ) :
                    $uid    = get_current_user_id();
                    $my_pts = Moe_Points::get( $uid );
                    $level  = Moe_Levels::for_user( $uid );
                ?>
                <div class="moe-box-title">💎 我的积分</div>
                <div class="moe-divider"></div>
                <div class="moe-my-row"><?php echo esc_html( "{$prefix}：{$my_pts}" ); ?></div>
                <div class="moe-my-row">等级头衔：<?php echo esc_html( $level['title'] ); ?></div>
                <?php else : ?>
                <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="moe-btn moe-btn-primary">登录查看我的积分</a>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ══════════════════════════════════════════════════════════
    //  [duiovo] 卡密兑换界面
    // ══════════════════════════════════════════════════════════
    public function redeem_card( $atts ) {
        $prefix = get_option( 'moe_prefix', '金币' );

        if ( ! is_user_logged_in() ) {
            return sprintf(
                '<div class="moe-card-box"><p>请先 <a href="%s">登录</a> 后兑换卡密</p></div>',
                esc_url( wp_login_url( get_permalink() ) )
            );
        }

        $uid    = get_current_user_id();
        $my_pts = Moe_Points::get( $uid );

        ob_start();
        ?>
        <div class="moe-card-box">
            <div class="moe-box-title">🎫 卡密兑换</div>
            <div class="moe-divider"></div>
            <p class="moe-card-hint">当前<?php echo esc_html( $prefix ); ?>：<strong><?php echo $my_pts; ?></strong></p>
            <div class="moe-card-form">
                <input type="text" id="moe-card-input" class="moe-input" placeholder="请输入卡密码" autocomplete="off">
                <button id="moe-card-submit" class="moe-btn moe-btn-primary">✨ 立即兑换</button>
            </div>
            <p id="moe-card-msg" class="moe-msg"></p>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── 锁定框 HTML ────────────────────────────────────────────
    private function _locked_box( $msg, $action_html ) {
        return "<div class='moe-locked-box'>
            <p class='moe-locked-msg'>{$msg}</p>
            <div class='moe-locked-action'>{$action_html}</div>
        </div>";
    }
}
