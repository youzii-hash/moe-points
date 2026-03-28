/**
 * ============================================================
 * 文件职责：前端交互脚本
 *   - Toast 通知
 *   - 签到按钮
 *   - [ovo] 积分解锁
 *   - [duiovo] 卡密兑换
 *   - 固定积分按钮 & 居中弹窗开关
 * ============================================================
 */

(function ($) {
    'use strict';

    /* ── Toast 通知 ─────────────────────────────────────────── */
    function showToast(msg, duration) {
        duration = duration || 3000;
        var $wrap = $('#moe-toast-wrap');
        if (!$wrap.length) {
            $wrap = $('<div id="moe-toast-wrap"></div>').appendTo('body');
        }
        var $t = $('<div class="moe-toast"></div>').text(msg);
        $wrap.append($t);
        setTimeout(function () {
            $t.addClass('out');
            setTimeout(function () { $t.remove(); }, 380);
        }, duration);
    }

    /* 页面加载时弹出积分变动通知 */
    $(function () {
        if (typeof moeData !== 'undefined' && moeData.notifyQueue && moeData.notifyQueue.length) {
            $.each(moeData.notifyQueue, function (i, msg) {
                setTimeout(function () { showToast(msg); }, i * 600);
            });
        }
    });

    /* ── 签到按钮 ───────────────────────────────────────────── */
    $(document).on('click', '#moe-checkin-btn', function () {
        var $btn = $(this);
        if (!moeData.loggedIn) { window.location.href = moeData.loginUrl; return; }
        $btn.prop('disabled', true).text('签到中…');
        $.post(moeData.ajaxUrl, { action: 'moe_checkin', nonce: moeData.nonce }, function (res) {
            if (res.success) {
                $btn.text('今日已签到').addClass('moe-btn-done');
                showToast(res.data.msg);
            } else {
                $btn.prop('disabled', false).text('每日签到');
                showToast(res.data.msg);
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('每日签到');
            showToast('网络错误，请重试');
        });
    });

    /* ── [ovo] 积分解锁 ─────────────────────────────────────── */
    $(document).on('click', '.moe-unlock-btn', function () {
        var $btn   = $(this);
        var postId = $btn.data('post');
        var cost   = $btn.data('cost');
        var prefix = (typeof moeData !== 'undefined' && moeData.prefix) ? moeData.prefix : '积分';
        if (!moeData.loggedIn) { window.location.href = moeData.loginUrl; return; }
        if (!confirm('确认花费 ' + cost + ' ' + prefix + ' 解锁此内容？')) return;
        $btn.prop('disabled', true).text('解锁中…');
        $.post(moeData.ajaxUrl, {
            action: 'moe_unlock_ovo', nonce: moeData.nonce, post_id: postId
        }, function (res) {
            if (res.success) { showToast(res.data.msg); window.location.reload(); }
            else {
                $btn.prop('disabled', false).text('使用 ' + cost + ' ' + prefix + ' 解锁');
                showToast(res.data.msg);
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('使用 ' + cost + ' ' + prefix + ' 解锁');
            showToast('网络错误，请重试');
        });
    });

    /* ── [duiovo] 卡密兑换 ──────────────────────────────────── */
    $(document).on('click', '#moe-card-submit', function () {
        var code = $.trim($('#moe-card-input').val());
        var $msg = $('#moe-card-msg');
        if (!code) { $msg.removeClass('success').addClass('error').text('请输入卡密'); return; }
        var $btn = $(this).prop('disabled', true).text('兑换中…');
        $.post(moeData.ajaxUrl, {
            action: 'moe_redeem_card', nonce: moeData.nonce, code: code
        }, function (res) {
            if (res.success) {
                $msg.removeClass('error').addClass('success').text(res.data.msg);
                $('#moe-card-input').val('');
                showToast(res.data.msg);
            } else {
                $msg.removeClass('success').addClass('error').text(res.data.msg);
            }
            $btn.prop('disabled', false).text('立即兑换');
        }).fail(function () {
            $msg.removeClass('success').addClass('error').text('网络错误，请重试');
            $btn.prop('disabled', false).text('立即兑换');
        });
    });

    /* ── 固定按钮 & 居中弹窗 ────────────────────────────────── */
    function openPanel() {
        $('#moe-panel').removeClass('moe-panel-hidden');
        $('#moe-overlay').addClass('active');
    }
    function closePanel() {
        $('#moe-panel').addClass('moe-panel-hidden');
        $('#moe-overlay').removeClass('active');
    }

    $(document).on('click', '#moe-ball',         openPanel);
    $(document).on('click', '#moe-panel-close',  closePanel);
    $(document).on('click', '#moe-overlay',      closePanel);

    /* ESC 键关闭 */
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') closePanel();
    });


    /* ── 按钮：上滑隐藏、下滑出现 ──────────────────────────── */
    (function () {
        var ball = document.getElementById('moe-ball');
        if (!ball) return;
        var lastY   = window.pageYOffset;
        var ticking = false;

        window.addEventListener('scroll', function () {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(function () {
                var cur  = window.pageYOffset;
                var diff = cur - lastY;
                if (Math.abs(diff) >= 8) {
                    if (diff > 0 && cur > 60) {
                        ball.classList.add('moe-ball-hidden');
                    } else {
                        ball.classList.remove('moe-ball-hidden');
                    }
                    lastY = cur;
                }
                ticking = false;
            });
        }, { passive: true });
    })();



    /* ── 评论区：头像框叠加 + 点击跳主页 ────────────────────────
     * 兼容主题 PJAX / AJAX 无刷新加载：
     *   1. 初始执行一次
     *   2. 监听 document 的 click（事件委托，随时有效）
     *   3. MutationObserver 检测 DOM 新增节点，自动补处理头像框
     *   4. 监听常见 PJAX 完成事件，重新初始化
     * ─────────────────────────────────────────────────────────── */

    // 昵称链接：事件委托绑定在 document，PJAX 后依然有效
    $(document).on('click', 'a[data-moe-profile]', function(e){
        var url = $(this).data('moe-profile');
        if ( !url ) return; // 空值不拦截，保持默认行为
        e.preventDefault();
        e.stopPropagation();
        window.location.href = url;
    });

    // 处理头像框 + 头像点击（传入范围节点，支持局部刷新）
    function moeInitAvatars(root) {
        $(root || document).find('img[data-moe-uid]').each(function () {
            var $img     = $(this);
            var frameUrl = $img.attr('data-moe-frame')   || '';
            var pUrl     = $img.attr('data-moe-profile') || '';

            // ── 头像框 ────────────────────────────────────────
            if ( frameUrl && !$img.data('moe-framed') ) {
                $img.data('moe-framed', true);
                var doFrame = function () {
                    var sz      = $img.width() || parseInt($img.attr('width'),10) || 48;
                    var frameSz = Math.round(sz * 1.30);
                    var offset  = Math.round((frameSz - sz) / 2);
                    var $p      = $img.parent();
                    $p.css({ position: 'relative', overflow: 'visible' });
                    $p.parentsUntil('li, article, .comment, .comment-body, #comments')
                      .each(function () {
                          var ov = $(this).css('overflow');
                          if ( ov === 'hidden' || ov === 'clip' ) $(this).css('overflow','visible');
                      });
                    if ( $p.find('.moe-av-frame').length ) return;
                    $('<img>').addClass('moe-av-frame')
                        .attr({ src: frameUrl, 'aria-hidden': 'true' })
                        .css({ position:'absolute', top:-offset+'px', left:-offset+'px',
                               width:frameSz+'px', height:frameSz+'px',
                               'object-fit':'contain', 'pointer-events':'none', 'z-index':10 })
                        .appendTo($p);
                }
                this.complete && this.naturalWidth ? doFrame() : $img.on('load', doFrame);
            }

            // ── 头像点击 ──────────────────────────────────────
            if ( pUrl && !$img.data('moe-linked') ) {
                $img.data('moe-linked', true);
                var $a = $img.closest('a');
                if ( $a.length ) {
                    // 加 data 属性让上面的事件委托接管
                    $a.attr('data-moe-profile', pUrl);
                } else {
                    $img.css('cursor','pointer').on('click', function(e){
                        e.preventDefault();
                        window.location.href = pUrl;
                    });
                }
            }
        });
    }

    // 初始执行
    $(function(){ moeInitAvatars(); });

    // MutationObserver：监听 DOM 新增（主题 AJAX 加载评论时触发）
    if ( window.MutationObserver ) {
        var _moePending = false;
        new MutationObserver(function(mutations) {
            if ( _moePending ) return;
            // 判断是否有新增含头像的节点
            var hasAvatar = mutations.some(function(m){
                return [].some.call(m.addedNodes, function(n){
                    return n.nodeType === 1 &&
                        (n.matches('img[data-moe-uid]') || n.querySelector('img[data-moe-uid]'));
                });
            });
            if ( !hasAvatar ) return;
            _moePending = true;
            setTimeout(function(){ moeInitAvatars(); _moePending = false; }, 80);
        }).observe(document.body, { childList: true, subtree: true });
    }

    // 常见 PJAX / SPA 完成事件
    $(document).on([
        'pjax:end',                // jquery-pjax (defunkt) — Sakurairo 主题使用这个
        'pjax:complete',           // jquery-pjax 备用
        'pjax:success',            // MoOx/pjax (原生 pjax)
        'swup:contentReplaced',    // Swup
        'turbolinks:load',         // Turbolinks
        'page:load',               // 旧版 Turbolinks
        'wp-theme:ajax-done',      // 部分WP主题自定义事件
    ].join(' '), function(){
        moeInitAvatars();
    });

})(jQuery);
