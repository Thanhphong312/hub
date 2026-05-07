# BullStart Postman Collection

Two files:

- `BullStart-API.postman_collection.json` — collection of every API endpoint.
- `BullStart-API.postman_environment.json` — variables (`base_url`, `api_key`, `bearer_token`, …).

## Import

1. Open Postman → top-left **Import** button.
2. Drag both `.json` files in.
3. Top-right environment dropdown → select **BullStart — Production**.
4. Edit the environment values:
   - `api_key` — your seller's `bsk_…` key (Profile page in the desktop app, or `users.api_key` row in the DB).
   - `user_email` / `user_password` — for the bearer-token folder. The `Auth → Login` request auto-saves the returned token into `{{bearer_token}}`.
   - `base_url` — defaults to `https://bullstart.us`. Switch to `http://localhost:8000` for local dev.

## Folders

| Folder | Auth | Use case |
|---|---|---|
| **Public API (X-API-KEY)** | `X-API-KEY` header | External seller integration. Three resources: `/catalog/products`, `/catalog/accessories`, `/fulfill/*`. |
| **Auth (Bearer / Sanctum)** | `POST /login` first | Token-based auth for the desktop / mobile apps. Login request auto-stores the token. |
| **Orders (Bearer)** | Bearer | All `/orders/*` endpoints used by the apps — list, create, update, pay, bulk ops, export, import, tracking. |
| **Wallet (Bearer)** | Bearer | Balance, transactions, manual deposit, VNPay deposit. |
| **Conversion** | Bearer (admin/support, with `users.convert=1`) | Pending `_qr` jobs + upload generated images. |
| **Gangsheet** | Bearer (admin/support) | Build inputs for the gangsheet composer. |

## Quick sanity check

1. Run **Public API → Catalog — Products** with your `api_key`. Should return your tier's product variants.
2. Run **Auth → Login** with `admin@bullstart.com` / `password` (default seed). The Tests tab logs `Saved bearer_token to environment` if it worked.
3. Run **Orders → List orders** — should now use the freshly-saved bearer.

## Common patterns

### Create an order via API key

```
POST {{api_url}}/fulfill
X-API-KEY: {{api_key}}

{
  "ref_id": "TIKTOK-...",
  "shipping_label": "https://drive.google.com/file/d/.../view",
  "items": [{ "product_variant_id": 2, "quantity": 1, "metas": [{ "key": "front", "value": "..." }] }]
}
```

### Look up an order later

```
GET {{api_url}}/fulfill/PS_C155        # by system_id
GET {{api_url}}/fulfill/TIKTOK-577371  # or by your custom ref_id
X-API-KEY: {{api_key}}
```

### Filter on /fulfill list

`?status=6&date_from=2026-05-01&date_to=2026-05-07&per_page=100`

`status` codes: 0=new_order · 1=processing · 2=wrongsize · 3=fixed · 4=reprint · 5=onhold · 6=shipped · 7=cancelled.

## Notes

- Drive URLs in `shipping_label` / `meta_*` should be `Anyone with the link can view` — the converter and carrier endpoints fetch them anonymously.
- Rate-limit yourself when creating orders in bulk; the platform doesn't currently enforce a hard limit but the carrier integration (`/wallet/vnpay/*`, tracking lookup) is sensitive to spikes.
- For local development against `php artisan serve`, set `base_url = http://localhost:8000`.
