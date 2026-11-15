import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
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
    if (m.method === "Runtime.consoleAPICalled" && m.params.type === "error")
        exceptions.push(
            m.params.args.map((arg) => arg.description || arg.value).join(" "),
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
    assert.equal(
        await evaluate(
            "document.querySelector('[data-panel-navigation]').children.length",
        ),
        5,
        "Five main student sections",
    );
    await evaluate(
        "Array.from(document.querySelectorAll('.nav-group summary')).find(el=>el.textContent.includes('خودشناسی')).click()",
    );
    await evaluate(
        "document.querySelector('a[href=\"/career/resumes\"]').click()",
    );
    await until(
        "location.pathname === '/career/resumes' && !!document.querySelector('[data-resume-create]')",
    );
    await fill("[name=title]", "رزومه خصوصی مرورگر");
    await submit("[data-resume-create]");
    await until("!!document.querySelector('[data-resume-draft]')");
    const editorPath = await evaluate("location.pathname");
    await fill("[name=resume_name]", "آزمایش رزومه مرورگر");
    await fill("[name=headline]", "توسعه‌دهنده Laravel");
    await fill("[name=email]", "browser@example.test");
    await fill("[name=resume_phone]", "09120000000");
    await fill("[name=city]", "تهران");
    await fill(
        "[name=summary]",
        "توسعه برنامه‌های Laravel و گزارش‌های SQL برای پروژه‌های آزمایشی.",
    );
    await evaluate(
        "document.querySelector('[data-add-entry=experience]').click()",
    );
    await fill("[name=experience_employer_0]", "مجموعه آزمایشی");
    await fill("[name=experience_title_0]", "توسعه‌دهنده");
    await fill("[name=experience_period_0]", "۱۴۰۱ تا اکنون");
    await fill(
        "[name=experience_description_0]",
        "پیاده‌سازی برنامه و گزارش داده.",
    );
    await evaluate(
        "document.querySelector('[data-add-entry=education]').click()",
    );
    await fill("[name=education_institution_0]", "دانشگاه آزمایشی");
    await fill("[name=education_degree_0]", "مهندسی کامپیوتر");
    await fill("[name=education_period_0]", "۱۳۹۷ تا ۱۴۰۱");
    for (const skill of ["Laravel", "SQL", "JavaScript"]) {
        await fill("[name=skill]", skill);
        await evaluate("document.querySelector('[data-add-skill]').click()");
    }
    await evaluate("document.querySelector('[data-add-entry=links]').click()");
    await fill("[name=links_label_0]", "نمونه کار");
    await fill("[name=links_url_0]", "https://example.test/portfolio");
    await submit("[data-resume-draft]");
    await until("!document.querySelector('[data-resume-snapshot]').disabled");
    await evaluate("document.querySelector('[data-resume-snapshot]').click()");
    await until("!!document.querySelector('[data-resume-print]')");
    const firstPath = await evaluate("location.pathname");
    const firstPrint = await evaluate(
        "document.querySelector('[data-resume-print]').getAttribute('href')",
    );
    await goto(firstPrint);
    assert.equal(await evaluate("document.documentElement.dir"), "rtl");
    assert(
        await evaluate(
            "document.body.innerText.includes('آزمایش رزومه مرورگر')",
        ),
    );
    assert(
        !(await evaluate(
            "document.body.innerText.includes('رزومه خصوصی مرورگر')",
        )),
    );
    let pdf = await send("Page.printToPDF", {
        printBackground: true,
        preferCSSPageSize: true,
        displayHeaderFooter: false,
    });
    await writeFile(
        "/tmp/kardorost-resume-fa.pdf",
        Buffer.from(pdf.data, "base64"),
    );
    await goto(firstPath);
    await fill("[name=job_text]", "Laravel SQL Python Java developer");
    await submit("[data-resume-check]");
    await until(
        "location.pathname.startsWith('/career/checks/') && !!document.querySelector('[data-matched-terms]')",
    );
    assert(
        await evaluate(
            "document.querySelector('[data-matched-terms]').textContent.includes('laravel')",
        ),
    );
    assert(
        await evaluate(
            "document.querySelector('[data-missing-terms]').textContent.includes('python')",
        ),
    );
    assert(
        await evaluate(
            "document.querySelector('[data-missing-terms]').textContent.includes('java')",
        ),
    );
    await goto(editorPath);
    await fill("[name=locale]", "en");
    await fill("[name=resume_name]", "Browser Resume Person");
    await fill("[name=headline]", "Software Developer");
    await fill("[name=city]", "Tehran");
    await fill("[name=summary]", "Build Laravel applications and SQL reports.");
    await fill("[name=experience_employer_0]", "Example Organization");
    await fill("[name=experience_title_0]", "Software Developer");
    await fill("[name=experience_period_0]", "2021 - Present");
    await fill(
        "[name=experience_description_0]",
        "Delivered private learning tools and reporting workflows.",
    );
    await fill("[name=education_institution_0]", "Example University");
    await fill("[name=education_degree_0]", "Computer Science");
    await fill("[name=education_period_0]", "2017 - 2021");
    await fill("[name=links_label_0]", "Portfolio");
    await submit("[data-resume-draft]");
    await until("!document.querySelector('[data-resume-snapshot]').disabled");
    await goto(firstPrint);
    assert(
        await evaluate(
            "document.body.innerText.includes('آزمایش رزومه مرورگر')",
        ),
    );
    assert(
        !(await evaluate(
            "document.body.innerText.includes('Browser Resume Person')",
        )),
    );
    await goto(editorPath);
    await evaluate("document.querySelector('[data-resume-snapshot]').click()");
    await until("!!document.querySelector('[data-resume-print]')");
    const englishPrint = await evaluate(
        "document.querySelector('[data-resume-print]').getAttribute('href')",
    );
    assert.notEqual(englishPrint, firstPrint);
    await goto(englishPrint);
    assert.equal(await evaluate("document.documentElement.dir"), "ltr");
    assert(
        await evaluate("document.body.innerText.includes('Computer Science')"),
    );
    pdf = await send("Page.printToPDF", {
        printBackground: true,
        preferCSSPageSize: true,
        displayHeaderFooter: false,
    });
    await writeFile(
        "/tmp/kardorost-resume-en.pdf",
        Buffer.from(pdf.data, "base64"),
    );
    const englishText = execFileSync(
        "pdftotext",
        ["/tmp/kardorost-resume-en.pdf", "-"],
        { encoding: "utf8" },
    );
    for (const text of [
        "Profile",
        "Experience",
        "Education",
        "Skills",
        "Computer Science",
        "Browser Resume Person",
        "Example Organization",
    ])
        assert(englishText.includes(text), "Extractable PDF text: " + text);
    for (const file of [
        "/tmp/kardorost-resume-en.pdf",
        "/tmp/kardorost-resume-fa.pdf",
    ]) {
        const info = execFileSync("pdfinfo", [file], { encoding: "utf8" });
        assert(/Pages:\s+1/.test(info), "Short fixture fits one page");
        assert(info.includes("(A4)"), "A4 document");
    }
    await goto(editorPath);
    await fill("[name=summary]", "Detailed engineering resume. ".repeat(80));
    await fill(
        "[name=experience_description_0]",
        "Delivered independently testable components. ".repeat(60) +
            "FINAL DELIVERY MARKER",
    );
    await submit("[data-resume-draft]");
    await until("!document.querySelector('[data-resume-snapshot]').disabled");
    await evaluate("document.querySelector('[data-resume-snapshot]').click()");
    await until("!!document.querySelector('[data-resume-print]')");
    const longPrint = await evaluate(
        "document.querySelector('[data-resume-print]').getAttribute('href')",
    );
    await goto(longPrint);
    pdf = await send("Page.printToPDF", {
        printBackground: true,
        preferCSSPageSize: true,
        displayHeaderFooter: false,
    });
    await writeFile(
        "/tmp/kardorost-resume-long.pdf",
        Buffer.from(pdf.data, "base64"),
    );
    const longText = execFileSync(
        "pdftotext",
        ["/tmp/kardorost-resume-long.pdf", "-"],
        { encoding: "utf8" },
    );
    assert(
        longText.replace(/\s+/g, " ").includes("FINAL DELIVERY MARKER"),
        "Long experience survives page breaks",
    );
    assert(
        longText.includes("Portfolio"),
        "Content after long experience survives",
    );
    const longInfo = execFileSync(
        "pdfinfo",
        ["/tmp/kardorost-resume-long.pdf"],
        { encoding: "utf8" },
    );
    assert(
        Number(longInfo.match(/Pages:\s+(\d+)/)[1]) >= 2,
        "Long fixture spans pages",
    );
    await goto(editorPath);
    await evaluate(
        "Array.from(document.querySelectorAll('button')).find(el=>el.textContent.trim()==='بایگانی رزومه').click()",
    );
    await until(
        "document.querySelector('[data-resume-draft] fieldset').disabled",
    );
    await evaluate(
        "Array.from(document.querySelectorAll('button')).find(el=>el.textContent.trim()==='بازیابی رزومه').click()",
    );
    await until(
        "!document.querySelector('[data-resume-draft] fieldset').disabled",
    );
    await evaluate(
        "Array.from(document.querySelectorAll('.nav-group summary')).find(el=>el.textContent.includes('حساب من')).click()",
    );
    await evaluate("document.querySelector('a[href=\"/finance\"]').click()");
    await until("location.pathname === '/finance'");
    await goto(editorPath);
    assert(
        await evaluate(
            "document.querySelector('a[href=\"/career/resumes\"]').closest('details').open",
        ),
    );
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await evaluate("document.querySelector('.mobile-toggle').click()");
    await until(
        "document.querySelector('.sidebar').getBoundingClientRect().right <= innerWidth",
    );
    assert(
        await evaluate(
            "(()=>{const rect=document.querySelector('.sidebar-bottom a[href=\"/support\"]').getBoundingClientRect();return rect.top>=0 && rect.bottom<=innerHeight;})()",
        ),
        "Support remains visible",
    );
    await send("Input.dispatchKeyEvent", {
        type: "keyDown",
        key: "Escape",
        code: "Escape",
    });
    await until(
        "document.querySelector('.sidebar').getBoundingClientRect().left >= innerWidth",
    );
    assert(await evaluate("document.documentElement.scrollWidth<=innerWidth"));
    const shot = await send("Page.captureScreenshot", { format: "png" });
    await writeFile(
        "/tmp/kardorost-career-mobile.png",
        Buffer.from(shot.data, "base64"),
    );
    await send("Emulation.clearDeviceMetricsOverride");
    await login("admin");
    assert.equal(
        await evaluate(
            "document.querySelector('[data-panel-navigation]').children.length",
        ),
        8,
        "Grouped admin navigation",
    );
    await goto("/career/resumes?view=admin");
    assert(
        await evaluate(
            "document.body.innerText.includes('رزومه خصوصی مرورگر')",
        ),
    );
    await fill("[name=phone]", process.env.BROWSER_STUDENT_PHONE);
    await submit("form:has([name=phone])");
    await until("location.search.includes('phone=')");
    assert(
        await evaluate(
            "document.body.innerText.includes('رزومه خصوصی مرورگر')",
        ),
    );
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            private_draft: true,
            structured_entries: true,
            immutable_fa_en_snapshots: true,
            keyword_review: true,
            archive_restore: true,
            five_student_sections: true,
            grouped_admin_navigation: true,
            support_visible_mobile: true,
            mobile390: true,
            multi_page_pdf: true,
        }),
    );
} finally {
    socket.close();
}
