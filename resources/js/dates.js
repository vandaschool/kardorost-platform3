export const fa = (value) => new Intl.NumberFormat("fa-IR").format(value ?? 0);
export function dateTime(value) {
    return value
        ? new Intl.DateTimeFormat("fa-IR", {
              dateStyle: "medium",
              timeStyle: "short",
              timeZone: "Asia/Tehran",
          }).format(new Date(value))
        : "تعیین نشده";
}
export function tehranInput(value) {
    if (!value) return "";
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat("en-CA", {
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
            hour: "2-digit",
            minute: "2-digit",
            hourCycle: "h23",
            timeZone: "Asia/Tehran",
        })
            .formatToParts(new Date(value))
            .map((x) => [x.type, x.value]),
    );
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}
export const providers = {
    meet: "گوگل میت",
    skyroom: "اسکای‌روم",
    zoom: "زوم",
};
export const submissionStatuses = {
    submitted: "منتظر بازخورد",
    reviewed: "بررسی‌شده",
    revision_requested: "نیازمند اصلاح",
};
export const attendanceStatuses = {
    present: "حاضر",
    absent: "غایب",
    excused: "غیبت موجه",
};
