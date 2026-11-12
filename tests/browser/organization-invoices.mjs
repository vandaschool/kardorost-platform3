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

assert(
    process.env.BROWSER_ORGANIZATION_PHONE,
    "Provide isolated organization manager phone",
);

try {
    await login("admin");
    await goto("/admin/organization-invoices");
    await until(`!!document.querySelector('details')`);
    await evaluate(`document.querySelector('details').open=true`);
    await evaluate(
        `(()=>{const el=document.querySelector('select[name=organization_id]');el.value=el.querySelector('option:not([value=""])').value;el.dispatchEvent(new Event('change',{bubbles:true}));})()`,
    );
    await fill("[name=invoice_title]", "صورتحساب آزمایشی قرارداد سازمان");
    await fill("[name=contract_reference]", "BROWSER-CONTRACT");
    await fill("[name=line_title_0]", "خدمات آموزشی آزمایشی");
    await fill("[name=line_price_0]", "100000");
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat("en-US-u-ca-persian-nu-latn", {
            year: "numeric",
            month: "numeric",
            day: "numeric",
            timeZone: "Asia/Tehran",
        })
            .formatToParts(new Date(Date.now() + 7 * 86400000))
            .map((p) => [p.type, p.value]),
    );
    for (const [label, key] of [
        ["سال", "year"],
        ["ماه", "month"],
        ["روز", "day"],
    ]) {
        await fill(
            `select[aria-label^="${label}"]`,
            String(Number(parts[key])),
        );
    }
    await submit("details form");
    await until(
        `location.pathname.includes('/admin/organization-invoices/') && !!document.querySelector('main input[type=checkbox]')`,
    );
    const invoiceId = await evaluate(`location.pathname.split('/').at(-1)`);
    await evaluate(
        `document.querySelector('main input[type=checkbox]').click()`,
    );
    await evaluate(
        `document.querySelector('main input[type=checkbox]').form.requestSubmit()`,
    );
    await until(
        `!document.querySelector('[name=invoice_title]') && document.body.innerText.includes('صادرشده')`,
    );
    await login("organization");
    await goto("/organization/invoices/" + invoiceId);
    await until(`!!document.querySelector('details form')`);
    await evaluate(`document.querySelector('details').open=true`);
    await fill("[name=transfer_reference]", "BROWSER987654");
    const date = Object.fromEntries(
        new Intl.DateTimeFormat("en-US-u-ca-persian-nu-latn", {
            year: "numeric",
            month: "numeric",
            day: "numeric",
            timeZone: "Asia/Tehran",
        })
            .formatToParts(new Date())
            .map((p) => [p.type, p.value]),
    );
    for (const [label, key] of [
        ["سال", "year"],
        ["ماه", "month"],
        ["روز", "day"],
    ]) {
        await fill(
            `details select[aria-label^="${label}"]`,
            String(Number(date[key])),
        );
    }
    await writeFile(
        "/tmp/kardorost-org-invoice-proof.pdf",
        "%PDF-1.4\nBrowser fixture organization payment",
    );
    const root = await send("DOM.getDocument");
    const node = await send("DOM.querySelector", {
        nodeId: root.root.nodeId,
        selector: "details input[type=file]",
    });
    await send("DOM.setFileInputFiles", {
        nodeId: node.nodeId,
        files: ["/tmp/kardorost-org-invoice-proof.pdf"],
    });
    await submit("details form");
    await until(`document.body.innerText.includes('رسید در انتظار بررسی')`);
    assert.equal(
        await evaluate(
            `document.querySelector('.eyebrow').innerText.includes('صادرشده')`,
        ),
        true,
        "Uploading a receipt is not settlement",
    );
    await login("admin");
    await goto("/admin/organization-invoices/" + invoiceId);
    await until(`!!document.querySelector('.receipt-review-card')`);
    await evaluate(
        `Array.from(document.querySelectorAll('button')).find(b=>b.innerText==='بررسی رسید').click()`,
    );
    await until(`!!document.querySelector('.receipt-review-card form')`);
    await fill(".receipt-review-card input[inputmode=numeric]", "100000");
    await fill(
        ".receipt-review-card textarea",
        "واریز آزمایشی با گردش حساب تطبیق داده شد",
    );
    await evaluate(
        `document.querySelector('.receipt-review-card input[type=checkbox]').click()`,
    );
    await submit(".receipt-review-card form");
    await until(
        `document.querySelector('.eyebrow').innerText.includes('تسویه‌شده')`,
    );
    await login("organization");
    await goto("/organization/invoices/" + invoiceId);
    await until(
        `document.querySelector('.eyebrow').innerText.includes('تسویه‌شده')`,
    );
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await until(
        `document.querySelector('.sidebar').getBoundingClientRect().left >= window.innerWidth`,
    );
    assert.equal(
        await evaluate(
            "document.documentElement.scrollWidth <= window.innerWidth",
        ),
        true,
    );
    await writeFile(
        "/tmp/kardorost-organization-invoice-mobile.png",
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
    await goto("/finance");
    await until(`document.body.innerText.includes('پرداخت‌ها و اقساط')`);
    assert.equal(
        await evaluate(
            `document.body.innerText.includes('صورتحساب آزمایشی قرارداد سازمان')`,
        ),
        false,
        "Organization receipt is excluded from personal finance",
    );
    assert.deepEqual(exceptions, []);
    console.log(
        "PASS: admin draft/issue, manager private receipt, independent admin settlement, mobile history and personal finance isolation",
    );
} finally {
    socket.close();
}
