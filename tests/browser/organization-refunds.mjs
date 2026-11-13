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

const refundInvoice = Number(
    process.env.BROWSER_ORGANIZATION_REFUND_INVOICE_ID,
);
const pendingInvoice = Number(
    process.env.BROWSER_ORGANIZATION_PENDING_INVOICE_ID,
);
assert(
    refundInvoice > 0 && pendingInvoice > 0,
    "Provide isolated excess-fund and pending-payment fixture invoice IDs.",
);
try {
    await login("organization");
    await goto("/organization/invoices/" + pendingInvoice);
    await until(`!!document.querySelector('[name=gateway_amount]')`);
    assert.equal(
        await evaluate(
            `document.querySelector('[name=gateway_amount]').readOnly`,
        ),
        true,
    );
    assert.equal(
        await evaluate(`document.querySelector('[name=gateway_amount]').value`),
        "40000",
    );
    assert.equal(
        await evaluate(`!!document.querySelector('details input[type=file]')`),
        false,
        "A pending gateway attempt blocks new receipt upload",
    );
    await goto("/organization/invoices/" + refundInvoice);
    await until(`!!document.querySelector('.organization-refund-request')`);
    await evaluate(
        `document.querySelector('.organization-refund-request').open=true`,
    );
    await fill(
        ".organization-refund-request input[dir=ltr]",
        "IR820540102680020817909002",
    );
    await fill("[name=beneficiary_name]", "حساب سازمان آزمایشی مرورگر");
    await fill(
        ".organization-refund-request textarea",
        "درخواست آزمایشی برگشت وجه سازمان",
    );
    await evaluate(
        `document.querySelector('.organization-refund-request input[type=checkbox]').click()`,
    );
    await submit(".organization-refund-request form");
    await until(
        `!!document.querySelector('.refund-history') && !document.querySelector('.organization-refund-request')`,
    );
    assert.equal(
        await evaluate(
            `document.querySelector('.refund-history').innerText.includes('IR820540102680020817909002')`,
        ),
        false,
        "Stored IBAN is masked for managers",
    );
    await login("admin");
    await goto("/admin/organization-invoices/" + refundInvoice);
    await until(`!!document.querySelector('.refund-history select')`);
    await fill(".refund-history select", "approve");
    await fill(
        ".refund-history textarea",
        "وجه و مجوز حساب سازمان با مدارک آزمایشی تطبیق داده شد",
    );
    await submit(".refund-history form");
    await until(`!!document.querySelector('[name=refund_amount]')`);
    assert.equal(
        await evaluate(`!!document.querySelector('.refund-history a')`),
        false,
        "Approval alone cannot generate transfer evidence",
    );
    await fill("[name=refund_amount]", "80000");
    await fill("[name=refund_destination]", "IR820540102680020817909002");
    await fill("[name=refund_reference]", "ORG-BROWSER-REFUND");
    const parts = Object.fromEntries(
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
    ])
        await fill(
            `.refund-history select[aria-label^="${label}"]`,
            String(Number(parts[key])),
        );
    await writeFile(
        "/tmp/kardorost-org-refund-proof.pdf",
        "%PDF-1.4\nBrowser fixture organization refund evidence",
    );
    const root = await send("DOM.getDocument");
    const node = await send("DOM.querySelector", {
        nodeId: root.root.nodeId,
        selector: ".refund-history input[type=file]",
    });
    await send("DOM.setFileInputFiles", {
        nodeId: node.nodeId,
        files: ["/tmp/kardorost-org-refund-proof.pdf"],
    });
    for (let index = 0; index < 2; index++)
        await evaluate(
            `document.querySelectorAll('.refund-history input[type=checkbox]')[${index}].click()`,
        );
    await submit(".refund-history form");
    await until(
        `!document.querySelector('.refund-history form') && !!document.querySelector('.refund-history a')`,
    );
    await login("organization");
    await goto("/organization/invoices/" + refundInvoice);
    await until(
        `!!document.querySelector('.refund-history a') && !!document.querySelector('[name=gateway_amount]')`,
    );
    assert.equal(
        await evaluate(
            `document.querySelector('[data-testid=invoice-remaining]').innerText.includes('۶۰٬۰۰۰')`,
        ),
        true,
    );
    assert.equal(
        await evaluate(
            `document.querySelector('[data-testid=invoice-paid]').innerText.includes('۴۰٬۰۰۰')`,
        ),
        true,
    );
    assert.equal(
        await evaluate(
            `document.querySelector('.refund-history').innerText.includes('IR820540102680020817909002')`,
        ),
        false,
    );
    assert.equal(
        await evaluate(
            `(async()=>{const r=await fetch(document.querySelector('.refund-history a').href); return r.status;})()`,
        ),
        200,
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
        "/tmp/kardorost-organization-refund-mobile.png",
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
    await goto("/refunds");
    await until(`document.body.innerText.includes('بازپرداخت‌های من')`);
    assert.equal(
        await evaluate(
            `document.body.innerText.includes('درخواست آزمایشی برگشت وجه سازمان')`,
        ),
        false,
        "Corporate refunds stay out of personal history",
    );
    assert.deepEqual(exceptions, []);
    console.log(
        "PASS: pending gateway UI, organization refund request, admin review and manual evidence, masked IBAN, private proof, unchanged invoice balance, mobile history and personal isolation",
    );
} finally {
    socket.close();
}
