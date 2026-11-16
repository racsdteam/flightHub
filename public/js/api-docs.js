/*
 * RAC Flight Data Hub — /api/docs: starts Swagger UI (public/vendor/swagger-ui) on the hub's
 * public OpenAPI document. Served from the hub, no inline script (CSP). The key given to
 * "Authorize" stays in this page's memory only (persistAuthorization off: nothing in the
 * browser's storage). No outside URL: the online validator is off. The document lists one
 * server, this hub's own address; operations start collapsed and the Schemas section is hidden.
 */
window.addEventListener('DOMContentLoaded', function () {
    var root = document.getElementById('swagger-ui');
    window.SwaggerUIBundle({
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
        docExpansion: 'list',
        defaultModelsExpandDepth: -1
    });
});
