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
    new URL(base).port === "8044" &&
        process.env.DB_DATABASE ===
            "/tmp/kardorost-content-byline-browser.sqlite" &&
        process.env.BROWSER_PHP_BINARY,
    "Owned four-user article-byline fixture only",
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
        await evaluate("document.fonts.ready");
        assert(
            await evaluate(
                "(()=>{const h=document.querySelector('.public-heading h1');if(!h)return true;const range=document.createRange();range.selectNodeContents(h);return Array.from(range.getClientRects()).every(r=>r.left>=-1&&r.right<=innerWidth+1)})()",
            ),
            "Heading visible " +
                width +
                ": " +
                JSON.stringify(
                    await evaluate(
                        "(()=>{const h=document.querySelector('.public-heading h1');return h?{rect:h.getBoundingClientRect().toJSON(),font:getComputedStyle(h).fontSize,text:h.textContent,viewport:innerWidth}:null})()",
                    ),
                ),
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
    await until("innerWidth===390");
    await send("Emulation.resetPageScaleFactor");
    await evaluate("document.documentElement.dataset.qaScreenshot='previous'");
    await send("Page.reload");
    await until(
        "document.documentElement.dataset.qaScreenshot!=='previous' && document.readyState==='complete' && !!document.querySelector('main')",
    );
    await evaluate("document.fonts.ready");
    await evaluate(
        "(()=>{const editor=document.querySelector('[data-content-byline-editor]');if(editor)editor.scrollIntoView({block:'center'});else window.scrollTo(0,0)})()",
    );
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

const mainSlug = "software-byline91",
    otherSlug = "software-other-byline91",
    firstByline = "نویسنده آزمایشی۹۱",
    otherByline = "نویسنده دیگر۹۱",
    scheduledByline = "نویسنده برنامه۹۱",
    finalByline = "نویسنده خصوصی پایانی۹۱",
    literal = "<img src=x onerror=window.bylineXss=1>",
    tag = "برچسب مشترک۹۱";
const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(\App\Models\User::count()!==4||\App\Models\User::where('name','like','بررسی نویسنده صرفاً آزمایشی %')->count()!==4||\App\Models\Payment::count()||\App\Models\Enrollment::count()||\App\Models\ContentRedirect::count()||\App\Models\ContentPage::count()>2||\App\Models\ContentPage::whereNotIn('slug',['software-byline91','software-other-byline91'])->exists()){throw new RuntimeException('Preserve unrelated native byline data.');}$hash=fn($r)=>hash('sha256',json_encode($r->getAttributes(),JSON_THROW_ON_ERROR));$pages=\App\Models\ContentPage::orderBy('id')->get()->mapWithKeys(fn($p)=>[$p->slug=>['id'=>$p->id,'draft'=>$p->draft,'hash'=>$hash($p),'publications'=>$p->publications()->orderBy('number')->get()->map(fn($v)=>['number'=>$v->number,'definition'=>$v->definition,'hash'=>$hash($v)])->all(),'schedules'=>$p->schedules()->orderBy('id')->get()->map(fn($v)=>['status'=>$v->status,'definition'=>$v->definition,'hash'=>$hash($v)])->all()]]);$audit=\App\Models\AuditEvent::where('subject_type','content_page')->orderBy('id')->get()->map($hash)->all();echo json_encode(['pages'=>$pages,'audit_hash'=>hash('sha256',json_encode($audit,JSON_THROW_ON_ERROR)),'financial_rows'=>[\App\Models\Payment::count(),\App\Models\Enrollment::count()]],JSON_THROW_ON_ERROR);`;
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
    return state().pages[mainSlug];
}
function complete(s) {
    const p = s.pages[mainSlug];
    return (
        p?.publications.length === 3 &&
        p.draft.byline === finalByline &&
        p.schedules.length === 1 &&
        p.schedules[0].status === "cancelled" &&
        s.pages[otherSlug]?.publications.length === 1
    );
}
async function editor(slug = mainSlug) {
    await goto("/admin/content/" + state().pages[slug].id);
}
async function save() {
    await submit("[data-content-draft]");
    await networkIdle();
    await editor();
}
async function publish(slug = mainSlug) {
    await editor(slug);
    await click("[data-content-publish]");
    await editor(slug);
}
async function create(slug, title, byline) {
    await goto("/admin/content");
    await evaluate(
        "document.querySelector('[data-content-create]').closest('details').open=true",
    );
    for (const [name, value] of Object.entries({
        kind: "article",
        slug,
        title,
        excerpt: "خلاصه صرفاً آزمایشی نویسنده۹۱",
        body: "متن عمومی صرفاً آزمایشی نویسنده۹۱",
    }))
        await fill("[data-content-create] [name=" + name + "]", value);
    await fill("[data-content-create] [name=content_byline]", byline);
    await fill("[data-content-create] [name=content_category]", "دسته مشترک۹۱");
    await click("[data-content-create] [data-add-content-tag]");
    await fill("[data-content-create] [name=content_tag_0]", tag);
    await submit("[data-content-create]");
    await networkIdle();
    assert(state().pages[slug]);
    await publish(slug);
}
async function publicByline(expected) {
    await goto("/journal/" + mainSlug);
    assert.deepEqual(
        await evaluate(
            "Array.from(document.querySelectorAll('[data-public-article-byline] bdi')).map(e=>e.textContent.trim())",
        ),
        expected === null ? [] : [expected],
    );
    assert.equal(await evaluate("window.bylineXss||0"), 0);
    assert.equal(
        await evaluate(
            "!!document.querySelector('[data-public-article-byline] img')",
        ),
        false,
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
    const initial = state(),
        replay = complete(initial);
    await login("admin");
    if (!replay) {
        if (!main())
            await create(
                mainSlug,
                "مقاله نویسنده صرفاً آزمایشی۹۱",
                firstByline,
            );
        if (!main().publications.length) await publish();
        if (!state().pages[otherSlug])
            await create(
                otherSlug,
                "مقاله دیگر نویسنده صرفاً آزمایشی۹۱",
                otherByline,
            );
        if (!state().pages[otherSlug].publications.length)
            await publish(otherSlug);
        if (main().publications.length === 1) {
            const first = main().publications[0];
            await editor();
            const before = state();
            await fill("[name=content_byline]", "a".repeat(81));
            await evaluate(
                "document.querySelector('[data-content-draft]').noValidate=true",
            );
            await submit("[data-content-draft]");
            await networkIdle();
            assert.deepEqual(state(), before);
            assert(
                await evaluate(
                    "!!document.querySelector('[data-content-draft] [role=alert]')",
                ),
            );
            await editor();
            if (!main().schedules.length) {
                await fill("[name=content_byline]", scheduledByline);
                await save();
                await publicByline(firstByline);
                await schedule();
                assert.equal(main().schedules[0].status, "pending");
            }
            const frozenSchedule = main().schedules[0].definition;
            assert.equal(frozenSchedule.byline, scheduledByline);
            await editor();
            const beforePreview = state();
            await fill("[name=content_byline]", literal);
            assert.equal(
                await evaluate(
                    "document.querySelector('[data-content-byline-preview] bdi').textContent",
                ),
                literal,
            );
            assert.equal(
                await evaluate(
                    "!!document.querySelector('[data-content-byline-preview] img')",
                ),
                false,
            );
            assert.deepEqual(state(), beforePreview);
            await save();
            assert.equal(main().schedules[0].status, "cancelled");
            assert.deepEqual(main().schedules[0].definition, frozenSchedule);
            await publicByline(firstByline);
            await publish();
            assert.equal(main().publications.length, 2);
            assert.equal(main().publications[0].hash, first.hash);
        }
        if (main().publications.length === 2) {
            const firstTwo = main().publications;
            await publicByline(literal);
            await responsive();
            await screenshot("/tmp/kardorost-byline91-public-mobile.png");
            await goto("/journal");
            assert(
                await evaluate(
                    `document.body.innerText.includes(${JSON.stringify(literal)})`,
                ),
            );
            assert.equal(
                await evaluate(
                    "!!document.querySelector('[data-public-article-byline] img')",
                ),
                false,
            );
            assert.equal(
                await evaluate(
                    "document.body.innerText.includes('بررسی نویسنده صرفاً آزمایشی')",
                ),
                false,
            );
            await responsive();
            await editor();
            await fill("[name=content_byline]", "");
            await save();
            await publicByline(literal);
            await publish();
            assert.equal(main().publications.length, 3);
            assert(!Object.hasOwn(main().publications[2].definition, "byline"));
            assert.deepEqual(main().publications.slice(0, 2), firstTwo);
        }
        if (main().draft.byline !== finalByline) {
            await editor();
            await fill("[name=content_byline]", finalByline);
            await save();
        }
    }
    const frozen = state();
    assert(complete(frozen));
    for (const pub of main().publications) {
        assert.deepEqual(pub.definition.tags, [tag]);
        assert.equal(pub.definition.category, "دسته مشترک۹۱");
    }
    assert.deepEqual(frozen.financial_rows, [0, 0]);
    await publicByline(null);
    assert.equal(
        await evaluate(
            `document.body.innerText.includes(${JSON.stringify(finalByline)})`,
        ),
        false,
    );
    await responsive();
    await goto("/journal");
    assert.equal(
        await evaluate(
            `document.body.innerText.includes(${JSON.stringify(finalByline)})`,
        ),
        false,
    );
    assert(
        await evaluate(
            `document.body.innerText.includes(${JSON.stringify(otherByline)})`,
        ),
    );
    assert.equal(
        await evaluate(
            "document.body.innerText.includes('بررسی نویسنده صرفاً آزمایشی')",
        ),
        false,
    );
    await goto("/journal/" + otherSlug);
    await responsive();
    await screenshot("/tmp/kardorost-byline91-current-byline-mobile.png");
    for (const role of ["student", "coach", "organization"]) {
        await login(role);
        assert.equal(await status("/admin/content/" + main().id), 403);
        await goto("/journal");
        await responsive();
    }
    await login("admin");
    await editor();
    assert.equal(
        await evaluate("document.querySelector('[name=content_byline]').value"),
        finalByline,
    );
    assert.equal(await evaluate("window.bylineXss||0"), 0);
    assert.equal(
        await evaluate(
            "!!document.querySelector('[data-content-byline-preview] img')",
        ),
        false,
    );
    await evaluate(
        "document.querySelector('[data-content-byline-editor]').scrollIntoView({block:'center'})",
    );
    await responsive();
    await screenshot("/tmp/kardorost-byline91-editor-mobile.png");
    assert.deepEqual(state(), frozen);
    assert.deepEqual(exceptions, []);
    assert.deepEqual(externalRequests, []);
    console.log(
        JSON.stringify({
            result: "passed",
            completed_fixture_readonly_replay: replay,
            mutation_workflow_executed: !replay,
            main_publications: 3,
            other_publications: 1,
            frozen_cancelled_schedule: 1,
            old_tags_and_first_two_versions_preserved: true,
            private_final_byline_not_public: true,
            actual_plain_HTML_unsaved_preview_and_reviewed_removal: true,
            three_nonadmin403: true,
            widths: [320, 390, 768, 1440],
            external_requests: 0,
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
