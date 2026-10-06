# Hệ thống Kiểm tra Trắc nghiệm - Công an Nhân dân

## Mô tả
Ứng dụng web chạy độc lập trên server (PHP) dùng để tổ chức kiểm tra trắc nghiệm nội bộ.

**Chỉ Admin mới có quyền quản lý và tải file kết quả Excel.**

## Tính năng

### Dành cho thí sinh
1. Đăng nhập bằng Gmail (mock – không cần API Google)
2. Chọn khối & đơn vị (Tham mưu / An ninh / Cảnh sát / Phường-Xã)
3. Nhập thông tin: Họ tên – Cấp bậc – Đội công tác – Số câu hỏi
4. Làm bài trắc nghiệm ngẫu nhiên
5. Thời gian làm bài 6 phút; 60 giây cuối đồng hồ nhấp nháy đỏ và hệ thống tự nộp khi hết giờ
6. Xem kết quả ngay (ĐẠT / KHÔNG ĐẠT)
7. **Không thể tải file Excel** – kết quả được gửi về server

### Dành cho Admin (`admin.html`)
1. Đăng nhập riêng (username + mật khẩu)
2. Xem thống kê: Tổng bài thi, Số đạt, Số không đạt, Điểm TB
3. Lọc theo đơn vị
4. Xem chi tiết từng bài thi
5. **Xuất Excel** toàn bộ hoặc theo đơn vị
6. Xóa từng kết quả hoặc xóa toàn bộ

## Thông tin đăng nhập Admin mặc định
```
Username: admin
Password: CongAn@2026
```
**→ Hãy đổi ngay trong file `config.php` khi triển khai thật.**

## Cách chạy trên server
1. Upload toàn bộ thư mục lên server hỗ trợ PHP (Apache, Nginx + PHP-FPM, XAMPP, Laragon…).
2. Đảm bảo thư mục `data/` có quyền ghi (chmod 755 hoặc 775).
3. Truy cập:
   - Thí sinh: `https://your-domain/index.html`
   - Admin:   `https://your-domain/admin.html`

### Chạy local nhanh (cần PHP)
```bash
php -S localhost:8080
# Mở http://localhost:8080
# Admin: http://localhost:8080/admin.html
```

## Cấu trúc file
```
├── index.html          # Trang làm bài (thí sinh)
├── admin.html          # Trang quản trị (chỉ admin)
├── styles.css
├── data.js             # Đơn vị + ngân hàng câu hỏi
├── app.js              # Logic thí sinh
├── config.php          # Cấu hình admin (đổi mật khẩu tại đây)
├── save_result.php     # API lưu kết quả
├── admin_api.php       # API quản trị
├── data/               # Thư mục lưu kết quả (tự tạo)
│   └── results.json
└── README.md
```

## Cột trong file Excel (Admin xuất)
| STT | Thời gian | Họ và Tên | Cấp bậc | Đội công tác | Đơn vị | Tổng câu hỏi | Đúng | Sai | Tỷ lệ (%) | Kết quả | Ký nhận kết quả |

## Bảo mật
- Mật khẩu admin nằm trong `config.php` – đổi ngay khi dùng thật.
- File `results.json` chỉ admin mới đọc được qua API (có session).
- Thí sinh không có nút xuất Excel.

## Tùy chỉnh
- Đổi mật khẩu admin: `config.php`
- Thêm/sửa câu hỏi: `data.js` → `QUESTION_BANK`
- Thêm đơn vị: `data.js` → `UNITS`
- Ngưỡng đạt: từ 50% trở lên (`app.js` → `state.percent < 50` là không đạt)
