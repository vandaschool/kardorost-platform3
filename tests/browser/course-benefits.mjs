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
    await goto("/admin/courses");
    await until(
        `Array.from(document.querySelectorAll('details')).some(el=>el.querySelector('summary')?.innerText.includes('مشاوره همراه دوره'))`,
    );
    await evaluate(
        `(()=>{const el=Array.from(document.querySelectorAll('details')).find(el=>el.querySelector('summary')?.innerText.includes('مشاوره همراه دوره'));el.id='benefit-editor';el.open=true;el.querySelector('input[type=checkbox]').click()})()`,
    );
    const coachId = await evaluate(
        `document.querySelector('#benefit-editor select option:not([disabled])').value`,
    );
    await fill("#benefit-editor select", coachId);
    await fill(
        '#benefit-editor input[maxlength="160"]',
        "مشاوره همراه دوره مرورگر",
    );
    await evaluate(
        `(()=>{const el=document.querySelector('#benefit-editor select:has(option[value=paid_in_full])');el.value='paid_in_full';el.dispatchEvent(new Event('change',{bubbles:true}));})()`,
    );
    await evaluate(
        `document.querySelectorAll('#benefit-editor input[type=checkbox]')[1].click()`,
    );
    await submit("#benefit-editor form");
    await until(
        `document.body.innerText.includes('شرایط مشاوره برای ثبت‌نام‌های بعدی ذخیره شد')`,
    );
    await evaluate(`document.querySelector('#benefit-editor').open=true`);
    await fill('#benefit-editor input[max="100"]', "2");
    await evaluate(
        `document.querySelectorAll('#benefit-editor input[type=checkbox]')[1].click()`,
    );
    await submit("#benefit-editor form");
    await until(
        `document.querySelector('#benefit-editor input[max="100"]').value==='2' && !document.querySelector('#benefit-editor[open]')`,
    );
    assert.equal(
        await evaluate(
            `document.querySelectorAll('#benefit-editor .field-error').length`,
        ),
        0,
        "Successive edits use updated revision",
    );
    await goto("/admin/courses");
    await until(`document.body.innerText.includes('مشاوره همراه دوره')`);
    await login("student");
    await goto("/courses");
    await until(`document.body.innerText.includes('مشاوره همراه دوره مرورگر')`);
    assert.match(await evaluate("document.body.innerText"), /پس از تسویه کامل/);
    await evaluate(`document.querySelector('.cohort-checkout button').click()`);
    await until(`location.pathname==='/learning'`);
    await goto("/finance");
    await until(
        `document.body.innerText.includes('در انتظار پرداخت واجد شرایط')`,
    );
    await evaluate(`document.querySelector('.receipt-upload button').click()`);
    await until(`!!document.querySelector('.receipt-upload-form')`);
    await fill(".receipt-upload-form input[dir=ltr]", "BROWSER-COURSE-1234");
    const fixturePath = "/tmp/kardorost-course-benefit-receipt.pdf";
    await writeFile(
        fixturePath,
        "%PDF-1.4\nSynthetic course benefit browser receipt",
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
        `document.body.innerText.includes('رسید شما در انتظار بررسی مدیریت است.')`,
    );
    await goto("/consultation-grants");
    assert.equal(
        await evaluate(`!!document.querySelector('.consultation-grant-card')`),
        false,
        "Receipt upload alone must not issue credit",
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
    await goto("/admin/course-benefits");
    await until(`document.body.innerText.includes('سهمیه صادر شده')`);
    assert.equal(
        await evaluate(`!!document.querySelector('main button.btn.secondary')`),
        false,
        "Issued contract has no repair button",
    );
    await login("student");
    await goto("/finance");
    await until(`document.body.innerText.includes('سهمیه صادر شده')`);
    await goto("/consultation-grants");
    await until(`!!document.querySelector('.consultation-grant-card')`);
    assert.match(await evaluate("document.body.innerText"), /همراه دوره:/);
    assert.equal(
        await evaluate(`document.body.innerText.includes('COURSE-BENEFIT-')`),
        false,
    );
    assert.equal(
        await evaluate(
            `document.querySelectorAll('.consultation-grant-card').length`,
        ),
        1,
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
    for (const path of ["/consultation-grants", "/finance", "/courses"]) {
        await goto(path);
        assert.equal(
            await evaluate(
                "document.documentElement.scrollWidth<=window.innerWidth",
            ),
            true,
            path + " mobile width",
        );
    }
    await goto("/finance");
    await writeFile(
        "/tmp/kardorost-course-benefits-mobile.png",
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
        "PASS: admin course benefit configuration, checkout terms, frozen finance promise, private receipt before issuance, admin approval, exactly one grant, source privacy and 390px mobile layout",
    );
} finally {
    socket.close();
}
