# 📘 Hướng dẫn nạp tiền BullStart

Chào bạn 👋 — gửi bạn các bước nạp tiền vào ví BullStart. Có 2 cách tùy
bạn tiện cái nào: **chuyển khoản VNĐ qua QR** hoặc **wire USD qua PingPong**.

---

## 💳 Cách 1 — Chuyển khoản VNĐ (Bank Transfer)

Dùng app banking VN của bạn — nhanh, có QR sẵn.

### Các bước

**1. Mở ví**
- Web: vào https://bullstart.us → đăng nhập → tab **Wallet**.
- Desktop app: mở **BullStart Seller** → tab **Wallet**.

**2. Bấm nút **Bank Transfer** (màu xanh).

**3. Nhập số USD muốn nạp** (ví dụ `20`) → bấm **Tiếp tục → hiện QR**.
- Hệ thống sẽ tự quy ra VNĐ theo tỷ giá hiện tại
  (ví dụ `20 USD = 520.000 ₫` với rate 26.000).

**4. Popup hiện QR + thông tin chuyển khoản:**
- Tên ngân hàng
- Số tài khoản (có nút Copy)
- Tên chủ tài khoản
- Số tiền cần chuyển (VNĐ)
- **Nội dung chuyển khoản** dạng `BS BTABC123` (có nút Copy)

**5. Mở app banking → quét QR.**
App banking sẽ tự điền: số TK, số tiền, nội dung. Bạn chỉ cần kiểm tra
+ xác nhận chuyển.

> ⚠️ **Lưu ý quan trọng:** Nội dung chuyển khoản **phải chứa đúng mã**
> hệ thống đưa (vd `BS BTABC123`). Đây là cách admin nhận diện giao dịch
> của bạn — sai mã = không khớp = không cộng ví.

**6. Sau khi chuyển xong, app banking sẽ hiện mã giao dịch**
(thường bắt đầu bằng `FT...`, hoặc gọi là "Mã chứng từ"/"Mã tham chiếu").

**7. Quay lại popup BullStart** — cuộn xuống thấy ô **"Sau khi chuyển khoản,
nhập mã giao dịch ngân hàng"**:
- Dán mã `FT...` vào.
- (Tùy chọn) Ghi chú nếu cần.
- Bấm **Xác nhận đã chuyển**.

**8. Toast `✅ Đã ghi nhận` xuất hiện.** Admin nhận thông báo ngay
trên Telegram và sẽ đối soát + duyệt khi tiền vào tài khoản. Thường
trong 5–30 phút (tùy thời gian xử lý của ngân hàng).

**9. Sau khi admin duyệt** → ví của bạn **tự động cộng tiền USD**. Refresh
trang Wallet là thấy.

---

## 💵 Cách 2 — Wire USD qua PingPong

Dùng khi bạn có tài khoản PingPong / wire quốc tế — không có tỷ giá quy đổi.

### Các bước

**1. Wallet → bấm nút **PingPong** (màu indigo).
**2. Nhập số USD** (ví dụ `100`) → **Tiếp tục → hiện account info**.
**3. Popup hiện thông tin PingPong:**
- Account name, Account number (có Copy)
- Bank name, SWIFT code, Bank address
- Số tiền USD
- **Reference** dạng `BS PPABC123` (cần điền vào memo của wire)

**4. Mở PingPong / app gửi wire → tạo giao dịch với:**
- Đúng tài khoản nhận
- Đúng số USD
- **Memo / message:** `BS PPABC123` (copy từ popup)

**5. Wire xong, PingPong trả về một transaction ID** → copy.

**6. Quay lại popup BullStart** → ô **"PingPong transaction ID"** → dán
→ **Xác nhận đã chuyển**.

**7. Admin nhận Telegram + sẽ duyệt khi tiền vào.** Wire quốc tế thường
mất 1-3 ngày, hãy kiên nhẫn.

---

## 🤖 Bật Auto-pay (tùy chọn)

Nếu bạn muốn ví **tự động trừ tiền** vào đơn unpaid khi có tiền:

1. Click vào avatar góc dưới sidebar → **Profile**.
2. Kéo xuống thấy section **🤖 Auto-pay**.
3. Tick checkbox **"Tự động pay đơn unpaid khi ví có tiền"** → ON.

Sau khi bật:
- Mỗi 1 phút app sẽ kiểm tra ví bạn còn tiền + có đơn unpaid không.
- Nếu có → tự pay đơn **cũ nhất trước** (FIFO).
- Khi ví hết → dừng, đợi bạn nạp thêm.
- App đóng → auto-pay dừng. Mở lại app là chạy tiếp.

Tắt bất cứ lúc nào bằng cách untick checkbox.

---

## ❓ FAQ

**Q: Nạp xong bao lâu ví được cộng?**
A: Sau khi admin duyệt. Bank Transfer VN thường 5–30 phút trong giờ làm
việc. PingPong wire 1–3 ngày.

**Q: Tôi chuyển khoản nhưng quên điền mã `BS ...` thì sao?**
A: Liên hệ admin (Telegram/Zalo) báo: số tiền, thời gian, ngân hàng,
số TK gửi. Admin sẽ tra cứu thủ công + duyệt. Lần sau nhớ điền mã 🙏

**Q: Tôi đóng popup trước khi bấm "Xác nhận đã chuyển"?**
A: Không sao — chỉ là hệ thống **chưa biết bạn đã chuyển**. Mở Wallet
→ Bank Transfer → nhập lại số USD → tạo QR mới với mã mới → chuyển
lại + xác nhận. (Mã cũ trên app banking không khớp, có thể bị duyệt
chậm hơn nhưng admin vẫn xử lý được.)

**Q: Số tiền VNĐ sai một chút (lệch vài nghìn) có sao không?**
A: Admin vẫn duyệt được nhưng có thể chậm do phải đối chiếu. Tốt nhất
là **để app banking tự điền từ QR**, đừng sửa.

**Q: Tôi nạp 20 USD nhưng ví chỉ thấy 19.23 USD?**
A: Đây là conversion theo rate snapshot tại thời điểm tạo QR (VND đã
chuyển / rate). Hệ thống lưu cả VND amount + rate trong tx của bạn —
xem chi tiết ở Wallet → tab Deposits.

**Q: Tôi bấm "Xác nhận đã chuyển" rồi nhưng app banking báo lỗi chuyển khoản?**
A: Liên hệ admin báo huỷ giao dịch + tạo lại với mã mới. Admin sẽ
reject tx cũ trên hệ thống.

---

## 📞 Hỗ trợ

Bất kỳ thắc mắc nào, gửi vào nhóm Telegram support hoặc nhắn admin trực
tiếp. Khi báo lỗi vui lòng gửi kèm:
- Mã reference (vd `BS BTABC123`)
- Mã giao dịch ngân hàng (FT...)
- Số tiền + thời gian chuyển
- Screenshot nếu có

Cám ơn bạn đã dùng BullStart! 🐂
