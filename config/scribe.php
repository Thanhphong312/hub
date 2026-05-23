<?php

/**
 * Scribe config tailored to BullStart.
 *
 * - Output: static HTML at public/docs (visible at /docs)
 * - Try-it: enabled, base URL points at prod
 * - Two auth schemes documented: Sanctum Bearer (default) and the X-API-KEY
 *   header used by /fulfill and /catalog/* (described in the intro text;
 *   Scribe only ships ONE auth scheme so X-API-KEY is documented manually
 *   per-route via custom @bodyParam/@queryParam style metadata)
 *
 * Regenerate after touching annotations or routes:
 *   php artisan scribe:generate
 */
return [
    'theme' => 'default',

    'title' => 'BullStart API',
    'description' => '',

    // Base URL used by Try-it panel. Override via SCRIBE_BASE_URL env to point
    // at staging / local during dev.
    'base_url' => env('SCRIBE_BASE_URL', config('app.url') . '/api'),

    // Where docs ship — type=laravel keeps it inside the app (CSRF / auth aware);
    // type=static dumps plain HTML for nginx to serve.
    'type' => 'static',

    'static' => [
        'output_path' => 'public/docs',
    ],

    'laravel' => [
        'add_routes' => true,
        'docs_url' => '/docs',
        'assets_directory' => null,
        'middleware' => [],
    ],

    'external' => [
        'html_attributes' => [],
    ],

    'try_it_out' => [
        'enabled' => true,
        'base_url' => null,        // null = use the global `base_url` above
        'use_csrf' => false,
        'csrf_url' => '/sanctum/csrf-cookie',
    ],

    // Auth: default to Sanctum Bearer. /fulfill + /catalog routes use X-API-KEY
    // — those are noted in the intro + per-controller @authenticated comment.
    'auth' => [
        'enabled' => true,
        'default' => false,
        'in' => 'bearer',
        'name' => 'Authorization',
        'use_value' => env('SCRIBE_AUTH_KEY'),
        'placeholder' => '{YOUR_SANCTUM_TOKEN}',
        'extra_info' => <<<MD
Lấy token bằng `POST /login` với `email + password` → response trả về `token`.
Copy vào ô `Authorization` ở dạng `Bearer <token>` (Scribe tự thêm prefix).

**Route nào dùng `X-API-KEY` thay vì Bearer**: `POST /fulfill`, `GET /fulfill`,
`GET /catalog/*`. Lấy key tại Profile page (app bullstart) hoặc qua
`GET /me/api-key`. Trong Try-it panel của các route đó, mở **Extra headers**
và thêm `X-API-KEY: bsk_...`.
MD,
    ],

    'intro_text' => <<<MD
Bộ API của BullStart hub (`hubbullstart`) — quản lý orders, wallet, products,
inventory, gangsheet…

## Hai phương thức xác thực

| Scheme | Routes | Lấy ở đâu |
|---|---|---|
| **Bearer (Sanctum)** | mọi route `/api/*` không thuộc nhóm public | `POST /login` |
| **X-API-KEY header** | `POST /fulfill`, `GET /fulfill`, `GET /catalog/*` | Profile page hoặc `GET /me/api-key` |

## Cách test nhanh

1. Bấm tab **Auth** ở sidebar → đăng nhập (`POST /login`) bằng tài khoản seller/admin.
2. Copy `token` từ response → paste vào ô **Authorization** góc trên phải.
3. Vào bất kỳ endpoint nào → bấm **Try it out** → điền params → **Send Request**.

## Conventions

- Response paginated dùng Laravel default shape: `{ data, current_page, last_page, total }`.
- Date filter (`date_from`, `date_to`) chấp nhận format `YYYY-MM-DD`.
- Order status int → 0=new_order, 1=processing, 2=wrongsize, 3=fixed, 4=reprint, 5=onhold, 6=shipped, 7=cancelled.
MD,

    'example_languages' => ['bash', 'javascript', 'php', 'python'],

    'postman' => [
        'enabled' => true,
        'overrides' => [],
    ],

    'openapi' => [
        'enabled' => true,
        'overrides' => [],
        'generators' => [],
    ],

    'groups' => [
        'default' => 'Endpoints',
        'order' => [
            'Auth',
            'Orders',
            'Wallet',
            'Products',
            'Inventory',
            'Gangsheet',
            'Public API (X-API-KEY)',
            'Settings',
            'Users',
        ],
    ],

    'logo' => false,
    'last_updated' => 'Last updated: {date:F j, Y}',

    'routes' => [
        [
            'match' => [
                'prefixes' => ['api/*'],
                'domains' => ['*'],
            ],
            'include' => [],
            // Skip docs/internal routes from showing in the API doc.
            'exclude' => ['docs', 'docs/*'],
            'apply' => [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'response_calls' => [
                    'methods' => [], // disable live response calls during generate (safer)
                ],
            ],
        ],
    ],

    // Scribe ships sane default `strategies` — letting it stay null/omitted
    // means we use defaults. We disabled live response calls already via
    // `routes[].apply.response_calls.methods = []` above, which is enough.

    'database_connections_to_transact' => [config('database.default')],

    'fractal' => [
        'serializer' => null,
    ],
];
