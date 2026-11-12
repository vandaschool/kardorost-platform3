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
    await login("student");
    await goto("/refunds");
    await until(`!!document.querySelector('article form textarea')`);
    await fill("article form input[dir=ltr]", "IR820540102680020817909002");
    await fill("article form textarea", "Browser full refund request");
    await evaluate(
        `document.querySelector('article form input[type=checkbox]').click()`,
    );
    await submit("article form");
    await until(
        `document.body.innerText.includes('Browser full refund request') && document.querySelectorAll('article').length===1`,
    );
    assert.equal(
        await evaluate(
            `document.body.innerText.includes('IR820540102680020817909002')`,
        ),
        false,
        "Owner list masks stored IBAN",
    );
    await login("admin");
    await goto("/refunds?view=admin&status=all");
    await until(
        `document.body.innerText.includes('Browser full refund request')`,
    );
    await fill("article select", "approve");
    await fill("article textarea", "Browser approval after checking payment");
    await submit("article form");
    await until(
        `document.body.innerText.includes('تأییدشده؛ منتظر واریز') && !document.querySelector('article select:not([aria-label])')`,
    );
    assert.equal(
        await evaluate(
            `document.body.innerText.includes('مدرک برگشت وجه ثبت شد') && !!document.querySelector('article a')`,
        ),
        false,
        "Approval does not fabricate completion evidence",
    );
    const inputs = await evaluate(
        `Array.from(document.querySelectorAll('article form input')).map(i=>[i.type,i.getAttribute('dir')])`,
    );
    await fill("article form input[inputmode=numeric]", "100000");
    await evaluate(
        `(()=>{const a=document.querySelectorAll('article form input[dir=ltr]');a[0].value='IR820540102680020817909002';a[0].dispatchEvent(new Event('input',{bubbles:true}));a[1].value='BROWSER123456';a[1].dispatchEvent(new Event('input',{bubbles:true}));})()`,
    );
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
    ]) {
        await fill(
            `article select[aria-label^="${label}"]`,
            String(Number(parts[key])),
        );
    }
    await writeFile(
        "/tmp/kardorost-browser-refund.pdf",
        "%PDF-1.4\nBrowser fixture refund evidence",
    );
    const root = await send("DOM.getDocument");
    const node = await send("DOM.querySelector", {
        nodeId: root.root.nodeId,
        selector: "article input[type=file]",
    });
    await send("DOM.setFileInputFiles", {
        nodeId: node.nodeId,
        files: ["/tmp/kardorost-browser-refund.pdf"],
    });
    await evaluate(
        `document.querySelectorAll('article form input[type=checkbox]').forEach(i=>i.click())`,
    );
    await submit("article form");
    await until(
        `!document.querySelector('article form') && document.body.innerText.includes('BROWSER123456')`,
    );
    await login("student");
    await goto("/refunds");
    await until(
        `document.body.innerText.includes('BROWSER123456') && !!document.querySelector('article a')`,
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
        "No mobile overflow",
    );
    await writeFile(
        "/tmp/kardorost-refunds-mobile.png",
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
        "PASS: owner refund request, admin approval, manual evidence registration, owner completion history, private IBAN, mobile layout",
    );
} finally {
    socket.close();
}
