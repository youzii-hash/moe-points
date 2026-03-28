/**
 * ============================================================
 * 文件职责：个人主页前端交互
 *   - 头像选择面板（点击缩略图 → 确认更换）
 *   - 昵称/简介/密码 编辑弹窗
 *   - 消息通知全部已读
 * ============================================================
 */
(function ($) {
    'use strict';

    if ( typeof moeData === 'undefined' ) return;

    /* ── 工具 ──────────────────────────────────────────────── */
    function post(action, data, cb) {
        data.action = action;
        data.nonce  = moeData.nonce;
        $.post(moeData.ajaxUrl, data, cb).fail(function(){
            cb({ success: false, data: { msg: '网络错误，请重试' } });
        });
    }

    /* ── 头像选择 ───────────────────────────────────────────── */
    var selectedAttId = 0;

    // fix: 改为事件委托，AJAX导航替换内容后新元素仍能响应
    $(document).on('click', '#moe-avatar-btn', function () {
        $('#moe-avatar-panel').slideToggle(160);
    });
    $(document).on('click', '#moe-avatar-cancel', function () {
        $('#moe-avatar-panel').slideUp(160);
        selectedAttId = 0;
        $('.moe-gallery-item').removeClass('selected');
    });

    $(document).on('click', '.moe-gallery-item', function () {
        $('.moe-gallery-item').removeClass('selected');
        $(this).addClass('selected');
        selectedAttId = $(this).data('att');
        post('moe_change_avatar', { att_id: selectedAttId }, function (res) {
            if (res.success) {
                $('#moe-self-avatar').attr('src', res.data.url);
                $('#moe-avatar-panel').slideUp(160);
                showToast(res.data.msg);
            } else {
                showToast(res.data.msg, true);
            }
        });
    });

    /* ── 编辑弹窗 ───────────────────────────────────────────── */
    var currentField = '';
    var fieldLabels  = { nickname: '修改昵称', bio: '修改简介', password: '修改密码' };

    function buildForm(field, curVal) {
        var html = '';
        if (field === 'nickname') {
            html = '<label>新昵称（2-20字符）</label><input type="text" id="moe-edit-input" value="' + escHtml(curVal) + '" maxlength="20">';
        } else if (field === 'bio') {
            html = '<label>个人简介（最多200字）</label><textarea id="moe-edit-input" maxlength="200">' + escHtml(curVal) + '</textarea>';
        } else if (field === 'password') {
            html = '<label>当前密码</label><input type="password" id="moe-pw-old" autocomplete="current-password">'
                 + '<label>新密码（至少6位）</label><input type="password" id="moe-pw-new" autocomplete="new-password">'
                 + '<label>确认新密码</label><input type="password" id="moe-pw-confirm" autocomplete="new-password">';
        }
        return html;
    }

    $(document).on('click', '.moe-edit-btn', function () {
        currentField = $(this).data('field');
        var curVal   = $(this).data('val') || '';
        $('#moe-edit-title').text(fieldLabels[currentField] || '修改');
        $('#moe-edit-body').html(buildForm(currentField, curVal));
        $('#moe-edit-overlay').fadeIn(160);
        $('#moe-edit-body input, #moe-edit-body textarea').first().focus();
    });

    function closeModal() {
        $('#moe-edit-overlay').fadeOut(160);
        currentField = '';
    }
    // fix: 事件委托，AJAX导航后弹窗按钮仍能响应
    $(document).on('click', '#moe-edit-close, #moe-edit-cancel2', closeModal);
    $(document).on('click', '#moe-edit-overlay', function (e) {
        if ($(e.target).is('#moe-edit-overlay')) closeModal();
    });

    $(document).on('click', '#moe-edit-save', function () {
        var $btn = $(this).prop('disabled', true).text('保存中…');
        var data  = { field: currentField };

        if (currentField === 'password') {
            data.value     = $('#moe-pw-new').val();
            data.confirm   = $('#moe-pw-confirm').val();
            data.old_value = $('#moe-pw-old').val();
        } else {
            data.value = $('#moe-edit-input').val();
        }

        post('moe_update_profile', data, function (res) {
            $btn.prop('disabled', false).text('保存');
            if (res.success) {
                closeModal();
                showToast(res.data.msg);
                // 更新页面显示
                if (currentField === 'nickname') {
                    $('#moe-val-nickname').text(res.data.value);
                } else if (currentField === 'bio') {
                    $('#moe-val-bio').text(res.data.value || '');
                }
                // 密码修改成功后提示重新登录
                if (currentField === 'password') {
                    setTimeout(function () { location.reload(); }, 1500);
                }
            } else {
                // 在弹窗内显示错误
                var $msg = $('#moe-edit-body').find('.moe-edit-msg');
                if (!$msg.length) {
                    $msg = $('<p class="moe-edit-msg err"></p>').prependTo('#moe-edit-body');
                }
                $msg.text(res.data.msg).addClass('err').removeClass('ok');
            }
        });
    });

    // Enter 键触发保存（textarea 除外）
    $(document).on('keydown', '#moe-edit-body input', function (e) {
        if (e.key === 'Enter') $('#moe-edit-save').trigger('click');
    });

    /* ── 消息全部已读 ─────────────────────────────────────── */
    $(document).on('click', '#moe-mark-read', function () {
        post('moe_mark_read', {}, function (res) {
            if (res.success) {
                $('.moe-notice-item').removeClass('moe-unread');
                $('.moe-badge-red').remove();
                $('#moe-mark-read').remove();
            }
        });
    });

    /* ── Toast（复用 frontend.js 里的，这里备用） ──────────── */
    function showToast(msg, isErr) {
        var $w = $('#moe-toast-wrap');
        if (!$w.length) $w = $('<div id="moe-toast-wrap"></div>').appendTo('body');
        var $t = $('<div class="moe-toast"></div>').text(msg);
        if (isErr) $t.css('border-left-color','#e74c3c');
        $w.append($t);
        setTimeout(function(){ $t.addClass('out'); setTimeout(function(){ $t.remove(); },380); }, 3000);
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

})(jQuery);
