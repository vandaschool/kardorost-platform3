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
    await goto("/admin/assessments");
    await until(`!!document.querySelector('main form input')`);
    await fill("main form input", "پرسشنامه آزمایشی رابط، ابزار واقعی نیست");
    await submit("main form");
    await until(`!!document.querySelector('[name=assessment_title]')`);
    await fill(
        "[name=assessment_description]",
        "محتوای آزمایشی صرفاً برای بررسی نرم‌افزار",
    );
    await fill(
        "[name=assessment_instructions]",
        "برای بررسی رابط یک گزینه انتخاب کنید.",
    );
    await fill(
        "[name=assessment_source]",
        "Software test fixture, not a psychometric instrument",
    );
    await fill("[name=dimension_title_0]", "بعد نمایشی آزمایش");
    await fill("[name=question_text_0]", "پرسش آزمایشی اول");
    await evaluate(
        `Array.from(document.querySelectorAll('button')).find(b=>b.innerText.includes('افزودن پرسش')).click()`,
    );
    await until(`!!document.querySelector('[name=question_text_1]')`);
    await fill("[name=question_text_1]", "پرسش آزمایشی معکوس");
    await evaluate(
        `document.querySelectorAll('fieldset input[type=checkbox]')[1].click()`,
    );
    await submit("main form");
    await until(
        `Array.from(document.querySelectorAll('button')).some(b=>b.innerText==='انتشار نسخه تازه')`,
    );
    assert.equal(
        await evaluate(
            `Array.from(document.querySelectorAll('button')).find(b=>b.innerText==='انتشار نسخه تازه').disabled`,
        ),
        false,
        "Saved draft can be published",
    );
    await evaluate(
        `document.querySelectorAll('main form')[1].querySelector('input[type=checkbox]').click()`,
    );
    await submit("main form:nth-of-type(2)");
    await until(`document.body.innerText.includes('نسخه تازه منتشر شد')`);
    await login("student");
    await goto("/assessments");
    await until(`!!document.querySelector('article form')`);
    await evaluate(
        `document.querySelector('article input[type=checkbox]').click()`,
    );
    await submit("article form");
    await until(
        `document.querySelectorAll('fieldset.assessment-question').length===2`,
    );
    await evaluate(
        `document.querySelector('fieldset input[value="5"]').click()`,
    );
    await submit("main form");
    await until(`document.body.innerText.includes('۱ پاسخ ذخیره‌شده')`);
    await goto("/assessments");
    await until(`document.body.innerText.includes('ادامه پاسخ‌دادن')`);
    await evaluate(`document.querySelector('article a').click()`);
    await until(
        `document.querySelectorAll('fieldset.assessment-question').length===2`,
    );
    assert.equal(
        await evaluate(
            `document.querySelector('fieldset input[value="5"]').checked`,
        ),
        true,
        "Saved answer resumes",
    );
    await evaluate(
        `document.querySelectorAll('fieldset.assessment-question')[1].querySelector('input[value="1"]').click()`,
    );
    await submit("main form");
    await until(
        `Array.from(document.querySelectorAll('button')).some(b=>b.innerText.includes('ثبت نهایی و مشاهده نتیجه'))`,
    );
    assert.equal(
        await evaluate(
            `Array.from(document.querySelectorAll('button')).find(b=>b.innerText.includes('ثبت نهایی و مشاهده نتیجه')).disabled`,
        ),
        false,
        "All saved answers can be finalized",
    );
    await evaluate(`document.querySelectorAll('main form')[1].requestSubmit()`);
    await until(`!!document.querySelector('meter')`);
    assert.equal(
        await evaluate('document.querySelector("meter").value'),
        100,
        "Reversed item yields expected range position",
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
        "/tmp/kardorost-assessment-report-mobile.png",
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
        "PASS: admin authoring/publishing, student partial save/resume, reverse-scored report, mobile layout",
    );
} finally {
    socket.close();
}
