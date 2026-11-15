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
const activeRequests = new Set();
socket.addEventListener("message", (event) => {
    const m = JSON.parse(event.data);
    if (
        m.method === "Network.requestWillBeSent" &&
        ["XHR", "Fetch"].includes(m.params.type)
    )
        activeRequests.add(m.params.requestId);
    if (["Network.loadingFinished", "Network.loadingFailed"].includes(m.method))
        activeRequests.delete(m.params.requestId);
    if (m.method === "Runtime.consoleAPICalled" && m.params.type === "error")
        exceptions.push(
            "console: " +
                m.params.args
                    .map((a) => a.description || a.value || "")
                    .join(" "),
        );
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
await send("Network.enable");
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
async function networkIdle() {
    let stable = 0;
    for (let index = 0; index < 100; index++) {
        stable = activeRequests.size === 0 ? stable + 1 : 0;
        if (stable >= 3) return;
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
    throw new Error(
        "Previous navigation did not finish before switching fixture accounts",
    );
}
async function login(role) {
    await networkIdle();
    await send("Network.clearBrowserCookies");
    await send("Page.navigate", { url: base + "/login" });
    await until(`!!document.querySelector('input[name=password]')`);
    await networkIdle();
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
    await goto("/admin/reports?from=2026-03-01&to=2026-03-03");
    await until("!!document.querySelector('[data-metric=captured_amount]')");
    assert(
        await evaluate(
            "document.querySelector('[data-metric=captured_amount]').innerText.includes('۱۰۰٬۰۰۰')",
        ),
    );
    assert(
        await evaluate(
            "document.querySelector('[data-metric=refunded_amount]').innerText.includes('۲۰٬۰۰۰')",
        ),
    );
    assert(
        await evaluate(
            "document.querySelector('[data-metric=net_cash_flow]').innerText.includes('۸۰٬۰۰۰')",
        ),
    );
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-report-record]').length",
        ),
        20,
    );
    await evaluate(
        "document.querySelector('[data-testid=report-daily]').open=true",
    );
    assert.equal(
        await evaluate("document.querySelectorAll('.report-day').length"),
        3,
    );
    const csv = await evaluate(
        "(async()=>{const r=await fetch(document.querySelector('[data-testid=report-export]').href);return {text:await r.text(),cache:r.headers.get('cache-control'),type:r.headers.get('content-type')};})()",
    );
    assert.equal(csv.text.trim().split("\n").length, 26);
    assert(!csv.text.includes("OUTSIDE-REPORT"));
    assert(csv.cache.includes("no-store"));
    assert(csv.type.includes("text/csv"));
    await fill("[name=dataset]", "refunds");
    await submit(".report-filter");
    await until(
        "location.search.includes('dataset=refunds') && document.querySelectorAll('[data-report-record]').length===1",
    );
    assert(await evaluate("document.body.innerText.includes('REPORT-REFUND')"));
    assert(
        !(await evaluate(
            "document.body.innerText.includes('Private report reason')",
        )),
    );
    await fill("[name=dataset]", "enrollments");
    await submit(".report-filter");
    await until(
        "location.search.includes('dataset=enrollments') && document.querySelectorAll('[data-report-record]').length===2",
    );
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await until(
        "document.documentElement.scrollWidth<=innerWidth && document.querySelector('.sidebar').getBoundingClientRect().left >= innerWidth - 1",
    );
    const shot = await send("Page.captureScreenshot", { format: "png" });
    await writeFile(
        "/tmp/kardorost-reports-mobile.png",
        Buffer.from(shot.data, "base64"),
    );
    await goto("/admin/reports?from=2025-01-01&to=2025-01-03");
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-report-record]').length",
        ),
        0,
    );
    assert(
        await evaluate(
            "document.querySelector('[data-metric=captured_amount]').innerText.includes('۰ تومان')",
        ),
    );
    assert(
        await evaluate(
            "document.body.innerText.includes('رکوردی در این بازه وجود ندارد')",
        ),
    );
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            captured_refunded_net_cash_flow: true,
            all_csv_rows_vs_paginated_records: true,
            three_day_details: true,
            refund_privacy: true,
            enrollment_dataset: true,
            empty_period: true,
            mobile390: true,
        }),
    );
} finally {
    socket.close();
}
