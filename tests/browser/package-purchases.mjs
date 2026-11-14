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
    await goto("/admin/consultation-packages");
    await evaluate(`document.querySelector('details').open=true`);
    const create = '[data-product-editor="new"]';
    await fill(create + " [name=product_title]", "پکیج خرید مستقل مرورگر");
    await fill(
        create + " [name=product_description]",
        "دو جلسه مشاوره مسیر حرفه‌ای با مدت و مهلت مشخص",
    );
    const coachId = await evaluate(
        `document.querySelector('[name=product_coach] option:not([disabled])').value`,
    );
    await fill(create + " [name=product_coach]", coachId);
    await fill(create + " [name=product_price]", "100000");
    await fill(create + " [name=product_sessions]", "2");
    for (const field of [
        "product_published",
        "product_manual",
        "product_reviewed",
    ])
        await evaluate(
            `document.querySelector(${JSON.stringify(create + " [name=" + field + "]")}).click()`,
        );
    await submit(create);
    await until(
        `document.querySelectorAll('[data-product-editor]').length===2 && !document.querySelector('[name=product_title]').value`,
    );
    await login("student");
    await goto("/consultation-packages");
    await until(`!!document.querySelector('[data-package-offer]')`);
    await evaluate(
        `document.querySelector('[data-package-offer] input[type=checkbox]').click()`,
    );
    await submit("[data-package-offer] form");
    await until(
        `location.pathname.startsWith('/package-purchases/') && !!document.querySelector('[data-package-purchase]')`,
    );
    const orderPath = await evaluate("location.pathname");
    assert.match(await evaluate("document.body.innerText"), /۱۰۰٬۰۰۰ تومان/);
    await login("admin");
    await goto("/admin/consultation-packages");
    const edit = '[data-product-editor]:not([data-product-editor="new"])';
    await evaluate(
        `document.querySelector('${edit}').closest('details').open=true`,
    );
    await fill(edit + " [name=product_price]", "200000");
    await evaluate(
        `document.querySelector('${edit} [name=product_reviewed]').click()`,
    );
    await submit(edit);
    await until(
        `document.body.innerText.includes('شرایط فروش به‌روز شد') && !document.querySelector('${edit} [name=product_reviewed]').checked`,
    );
    await fill(
        edit + " [name=product_description]",
        "شرح تازه فقط برای خریدهای بعدی",
    );
    await evaluate(
        `document.querySelector('${edit} [name=product_reviewed]').click()`,
    );
    await submit(edit);
    await until(
        `!document.querySelector('${edit} [name=product_reviewed]').checked`,
    );
    assert.equal(
        await evaluate(
            `document.querySelectorAll('${edit} .field-error').length`,
        ),
        0,
    );
    await login("student");
    await goto(orderPath);
    assert.match(await evaluate("document.body.innerText"), /۱۰۰٬۰۰۰ تومان/);
    assert.equal(
        await evaluate(
            `document.body.innerText.includes('شرح تازه فقط برای خریدهای بعدی')`,
        ),
        false,
    );
    await evaluate(`document.querySelector('.receipt-upload button').click()`);
    await until(`!!document.querySelector('.receipt-upload-form')`);
    await fill(".receipt-upload-form input[dir=ltr]", "BROWSER-PACKAGE-12345");
    const fixturePath = "/tmp/kardorost-package-purchase-receipt.pdf";
    await writeFile(
        fixturePath,
        "%PDF-1.4\nStandalone package synthetic browser receipt",
    );
    const dom = await send("DOM.getDocument");
    const input = await send("DOM.querySelector", {
        nodeId: dom.root.nodeId,
        selector: ".receipt-upload-form input[type=file]",
    });
    await send("DOM.setFileInputFiles", {
        nodeId: input.nodeId,
        files: [fixturePath],
    });
    await submit(".receipt-upload-form");
    await until(
        `document.body.innerText.includes('رسید ثبت شد؛ سهمیه پس از تأیید مستقل پرداخت صادر می‌شود.')`,
    );
    await goto("/consultation-grants");
    assert.equal(
        await evaluate(`!!document.querySelector('.consultation-grant-card')`),
        false,
    );
    await login("admin");
    await goto("/admin/finance");
    await until(`!!document.querySelector('.receipt-review-card')`);
    await evaluate(
        `document.querySelector('.receipt-review-card button').click()`,
    );
    await until(`!!document.querySelector('.receipt-review-card form')`);
    await fill(".receipt-review-card input[inputmode=numeric]", "100000");
    await evaluate(
        `document.querySelector('.receipt-review-card input[type=checkbox]').click()`,
    );
    await submit(".receipt-review-card form");
    await until(`!document.querySelector('.receipt-review-card form')`);
    await login("student");
    await goto(orderPath);
    await until(`document.body.innerText.includes('سهمیه صادر شده')`);
    await goto("/consultation-grants");
    await until(
        `document.querySelector('.consultation-grant-card')?.innerText.includes('مانده: ۲ از ۲')`,
    );
    assert.equal(
        await evaluate(`document.body.innerText.includes('PACKAGE-PURCHASE-')`),
        false,
    );
    await goto("/consultations");
    await until(`!!document.querySelector('[name=booking_grant]')`);
    const slotId = await evaluate(
        `document.querySelector('[name=booking_grant]').closest('article').dataset.slotId`,
    );
    const grantId = await evaluate(
        `document.querySelector('[name=booking_grant] option:not([value=""])').value`,
    );
    await fill(
        `[data-slot-id="${slotId}"] textarea`,
        "جلسه با پکیج خریداری‌شده",
    );
    await fill(`[data-slot-id="${slotId}"] [name=booking_grant]`, grantId);
    await submit(`[data-slot-id="${slotId}"] form`);
    await until(
        `!!document.querySelector('[data-booking-id]') && document.body.innerText.includes('پوشش پکیج')`,
    );
    await goto("/consultation-grants");
    await until(
        `document.querySelector('.consultation-grant-card')?.innerText.includes('مانده: ۱ از ۲')`,
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
        `document.querySelector('.consultation-grant-card')?.innerText.includes('مانده: ۲ از ۲')`,
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
    for (const path of [
        "/consultation-packages",
        orderPath,
        "/consultation-grants",
        "/finance",
    ]) {
        await goto(path);
        assert.equal(
            await evaluate(
                "document.documentElement.scrollWidth<=window.innerWidth",
            ),
            true,
            path + " mobile width",
        );
    }
    await goto(orderPath);
    await writeFile(
        "/tmp/kardorost-package-purchase-mobile.png",
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
        "PASS: admin package publication and successive edits, frozen checkout, pending private receipt, independent approval, one purchased grant, real booking and cancellation ledger, source privacy and mobile 390",
    );
} finally {
    socket.close();
}
