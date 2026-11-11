/*
 * RAC Flight Data Hub — the only script of its own: copy buttons (CSP: served from the hub,
 * no inline script). A button with data-copy="<element id>" copies that element's text.
 */
document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-copy]');
    if (!button) {
        return;
    }
    var source = document.getElementById(button.getAttribute('data-copy'));
    if (!source || !navigator.clipboard) {
        return;
    }
    navigator.clipboard.writeText(source.textContent.trim()).then(function () {
        var label = button.textContent;
        button.textContent = 'Copied';
        setTimeout(function () { button.textContent = label; }, 1500);
    });
});
