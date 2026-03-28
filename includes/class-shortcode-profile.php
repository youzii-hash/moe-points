<?php
/**
 * ============================================================
 * 文件职责：[gerzhu] 个人主页短代码
 *   /page-slug/           → 当前登录用户完整主页
 *   /page-slug/?moe_uid=X → 他人公开主页（只读）
 * ============================================================
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Moe_Shortcode_Profile {

    public function __construct() {
        add_shortcode( 'gerzhu', [ $this, 'render' ] );
    }

    public function render( $atts ) {
        $view_uid = (int) ( $_GET['moe_uid'] ?? 0 );
        if ( $view_uid && $view_uid !== get_current_user_id() ) {
            return $this->render_public( $view_uid );
        }
        if ( ! is_user_logged_in() ) {
            return '<div class="moe-profile-wrap"><p class="moe-tip">请先 <a href="'
                . esc_url( wp_login_url( get_permalink() ) )
                . '">登录</a> 后查看个人主页</p></div>';
        }
        return $this->render_self();
    }

    // ══════════════════════════════════════════════════════════
    //  自己的主页
    // ══════════════════════════════════════════════════════════
    private function render_self() {
        $uid        = get_current_user_id();
        $user       = get_userdata( $uid );
        $avatar_url = Moe_Profile::get_avatar_url( $uid ) ?: get_avatar_url( $uid, [ 'size' => 120 ] );
        $bio        = (string) get_user_meta( $uid, 'description', true ); // 只读一次
        $gallery    = Moe_Profile::get_gallery();
        $cost       = (int) get_option( 'moe_avatar_change_cost', 0 );
        $prefix     = get_option( 'moe_prefix', '金币' );
        $posts      = Moe_Profile::get_user_posts( $uid );

        // 一次查询同时得到通知列表和未读数，避免两条相似 SQL
        $notices = Moe_Profile::get_notifications( $uid );
        $unread  = count( array_filter( $notices, fn( $n ) => ! $n->is_read ) );

        // 批量预热评论缓存，避免下方循环 N 次 get_comment() 各自查库
        if ( ! empty( $notices ) ) {
            $uncached_ids = array_filter(
                array_column( $notices, 'comment_id' ),
                fn( $id ) => $id && ! wp_cache_get( (int)$id, 'comment' )
            );
            if ( $uncached_ids ) {
                global $wpdb;
                $in   = implode( ',', array_map( 'intval', $uncached_ids ) );
                $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->comments} WHERE comment_ID IN ({$in})" );
                foreach ( $rows as $row ) {
                    wp_cache_set( $row->comment_ID, $row, 'comment' );
                }
            }
        }

        ob_start();
        ?>
        <div class="moe-profile-wrap" id="moe-profile-self">

            <!-- 头像 -->
            <div class="moe-profile-section moe-avatar-section">
                <div class="moe-avatar-box">
                    <img id="moe-self-avatar" src="<?php echo esc_url( $avatar_url ); ?>" alt="头像" class="moe-self-avatar">
                    <?php if ( ! empty( $gallery ) ) : ?>
                    <button class="moe-avatar-change-btn" id="moe-avatar-btn"
                        title="<?php echo $cost > 0 ? "更换头像（{$cost}{$prefix}）" : '更换头像'; ?>">✏️</button>
                    <?php endif; ?>
                </div>
                <?php if ( ! empty( $gallery ) ) : ?>
                <div class="moe-avatar-panel" id="moe-avatar-panel" style="display:none">
                    <p class="moe-avatar-panel-tip">
                        选择头像<?php echo $cost > 0 ? "（消耗 <strong>{$cost}</strong> {$prefix}）" : '（免费）'; ?>
                    </p>
                    <div class="moe-avatar-gallery">
                        <?php foreach ( $gallery as $item ) : ?>
                        <img src="<?php echo esc_url( $item['thumb'] ); ?>"
                             data-att="<?php echo (int) $item['id']; ?>"
                             data-url="<?php echo esc_url( $item['url'] ); ?>"
                             class="moe-gallery-item" alt="可选头像">
                        <?php endforeach; ?>
                    </div>
                    <button class="moe-btn-sm" id="moe-avatar-cancel">取消</button>
                </div>
                <?php endif; ?>
            </div>

            <!-- 昵称 -->
            <div class="moe-profile-section">
                <div class="moe-profile-row">
                    <span class="moe-profile-label">🙂 昵称</span>
                    <span class="moe-profile-val" id="moe-val-nickname"><?php echo esc_html( $user->display_name ); ?></span>
                    <button class="moe-edit-btn" data-field="nickname" data-val="<?php echo esc_attr( $user->display_name ); ?>">修改</button>
                </div>
            </div>

            <!-- 密码 -->
            <div class="moe-profile-section">
                <div class="moe-profile-row">
                    <span class="moe-profile-label">🔑 密码</span>
                    <span class="moe-profile-val">••••••</span>
                    <button class="moe-edit-btn" data-field="password">修改</button>
                </div>
            </div>

            <!-- 简介 -->
            <div class="moe-profile-section">
                <div class="moe-profile-row">
                    <span class="moe-profile-label">📝 简介</span>
                    <span class="moe-profile-val moe-bio-val" id="moe-val-bio"><?php
                        echo $bio ? esc_html( $bio ) : '<em style="color:#aaa">暂无简介</em>';
                    ?></span>
                    <button class="moe-edit-btn" data-field="bio" data-val="<?php echo esc_attr( $bio ); ?>">修改</button>
                </div>
            </div>

            <!-- 投稿文章 -->
            <div class="moe-profile-section">
                <div class="moe-section-title">📄 我的投稿</div>
                <ul class="moe-post-list">
                    <?php if ( empty( $posts ) ) : ?>
                    <li class="moe-empty-tip">暂无投稿</li>
                    <?php else : foreach ( $posts as $p ) : ?>
                    <li>
                        <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>" class="moe-post-link">
                            <span class="moe-post-title"><?php echo esc_html( $p->post_title ); ?></span>
                            <span class="moe-post-date"><?php echo esc_html( get_the_date( 'Y-m-d', $p ) ); ?></span>
                        </a>
                    </li>
                    <?php endforeach; endif; ?>
                </ul>
            </div>

            <!-- 消息通知 -->
            <div class="moe-profile-section">
                <div class="moe-section-title">
                    🔔 消息通知
                    <?php if ( $unread > 0 ) : ?>
                    <span class="moe-badge-red"><?php echo $unread; ?></span>
                    <button class="moe-btn-sm" id="moe-mark-read" style="margin-left:8px">全部已读</button>
                    <?php endif; ?>
                </div>
                <div class="moe-notice-list">
                    <?php if ( empty( $notices ) ) : ?>
                    <p class="moe-empty-tip">暂无消息</p>
                    <?php else : foreach ( $notices as $n ) :
                        $cmt     = get_comment( (int) $n->comment_id ); // 命中上方预热缓存
                        $excerpt = $cmt ? mb_substr( strip_tags( $cmt->comment_content ), 0, 60 ) . '…' : '';
                        $p_url   = $n->post_id
                            ? get_permalink( $n->post_id ) . '#comment-' . $n->comment_id
                            : '#';
                    ?>
                    <div class="moe-notice-item <?php echo $n->is_read ? '' : 'moe-unread'; ?>">
                        <span class="moe-notice-from"><?php echo esc_html( $n->from_name ?: '访客' ); ?></span>
                        回复了你：
                        <a href="<?php echo esc_url( $p_url ); ?>" class="moe-notice-excerpt"><?php echo esc_html( $excerpt ); ?></a>
                        <span class="moe-notice-time"><?php echo esc_html( human_time_diff( strtotime( $n->created_at ) ) . '前' ); ?></span>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

        </div>

        <!-- 编辑弹窗 -->
        <div id="moe-edit-overlay" style="display:none">
            <div class="moe-edit-modal">
                <div class="moe-edit-modal-hd">
                    <span id="moe-edit-title">修改</span>
                    <button id="moe-edit-close">×</button>
                </div>
                <div class="moe-edit-modal-bd" id="moe-edit-body"></div>
                <div class="moe-edit-modal-ft">
                    <button class="moe-btn-primary" id="moe-edit-save">保存</button>
                    <button class="moe-btn-sm" id="moe-edit-cancel2">取消</button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ══════════════════════════════════════════════════════════
    //  他人公开主页
    // ══════════════════════════════════════════════════════════
    private function render_public( $uid ) {
        $user = get_userdata( $uid );
        if ( ! $user ) return '<p class="moe-tip">用户不存在</p>';

        $avatar_url = Moe_Profile::get_avatar_url( $uid ) ?: get_avatar_url( $uid, [ 'size' => 120 ] );
        $bio        = esc_html( get_user_meta( $uid, 'description', true ) ?: '这个人很神秘，什么也没留下～' );
        $posts      = Moe_Profile::get_user_posts( $uid );

        ob_start();
        ?>
        <div class="moe-profile-wrap moe-profile-public">
            <div class="moe-public-header">
                <img src="<?php echo esc_url( $avatar_url ); ?>" class="moe-public-avatar" alt="头像">
                <div class="moe-public-info">
                    <div class="moe-public-name"><?php echo esc_html( $user->display_name ); ?></div>
                    <div class="moe-public-bio"><?php echo $bio; ?></div>
                </div>
            </div>
            <div class="moe-profile-section">
                <div class="moe-section-title">📄 TA 的投稿</div>
                <ul class="moe-post-list">
                    <?php if ( empty( $posts ) ) : ?>
                    <li class="moe-empty-tip">暂无投稿</li>
                    <?php else : foreach ( $posts as $p ) : ?>
                    <li>
                        <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>" class="moe-post-link">
                            <span class="moe-post-title"><?php echo esc_html( $p->post_title ); ?></span>
                            <span class="moe-post-date"><?php echo esc_html( get_the_date( 'Y-m-d', $p ) ); ?></span>
                        </a>
                    </li>
                    <?php endforeach; endif; ?>
                </ul>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
