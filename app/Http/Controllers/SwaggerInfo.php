<?php

namespace App\Http\Controllers;

/**
 * Global OpenAPI 3.0 definitions for the BullStart hub API.
 *
 * Doesn't define any routes — exists purely to host the `@OA\Info`,
 * `@OA\Server`, `@OA\SecurityScheme`, and `@OA\Tag` annotations that
 * L5-Swagger scans to build the Swagger UI at `/api/documentation`.
 *
 * Regenerate spec after editing this file or any controller annotation:
 *   php artisan l5-swagger:generate
 *
 * @OA\Info(
 *     version="1.0.0",
 *     title="BullStart Seller API",
 *     description="API dành cho **seller** của BullStart — tạo đơn, theo dõi shipping, quản lý ví. Endpoint admin-only (Inventory, Gangsheet, Users, Settings, refund, approve deposit, bulk-merge…) không có trong doc này.

## Hai phương thức xác thực

| Scheme | Routes | Lấy ở đâu |
|---|---|---|
| **Bearer (Sanctum)** | hầu hết các route `/api/*` | `POST /api/login` |
| **X-API-KEY header** | `POST /api/fulfill`, `GET /api/fulfill`, `GET /api/catalog/*` (external integration) | Profile page hoặc `GET /api/me/api-key` |

## Cách test nhanh

1. Vào tab **Auth → POST /login** → click **Try it out** → điền email/password seller → Execute
2. Copy `token` từ response
3. Bấm nút **Authorize 🔒** góc trên phải → paste vào ô `bearerAuth` (chỉ chuỗi token, KHÔNG cần `Bearer` prefix)
4. Mọi endpoint có lock icon từ đó tự đính kèm `Authorization: Bearer <token>`
5. Endpoint Public API (X-API-KEY): trong Authorize popup, paste `bsk_...` vào ô `apiKeyAuth`

## Order status code

`0`=new_order, `1`=processing, `2`=wrongsize, `3`=fixed, `4`=reprint, `5`=onhold, `6`=shipped, `7`=cancelled.

Seller chỉ được tự set status `5` (onhold) hoặc `7` (cancelled) cho đơn của mình. Các transition khác do admin/support thực hiện.
",
 *     @OA\Contact(name="BullStart Team", email="admin@bullstart.us"),
 * )
 *
 * @OA\Server(
 *     url="https://bullstart.us/api",
 *     description="Production"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="Sanctum",
 *     description="Sanctum personal access token returned by POST /login. Paste token only (no 'Bearer ' prefix)."
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="apiKeyAuth",
 *     type="apiKey",
 *     in="header",
 *     name="X-API-KEY",
 *     description="Per-user API key (format `bsk_...`). Used by /fulfill + /catalog endpoints. Get it from Profile page or GET /me/api-key."
 * )
 *
 * @OA\Tag(name="Auth",       description="Login, logout, change password, profile, API key")
 * @OA\Tag(name="Orders",     description="Tạo / xem / pay đơn (Sanctum). Seller chỉ thấy đơn của mình.")
 * @OA\Tag(name="Wallet",     description="Balance, deposit (manual + VNPay), pay history")
 * @OA\Tag(name="Products",   description="Catalog read-only (variants + tier prices)")
 * @OA\Tag(name="Public API", description="External integrations qua X-API-KEY header (vd Shopify webhook, custom script)")
 */
class SwaggerInfo
{
}
