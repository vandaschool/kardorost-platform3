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
    await goto("/admin/certificate-policies");
    await evaluate(
        `document.querySelector('details').open=true;document.querySelector('[name=certificate_enabled]').click()`,
    );
    await until(`!!document.querySelector('[name=certificate_issuer]')`);
    await fill("[name=certificate_issuer]", "کاردرست آزمایشی");
    for (const field of [
        "lesson_ids",
        "session_ids",
        "assignment_ids",
        "certificate_policy_reviewed",
    ])
        await evaluate(`document.querySelector('[name=${field}]').click()`);
    await submit("[data-certificate-policy]");
    await until(
        `document.body.innerText.includes('شرایط درخواست‌های بعدی ذخیره شد')`,
    );
    await login("student");
    await goto("/certificates");
    const path = await evaluate(
        `document.querySelector('a[href^="/certificates/enrollments/"]').getAttribute('href')`,
    );
    await goto(path);
    assert.equal(
        await evaluate(
            `document.querySelector('[data-certificate-request] button').disabled`,
        ),
        true,
    );
    await goto("/learning/" + path.split("/").at(-1));
    await evaluate(
        `Array.from(document.querySelectorAll('button')).find(button=>button.innerText.includes('درس را تکمیل کردم')).click()`,
    );
    await until(
        `document.body.innerText.includes('این درس را تکمیل کرده‌اید')`,
    );
    await goto(path);
    await until(
        `!document.querySelector('[data-certificate-request] button').disabled`,
    );
    await evaluate(
        `document.querySelector('[data-certificate-request] input[type=checkbox]').click()`,
    );
    await submit("[data-certificate-request]");
    await until(`document.body.innerText.includes('در انتظار بررسی مدیریت')`);
    assert.equal(
        await evaluate(`!!document.querySelector('[data-issued-certificate]')`),
        false,
    );
    await login("admin");
    await goto("/admin/certificate-policies");
    await evaluate(`document.querySelector('details').open=true`);
    await fill("[name=score_percent]", "90");
    await fill("[name=certificate_issuer]", "صادرکننده درخواست‌های بعدی");
    await evaluate(
        `document.querySelector('[name=certificate_policy_reviewed]').click()`,
    );
    await submit("[data-certificate-policy]");
    await until(
        `!document.querySelector('[name=certificate_policy_reviewed]').checked`,
    );
    await goto("/admin/certificates");
    await until(`!!document.querySelector('[data-certificate-review]')`);
    assert.equal(
        await evaluate(
            `document.querySelector('[data-certificate-review] button').disabled`,
        ),
        false,
        "Frozen request keeps original passing threshold",
    );
    await fill(
        "[data-certificate-review] textarea",
        "نام و شواهد آموزشی بررسی شدند",
    );
    await evaluate(
        `document.querySelector('[data-certificate-review] input[type=checkbox]').click()`,
    );
    await submit("[data-certificate-review]");
    await until(`!document.querySelector('[data-certificate-review]')`);
    await login("student");
    await goto(path);
    await until(`!!document.querySelector('[data-issued-certificate]')`);
    const url = await evaluate(
        `document.querySelector('[data-issued-certificate] a').href`,
    );
    assert.equal(
        await evaluate(
            `document.body.innerText.includes('صادرکننده درخواست‌های بعدی')`,
        ),
        false,
    );
    await send("Network.clearBrowserCookies");
    await goto(new URL(url).pathname);
    await until(`!!document.querySelector('[data-certificate-public]')`);
    assert.match(
        await evaluate("document.body.innerText"),
        /وضعیت استعلام: معتبر/,
    );
    assert.equal(
        await evaluate(`document.body.innerText.includes('989121234560')`),
        false,
    );
    assert.equal(await evaluate(`!!document.querySelector('.sidebar')`), false);
    const response = await fetch(url);
    assert.equal(response.headers.get("X-Robots-Tag"), "noindex, noarchive");
    assert.equal(response.headers.get("Referrer-Policy"), "no-referrer");
    await send("Emulation.setEmulatedMedia", { media: "print" });
    const pdf = Buffer.from(
        (
            await send("Page.printToPDF", {
                printBackground: true,
                preferCSSPageSize: true,
            })
        ).data,
        "base64",
    );
    assert.equal(pdf.subarray(0, 5).toString(), "%PDF-");
    assert.ok(pdf.length > 10000);
    assert.equal(
        (pdf.toString("latin1").match(/\/Type\s*\/Page\b/g) || []).length,
        1,
        "Certificate prints on one A4 page",
    );
    await writeFile("/tmp/kardorost-course-certificate.pdf", pdf);
    await send("Emulation.setEmulatedMedia", { media: "" });
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    assert.equal(
        await evaluate(
            "document.documentElement.scrollWidth<=window.innerWidth",
        ),
        true,
    );
    await writeFile(
        "/tmp/kardorost-course-certificate-mobile.png",
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
    await login("admin");
    await goto("/admin/certificates?status=issued");
    await until(`!!document.querySelector('[data-certificate-revoke]')`);
    await evaluate(
        `document.querySelector('[data-certificate-revoke]').closest('details').open=true`,
    );
    await fill("[data-certificate-revoke] textarea", "دلیل داخلی آزمون ابطال");
    await submit("[data-certificate-revoke]");
    await until(`document.body.innerText.includes('گواهی باطل‌شده')`);
    await send("Network.clearBrowserCookies");
    await goto(new URL(url).pathname);
    await until(`document.body.innerText.includes('وضعیت استعلام: باطل‌شده')`);
    assert.equal(
        await evaluate(
            `document.body.innerText.includes('دلیل داخلی آزمون ابطال')`,
        ),
        false,
    );
    assert.equal(
        await evaluate(
            "document.documentElement.scrollWidth<=window.innerWidth",
        ),
        true,
    );
    assert.deepEqual(exceptions, []);
    console.log(
        "PASS: scoped criteria, student lesson completion and consent, frozen pending policy, manual issuance, anonymous verification privacy, printable PDF, revocation and 390px mobile",
    );
} finally {
    socket.close();
}
