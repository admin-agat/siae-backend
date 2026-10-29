<?php

// CORS del SIAE (28/10/2026). Antes no existía este archivo y Laravel usaba su valor
// por defecto (cualquier origen). La API usa token Bearer (no cookies), así que el
// riesgo era bajo, pero en producción conviene aceptar solo el dominio del frontend.
//
// CORS_ALLOWED_ORIGINS en el .env, separados por coma. Ejemplos:
//   local:      CORS_ALLOWED_ORIGINS=http://localhost:5173,http://127.0.0.1:5173
//   producción: CORS_ALLOWED_ORIGINS=https://siae-frontend.onrender.com
// Si no se define, se mantiene '*' para no romper ningún entorno existente.

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', '*'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 3600,

    // Bearer token, no cookies de sesión
    'supports_credentials' => false,

];
