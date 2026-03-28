/**
 * ============================================================
 * 文件职责：后台交互脚本
 *   - 等级设置：动态添加/删除等级行
 *   - 用户积分管理：快捷调整按钮（+10/-10/+50/-50）
 *   - 用户积分管理：输入框直接设置积分值
 * ============================================================
 */

(function ($) {
    'use strict';

    /* ══════════════════════════════════════════════════════
     *  等级设置页：动态添加等级行
     * ══════════════════════════════════════════════════════ */
    var levelIdx = $('.moe-level-card').length;

    $('#moe-add-level').on('click', function () {
        var tpl = document.getElementById('moe-level-tpl');
        if (!tpl) return;
        var html = tpl.innerHTML.replace(/__IDX__/g, levelIdx++);
        $('#moe-new-levels-wrap').append(html);
    });

    $(document).on('click', '.moe-del-level-card', function () {
        $(this).closest('.moe-level-card').remove();
    });

    /* ══════════════════════════════════════════════════════
     *  用户积分页：快捷调整（+10/-10 等按钮）
     * ══════════════════════════════════════════════════════ */
    $(document).on('click', '.moe-quick-pts', function () {
        const $btn  = $(this);
        const uid   = $btn.data('uid');
        const delta = parseInt($btn.data('delta'), 10);

        $btn.prop('disabled', true);

        $.post(moeAdmin.ajaxUrl, {
            action: 'moe_admin_set_points',
            nonce:  moeAdmin.nonce,
            uid:    uid,
            mode:   'delta',   // 相对调整
            value:  delta,
        }, function (res) {
            if (res.success) {
                // 更新同行的设置输入框显示值
                $btn.closest('tr').find('.moe-pts-input').val(res.data.points);
                showFeedback('调整成功！当前积分：' + res.data.points);
            } else {
                showFeedback('调整失败：' + (res.data || '未知错误'), true);
            }
            $btn.prop('disabled', false);
        }).fail(function () {
            showFeedback('网络错误，请重试', true);
            $btn.prop('disabled', false);
        });
    });

    /* ══════════════════════════════════════════════════════
     *  用户积分页：输入框直接设置积分值
     * ══════════════════════════════════════════════════════ */
    $(document).on('click', '.moe-pts-set', function () {
        const $btn  = $(this);
        const uid   = $btn.data('uid');
        const value = parseInt($btn.closest('td').prev().find('.moe-pts-input').val(), 10);

        if (isNaN(value) || value < 0) {
            showFeedback('请输入有效的积分数值', true);
            return;
        }

        $btn.prop('disabled', true).text('设置中…');

        $.post(moeAdmin.ajaxUrl, {
            action: 'moe_admin_set_points',
            nonce:  moeAdmin.nonce,
            uid:    uid,
            mode:   'set',   // 绝对值设置
            value:  value,
        }, function (res) {
            if (res.success) {
                showFeedback('设置成功！当前积分：' + res.data.points);
                $btn.closest('tr').find('.moe-pts-input').val(res.data.points);
            } else {
                showFeedback('设置失败：' + (res.data || '未知错误'), true);
            }
            $btn.prop('disabled', false).text('设置');
        }).fail(function () {
            showFeedback('网络错误，请重试', true);
            $btn.prop('disabled', false).text('设置');
        });
    });

    /* ── 反馈消息显示 ─────────────────────────────────────── */
    function showFeedback(msg, isErr) {
        const $el = $('#moe-pts-feedback');
        $el.text(msg).css('color', isErr ? '#e74c3c' : '#27ae60');
        clearTimeout($el.data('timer'));
        $el.data('timer', setTimeout(function () { $el.text(''); }, 3500));
    }

})(jQuery);
