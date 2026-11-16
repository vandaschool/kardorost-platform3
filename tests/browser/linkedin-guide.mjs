import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
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
    await until(
        `location.pathname==='/${role === "org_admin" ? "organization" : role}'`,
    );
}

const php = process.env.BROWSER_PHP_BINARY;
assert(
    php &&
        new URL(base).port === "8031" &&
        process.env.DB_DATABASE === "/tmp/kardorost-linkedin-browser.sqlite",
    "Use only the isolated LinkedIn guide fixture.",
);
const fixture = JSON.parse(
    readFileSync("/tmp/kardorost-linkedin-browser-fixture.json", "utf8"),
);
const env = {
    ...process.env,
    DB_CONNECTION: "sqlite",
    DB_DATABASE: process.env.DB_DATABASE,
    APP_URL: base,
    SMS_DRIVER: "disabled",
    ZIBAL_ENABLED: "false",
};
const bootstrap = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();`;
function inspect() {
    return JSON.parse(
        execFileSync(
            php,
            [
                "-r",
                bootstrap +
                    String.raw`echo json_encode(['resumes'=>\App\Models\CareerResume::count(),'versions'=>\App\Models\ResumeVersion::count(),'profiles'=>\App\Models\CoachProfile::count(),'checks'=>\App\Models\ResumeCheck::count(),'export_audits'=>\App\Models\AuditEvent::where('action','resume.linkedin_exported')->count()]);`,
            ],
            { cwd: process.cwd(), env, encoding: "utf8" },
        ),
    );
}
const before = inspect();
assert(
    before.resumes === 0 ||
        (process.env.BROWSER_REUSE_LINKEDIN_FIXTURE === "1" &&
            before.resumes === 1 &&
            before.versions === 2 &&
            before.profiles === 0 &&
            before.checks === 0),
    "Only the explicit partially completed owned software fixture may be reused.",
);
const headline =
    "<script>window.linkedinInjected=true</script> توسعه‌دهنده PHP";
const summary =
    "شرحِ صرفاً آزمایشی رزومه\n" +
    "اطلاعاتی که خود کاربر وارد کرده است. ".repeat(65);
async function downloaded() {
    return evaluate(
        `(async()=>{const r=await fetch(document.querySelector('[data-linkedin-download]').href);const text=await r.text();return {status:r.status,text,type:r.headers.get('content-type'),disposition:r.headers.get('content-disposition'),cache:r.headers.get('cache-control'),robots:r.headers.get('x-robots-tag'),nosniff:r.headers.get('x-content-type-options')};})()`,
    );
}
async function responsive(label) {
    for (const width of [320, 390, 768, 1440]) {
        await send("Emulation.setDeviceMetricsOverride", {
            width,
            height: 900,
            deviceScaleFactor: 1,
            mobile: width < 768,
        });
        await until(`innerWidth===${width}`);
        assert(
            await evaluate(
                "document.documentElement.scrollWidth<=innerWidth+1",
            ),
            `${label} overflow ${width}`,
        );
    }
}
async function forbidden(path) {
    return evaluate(
        `fetch(${JSON.stringify(path)},{headers:{Accept:'application/json'}}).then(async r=>({status:r.status,private:(await r.text()).includes('linkedinInjected')}))`,
    );
}
try {
    await login("student");
    assert.equal(
        await evaluate(
            "document.querySelector('[data-panel-navigation]').children.length",
        ),
        5,
    );
    await goto("/career/resumes");
    await fill("[name=title]", "راهنمای لینکدین صرفاً آزمایشی");
    await submit("[data-resume-create]");
    await until("!!document.querySelector('[data-resume-draft]')");
    const editor = await evaluate("location.pathname");
    await fill("[name=resume_name]", "نام ثابت نسخه رزومه آزمایشی");
    await fill("[name=headline]", headline);
    await fill("[name=email]", "linkedin-private-contact@example.test");
    await fill("[name=resume_phone]", "09120000099");
    await fill("[name=summary]", summary);
    await evaluate(
        "document.querySelector('[data-add-entry=experience]').click()",
    );
    await fill("[name=experience_title_0]", "نقش ثبت‌شده صاحب");
    await fill("[name=experience_employer_0]", "مجموعه آزمایشی");
    await fill("[name=experience_period_0]", "۱۴۰۱ تا ۱۴۰۲");
    await fill(
        "[name=experience_description_0]",
        "شرح مسئولیت، بدون افزودن ادعای تازه",
    );
    await evaluate(
        "document.querySelector('[data-add-entry=education]').click()",
    );
    await fill("[name=education_institution_0]", "مرکز آموزشی آزمایشی");
    await fill("[name=education_degree_0]", "رشته ثبت‌شده");
    await evaluate(
        "document.querySelector('[data-add-entry=projects]').click()",
    );
    await fill("[name=projects_title_0]", "پروژه آزمایشی");
    await fill("[name=projects_description_0]", "همان شرح ورودی");
    for (const skill of ["PHP", "SQL"]) {
        await fill("[name=skill]", skill);
        await evaluate("document.querySelector('[data-add-skill]').click()");
    }
    await evaluate("document.querySelector('[data-add-entry=links]').click()");
    await fill("[name=links_label_0]", "پیوند خصوصی");
    await fill(
        "[name=links_url_0]",
        "https://example.test/linkedin-private-link",
    );
    await submit("[data-resume-draft]");
    await until("!document.querySelector('[data-resume-snapshot]').disabled");
    await evaluate("document.querySelector('[data-resume-snapshot]').click()");
    await until("!!document.querySelector('[data-linkedin-guide]')");
    const firstVersion = await evaluate("location.pathname");
    await evaluate("document.querySelector('[data-linkedin-guide]').click()");
    await until("!!document.querySelector('[data-linkedin-download]')");
    const firstGuide = await evaluate("location.pathname");
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-linkedin-section]').length",
        ),
        6,
    );
    assert.equal(
        await evaluate("document.querySelector('#linkedin-headline').value"),
        headline,
    );
    assert.equal(
        await evaluate("document.querySelector('#linkedin-about').value"),
        summary.trim(),
    );
    assert.equal(await evaluate("!!window.linkedinInjected"), false);
    await send("Browser.grantPermissions", {
        origin: base,
        permissions: ["clipboardReadWrite", "clipboardSanitizedWrite"],
    });
    await evaluate(
        "document.querySelector('[data-linkedin-copy=headline]').click()",
    );
    await until(
        "document.querySelector('[role=status]')?.textContent.includes('کپی شد')",
    );
    assert.equal(await evaluate("navigator.clipboard.readText()"), headline);
    await evaluate(
        "Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:()=>Promise.reject(new Error('Synthetic unavailable clipboard'))}});document.querySelector('[data-linkedin-copy=about]').click()",
    );
    await until(
        "document.querySelector('[role=alert]')?.textContent.includes('دستی کپی')",
    );
    assert(
        await evaluate(
            "(()=>{const t=document.querySelector('#linkedin-about');return t.selectionStart===0 && t.selectionEnd===t.value.length;})()",
        ),
    );
    await evaluate("delete navigator.clipboard");
    const first = await downloaded();
    assert.equal(first.status, 200);
    assert.equal(first.type, "text/plain; charset=UTF-8");
    assert(
        first.cache.includes("no-store") &&
            first.disposition.includes("attachment;") &&
            first.robots.includes("noindex") &&
            first.nosniff === "nosniff",
    );
    assert(
        first.text.includes(headline) &&
            first.text.includes(summary.trim()) &&
            first.text.includes("PHP\nSQL"),
    );
    for (const privateField of [
        "linkedin-private-contact@example.test",
        "09120000099",
        "linkedin-private-link",
    ])
        assert(!first.text.includes(privateField));
    await responsive("Persian LinkedIn guide");
    await goto(editor);
    await fill("[name=locale]", "en");
    await fill("[name=resume_name]", "Second saved resume name");
    await fill("[name=headline]", "Software Developer");
    await fill(
        "[name=summary]",
        "Second saved source summary. No translation requested.",
    );
    await submit("[data-resume-draft]");
    await until("!document.querySelector('[data-resume-snapshot]').disabled");
    await evaluate("document.querySelector('[data-resume-snapshot]').click()");
    await until("!!document.querySelector('[data-linkedin-guide]')");
    assert.notEqual(await evaluate("location.pathname"), firstVersion);
    await evaluate("document.querySelector('[data-linkedin-guide]').click()");
    await until("!!document.querySelector('#linkedin-headline')");
    assert.equal(
        await evaluate("document.querySelector('#linkedin-headline').dir"),
        "ltr",
    );
    assert.equal(
        await evaluate("document.querySelector('#linkedin-headline').value"),
        "Software Developer",
    );
    assert((await downloaded()).text.includes("LinkedIn profile draft"));
    assert(
        (await downloaded()).text.includes("نقش ثبت‌شده صاحب"),
        "Source experience is retained in its entered language.",
    );
    await responsive("English LinkedIn guide");
    await goto(firstGuide);
    assert.equal(
        (await downloaded()).text,
        first.text,
        "New saved snapshot does not rewrite the prior LinkedIn export.",
    );
    await login("admin");
    await goto("/career/resumes?view=admin");
    assert(
        await evaluate(
            "document.body.innerText.includes('راهنمای لینکدین صرفاً آزمایشی')",
        ),
    );
    await goto(firstGuide);
    assert.equal((await downloaded()).text, first.text);
    await responsive("Full admin LinkedIn guide");
    await login("coach");
    assert.deepEqual(await forbidden(firstGuide), {
        status: 403,
        private: false,
    });
    assert.deepEqual(await forbidden(firstGuide + "/download"), {
        status: 403,
        private: false,
    });
    await login("org_admin");
    assert.deepEqual(await forbidden(firstGuide), {
        status: 403,
        private: false,
    });
    assert.deepEqual(await forbidden(firstGuide + "/download"), {
        status: 403,
        private: false,
    });
    process.env.BROWSER_STUDENT_PHONE = "09121234564";
    await login("student");
    assert.deepEqual(await forbidden(firstGuide), {
        status: 403,
        private: false,
    });
    assert.deepEqual(await forbidden(firstGuide + "/download"), {
        status: 403,
        private: false,
    });
    process.env.BROWSER_STUDENT_PHONE = "09121234560";
    await login("student");
    await goto("/career/resumes");
    await fill("[name=title]", "رزومه خالی صرفاً آزمایشی");
    await submit("[data-resume-create]");
    await until("!!document.querySelector('[data-resume-snapshot]')");
    await evaluate("document.querySelector('[data-resume-snapshot]').click()");
    await until("!!document.querySelector('[data-linkedin-guide]')");
    await evaluate("document.querySelector('[data-linkedin-guide]').click()");
    await until("!!document.querySelector('[data-linkedin-empty]')");
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-linkedin-section]').length",
        ),
        0,
    );
    assert.equal(
        await evaluate(
            "document.querySelectorAll('[data-linkedin-check]').length",
        ),
        6,
    );
    await responsive("Empty LinkedIn guide");
    assert.deepEqual(inspect(), {
        resumes: before.resumes + 2,
        versions: before.versions + 3,
        profiles: 0,
        checks: 0,
        export_audits: before.export_audits + 5,
    });
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "actual-resume-editor-save-two-frozen-versions-and-guide-navigation",
                "six-sections-exact-source-long-unicode-and-no-html-execution",
                "actual-clipboard-copy-and-manual-selection-fallback",
                "real-private-text-download-with-contact-field-omission",
                "english-direction-no-translation-and-unchanged-earlier-export",
                "full-admin-access-current-teacher-same-org-manager-peer-isolation",
                "empty-guide-no-fabricated-sections-or-publication",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    socket.close();
}
