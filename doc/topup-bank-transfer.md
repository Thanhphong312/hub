# Hướng dẫn Top-up qua chuyển khoản (giả lập VNPay)

> Production VNPay gateway chưa live — mọi top-up hiện tại đi qua **chuyển
> khoản ngân hàng thủ công** (Bank Transfer) hoặc **PingPong wire**.
> Flow giống nhau, chỉ khác kênh nhận tiền.

## 1. Tổng quan flow

```
Seller (web-bullstart / bullstart-seller)              Admin (bullstart)
─────────────────────────────────────────              ─────────────────
1. Mở Wallet → bấm "Bank Transfer"
2. Nhập số USD muốn nạp
3. Server tính VND theo rate +
   sinh reference duy nhất → trả QR + bank info
   (CHƯA tạo transaction ở DB, CHƯA notify)
4. Quét QR / chuyển khoản ở app banking
   - Chuyển ĐÚNG số tiền VND
   - Memo phải chứa reference "BS BTABC123"
5. Sau khi chuyển xong, copy mã giao dịch
   ngân hàng (FT…) dán vào form, bấm
   "Xác nhận đã chuyển"
6. Server:
   - Tạo wallet_transaction (pending)
   - Bắn Telegram 🔔                                   ← nhận notification
                                                      7. Mở Wallet → tab Deposits
                                                         → đối chiếu nội dung CK
                                                      8. Bấm "Approve"
                                                         → cộng tiền vào ví seller
                                                         → bắn Telegram ✅
9. Refresh Wallet → thấy tiền vào
```

## 2. Admin cấu hình lần đầu

### 2.1 Telegram (nhận notification)

`bullstart` admin → **Settings → Telegram**

- Bật bot với `bot_token`.
- Thêm **Topup Chat ID** + **Topup Thread/Topic ID** (optional) — group nhận
  notify khi seller submit + admin approve.
- Có thể dùng nhóm sẵn (cùng default report) hoặc tạo nhóm riêng.

### 2.2 Bank Transfer (chuyển khoản VNĐ + QR)

`bullstart` admin → **Settings → Bank Transfer**

| Trường | Ví dụ | Ghi chú |
|---|---|---|
| **Enabled** | ✅ | Bật/tắt nút Bank Transfer ở Wallet của seller |
| **Rate (VND/USD)** | 26000 | Tỷ giá quy đổi |
| **Min USD** | 1 | Số tiền nạp tối thiểu |
| **Bank name** | `VCB` / `TCB` / `MB` / `ACB` | **Mã ngắn VietQR** (chỉ chữ + số, không khoảng trắng) — danh sách: <https://api.vietqr.io/v2/banks> |
| **Account number** | `1019283746` | Số TK ngân hàng VN |
| **Account holder** | `NGUYEN VAN A` | Tên chủ TK (in hoa, không dấu) |
| **Branch** | `Ha Noi` | Chi nhánh (optional) |
| **Content template** | `BS {ref}` | Nội dung CK seller phải nhập — `{ref}` thay bằng reference auto-sinh |
| **QR template URL** | `https://img.vietqr.io/image/{bank}-{account}-compact2.png?amount={amount}&addInfo={content}&accountName={holder}` | VietQR auto-render. Placeholders: `{bank}`, `{account}`, `{amount}`, `{content}`, `{holder}` |

Sau khi điền **Save** — Preview phía dưới sẽ render QR mẫu. Nếu QR không hiện
→ kiểm tra `bank_name` đúng mã VietQR + account_number toàn số.

### 2.3 PingPong (USD wire, không có QR)

`bullstart` admin → **Settings → Invoice Payment**

Điền section PingPong: `account_name`, `account_number`, `bank_name`,
`swift_code`, `bank_address`, `notes`. Wallet → nút **PingPong** chỉ
hiện khi `account_number` không rỗng.

## 3. Seller top-up

### 3.1 Cách bật

Mở **web-bullstart** (https://your-domain) hoặc **bullstart-seller** (desktop).
Đăng nhập với account seller (vd `seller@bullstart.com` / `password`).

Sidebar → click avatar → **Profile** → thấy mặc định wallet balance.

### 3.2 Nạp tiền (Bank Transfer — VNĐ + QR)

1. **Sidebar** → click nút **`+`** (xanh emerald cạnh balance), hoặc vào
   **Wallet** → bấm **Bank Transfer** (xanh dương).
2. Popup mở ra hỏi **Số tiền USD**. Gõ số (vd `20`) → preview hiện
   `₫520,000` (= 20 × 26000).
3. Bấm **Tiếp tục → hiện QR**.
4. Popup chuyển sang trang **QR**:
   - **QR code** lớn (quét bằng app banking)
   - **Bank**, **Số tài khoản** (có nút Copy), **Chủ TK**, **Số tiền**, **Nội dung**.
   - Nội dung dạng `BS BTABC123` — phải copy chính xác.
5. Mở app banking → quét QR → kiểm tra:
   - Số tiền ₫520,000 (đúng số)
   - Nội dung `BS BTABC123` (đúng reference)
6. Chuyển khoản. App banking sẽ trả về một **mã giao dịch**
   (FT26012345678 hoặc số chứng từ).
7. Quay lại popup → cuộn xuống form **"Sau khi chuyển khoản, nhập mã giao
   dịch ngân hàng"** → dán mã + (tùy chọn) ghi chú → bấm **Xác nhận đã chuyển**.
8. Toast `✅ Đã ghi nhận`. Admin nhận Telegram ngay.

### 3.3 Nạp tiền (PingPong — USD wire)

Tương tự nhưng:
- Bấm nút **PingPong** (xanh indigo).
- KHÔNG có QR — popup hiện account info: name / number / bank / SWIFT / address / reference.
- Copy số tài khoản + reference, gửi wire từ ngân hàng của bạn.
- Sau khi wire xong → dán **PingPong transaction ID** vào form → **Xác nhận**.

### 3.4 Sau khi xác nhận

- Wallet **chưa cộng ngay**. Status = pending.
- Đợi admin đối chiếu thực tế tiền vào tài khoản + approve.
- Sau approve, wallet cộng tiền tức thì — seller refresh thấy ngay.

## 4. Admin duyệt deposit

`bullstart` admin → **Wallet** → tab **Deposits** (hoặc filter status=pending).

1. Thấy row mới từ seller, click để xem chi tiết:
   - `amount` (USD), `vnd_amount` (nếu Bank Transfer)
   - `transaction_id` = reference `BTABC123`
   - `note` = `Bank transfer · BS BTABC123 | bank_ref=FT26012345678 | seller_note=…`
2. Mở internet banking → kiểm tra giao dịch đến có
   - Đúng số tiền VND
   - Đúng nội dung `BS BTABC123` ở memo
   - (Có thể đối chiếu thêm mã FT seller gửi)
3. Bấm **Approve** → wallet seller cộng tiền + Telegram bắn `✅ Top-up approved`.
4. Nếu không khớp (tiền không vào hoặc sai reference):
   - **Reject** → tx chuyển rejected (chưa có sẵn, hiện chỉ delete được).
   - Hoặc liên lạc seller xác minh.

## 5. Auto-pay sau khi nạp xong

Seller bật **Auto-pay** ở Profile → app ping `/orders/auto-pay/run` mỗi 60s.
Sau khi admin approve top-up, **trong 1 phút** đơn unpaid cũ nhất sẽ
tự pay nếu ví đủ. Admin có thể force-run ngay từ menu **Auto-pay**
(bullstart admin → 🤖 Auto-pay → Run now per seller).

## 6. Endpoint API tham chiếu

| Method | Path | Mô tả |
|---|---|---|
| `GET` | `/api/wallet/bank-transfer/rate` | Lấy rate + min_usd + bank_name |
| `POST` | `/api/wallet/bank-transfer/init` | body `{ amount_usd }` → trả QR + bank info + reference (KHÔNG tạo tx) |
| `POST` | `/api/wallet/bank-transfer/confirm` | body `{ reference, amount_usd, bank_ref, note? }` → tạo tx + bắn Telegram |
| `GET` | `/api/wallet/pingpong/info` | PingPong account info |
| `POST` | `/api/wallet/pingpong/init` | Tương tự Bank Transfer (USD, không QR) |
| `POST` | `/api/wallet/pingpong/confirm` | Tương tự |
| `POST` | `/api/wallet/deposit/{id}/approve` | Admin approve → cộng ví |

## 7. Troubleshooting

### "Bank Transfer failed: Bank account is not configured"
Admin chưa điền `bank_name` + `account_no` ở Settings → Bank Transfer.

### "Bank-transfer top-up is disabled"
`Enabled` ở Settings = off. Bật lại.

### QR không hiện trong popup
- Kiểm tra `qr_template_url` không rỗng.
- `bank_name` phải là mã VietQR (vd `VCB`, không phải "Vietcombank").
- Account_number toàn số (loại ký tự đặc biệt nếu có).

### Telegram không nhận notification
- Kiểm tra Settings → Telegram → Topup Chat ID đã set.
- `bot_token` chưa? Bot đã được mời vào group chưa?
- Test bằng nút "Send test message" trong Telegram tab.

### Seller submit confirm nhưng admin không thấy tx
- Reference (BTABC123) phải khớp ở DB. Xem `wallet_transactions` table
  filter `transaction_id LIKE 'BT%' AND status = 'pending'`.
- Có thể seller chưa submit confirm step (đóng popup ngay khi vừa thấy QR
  thì init() không tạo tx — design intentional để tránh tx rác).

### Reference đã được dùng (409)
- Mỗi reference chỉ confirm 1 lần. Nếu seller submit confirm 2 lần liên tiếp
  → lần 2 trả 409. Seller cần tạo QR mới (nhập USD lại) để có reference khác.

## 8. Lưu trữ cấu hình

Tất cả config trong table `app_settings` (key/value JSON):

| Key | Mô tả |
|---|---|
| `bank_transfer` | Bank info + QR template + rate |
| `invoice_pingpong` | PingPong account |
| `telegram` | Bot token + topup_chat_id |

Đổi config trên dev không cần redeploy — chỉ update Settings UI là live ngay
(không có config cache cho `app_settings`).
