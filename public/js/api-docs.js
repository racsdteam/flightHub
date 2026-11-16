/*
 * RAC Flight Data Hub — /api/docs: starts Swagger UI (public/vendor/swagger-ui) on the hub's
 * OpenAPI document. Served from the hub, no inline script (CSP). The key given to "Authorize"
 * stays in this page's memory only (persistAuthorization off: nothing in the browser's
 * storage). No outside URL: the online validator is off.
 */
window.addEventListener('DOMContentLoaded', function () {
    var root = document.getElementById('swagger-ui');
    var ui = window.SwaggerUIBundle({
        url: root.getAttribute('data-spec-url'),
        domNode: root,
        presets: [window.SwaggerUIBundle.presets.apis],
        layout: 'BaseLayout',
        deepLinking: false,
        persistAuthorization: false,
        queryConfigEnabled: false,
        validatorUrl: null,
        supportedSubmitMethods: ['get'],
        displayRequestDuration: true,
        showExtensions: true,
        defaultModelsExpandDepth: 0,
        onComplete: function () {
            // "Try it out" calls the API of the origin that serves this page, when the
            // document lists it (production, or the local server of php artisan serve).
            var here = window.location.origin + '/api/v1';
            var listed = ui.specSelectors.servers().some(function (server) {
                return server.get('url') === here;
            });
            if (listed) {
                ui.oas3Actions.setSelectedServer(here);
            }
        }
    });
});
