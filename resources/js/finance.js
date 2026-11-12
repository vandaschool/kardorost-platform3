import jalaali from "jalaali-js";
export const money = (amount) =>
    new Intl.NumberFormat("fa-IR").format(Math.floor((amount ?? 0) / 10)) +
    " تومان";
export const latinDigits = (value) =>
    String(value ?? "").replace(/[۰-۹٠-٩]/g, (c) =>
        String(
            "۰۱۲۳۴۵۶۷۸۹".includes(c)
                ? "۰۱۲۳۴۵۶۷۸۹".indexOf(c)
                : "٠١٢٣٤٥٦٧٨٩".indexOf(c),
        ),
    );
export const paymentStatuses = {
    paid: "تأیید و تخصیص‌یافته",
    refunded: "مدرک برگشت وجه ثبت شده",
    pending: "در انتظار درگاه",
    pending_review: "رسید در انتظار بررسی",
    review_required: "نیازمند بررسی مدیریت",
    failed: "ناموفق / ردشده",
    expired: "مهلت تمام‌شده",
};
export const receiptStatuses = {
    pending: "در انتظار بررسی",
    approved: "تأییدشده",
    rejected: "ردشده",
};
export function dateOnly(value) {
    return value
        ? new Intl.DateTimeFormat("fa-IR", {
              dateStyle: "medium",
              timeZone: "Asia/Tehran",
          }).format(new Date(String(value).slice(0, 10) + "T12:00:00+03:30"))
        : "—";
}
export function fromJalali(year, month, day) {
    year = Number(year);
    month = Number(month);
    day = Number(day);
    if (!jalaali.isValidJalaaliDate(year, month, day)) return "";
    const g = jalaali.toGregorian(year, month, day);
    return `${g.gy}-${String(g.gm).padStart(2, "0")}-${String(g.gd).padStart(2, "0")}`;
}
export function toJalali(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value || "");
    if (!match) return null;
    const check = new Date(`${match[1]}-${match[2]}-${match[3]}T12:00:00Z`);
    if (
        !Number.isFinite(check.getTime()) ||
        check.getUTCFullYear() !== Number(match[1]) ||
        check.getUTCMonth() + 1 !== Number(match[2]) ||
        check.getUTCDate() !== Number(match[3])
    )
        return null;
    const j = jalaali.toJalaali(
        Number(match[1]),
        Number(match[2]),
        Number(match[3]),
    );
    return { year: j.jy, month: j.jm, day: j.jd };
}
export function monthDays(year, month) {
    return year && month
        ? jalaali.jalaaliMonthLength(Number(year), Number(month))
        : 31;
}
