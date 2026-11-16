/*
 * RAC Flight Data Hub — the hub's own script for the admin screens (CSP: served from the hub,
 * no inline script). Client side only; nothing is sent anywhere.
 *  - data-copy="<id>": copies that element's text (a list: one item per line).
 *  - data-download="<id>" data-filename="x.txt": saves that element's text as a .txt file.
 *  - [data-dialog]: when one appears, focus moves to its [data-autofocus]; Escape presses its
 *    [data-dialog-cancel] (the dialog's own Cancel button).
 */
(function () {
    function textOf(id) {
        var source = document.getElementById(id);
        if (!source) {
            return null;
        }
        var items = source.querySelectorAll('li');
        if (items.length) {
            return Array.prototype.map.call(items, function (li) { return li.textContent.trim(); }).join('\n') + '\n';
        }
        return source.textContent.trim();
    }

    function flash(button, word) {
        if (button.hasAttribute('data-busy')) {
            return;
        }
        var html = button.innerHTML;
        button.setAttribute('data-busy', '');
        button.textContent = word;
        setTimeout(function () { button.innerHTML = html; button.removeAttribute('data-busy'); }, 1500);
    }

    document.addEventListener('click', function (event) {
        var copy = event.target.closest('[data-copy]');
        if (copy) {
            var text = textOf(copy.getAttribute('data-copy'));
            if (text !== null && navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function () { flash(copy, 'Copied'); });
            }
            return;
        }

        var download = event.target.closest('[data-download]');
        if (download) {
            var content = textOf(download.getAttribute('data-download'));
            if (content === null) {
                return;
            }
            var url = URL.createObjectURL(new Blob([content], { type: 'text/plain;charset=utf-8' }));
            var link = document.createElement('a');
            link.href = url;
            link.download = download.getAttribute('data-filename') || 'download.txt';
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
            flash(download, 'Saved');
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }
        var cancel = document.querySelector('[data-dialog] [data-dialog-cancel]');
        if (cancel) {
            cancel.click();
        }
    });

    new MutationObserver(function () {
        var dialog = document.querySelector('[data-dialog]:not([data-focused])');
        if (dialog) {
            dialog.setAttribute('data-focused', '');
            var target = dialog.querySelector('[data-autofocus]');
            if (target) {
                target.focus();
            }
        }
    }).observe(document.documentElement, { childList: true, subtree: true });
})();
