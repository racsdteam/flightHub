<?php

// Only the settings that differ from Livewire's defaults (merged with the package config).
return [

    // Strict CSP without 'unsafe-eval' (D4): Livewire's bundle with Alpine's CSP-safe build.
    'csp_safe' => true,

    // Assets are emitted by the layout (@livewireStyles / @livewireScripts) with the CSP nonce.
    'inject_assets' => false,

    // Full-page components render into the admin layout.
    'component_layout' => 'components.layouts.app',

];
