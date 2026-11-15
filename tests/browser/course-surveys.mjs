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
    process.env.BROWSER_PEER_PHONE && process.env.BROWSER_THIRD_PHONE,
    "Provide three independent active cohort students.",
);
try {
    await login("admin");
    await goto("/admin/surveys");
    await evaluate(`document.querySelector('details').open=true`);
    const cohortId = await evaluate(
        `document.querySelector('[name=survey_cohort] option:not([disabled])').value`,
    );
    await fill("[name=survey_cohort]", cohortId);
    await fill("[name=survey_title]", "نظرسنجی تجربه دوره مرورگر");
    await submit("[data-survey-create]");
    await until(`!!document.querySelector('[data-survey-draft]')`);
    const editorPath = await evaluate("location.pathname");
    await fill(
        "[name=survey_description]",
        "بازخورد شما برای بهبود دوره استفاده می‌شود.",
    );
    await fill("[name=survey_mode]", "aggregate");
    await fill("[name=survey_minimum]", "3");
    await evaluate(
        `document.querySelector('[data-add-survey-question]').click()`,
    );
    await fill(
        '[data-survey-question="0"] input[type=text], [data-survey-question="0"] label input:not([type=checkbox])',
        "کیفیت دوره چطور بود؟",
    );
    await evaluate(
        `document.querySelector('[data-add-survey-question]').click()`,
    );
    await fill(
        '[data-survey-question="1"] label input:not([type=checkbox])',
        "پیشنهاد شما برای بهبود چیست؟",
    );
    await fill('[data-survey-question="1"] select', "text");
    await evaluate(
        `document.querySelector('[data-survey-question="1"] input[type=checkbox]').click()`,
    );
    await submit("[data-survey-draft]");
    await until(`document.body.innerText.includes('پیش‌نویس ذخیره شد')`);
    await goto(editorPath);
    await until(
        `document.querySelectorAll('[data-survey-question]').length===2`,
    );
    await evaluate(
        `document.querySelector('[data-survey-publish] input[type=checkbox]').click()`,
    );
    await submit("[data-survey-publish]");
    await until(
        `document.body.innerText.includes('نسخه ثابت نظرسنجی منتشر شد')`,
    );
    const reportPath = await evaluate(
        `document.querySelector('a[href$="/report"]').getAttribute('href')`,
    );
    await login("student");
    await goto("/surveys");
    await until(`!!document.querySelector('[data-survey-offer]')`);
    assert.match(
        await evaluate("document.body.innerText"),
        /نام شما در گزارش نمایش داده نمی‌شود/,
    );
    await evaluate(
        `document.querySelector('[data-survey-offer] button').click()`,
    );
    await until(`!!document.querySelector('[data-survey-response]')`);
    const responsePath = await evaluate("location.pathname");
    await fill("[data-survey-response] select", "4");
    await fill(
        "[data-survey-response] textarea",
        "پیشنهاد اول برای بهبود آموزش",
    );
    await evaluate(
        `Array.from(document.querySelectorAll('[data-survey-response] button')).find(button=>button.innerText==='ذخیره و ادامه بعداً').click()`,
    );
    await until(`document.body.innerText.includes('پاسخ نیمه‌کاره ذخیره شد')`);
    await goto(responsePath);
    assert.equal(
        await evaluate(
            `document.querySelector('[data-survey-response] select').value`,
        ),
        "4",
    );
    await submit("[data-survey-response]");
    await until(`document.body.innerText.includes('پاسخ نهایی ثبت شده است')`);
    await login("coach");
    await goto(reportPath);
    await until(`document.body.innerText.includes('هنوز حداقل')`);
    assert.equal(
        await evaluate(
            `document.body.innerText.includes('پیشنهاد اول برای بهبود آموزش')`,
        ),
        false,
    );
    const originalPhone = process.env.BROWSER_STUDENT_PHONE;
    for (const [phone, rating, comment] of [
        [process.env.BROWSER_PEER_PHONE, "2", "پیشنهاد دوم برای آموزش"],
        [process.env.BROWSER_THIRD_PHONE, "3", "پیشنهاد سوم برای آموزش"],
    ]) {
        process.env.BROWSER_STUDENT_PHONE = phone;
        await login("student");
        await goto("/surveys");
        await evaluate(
            `document.querySelector('[data-survey-offer] button').click()`,
        );
        await until(`!!document.querySelector('[data-survey-response]')`);
        await fill("[data-survey-response] select", rating);
        await fill("[data-survey-response] textarea", comment);
        await submit("[data-survey-response]");
        await until(
            `document.body.innerText.includes('پاسخ نهایی ثبت شده است')`,
        );
    }
    process.env.BROWSER_STUDENT_PHONE = originalPhone;
    await login("coach");
    await goto(reportPath);
    await until(`!!document.querySelector('[data-survey-stat] progress')`);
    assert.match(await evaluate("document.body.innerText"), /میانگین: ۳ از ۵/);
    assert.equal(
        await evaluate(
            `document.body.innerText.includes('بررسی مرورگر student') || document.body.innerText.includes('عضو دوم مرورگر') || document.body.innerText.includes('عضو سوم مرورگر')`,
        ),
        false,
    );
    assert.match(
        await evaluate("document.body.innerText"),
        /پیشنهاد اول برای بهبود آموزش/,
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
        "report mobile width",
    );
    await writeFile(
        "/tmp/kardorost-course-survey-mobile.png",
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
    await goto(editorPath);
    assert.equal(
        await evaluate(
            "document.documentElement.scrollWidth<=window.innerWidth",
        ),
        true,
        "editor mobile width",
    );
    await login("student");
    await goto("/surveys");
    assert.equal(
        await evaluate(
            "document.documentElement.scrollWidth<=window.innerWidth",
        ),
        true,
        "catalog mobile width",
    );
    await goto(responsePath);
    assert.equal(
        await evaluate(
            `Array.from(document.querySelectorAll('[data-survey-response] select, [data-survey-response] textarea')).every(el=>el.disabled)`,
        ),
        true,
    );
    assert.deepEqual(exceptions, []);
    console.log(
        "PASS: admin survey publication, saved draft resume, immutable final response, three student threshold, assigned-coach aggregate report without names, and mobile editor/report/catalog",
    );
} finally {
    socket.close();
}
