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
    await networkIdle();
    await evaluate("document.documentElement.dataset.qaNavigation='previous'");
    await send("Page.navigate", { url: base + path });
    await until(
        `location.pathname===${JSON.stringify(path.split("?")[0])} && !!document.querySelector('main') && document.documentElement.dataset.qaNavigation!=='previous' && document.readyState==='complete'`,
    );
    await networkIdle();
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

function csv(text) {
    const rows = [];
    let row = [],
        cell = "",
        quote = false;
    for (let i = 0; i < text.length; i++) {
        const c = text[i];
        if (quote) {
            if (c === '"' && text[i + 1] === '"') {
                cell += '"';
                i++;
            } else if (c === '"') quote = false;
            else cell += c;
        } else if (c === '"') quote = true;
        else if (c === ",") {
            row.push(cell);
            cell = "";
        } else if (c === "\n") {
            row.push(cell);
            rows.push(row);
            row = [];
            cell = "";
        } else if (c !== "\r") cell += c;
    }
    if (cell || row.length) {
        row.push(cell);
        rows.push(row);
    }
    return rows;
}

const php = process.env.BROWSER_PHP_BINARY;
assert(
    php &&
        new URL(base).port === "8031" &&
        process.env.DB_DATABASE === "/tmp/kardorost-linkedin-browser.sqlite",
    "Use only the owned isolated five-role fixture.",
);
const fixture = JSON.parse(
    readFileSync("/tmp/kardorost-review-history-browser-fixture.json", "utf8"),
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
                    String.raw`$s=\App\Models\Submission::findOrFail(` +
                    fixture.submission_id +
                    String.raw`);echo json_encode(['revision'=>$s->review_version,'reviews'=>$s->reviews()->count(),'score'=>$s->score,'status'=>$s->status]);`,
            ],
            { cwd: process.cwd(), env, encoding: "utf8" },
        ),
    );
}
const actualEvents = Number(
    execFileSync(
        php,
        [
            "-r",
            bootstrap +
                String.raw`echo \App\Models\AuditEvent::where('action','assignment.reviewed')->where('subject_type','submission')->where('subject_id',` +
                fixture.submission_id +
                String.raw`)->count();`,
        ],
        { cwd: process.cwd(), env, encoding: "utf8" },
    ),
);
const before = inspect();
const reuse = process.env.BROWSER_REUSE_REVIEW_HISTORY_FIXTURE === "1";
assert(
    before.revision === 25 ||
        (reuse &&
            actualEvents === 2 &&
            before.revision === 27 &&
            before.reviews === 27 &&
            before.score === 0 &&
            before.status === "reviewed"),
    "Only the explicit owned partial fixture with its two actual review forms may be resumed.",
);

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
async function download(path) {
    return evaluate(
        `(async()=>{const r=await fetch(${JSON.stringify(path)});const b=new Uint8Array(await r.arrayBuffer());return {status:r.status,text:new TextDecoder().decode(b),bom:Array.from(b.slice(0,3)),cache:r.headers.get('cache-control'),robots:r.headers.get('x-robots-tag'),nosniff:r.headers.get('x-content-type-options'),disposition:r.headers.get('content-disposition')};})()`,
    );
}
const memberPath = "/organization/members/" + fixture.member_id;
const exportPath = memberPath + "/learning-export?dataset=reviews";
const adminPath =
    "/admin/organizations/" +
    fixture.organization_id +
    "/members/" +
    fixture.member_id +
    "/learning-export?dataset=reviews";
const special =
    "\u200B=SUM(1,2)\n<script>window.reviewHistoryInjected=true</script>";
try {
    if (!reuse) {
        await login("coach");
        await goto(
            "/teaching/" + fixture.course_id + "?cohort=" + fixture.cohort_id,
        );
        await evaluate(
            "Array.from(document.querySelectorAll('.learning-tabs button')).find(el=>el.textContent.includes('تمرین و بازخورد')).click()",
        );
        await until("!!document.querySelector('.review-card')");
        await evaluate(
            "Array.from(document.querySelectorAll('.review-card button')).find(el=>el.textContent.includes('ویرایش بازخورد')).click()",
        );
        await until("!!document.querySelector('.review-form')");
        await fill(".review-form select", "revision_requested");
        await fill(".review-form textarea", special);
        await submit(".review-form");
        await until(
            "!document.querySelector('.review-form') && document.body.innerText.includes('بازخورد ذخیره شد')",
        );
        assert.deepEqual(inspect(), {
            revision: 26,
            reviews: 26,
            score: null,
            status: "revision_requested",
        });
        assert.equal(await evaluate("!!window.reviewHistoryInjected"), false);
        await evaluate(
            "Array.from(document.querySelectorAll('.review-card button')).find(el=>el.textContent.includes('ویرایش بازخورد')).click()",
        );
        await until("!!document.querySelector('.review-form')");
        await fill(".review-form select", "reviewed");
        await until(
            "!!document.querySelector('.review-form input[type=number]')",
        );
        await fill(".review-form input[type=number]", "0");
        await fill(
            ".review-form textarea",
            "بازخورد نهایی ثبت‌شده از فرم واقعی",
        );
        await submit(".review-form");
        await until("!document.querySelector('.review-form')");
        assert.deepEqual(inspect(), {
            revision: 27,
            reviews: 27,
            score: 0,
            status: "reviewed",
        });
    }
    process.env.BROWSER_ORGANIZATION_PHONE =
        process.env.BROWSER_ORG_ADMIN_PHONE;
    await login("organization");
    await goto("/organization");
    await fill('select[aria-label="انتخاب سازمان"]', fixture.organization_id);
    await until(
        "document.querySelector('main').innerText.includes('سابقه ارزیابی صرفاً آزمایشی')",
    );
    await goto(memberPath);
    await until(
        "!!document.querySelector('[data-testid=member-learning-export-reviews]')",
    );
    assert.equal(
        await evaluate(
            "document.querySelectorAll('.organization-learning-exports a').length",
        ),
        4,
    );
    const href = await evaluate(
        "document.querySelector('[data-testid=member-learning-export-reviews]').getAttribute('href')",
    );
    const result = await download(href);
    assert.equal(result.status, 200);
    assert.deepEqual(result.bom, [239, 187, 191]);
    assert(
        result.cache.includes("no-store") &&
            result.robots.includes("noindex") &&
            result.nosniff === "nosniff" &&
            result.disposition.includes("-reviews.csv"),
    );
    const rows = csv(result.text);
    assert.equal(rows.length, 28);
    assert(rows.every((row) => row.length === 19));
    assert.deepEqual(
        rows.slice(1).map((row) => row[12]),
        Array.from({ length: 27 }, (_, i) => String(i + 1)),
    );
    assert.equal(rows[1][14], "0");
    assert.equal(rows[2][14], "");
    assert.equal(rows[26][14], "");
    assert.equal(rows[27][14], "0");
    assert.equal(rows[26][16], "'" + special.replace(/^\u200B/, ""));
    assert.equal(rows[27][16], "بازخورد نهایی ثبت‌شده از فرم واقعی");
    assert(rows.slice(1).every((row) => row[15] === "80"));
    for (const privateValue of [
        "PRIVATE-REVIEW-HISTORY-SUBMISSION-BODY",
        "PRIVATE-REVIEW-HISTORY-INSTRUCTIONS",
        "989121234560",
        "989121234561",
        "private/file.pdf",
    ])
        assert(!result.text.includes(privateValue));
    assert.equal(await evaluate("!!window.reviewHistoryInjected"), false);
    await responsive("Member history export");
    await login("admin");
    await goto(
        "/admin/organization-managers?organization=" + fixture.organization_id,
    );
    await until(
        `!!document.querySelector('[data-member="${fixture.member_id}"] [data-testid=admin-member-learning-export-reviews]')`,
    );
    const adminHref = await evaluate(
        `document.querySelector('[data-member="${fixture.member_id}"] [data-testid=admin-member-learning-export-reviews]').getAttribute('href')`,
    );
    const admin = await download(adminHref);
    assert.equal(admin.status, 200);
    const adminRows = csv(admin.text);
    assert.equal(adminRows.length, 28);
    assert.deepEqual(
        adminRows.map((r) => r.filter((_, i) => i !== 2)),
        rows.map((r) => r.filter((_, i) => i !== 2)),
    );
    await responsive("Full admin history export");
    await login("coach");
    assert.equal((await download(exportPath)).status, 403);
    assert.equal((await download(adminPath)).status, 403);
    await login("student");
    assert.equal((await download(exportPath)).status, 403);
    assert.equal((await download(adminPath)).status, 403);
    await goto("/organization-memberships");
    await until(
        `!!document.querySelector('[data-membership="${fixture.organization_id}"]')`,
    );
    await evaluate(
        `document.querySelector('[data-membership="${fixture.organization_id}"] details').open=true`,
    );
    await fill(
        `[data-membership="${fixture.organization_id}"] [name=reason]`,
        "خروج صرفاً آزمایشی برای قطع خروجی سابقه ارزیابی",
    );
    await submit(`[data-membership="${fixture.organization_id}"] form`);
    await until(
        `!document.querySelector('[data-membership="${fixture.organization_id}"]')`,
    );
    await login("organization");
    await fill('select[aria-label="انتخاب سازمان"]', fixture.organization_id);
    await until(
        "document.querySelector('main').innerText.includes('سابقه ارزیابی صرفاً آزمایشی')",
    );
    assert.equal((await download(exportPath)).status, 403);
    await login("admin");
    assert.equal((await download(adminPath)).status, 403);
    assert.equal(
        inspect().reviews,
        27,
        "Membership departure preserves recorded assessment history.",
    );
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "two-real-teacher-review-forms-preserve-revisions-26-27",
                "member-and-full-admin-real-history-csv-27-rows-19-columns",
                "original-feedback-zero-null-current-max-private-headers-and-formula-guard",
                "four-export-links-and-320-390-768-1440",
                "teacher-student-export-denial-and-actual-member-departure-revokes-both-routes",
                "history-preserved-no-html-execution",
            ],
        }),
    );
} finally {
    socket.close();
}
