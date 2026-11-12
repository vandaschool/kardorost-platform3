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
    await goto("/admin/organization-licenses");
    await until(`!!document.querySelector('details')`);
    await evaluate(`document.querySelector('details').open=true`);
    await evaluate(
        `document.querySelectorAll('details select').forEach(el=>{el.value=el.querySelector('option:not([value=""])').value;el.dispatchEvent(new Event('change',{bubbles:true}));})`,
    );
    await fill("input[name=seat_limit]", "2");
    await fill("input[name=contract_reference]", "BROWSER-CONTRACT");
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat("en-US-u-ca-persian-nu-latn", {
            year: "numeric",
            month: "numeric",
            day: "numeric",
            timeZone: "Asia/Tehran",
        })
            .formatToParts(new Date(Date.now() + 30 * 86400000))
            .map((p) => [p.type, p.value]),
    );
    for (const [label, key] of [
        ["سال", "year"],
        ["ماه", "month"],
        ["روز", "day"],
    ]) {
        await fill(
            `details select[aria-label^="${label}"]`,
            String(Number(parts[key])),
        );
    }
    await evaluate(
        `document.querySelector('details input[type=checkbox]').click()`,
    );
    await submit("details form");
    await until(`document.querySelectorAll('article').length===1`);
    await login("organization");
    await goto("/organization/licenses");
    await until(`!!document.querySelector('article a')`);
    await evaluate(`document.querySelector('article a').click()`);
    await until(
        `document.querySelectorAll('form.stack-form input[type=checkbox]').length>=3`,
    );
    await evaluate(
        `Array.from(document.querySelectorAll('button')).find(b=>b.innerText.includes('انتخاب اعضای مجاز')).click()`,
    );
    await until(
        `document.querySelectorAll('form.stack-form input[type=checkbox]:checked').length===2`,
    );
    await evaluate(
        `document.querySelectorAll('form.stack-form input[type=checkbox]')[document.querySelectorAll('form.stack-form input[type=checkbox]').length-1].click()`,
    );
    await submit("form.stack-form");
    await until(
        `document.body.innerText.includes('2 عضو ثبت‌نام شد') && document.querySelectorAll('.consultation-grid article').length===2`,
    );
    assert.equal(
        await evaluate(
            `document.querySelectorAll('form.stack-form input[type=checkbox]:disabled').length>=2`,
        ),
        true,
        "Already enrolled members cannot spend quota again",
    );
    const exported = await evaluate(
        `fetch(location.pathname+'/export').then(async r=>({status:r.status,cache:r.headers.get('cache-control'),csv:await r.text()}))`,
    );
    assert.equal(exported.status, 200);
    assert(exported.cache.includes("private"));
    assert(exported.csv.includes("عضو دوم مرورگر"));
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
        "/tmp/kardorost-organization-enrollment-mobile.png",
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
    await login("student");
    await goto("/learning");
    await until(`document.body.innerText.includes('با پوشش سازمان مرورگر')`);
    assert.equal(
        await evaluate(
            `document.body.innerText.includes('صورتحساب و تکمیل پرداخت')`,
        ),
        false,
        "Sponsored student has no personal payment request",
    );
    assert.deepEqual(exceptions, []);
    console.log(
        "PASS: admin capacity allocation, manager bulk enrollment, scoped CSV, mobile layout and sponsored student learning",
    );
} finally {
    socket.close();
}
