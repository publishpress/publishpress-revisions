/* Confirm deliberate revision deletion without invoking the editor's leave-page warning. */
(function ($) {
    'use strict';
    var dialog, destination, opener;
    function close() {
        dialog.close();
        destination = null;
        if (opener && opener.isConnected) opener.focus();
    }
    function createDialog() {
        dialog = document.createElement('dialog');
        dialog.setAttribute('aria-labelledby', 'rvy-delete-title');
        dialog.setAttribute('aria-describedby', 'rvy-delete-description');
        dialog.style.cssText = 'max-width:440px;width:calc(100% - 64px);padding:24px;border:1px solid #8c8f94;border-radius:4px;';
        var title = document.createElement('h2');
        title.id = 'rvy-delete-title'; title.textContent = rvyDeleteConfirm.title;
        var message = document.createElement('p');
        message.id = 'rvy-delete-description'; message.textContent = rvyDeleteConfirm.message;
        var cancel = document.createElement('button');
        cancel.type = 'button'; cancel.className = 'button'; cancel.textContent = rvyDeleteConfirm.cancel;
        cancel.addEventListener('click', close);
        var confirm = document.createElement('button');
        confirm.type = 'button'; confirm.className = 'button button-primary'; confirm.textContent = rvyDeleteConfirm.confirm;
        confirm.style.marginInlineStart = '10px';
        confirm.addEventListener('click', function () {
            var url = destination;
            if (!url) return;
            destination = null;
            $(window).off('beforeunload.edit-post');
            window.location.assign(url);
        });
        dialog.addEventListener('cancel', function (event) { event.preventDefault(); close(); });
        dialog.append(title, message, cancel, confirm);
        document.body.appendChild(dialog);
    }
    document.addEventListener('click', function (event) {
        var link = event.target.closest && event.target.closest('#submitdiv a.submitdelete');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        var url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin || !url.searchParams.get('_wpnonce')) return;
        event.preventDefault(); event.stopImmediatePropagation();
        if (!dialog) createDialog();
        if (dialog.open) return;
        destination = url.href; opener = link;
        dialog.showModal();
        dialog.querySelector('button').focus();
    }, true);
})(jQuery);
