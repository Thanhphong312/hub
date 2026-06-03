# 💰 Hướng dẫn thanh toán đơn BullStart

Sau khi đã **có tiền trong ví** (xem [Hướng dẫn nạp tiền](#)), bạn cần
thanh toán các đơn để chuyển từ **Unpaid → Paid** và đẩy đơn sang
**Processing** cho xưởng in.

Có 3 cách thanh toán:
1. **Pay từng đơn** — chọn 1 đơn, trả riêng.
2. **Pay all unpaid** — trả 1 lúc tất cả đơn unpaid.
3. **Auto-pay** — bật ON là hệ thống tự pay (xem [Hướng dẫn Auto-pay](#)).

---

## 🎯 Hiểu các con số trên đơn

Mỗi đơn có 3 giá trị tiền:

| Trường       | Nghĩa                                                       |
| ------------ | ----------------------------------------------------------- |
| `print_cost` | Tiền in (base + label fee + addition fee + 2nd fee).        |
| `shipping_cost` | Phí ship.                                                 |
| **`total_cost`** | `print_cost + shipping_cost` — **tổng phải trả**.        |
| `paid_cost`  | Đã trả bao nhiêu. Đơn unpaid = `paid_cost < total_cost`.    |

> 💡 Giá được tính **server-side** dựa theo `tier` của bạn — không thể
> bị edit từ client. Tier khác = giá khác.

---

## 🛠️ Cách 1 — Pay từng đơn

Dùng khi bạn muốn **review từng đơn** trước khi tiền rời ví.

**1. Vào tab **Orders** trong sidebar.**

**2. Filter `status = New Order`** (hoặc xem tất cả).
- Cột **Paid** sẽ hiện badge đỏ `$X / $Y` nếu đơn unpaid.

**3. Click vào đơn** → mở Order Detail.

**4. Bên phải có khung **Payment** hiện:**
- Total cost
- Paid so far
- Remaining
- Wallet hiện tại của bạn

**5. Bấm nút **Pay $X.XX** (màu xanh).**
- Nếu ví đủ → confirm dialog → bấm OK.
- Nếu ví không đủ → nút bị disable + báo "Insufficient wallet".

**6. Sau khi pay thành công:**
- Đơn chuyển sang **Processing** (status 1).
- Ví giảm đúng `total_cost`.
- 1 wallet_transaction type `paid` được ghi.
- Toast "Đã thanh toán" hiện.

---

## 🛠️ Cách 2 — Pay all unpaid (nhanh nhất)

Dùng khi bạn có **nhiều đơn unpaid cùng lúc** và muốn xử lý 1 phát.

**1. Vào tab **Wallet** trong sidebar.**

**2. Bên phải có card **"Pay all unpaid orders"** hiện:**
- Tổng số đơn unpaid
- Tổng tiền phải trả (sum of remaining)
- Wallet hiện tại
- **Wallet sau khi pay** (preview)

**3. Bấm nút **Pay $X.XX**.**
- Confirm dialog hỏi "Pay all N orders for $X.XX?".
- Bấm OK.

**4. Hệ thống pay theo thứ tự đơn cũ nhất trước (FIFO):**
- Pay đến khi **ví hết tiền** thì dừng — không pay 1 phần đơn.
- Đơn nào pay được → chuyển sang Processing.
- Đơn nào không đủ tiền → vẫn unpaid.

**5. Toast báo:**
- `Paid N/M orders, total $X.XX`.
- Nếu N < M → ví không đủ cho hết, nạp thêm rồi pay tiếp.

> ⚠️ **Lưu ý:** Pay all **không skip** đơn. Nếu đơn #1 cần $50 và ví
> chỉ còn $30, nó **dừng** ở đơn #1 chứ không nhảy sang đơn #2 dù #2
> chỉ cần $20. Để xử lý case này → Pay từng đơn thủ công.

---

## 🛠️ Cách 3 — Auto-pay (tự động)

Bật ở **Profile → 🤖 Auto-pay**. Xem chi tiết ở
[Hướng dẫn Auto-pay](#).

Tóm tắt:
- Bật ON → hệ thống tự pay mỗi 1 phút.
- Chọn **delay window** (0/1/2/3/4h hoặc custom) — đơn chỉ bị pay sau
  N giờ kể từ khi tạo, cho bạn cửa sổ edit/cancel.
- Dừng khi ví hết hoặc đóng tab.

---

## ↩️ Refund đơn đã pay

Nếu đơn đã pay nhưng cần **trả lại tiền** (huỷ đơn, in sai, …):

**1. Mở Order Detail của đơn đã pay.**

**2. Nếu có quyền refund → nút **Refund $X.XX** hiện.**
- Quyền này do admin set ở Permissions.

**3. Bấm Refund → confirm.**
- Tiền refund đầy đủ về ví.
- `paid_cost` reset về 0.
- Status đơn về **New Order** (hoặc giữ nguyên — tuỳ admin policy).
- 1 wallet_transaction type `refund` được ghi với amount dương.

**Không có quyền refund?** → Nhắn admin/support kèm system_id.

---

## 📊 Xem lịch sử thanh toán

**Wallet → tab **Transactions** liệt kê toàn bộ:**
- `topup` — nạp tiền (amount dương)
- `paid` — pay đơn (amount âm)
- `refund` — refund đơn (amount dương)
- `manual` — admin chỉnh tay (deposit/refund)

Click vào dòng `paid` để jump sang Order Detail tương ứng.

---

## ❓ FAQ

**Q: Tôi pay đơn xong, status đơn không đổi sang Processing?**
A: Hệ thống chỉ tự chuyển nếu đơn đang ở **New Order**. Nếu đơn ở
status khác (Wrongsize, Onhold…) thì pay xong giữ nguyên status. Trường
hợp Processing không tự đổi → reload trang, có thể là cache.

**Q: Tôi vô tình pay nhầm đơn của khách khác?**
A: Vào Order Detail của đơn đó → bấm Refund (nếu có quyền). Hoặc nhắn
admin để admin refund + đối soát.

**Q: Pay all báo "Paid 5/10 orders" — 5 đơn còn lại đi đâu?**
A: 5 đơn còn lại vẫn ở status **Unpaid**, chưa bị động vào. Nạp thêm
tiền vào ví → bấm Pay all lần nữa là pay tiếp 5 đơn còn lại theo FIFO.

**Q: Ví tôi đủ tiền mà nút Pay vẫn disable?**
A: Check theo thứ tự:
1. Đơn có thực sự là Unpaid không? (Đơn đã Paid không có nút Pay nữa.)
2. Quyền **wallet** có `can_edit` không? (Admin set ở Permissions.)
3. Có lỗi gì ở console (F12)? Đôi khi token hết hạn → cần đăng nhập lại.

**Q: Đơn shipped rồi (status 6) có pay được không?**
A: Có. Nếu đơn shipped nhưng vẫn unpaid (vd: admin cho ship trước, pay
sau), bạn vẫn pay được bình thường.

**Q: Tôi muốn pay nửa đơn — được không?**
A: **Không.** BullStart chỉ pay full. Lý do: đơn ở status mixed-paid
rất khó đối soát với xưởng in. Nếu cần share cost, talk với admin.

**Q: Auto-pay với manual pay có conflict nhau?**
A: Không. Cả 2 đều check ví → trừ tiền → set `paid_cost = total_cost`
trong 1 DB transaction. Auto-pay chạy 1 phút/lần, manual chạy ngay khi
bấm. Tệ nhất xảy ra: 2 phía cùng cố pay 1 đơn → 1 phía thắng, phía kia
fail với "already paid" (an toàn).

---

## 📞 Hỗ trợ

Lỗi pay → gửi admin kèm:
- system_id đơn (vd `PS_C1399`)
- Screenshot Order Detail (cho thấy nút Pay / lỗi)
- Wallet balance lúc đó
- Thời gian xảy ra

Cám ơn bạn đã dùng BullStart! 🐂
