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
    new URL(base).port === "8042" &&
        process.env.DB_DATABASE ===
            "/tmp/kardorost-content-redirects-browser.sqlite" &&
        process.env.BROWSER_PHP_BINARY,
    "Owned four-user content-redirects fixture only",
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

const mainSlug = "software-redirect87",
    oldSlug = "software-old-redirect87",
    pageSlug = "software-page-redirect87",
    oldPage = "software-old-page87";
const privateBody = "پیش‌نویس خصوصی پایانی نشانی۸۷",
    publicBody = "متن عمومی صرفاً آزمایشی نشانی۸۷";
const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(\App\Models\User::count()!==4||\App\Models\User::where('name','like','بررسی نشانی صرفاً آزمایشی %')->count()!==4||\App\Models\Payment::count()||\App\Models\Enrollment::count()||\App\Models\ContentPage::whereNotIn('slug',['software-redirect87','software-old-redirect87','software-page-redirect87'])->exists()||\App\Models\ContentRedirect::whereNotIn('from_slug',['software-old-redirect87','software-old-page87'])->exists()){throw new RuntimeException('Preserve unrelated native data.');}$hash=fn($r)=>hash('sha256',json_encode($r->getAttributes(),JSON_THROW_ON_ERROR));$pages=\App\Models\ContentPage::orderBy('id')->get()->mapWithKeys(fn($p)=>[$p->slug=>['id'=>$p->id,'kind'=>$p->kind,'revision'=>$p->revision,'available'=>$p->available,'draft'=>$p->draft,'hash'=>$hash($p),'publications'=>$p->publications()->orderBy('number')->get()->map(fn($v)=>['definition'=>$v->definition,'hash'=>$hash($v)])->all(),'redirects'=>$p->redirects()->orderBy('id')->get()->map(fn($r)=>['id'=>$r->id,'from_slug'=>$r->from_slug,'enabled'=>$r->enabled,'revision'=>$r->revision,'hash'=>$hash($r)])->all()]]);$audit=\App\Models\AuditEvent::whereIn('subject_type',['content_page','content_redirect'])->orderBy('id')->get()->map($hash)->all();echo json_encode(['pages'=>$pages,'audit_hash'=>hash('sha256',json_encode($audit,JSON_THROW_ON_ERROR)),'financial_rows'=>[\App\Models\Payment::count(),\App\Models\Enrollment::count()]],JSON_THROW_ON_ERROR);`;
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
function page(slug = mainSlug) {
    return state().pages[slug];
}
function alias(slug = mainSlug) {
    return page(slug)?.redirects[0];
}
function complete(s) {
    return (
        s.pages[mainSlug]?.draft.body === privateBody &&
        s.pages[mainSlug]?.publications.length === 1 &&
        s.pages[mainSlug]?.redirects[0]?.enabled === false &&
        s.pages[mainSlug]?.redirects[0]?.revision >= 4 &&
        s.pages[oldSlug]?.publications.length === 1 &&
        s.pages[pageSlug]?.publications.length === 1 &&
        s.pages[pageSlug]?.redirects[0]?.enabled === true
    );
}
async function editor(slug = mainSlug) {
    await goto("/admin/content/" + page(slug).id);
}
async function openAliases() {
    await evaluate(
        "document.querySelector('[data-content-redirects]').open=true",
    );
}
async function create(slug, title, kind = "article") {
    await goto("/admin/content");
    await evaluate(
        "document.querySelector('[data-content-create]').closest('details').open=true",
    );
    for (const [name, value] of Object.entries({
        kind,
        slug,
        title,
        excerpt: "خلاصه صرفاً آزمایشی نشانی۸۷",
        body: publicBody,
    }))
        await fill("[data-content-create] [name=" + name + "]", value);
    await submit("[data-content-create]");
    await networkIdle();
    assert(page(slug));
    await editor(slug);
}
async function publish(slug) {
    await editor(slug);
    await click("[data-content-publish]");
    await editor(slug);
    assert.equal(page(slug).publications.length, 1);
}
async function addAlias(slug, source) {
    await editor(slug);
    await openAliases();
    await fill("[data-content-redirect-create] [name=from_slug]", source);
    await submit("[data-content-redirect-create]");
    await networkIdle();
    await editor(slug);
    assert.equal(alias(slug).from_slug, source);
}
async function toggleAlias(slug = mainSlug) {
    await editor(slug);
    await openAliases();
    await click('[data-toggle-content-redirect="' + alias(slug).id + '"]');
    await editor(slug);
}
async function navigateAlias(source, target) {
    await networkIdle();
    await evaluate("document.documentElement.dataset.qaNavigation='previous'");
    await send("Page.navigate", { url: base + source });
    await until(
        `location.pathname===${JSON.stringify(target)} && document.documentElement.dataset.qaNavigation!=='previous' && document.readyState==='complete'`,
    );
    await networkIdle();
}
function headers(path) {
    const py = String.raw`import urllib.request,json,sys
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*a,**kw):return None
r=urllib.request.Request(sys.argv[1],headers={'Host':'untrusted.example'})
try:response=urllib.request.build_opener(NoRedirect).open(r)
except urllib.error.HTTPError as error:response=error
response.read();print(json.dumps({'status':response.status,'location':response.headers.get('Location'),'cache':response.headers.get('Cache-Control'),'robots':response.headers.get('X-Robots-Tag')}))
`;
    return JSON.parse(
        execFileSync("python3", ["-c", py, base + path], { encoding: "utf8" }),
    );
}
try {
    lease();
    const initial = state();
    const replay = complete(initial);
    await login("admin");
    if (!replay) {
        if (!page()) await create(mainSlug, "مقاله مقصد صرفاً آزمایشی۸۷");
        if (!page().publications.length) await publish(mainSlug);
        const original = page().publications[0];
        assert.equal(original.definition.body, publicBody);
        if (page().draft.body !== privateBody) {
            assert.equal(page().draft.body, publicBody);
            await editor();
            await fill("[data-content-draft] [name=body]", privateBody);
            await submit("[data-content-draft]");
            await networkIdle();
            await editor();
        }
        if (!alias()) await addAlias(mainSlug, oldSlug);
        if (!page(oldSlug)) {
            if (!page().available) {
                await editor();
                await click("[data-content-visibility]");
                await editor();
            }
            if (!alias().enabled) await toggleAlias();
            await navigateAlias("/journal/" + oldSlug, "/journal/" + mainSlug);
            assert.equal(
                await evaluate(
                    `document.body.innerText.includes(${JSON.stringify(privateBody)})`,
                ),
                false,
            );
            await responsive();
            const response = headers(
                "/journal/" + oldSlug + "?next=https://evil.test&token=private",
            );
            assert.equal(response.status, 302);
            assert.equal(response.location, "/journal/" + mainSlug);
            assert(response.cache.includes("no-store"));
            assert.equal(response.robots, "noindex, follow");
            await editor();
            await click("[data-content-visibility]");
            await editor();
            assert.equal(page().available, false);
            assert.equal(await status("/journal/" + oldSlug), 404);
            await click("[data-content-visibility]");
            await editor();
            if (alias().revision === 1) {
                await toggleAlias();
                assert.equal(await status("/journal/" + oldSlug), 404);
            }
            if (!alias().enabled) await toggleAlias();
            await editor();
            await openAliases();
            const unchanged = state();
            for (const value of [oldSlug, "https://evil.test"]) {
                await fill("[name=from_slug]", value);
                await evaluate(
                    "document.querySelector('[data-content-redirect-create]').noValidate=true",
                );
                await submit("[data-content-redirect-create]");
                await networkIdle();
                assert.deepEqual(state(), unchanged);
                assert(
                    await evaluate(
                        "!!document.querySelector('[data-content-redirect-create] [role=alert]')",
                    ),
                );
            }
            await create(oldSlug, "مقاله نشانی اصلی تازه صرفاً آزمایشی۸۷");
            assert.equal(await status("/journal/" + oldSlug), 404);
        }
        if (!page(oldSlug).publications.length) await publish(oldSlug);
        await goto("/journal/" + oldSlug);
        assert(
            await evaluate(
                "document.body.innerText.includes('مقاله نشانی اصلی تازه صرفاً آزمایشی۸۷')",
            ),
        );
        if (alias().enabled) await toggleAlias();
        assert.equal(page().publications[0].hash, original.hash);
        assert.equal(page().draft.body, privateBody);
        if (!page(pageSlug))
            await create(pageSlug, "صفحه مقصد صرفاً آزمایشی۸۷", "page");
        if (!page(pageSlug).publications.length) await publish(pageSlug);
        if (!alias(pageSlug)) await addAlias(pageSlug, oldPage);
    }
    const frozen = state();
    assert(complete(frozen));
    assert.deepEqual(frozen.financial_rows, [0, 0]);
    const response = headers(
        "/pages/" + oldPage + "?next=https://evil.test&token=private",
    );
    assert.equal(response.status, 302);
    assert.equal(response.location, "/pages/" + pageSlug);
    assert(response.cache.includes("no-store"));
    await navigateAlias("/pages/" + oldPage, "/pages/" + pageSlug);
    await responsive();
    await screenshot("/tmp/kardorost-redirect87-public-mobile.png");
    for (const role of ["student", "coach", "organization"]) {
        await login(role);
        assert.equal(await status("/admin/content/" + page().id), 403);
        await navigateAlias("/pages/" + oldPage, "/pages/" + pageSlug);
        await responsive();
    }
    await login("admin");
    await editor();
    await openAliases();
    await evaluate(
        "document.querySelector('[data-content-redirects]').scrollIntoView({block:'center'})",
    );
    await responsive();
    await screenshot("/tmp/kardorost-redirect87-editor-mobile.png");
    assert.deepEqual(state(), frozen);
    assert.deepEqual(exceptions, []);
    assert.deepEqual(externalRequests, []);
    console.log(
        JSON.stringify({
            result: "passed",
            completed_fixture_readonly_replay: replay,
            mutation_workflow_executed: !replay,
            articles: 2,
            pages: 1,
            redirects_retained: 2,
            canonical_shadow_prevents_alias_even_when_private: true,
            main_old_publication_and_private_draft_preserved: true,
            relative_location_query_omission_untrusted_host_no_store: true,
            three_nonadmin403: true,
            widths: [320, 390, 768, 1440],
            external_requests: 0,
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
