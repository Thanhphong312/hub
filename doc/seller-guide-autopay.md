# 🤖 Hướng dẫn Auto-pay BullStart

Auto-pay giúp bạn **không cần vào pay từng đơn thủ công** — hệ thống tự
trừ ví theo thứ tự đơn (cũ nhất trước) miễn là ví bạn còn đủ tiền.

---

## 🎯 Khi nào nên bật?

Bật khi bạn:
- Có **nhiều đơn / ngày** và không muốn ngồi click pay từng đơn.
- Đã **chốt giá** với khách, không cần review lại trước khi pay.
- Muốn ví trừ tiền **tự động ngay khi có đơn** (hoặc sau N giờ — xem dưới).

Đừng bật nếu bạn cần **xem lại từng đơn** trước khi tiền rời ví.

---

## 🛠️ Các bước bật Auto-pay (trên web bullstart.us)

**1. Đăng nhập** vào https://bullstart.us → đăng nhập bằng email + password.

**2. Mở Profile.**
- Click vào **avatar góc dưới sidebar** (hoặc tên bạn) → chọn **Profile**.
- Hoặc gõ trực tiếp `https://bullstart.us/#/profile`.

**3. Kéo xuống section **🤖 Auto-pay**.**

**4. Tick checkbox "Tự động pay đơn unpaid khi ví có tiền".**
- Khi bật → badge chuyển sang **ON** (xanh).
- Lưu **tự động ngay** — không cần bấm Save.

**5. (Tuỳ chọn) Chọn **Delay** — bao lâu sau khi đơn tạo mới auto-pay.**

Bên dưới checkbox sẽ hiện 5 nút preset + ô custom:

| Nút       | Nghĩa                                               |
| --------- | --------------------------------------------------- |
| **Ngay**  | Pay ngay khi đơn xuất hiện (default).               |
| **1h**    | Đợi 1 giờ sau khi tạo đơn rồi mới pay.              |
| **2h**    | Đợi 2 giờ.                                          |
| **3h**    | Đợi 3 giờ.                                          |
| **4h**    | Đợi 4 giờ.                                          |
| **Custom**| Tự nhập số giờ tuỳ ý (0-72, tức tối đa 3 ngày).     |

Click vào nút → lưu tự động. Custom thì gõ số → click ra ngoài (blur) sẽ
tự lưu.

> 💡 **Tại sao có delay?** Để bạn có cửa sổ thời gian **edit / cancel
> đơn** trước khi tiền rời ví. Ví dụ chọn 2h → đơn tạo lúc 9:00 thì sớm
> nhất 11:00 mới bị auto-pay.

---

## ⚙️ Auto-pay hoạt động như thế nào?

- **Tần suất:** mỗi 1 phút, miễn là tab/web bạn đang mở.
- **Thứ tự:** đơn cũ nhất trước (FIFO theo `created_at`).
- **Điều kiện 1 đơn:** ví còn đủ trả **toàn bộ** đơn đó (không trả một phần).
- **Khi ví không đủ:** auto-pay **dừng ngay** ở đơn hiện tại — đợi bạn
  nạp thêm rồi tự chạy tiếp.
- **Khi đóng tab:** auto-pay **dừng** vì cron chạy ở browser. Mở lại
  trang là chạy tiếp.
- **Khi tắt máy:** mở lại web là chạy lại — không mất đơn nào, chỉ là
  trễ.

---

## 🔁 Hành vi sau khi pay

Khi 1 đơn được auto-pay:
- `paid_cost` của đơn = `total_cost` (đã trả full).
- Nếu đơn đang ở status **New Order** → tự chuyển sang **Processing**.
- Một **wallet_transaction** type `paid` được ghi lại, note
  `Auto-paid order #PS_C....`.
- Số dư ví trừ đi đúng số tiền của đơn.

---

## ❓ FAQ

**Q: Tôi bật auto-pay nhưng đơn không bị trừ tiền?**
A: Check theo thứ tự:
1. Ví có đủ tiền cho **đơn cũ nhất** chưa? (Auto-pay không skip đơn nào.)
2. Tab web có đang mở không? Đóng tab = cron dừng.
3. Đơn có đang trong **delay window** không? Nếu chọn 2h và đơn mới tạo
   30 phút thì còn 1.5h nữa.

**Q: Auto-pay sai đơn — có refund được không?**
A: Vào tab **Orders** → mở đơn → click **Refund** (nếu có quyền). Tiền
sẽ trả lại ví. Hoặc nhắn admin.

**Q: Đang chạy auto-pay, tôi muốn dừng gấp 1 đơn cụ thể?**
A: Tắt auto-pay nhanh nhất bằng cách untick checkbox. Hoặc đặt delay
cao (vd 24h) để có thời gian xử lý.

**Q: Tôi để delay = 72h được không?**
A: Được. Max là 72h (3 ngày). Cao hơn sẽ bị validate reject.

**Q: Có giới hạn số đơn auto-pay / ngày không?**
A: Không. Cứ còn tiền + còn đơn + qua delay → auto-pay chạy tiếp.

**Q: Đang ở app desktop (BullStart Seller), auto-pay có chạy không?**
A: Có — app desktop hỗ trợ y hệt web. Cùng 1 setting Profile cho cả 2.

---

## 📞 Hỗ trợ

Nếu auto-pay không như mong đợi, gửi cho admin:
- Email account
- Số đơn cụ thể (system_id, vd `PS_C1399`)
- Thời gian xảy ra
- Screenshot tab Profile (cho thấy ON/OFF + delay setting)

Cám ơn bạn đã dùng BullStart! 🐂
