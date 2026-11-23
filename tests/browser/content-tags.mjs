import { execFileSync } from "node:child_process";
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
assert(
    new URL(base).port === "8041" &&
        process.env.DB_DATABASE ===
            "/tmp/kardorost-content-tags-browser.sqlite" &&
        process.env.BROWSER_PHP_BINARY,
    "Owned four-user article-tags fixture only",
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
let loginRetryAfter = 0;
const externalRequests = [];
socket.addEventListener("message", (event) => {
    const m = JSON.parse(event.data);
    if (
        m.method === "Network.requestWillBeSent" &&
        /^https?:/.test(m.params.request.url) &&
        new URL(m.params.request.url).origin !== new URL(base).origin
    )
        externalRequests.push(m.params.request.url);
    if (
        m.method === "Network.responseReceived" &&
        m.params.response.status === 429 &&
        new URL(m.params.response.url).pathname === "/login"
    ) {
        const entry = Object.entries(m.params.response.headers).find(
            ([name]) => name.toLowerCase() === "retry-after",
        );
        const seconds = Number(entry?.[1]);
        if (Number.isInteger(seconds) && seconds > 0 && seconds <= 60)
            loginRetryAfter = seconds;
    }

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
    lease();
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
async function login(
    role,
    phone = process.env["BROWSER_" + role.toUpperCase() + "_PHONE"],
) {
    for (let attempt = 0; attempt < 3; attempt++) {
        await networkIdle();
        await send("Page.navigate", { url: "about:blank" });
        await until("location.href==='about:blank'");
        await send("Network.clearBrowserCookies");
        await send("Page.navigate", { url: base + "/login" });
        await until("!!document.querySelector('input[name=password]')");
        await networkIdle();
        await fill("[name=phone]", phone);
        await fill("[name=password]", password);
        loginRetryAfter = 0;
        await submit("form");
        for (let index = 0; index < 100; index++) {
            if (await evaluate(`location.pathname==='/${role}'`)) return;
            if (loginRetryAfter > 0) break;
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
        assert(
            loginRetryAfter > 0,
            "Fixture login must either finish or return an actual bounded Retry-After.",
        );
        await networkIdle();
        console.log(
            "Honoring actual login Retry-After: " +
                loginRetryAfter +
                " seconds.",
        );
        const deadline = Date.now() + loginRetryAfter * 1000 + 250;
        while (Date.now() < deadline)
            await new Promise((resolve) =>
                setTimeout(resolve, Math.min(20000, deadline - Date.now())),
            );
    }
    throw new Error(
        "Fixture login remained rate limited after three bounded attempts.",
    );
}

function lease() {
    assert(
        process.env.KARDOROST_LEASE_OWNER,
        "Supply the active delivery lease owner.",
    );
    execFileSync(
        "python3",
        [
            "/workspace/work/kardorost-autonomy-lease.py",
            "touch",
            process.env.KARDOROST_LEASE_OWNER,
        ],
        { stdio: "pipe" },
    );
}

async function click(selector) {
    lease();
    await evaluate(
        `document.querySelector(${JSON.stringify(selector)}).click()`,
    );
    await networkIdle();
}
async function status(path) {
    return evaluate(
        `(async()=>{const r=await fetch(${JSON.stringify(path)});await r.arrayBuffer();return r.status})()`,
    );
}
async function responsive() {
    for (const width of [320, 390, 768, 1440]) {
        await send("Emulation.setDeviceMetricsOverride", {
            width,
            height: 900,
            deviceScaleFactor: 1,
            mobile: width < 768,
        });
        await until(`innerWidth===${width}`);
        await evaluate(
            "new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)))",
        );
        assert(
            await evaluate(
                "document.documentElement.scrollWidth<=innerWidth+1",
            ),
            "No overflow " + width,
        );
    }
}
async function screenshot(path) {
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 900,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await evaluate(
        "new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)))",
    );
    await until(
        "!document.querySelector('.sidebar') || document.querySelector('.sidebar').getBoundingClientRect().left>=innerWidth-1",
    );
    lease();
    await writeFile(
        path,
        Buffer.from(
            (
                await send("Page.captureScreenshot", {
                    format: "png",
                    captureBeyondViewport: false,
                })
            ).data,
            "base64",
        ),
    );
}

const firstTag = "برچسب آزمایشی۸۵",
    secondTag = "برچسب انتشار دوم۸۵",
    scheduledTag = "پیش‌نویس خصوصی۸۵",
    finalTag = "برچسب خصوصی پایانی۸۵",
    otherTag = "برچسب دیگر۸۵",
    literal = "<img src=x onerror=window.tagXss=1>";
const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(\App\Models\User::count()!==4||\App\Models\User::where('name','like','بررسی برچسب صرفاً آزمایشی%')->count()!==4||\App\Models\Payment::count()||\App\Models\Enrollment::count()||\App\Models\ContentPage::count()>2||\App\Models\ContentPage::whereNotIn('slug',['software-tags85','software-other-tags85'])->exists()){throw new RuntimeException('Owned article-tags fixture only; preserve unrelated data.');}$hash=fn($v)=>hash('sha256',json_encode($v->getAttributes(),JSON_THROW_ON_ERROR));$pages=\App\Models\ContentPage::orderBy('id')->get()->mapWithKeys(fn($p)=>[$p->slug=>['id'=>$p->id,'revision'=>$p->revision,'draft'=>$p->draft,'available'=>$p->available,'hash'=>$hash($p),'publications'=>$p->publications()->orderBy('number')->get()->map(fn($v)=>['number'=>$v->number,'definition'=>$v->definition,'hash'=>$hash($v)])->all(),'schedules'=>$p->schedules()->orderBy('id')->get()->map(fn($v)=>['status'=>$v->status,'definition'=>$v->definition,'hash'=>$hash($v)])->all()]]);$audit=\App\Models\AuditEvent::where('subject_type','content_page')->orderBy('id')->get()->map($hash)->all();echo json_encode(['pages'=>$pages,'audit_hash'=>hash('sha256',json_encode($audit,JSON_THROW_ON_ERROR)),'financial_rows'=>[\App\Models\Payment::count(),\App\Models\Enrollment::count()]],JSON_THROW_ON_ERROR);`;
function state() {
    return JSON.parse(
        execFileSync(process.env.BROWSER_PHP_BINARY, ["-r", code], {
            encoding: "utf8",
            env: {
                ...process.env,
                DB_CONNECTION: "sqlite",
                SMS_DRIVER: "disabled",
                ZIBAL_ENABLED: "false",
            },
        }),
    );
}
function main() {
    return state().pages["software-tags85"];
}
function complete(s) {
    const p = s.pages["software-tags85"];
    return (
        p?.publications.length === 3 &&
        JSON.stringify(p.draft.tags) === JSON.stringify([finalTag]) &&
        p.schedules.length === 1 &&
        p.schedules[0].status === "cancelled" &&
        s.pages["software-other-tags85"]?.publications.length === 1
    );
}
async function addTag(value) {
    const n = await evaluate(
        "document.querySelectorAll('[data-content-tag-row]').length",
    );
    await click("[data-add-content-tag]");
    await fill(`[name=content_tag_${n}]`, value);
}
async function clearTags() {
    while (
        await evaluate(
            "document.querySelectorAll('[data-content-tag-row]').length>0",
        )
    )
        await click('[data-remove-content-tag="0"]');
}
async function editor() {
    await goto("/admin/content/" + main().id);
}
async function save() {
    await submit("[data-content-draft]");
    await networkIdle();
    await editor();
}
async function publish() {
    await editor();
    await click("[data-content-publish]");
    await networkIdle();
    await editor();
}
async function create(slug, title, tags) {
    await goto("/admin/content");
    await evaluate(
        "document.querySelector('[data-content-create]').closest('details').open=true",
    );
    for (const [name, value] of Object.entries({
        slug,
        title,
        excerpt: "این نوشته فقط برای بررسی برچسب‌های نرم‌افزار است.",
        body: "متن عمومی صرفاً آزمایشی، بدون شخص واقعی۸۵.",
    }))
        await fill("[name=" + name + "]", value);
    for (const tag of tags) await addTag(tag);
    await submit("[data-content-create]");
    await networkIdle();
    await until(
        "location.pathname.startsWith('/admin/content/') && !!document.querySelector('[data-content-draft]')",
    );
}
async function publicTags() {
    await goto("/journal/software-tags85");
    await responsive();
    return evaluate(
        "Array.from(document.querySelectorAll('[data-public-article-tags] a')).map(a=>a.textContent)",
    );
}
async function schedule() {
    await editor();
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat("en-US-u-ca-persian", {
            year: "numeric",
            month: "numeric",
            day: "numeric",
            timeZone: "Asia/Tehran",
        })
            .formatToParts(new Date(Date.now() + 2 * 86400000))
            .map((p) => [p.type, p.value]),
    );
    for (const [prefix, key] of [
        ["سال", "year"],
        ["ماه", "month"],
        ["روز", "day"],
    ])
        await fill(
            `select[aria-label="${prefix} انتشار محتوا"]`,
            String(Number(parts[key])),
        );
    await fill("[name=content_schedule_time]", "13:00");
    await click("[name=content_schedule_reviewed]");
    await submit("[data-content-schedule]");
    await networkIdle();
    await editor();
}
try {
    lease();
    const initial = state();
    await login("admin");
    if (!main())
        await create("software-tags85", "مقاله برچسب صرفاً آزمایشی۸۵", [
            firstTag,
            literal,
        ]);
    if (!main().publications.length) await publish();
    if (!state().pages["software-other-tags85"]) {
        await create("software-other-tags85", "مقاله دیگر صرفاً آزمایشی۸۵", [
            otherTag,
        ]);
    }
    if (!state().pages["software-other-tags85"].publications.length) {
        await goto(
            "/admin/content/" + state().pages["software-other-tags85"].id,
        );
        await click("[data-content-publish]");
        await networkIdle();
    }
    if (main().publications.length === 1) {
        assert.deepEqual(await publicTags(), [firstTag, literal]);
        assert(
            await evaluate(
                "!window.tagXss && !document.querySelector('[data-public-article-tags] img')",
            ),
        );
        await goto("/journal");
        await fill("[name=tag]", literal);
        await evaluate(
            "document.documentElement.dataset.qaNavigation='previous'",
        );
        await submit("form.public-filter");
        await until(
            "location.pathname==='/journal' && location.search.includes('tag=') && document.documentElement.dataset.qaNavigation!=='previous' && document.readyState==='complete'",
        );
        assert(
            await evaluate(
                "!!document.querySelector('a[href=\"/journal/software-tags85\"]') && !document.querySelector('a[href=\"/journal/software-other-tags85\"]')",
            ),
        );
        await responsive();
        await screenshot("/tmp/kardorost-tags85-public-mobile.png");
        if (!main().schedules.length) {
            await editor();
            for (let n = 2; n < 8; n++) await click("[data-add-content-tag]");
            assert(
                await evaluate(
                    "document.querySelector('[data-add-content-tag]').disabled",
                ),
            );
            for (let n = 7; n >= 2; n--)
                await click(`[data-remove-content-tag="${n}"]`);
            const before = state();
            await addTag(" " + main().draft.tags[0] + " ");
            await evaluate(
                "document.querySelector('[data-content-draft]').noValidate=true",
            );
            await submit("[data-content-draft]");
            await networkIdle();
            await until("!!document.querySelector('.field-error')");
            assert.deepEqual(state(), before);
            await click('[data-remove-content-tag="2"]');
            await evaluate(
                "document.querySelector('[data-content-draft]').noValidate=false",
            );
            await fill("[name=content_tag_0]", scheduledTag);
            await save();
            assert.deepEqual(await publicTags(), [firstTag, literal]);
            await schedule();
            assert.equal(main().schedules[0].status, "pending");
            assert.deepEqual(await publicTags(), [firstTag, literal]);
        }
        if (
            main().schedules[0].status === "pending" ||
            main().draft.tags?.[0] !== secondTag
        ) {
            await editor();
            await fill("[name=content_tag_0]", secondTag);
            await save();
        }
        assert.equal(main().schedules[0].status, "cancelled");
        assert.equal(main().schedules[0].definition.tags[0], scheduledTag);
        await publish();
        assert.deepEqual(await publicTags(), [secondTag, literal]);
    }
    const firstTwo = main().publications.slice(0, 2);
    if (main().publications.length === 2) {
        await editor();
        await clearTags();
        await save();
        await publish();
    }
    assert.equal(main().publications.length, 3);
    assert(!("tags" in main().publications[2].definition));
    assert.deepEqual(main().publications.slice(0, 2), firstTwo);
    if (JSON.stringify(main().draft.tags) !== JSON.stringify([finalTag])) {
        await editor();
        await addTag(finalTag);
        await save();
    }
    assert.deepEqual(await publicTags(), []);
    await goto("/journal");
    assert(
        await evaluate(
            "!Array.from(document.querySelector('[name=tag]').options).some(o=>[" +
                JSON.stringify(firstTag) +
                "," +
                JSON.stringify(secondTag) +
                "," +
                JSON.stringify(finalTag) +
                "].includes(o.value))",
        ),
    );
    for (const role of ["student", "coach", "organization"]) {
        await login(role);
        assert.equal(await status("/admin/content"), 403);
        await goto("/journal");
        await responsive();
    }
    await login("admin");
    await editor();
    assert(
        await evaluate(
            "Array.from(document.querySelectorAll('[data-content-tags-preview]')).some(e=>e.textContent.includes(" +
                JSON.stringify(finalTag) +
                "))",
        ),
    );
    assert(
        await evaluate(
            "!window.tagXss && !document.querySelector('[data-content-tags-preview] img')",
        ),
    );
    await responsive();
    await evaluate(
        "document.querySelector('[data-content-tags-editor]').scrollIntoView({block:'center'})",
    );
    await screenshot("/tmp/kardorost-tags85-editor-mobile.png");
    const final = state();
    assert(complete(final));
    assert.deepEqual(
        final.pages["software-tags85"].publications.slice(0, 2),
        firstTwo,
    );
    assert.deepEqual(final.financial_rows, [0, 0]);
    if (complete(initial)) assert.deepEqual(final, initial);
    assert.deepEqual(exceptions, []);
    assert.deepEqual(externalRequests, []);
    console.log(
        JSON.stringify({
            result: "passed",
            completed_fixture_readonly_replay: complete(initial),
            mutation_workflow_executed: !complete(initial),
            main_publications: 3,
            other_publications: 1,
            cancelled_frozen_schedule: 1,
            checks: [
                "actual-admin-create-tag-input-eight-row-bound-duplicate-refused-without-write",
                "draft-and-future-tags-private-until-saved-publication",
                "canonical-tag-filter-literal-HTML-escaped-no-execution",
                "reviewed-republication-and-removal-preserve-first-two-snapshots",
                "cancelled-schedule-frozen-labels-private-final-draft-no-public-tags",
                "three-nonadmin-panels-403",
                "320-390-768-1440-zero-external-requests",
                "completed-fixture-readonly-replay",
            ],
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
