import assert from "node:assert/strict";
import { writeFile } from "node:fs/promises";
const base = process.env.BROWSER_BASE_URL;
const password = process.env.BROWSER_PASSWORD;
assert(
    base &&
        password &&
        process.env.BROWSER_STUDENT_PHONE &&
        process.env.BROWSER_COACH_PHONE &&
        process.env.BROWSER_ADMIN_PHONE,
    "Supply isolated browser fixture credentials and BROWSER_BASE_URL.",
);
assert(
    ["127.0.0.1", "localhost"].includes(new URL(base).hostname),
    "This workflow only mutates a local fixture.",
);
const target = await (
    await fetch(
        "http://127.0.0.1:9222/json/new?" + encodeURIComponent(base + "/login"),
        { method: "PUT" },
    )
).json();
const socket = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve) =>
    socket.addEventListener("open", resolve, { once: true }),
);
let id = 0;
const pending = new Map();
const exceptions = [];
socket.addEventListener("message", (event) => {
    const m = JSON.parse(event.data);
    if (m.method === "Runtime.exceptionThrown")
        exceptions.push(m.params.exceptionDetails.text);
    if (m.id) {
        const p = pending.get(m.id);
        if (m.error) p.reject(m.error);
        else p.resolve(m.result);
        pending.delete(m.id);
    }
});
function send(method, params = {}) {
    return new Promise((resolve, reject) => {
        const n = ++id;
        pending.set(n, { resolve, reject });
        socket.send(JSON.stringify({ id: n, method, params }));
    });
}
await send("Runtime.enable");
await send("Page.enable");
async function evaluate(expression) {
    const r = await send("Runtime.evaluate", {
        expression,
        returnByValue: true,
        awaitPromise: true,
    });
    if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails));
    return r.result.value;
}
async function until(expression) {
    for (let i = 0; i < 100; i++) {
        if (await evaluate(expression)) return;
        await new Promise((r) => setTimeout(r, 100));
    }
    throw new Error(
        "Timed out: " +
            expression +
            "\n" +
            (await evaluate("document.body.innerText")),
    );
}
async function goto(path) {
    await send("Page.navigate", { url: base + path });
    await until(
        `location.pathname===${JSON.stringify(path.split("?")[0])} && !!document.querySelector('main')`,
    );
}
async function fill(selector, value) {
    await evaluate(
        `(()=>{const el=document.querySelector(${JSON.stringify(selector)});if(!el)throw Error('Missing input');el.value=${JSON.stringify(value)};el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));})()`,
    );
}
async function submit(selector) {
    await evaluate(
        `document.querySelector(${JSON.stringify(selector)}).requestSubmit()`,
    );
}
async function login(role) {
    await send("Network.clearBrowserCookies");
    await send("Page.navigate", { url: base + "/login" });
    await until(`!!document.querySelector('input[name=password]')`);
    await fill(
        "[name=phone]",
        process.env["BROWSER_" + role.toUpperCase() + "_PHONE"],
    );
    await fill("[name=password]", password);
    await submit("form");
    await until(`location.pathname==='/${role}'`);
}

try {
    await login("admin");
    await goto("/admin/consultation-grants");
    await until(`!!document.querySelector('[name=grant_student_phone]')`);
    await evaluate(`document.querySelector('details').open=true`);
    await fill("[name=grant_student_phone]", process.env.BROWSER_STUDENT_PHONE);
    const coachId = await evaluate(
        `document.querySelector('[name=grant_coach] option:not([disabled])').value`,
    );
    await fill("[name=grant_coach]", coachId);
    await fill("[name=grant_title]", "پکیج آزمایشی مشاوره مرورگر");
    await fill("[name=grant_source]", "BROWSER-PACKAGE-ONLY");
    await fill("[name=grant_total]", "1");
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat("en-US-u-ca-persian-nu-latn", {
            year: "numeric",
            month: "numeric",
            day: "numeric",
            timeZone: "Asia/Tehran",
        })
            .formatToParts(new Date(Date.now() + 15 * 86400000))
            .map((p) => [p.type, p.value]),
    );
    for (const [label, key] of [
        ["سال", "year"],
        ["ماه", "month"],
        ["روز", "day"],
    ])
        await fill(
            `select[aria-label^="${label}"]`,
            String(Number(parts[key])),
        );
    await evaluate(
        `document.querySelector('details input[type=checkbox]').click()`,
    );
    await submit("details form");
    await until(`!!document.querySelector('.consultation-grant-card')`);
    await login("student");
    await goto("/consultations");
    await until(`!!document.querySelector('[name=booking_grant]')`);
    const slotId = await evaluate(
        `document.querySelector('[name=booking_grant]').closest('article').dataset.slotId`,
    );
    const grantId = await evaluate(
        `document.querySelector('[name=booking_grant] option:not([value=""])').value`,
    );
    await fill(`[data-slot-id="${slotId}"] textarea`, "جلسه آزمایشی با سهمیه");
    await fill(`[data-slot-id="${slotId}"] [name=booking_grant]`, grantId);
    await submit(`[data-slot-id="${slotId}"] form`);
    await until(
        `!!document.querySelector('[data-booking-id]') && document.body.innerText.includes('پوشش پکیج')`,
    );
    assert.equal(
        await evaluate(
            `!!document.querySelector('a[href$="/pay"], button[href$="/pay"]')`,
        ),
        false,
    );
    await goto("/consultation-grants");
    await until(`!!document.querySelector('.consultation-grant-card')`);
    assert.equal(
        await evaluate(
            `document.querySelector('.consultation-grant-card').innerText.includes('مانده: ۰ از ۱')`,
        ),
        true,
    );
    await goto("/consultations");
    await until(`!!document.querySelector('[data-booking-id] form')`);
    await fill("[data-booking-id] textarea", "لغو آزمایشی پیش از شروع");
    await submit("[data-booking-id] form");
    await until(
        `document.querySelector('[data-booking-id] .status-badge').innerText==='لغوشده'`,
    );
    await goto("/consultation-grants");
    await until(
        `document.querySelector('.consultation-grant-card')?.innerText.includes('مانده: ۱ از ۱')`,
    );
    await goto("/consultations");
    await until(`!!document.querySelector('[name=request_grant]')`);
    await fill("[name=request_grant]", grantId);
    await evaluate(
        `(()=>{const a=document.querySelector('[name=request_grant]').form.querySelectorAll('textarea');a[0].value='هماهنگی با پکیج آزمایشی';a[0].dispatchEvent(new Event('input',{bubbles:true}));a[1].value='عصرها مناسب است';a[1].dispatchEvent(new Event('input',{bubbles:true}));})()`,
    );
    await evaluate(
        `document.querySelector('[name=request_grant]').form.requestSubmit()`,
    );
    await until(
        `document.body.innerText.includes('هماهنگی با پکیج آزمایشی') && document.body.innerText.includes('منتظر هماهنگی')`,
    );
    await login("admin");
    await goto("/consultations?view=admin");
    await until(
        `!!Array.from(document.querySelectorAll('article')).find(a=>a.innerText.includes('هماهنگی با پکیج آزمایشی'))?.querySelector('form select')`,
    );
    await evaluate(
        `(()=>{const form=Array.from(document.querySelectorAll('article')).find(a=>a.innerText.includes('هماهنگی با پکیج آزمایشی')).querySelector('form');const select=form.querySelector('select');select.value=select.querySelector('option:not([disabled])').value;select.dispatchEvent(new Event('change',{bubbles:true}));})()`,
    );
    await evaluate(
        `Array.from(document.querySelectorAll('article')).find(a=>a.innerText.includes('هماهنگی با پکیج آزمایشی')).querySelector('form').requestSubmit()`,
    );
    await until(`document.body.innerText.includes('زمان تعیین شد')`);
    await login("student");
    await goto("/consultation-grants");
    await until(
        `document.querySelector('.consultation-grant-card')?.innerText.includes('مانده: ۰ از ۱')`,
    );
    assert.equal(
        await evaluate(
            `document.body.innerText.includes('BROWSER-PACKAGE-ONLY')`,
        ),
        false,
    );
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await until(
        `document.querySelector('.sidebar').getBoundingClientRect().left>=window.innerWidth`,
    );
    assert.equal(
        await evaluate(
            "document.documentElement.scrollWidth<=window.innerWidth",
        ),
        true,
    );
    await writeFile(
        "/tmp/kardorost-consultation-grants-mobile.png",
        Buffer.from(
            (
                await send("Page.captureScreenshot", {
                    format: "png",
                    captureBeyondViewport: true,
                })
            ).data,
            "base64",
        ),
    );
    assert.deepEqual(exceptions, []);
    console.log(
        "PASS: reviewed admin entitlement, package booking without gateway, balance, pre-start cancellation release, student-selected coordinated booking, private source and 390px mobile layout",
    );
} finally {
    socket.close();
}
