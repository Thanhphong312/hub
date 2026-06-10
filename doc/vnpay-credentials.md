# Xin tài khoản & token VNPay Merchant Gateway

Hướng dẫn xin TmnCode + HashSecret để hệ thống chạy nạp ví bằng VNPay
(`POST /api/wallet/vnpay/create`).

VNPay có **2 môi trường tách biệt**, mỗi môi trường có một bộ
`TmnCode + HashSecret + URL` riêng:

| Môi trường  | Mục đích                  | Cổng đăng ký                                                 |
|-------------|---------------------------|---------------------------------------------------------------|
| Sandbox     | Test, không trừ tiền thật | https://sandbox.vnpayment.vn/devreg/                          |
| Production  | Live, trừ tiền thật       | https://doitac.vnpay.vn (Cổng dịch vụ đối tác — sau hợp đồng) |

---

## 1. Sandbox (test) — lấy ngay trong 1–2 phút

1. Vào https://sandbox.vnpayment.vn/devreg/
2. Điền form đăng ký demo:
   - Tên doanh nghiệp / tên demo
   - Email nhận thông tin
   - Loại hình + mô tả website
   - **Return URL** (URL VNPay redirect về sau khi user thanh toán):
     `https://<domain>/wallet/vnpay/return`
   - **IPN URL** (VNPay gọi server-to-server xác nhận giao dịch):
     `https://<domain>/wallet/vnpay/ipn`

     Lúc đầu chưa có domain thật thì cứ điền tạm — sandbox không bắt buộc
     IPN thật, có thể đổi sau bằng cách reply email VNPay.
3. Submit → trong vài giây sẽ nhận **email** từ
   `noreply@vnpayment.vn` / `support@vnpay.vn` chứa:
   - `Mã website (TmnCode)` — VD `I97VAUID`
   - `Chuỗi bí mật (HashSecret)` — 32 ký tự
   - URL thanh toán sandbox: `https://sandbox.vnpayment.vn/paymentv2/vpcpay.html`
   - Bộ thẻ test (NCB / Visa test) để chạy thử
4. Mở app Settings → tab **VNPay Merchant** → tick `Environment = sandbox`
   → dán `TmnCode`, `HashSecret`, set `Rate` (VND/USD, hiện đang 26000).
   Save. Done — không cần redeploy.

> Sandbox creds **không dùng được** cho production và ngược lại.

---

## 2. Production — qua hợp đồng B2B

Production phải ký hợp đồng với VNPay (qua đội Sales). Quy trình:

### 2.1 Liên hệ Sales
- Hotline: `1900 5555 77`
- Email: `sales@vnpay.vn`
- Hoặc submit form: https://vnpay.vn/lien-he

Báo nhu cầu: tích hợp **Cổng thanh toán VNPay (VNPAYQR / Payment Gateway)**
cho nạp ví khách hàng (B2B).

### 2.2 Hồ sơ cần chuẩn bị
VNPay sẽ yêu cầu (đại lượng cơ bản — sales hướng dẫn cụ thể):
- Giấy phép ĐKKD / quyết định thành lập doanh nghiệp
- CMND/CCCD người đại diện pháp luật
- Thông tin tài khoản ngân hàng nhận tiền (chủ tài khoản trùng tên DN)
- Mô tả mô hình kinh doanh, sản phẩm/dịch vụ
- Sản lượng giao dịch dự kiến (số lệnh/tháng, giá trị TB)
- Domain website triển khai (live URL)
- Return URL + IPN URL (xem mục 3)
- IP server gửi yêu cầu (nếu VNPay yêu cầu whitelist)

### 2.3 Ký hợp đồng + duyệt
- VNPay gửi báo giá (phí % theo phương thức: ATM / QR / Visa…).
- Ký hợp đồng (online hoặc giấy), nộp hồ sơ qua mục **Cổng đối tác**.
- VNPay thẩm định, cấp tài khoản đăng nhập **https://doitac.vnpay.vn**.

### 2.4 Lấy production credentials
Sau khi tài khoản doitac.vnpay.vn được cấp + merchant được duyệt:
1. Đăng nhập **https://doitac.vnpay.vn**
2. Menu **Quản lý Website / Merchant** → chọn website đã đăng ký
3. Xem **Mã Merchant (TmnCode)** và **Chuỗi bí mật (HashSecret)** ở
   trang chi tiết. HashSecret production **chỉ hiện 1 lần** — copy lưu lại.
4. URL thanh toán production: `https://pay.vnpay.vn/vpcpay.html`
5. Vào Settings → VNPay Merchant → đặt `Environment = production`,
   dán `production_tmn_code`, `production_hash_secret`,
   `production_url = https://pay.vnpay.vn/vpcpay.html`. Save.

> Không cần redeploy code — `VnpayController::setting()` đọc theo env hiện
> hành, cứ đổi ở Settings là chuyển môi trường.

---

## 3. Return URL & IPN URL cần khai báo với VNPay

Hai endpoint này **đã có sẵn** trong hub (`routes/web.php`):

| Loại       | Method    | Path                       | Chức năng                                                                 |
|------------|-----------|----------------------------|---------------------------------------------------------------------------|
| Return URL | GET       | `/wallet/vnpay/return`     | VNPay redirect trình duyệt về sau khi user thanh toán (hiển thị kết quả). |
| IPN URL    | GET/POST  | `/wallet/vnpay/ipn`        | VNPay gọi server-to-server xác nhận giao dịch (cộng ví thật).             |

Khi đăng ký Sandbox / Production, điền:

```
Return URL : https://<your-domain>/wallet/vnpay/return
IPN URL    : https://<your-domain>/wallet/vnpay/ipn
```

Yêu cầu: 2 URL phải dùng **HTTPS**, public truy cập được từ Internet.

> ⚠ Mỗi lần đổi domain hosting hub thì phải vào doitac.vnpay.vn (hoặc
> reply email sandbox) cập nhật Return/IPN — VNPay từ chối callback đến
> URL không khớp.

---

## 4. Test sau khi có creds

### Sandbox
1. Settings → VNPay Merchant: env=sandbox + creds + rate.
2. Wallet → **Nạp tiền VNPay** → nhập số tiền USD → Continue.
3. Đăng nhập VNPay sandbox bằng **thẻ test** từ email:
   ```
   NCB: 9704198526191432198 / NGUYEN VAN A / 07/15 / OTP 123456
   ```
4. Sau khi thanh toán: VNPay redirect về `/wallet/vnpay/return` → trang
   báo thành công; song song VNPay gửi IPN tới `/wallet/vnpay/ipn` →
   `WalletTransaction` chuyển status `success`, ví user được cộng theo
   rate đã set.

### Production
Tương tự nhưng phải dùng **thẻ thật** (số tiền nhỏ, ví dụ 10 000đ) để
verify end-to-end trước khi mở cho user thật. Sau khi OK, ghi nhật ký
giao dịch test + nhờ Sales VNPay đối soát để chắc tiền về tài khoản DN.

---

## 5. Bảo mật

- HashSecret **KHÔNG bao giờ** commit vào code / chat / log. Chỉ nhập
  qua Settings UI (lưu trong `app_settings` JSON, không xuất ra
  `/me` hay GET endpoint thông thường).
- Phân quyền sửa VNPay Merchant: chỉ admin (xem `routes/api.php` —
  trong nhóm `CheckRole:admin`).
- Nếu nghi ngờ HashSecret bị lộ: vào doitac.vnpay.vn → reset HashSecret
  → cập nhật lại Settings ngay (mọi giao dịch đang pending sẽ fail
  signature, cần restart frontend payment).
