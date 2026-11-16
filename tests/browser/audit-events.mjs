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
    await until(`location.pathname==='/${role}'`);
}

const php = process.env.BROWSER_PHP_BINARY;
assert(
    php &&
        new URL(base).port === "8029" &&
        process.env.DB_DATABASE ===
            "/tmp/kardorost-cohort-announcements-browser.sqlite",
    "Only the isolated local cohort fixture may be extended for audit QA.",
);
const env = {
    ...process.env,
    DB_CONNECTION: "sqlite",
    DB_DATABASE: process.env.DB_DATABASE,
    SMS_DRIVER: "disabled",
    ZIBAL_ENABLED: "false",
};
const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(config('database.default')!=='sqlite'||config('database.connections.sqlite.database')!=='/tmp/kardorost-cohort-announcements-browser.sqlite'||\App\Models\User::count()<8){throw new RuntimeException('Only the existing isolated fixture can be extended.');}if(\App\Models\AuditEvent::where('action','fixture.audit_browser')->exists()){throw new RuntimeException('Audit browser fixture already exists; inspect before rerunning.');}$actor=\App\Models\User::factory()->create(['name'=>'<script>window.auditInjected=true</script>']);$ids=[];for($i=0;$i<127;$i++){$time=$i===125?'2026-02-28 23:59:59':($i===126?'2026-03-03 00:00:00':'2026-03-01 12:00:00');$e=\App\Models\AuditEvent::create(['actor_id'=>$actor->id,'action'=>'fixture.audit_browser','subject_type'=>'course','subject_id'=>7,'metadata'=>['private_note'=>'PRIVATE-BROWSER-AUDIT-MARKER'],'created_at'=>$time]);$ids[]=$e->id;}echo json_encode(['actor_id'=>$actor->id,'ids'=>$ids]);`;
const fixture = JSON.parse(
    execFileSync(php, ["-r", code], {
        cwd: process.cwd(),
        env,
        encoding: "utf8",
    }),
);
async function auditGoto(path) {
    await evaluate("document.documentElement.dataset.auditNavigating='1'");
    await send("Page.navigate", { url: base + path });
    await until(
        `location.pathname===${JSON.stringify(path.split("?")[0])} && !document.documentElement.dataset.auditNavigating && !!document.querySelector('[data-testid=audit-filters]')`,
    );
    await networkIdle();
}
async function props() {
    return evaluate(
        "JSON.parse(document.querySelector('script[data-page]').textContent).props",
    );
}
async function filtersQuery(extra = "") {
    return (
        "/admin/activity?action=fixture.audit_browser&actor_id=" +
        fixture.actor_id +
        "&subject_type=course&subject_id=7" +
        extra
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
        assert(
            await evaluate(
                "document.documentElement.scrollWidth<=innerWidth+1",
            ),
            `Audit overflow ${width}`,
        );
    }
}
try {
    await login("admin");
    await auditGoto("/admin/activity");
    await fill("[name=action]", "fixture.audit_browser");
    await fill("[name=actor_id]", fixture.actor_id);
    await fill("[name=subject_type]", "course");
    await fill("[name=subject_id]", 7);
    await submit("[data-testid=audit-filters]");
    await until(
        "location.search.includes('action=fixture.audit_browser') && document.querySelectorAll('[data-audit-event]').length===25",
    );
    await networkIdle();
    await auditGoto(await evaluate("location.pathname+location.search"));
    let p = await props();
    assert.equal(p.events.total, 127);
    assert.equal(p.events.data[0].id, fixture.ids.at(-1));
    assert.equal(
        p.events.data[0].actor_name,
        "<script>window.auditInjected=true</script>",
    );
    assert.equal(await evaluate("window.auditInjected||false"), false);
    assert.equal(
        await evaluate(
            "document.querySelector('script[data-page]').textContent.includes('PRIVATE-BROWSER-AUDIT-MARKER')",
        ),
        false,
    );
    assert(
        p.events.data.every(
            (e) =>
                !Object.hasOwn(e, "metadata") &&
                !Object.hasOwn(e, "actor_phone"),
        ),
    );
    const first = p.events.data.map((e) => e.id);
    await evaluate(
        "[...document.querySelectorAll('.finance-pagination a')].find(a=>a.innerText.includes('بعد')).click()",
    );
    await until("new URL(location.href).searchParams.get('page')==='2'");
    await networkIdle();
    await auditGoto(await evaluate("location.pathname+location.search"));
    p = await props();
    assert.equal(p.events.current_page, 2);
    assert.equal(p.events.total, 127);
    assert.equal(p.filters.actor_id, fixture.actor_id);
    assert(p.events.data.every((e) => !first.includes(e.id)));
    await auditGoto(await filtersQuery("&page=6"));
    p = await props();
    assert.equal(p.events.data.length, 2);
    assert.equal(p.events.data.at(-1).id, fixture.ids[0]);
    await auditGoto(await filtersQuery());
    for (const [label, year, month, day] of [
        ["از تاریخ", 1404, 12, 10],
        ["تا تاریخ", 1404, 12, 11],
    ]) {
        await fill(`select[aria-label="سال ${label}"]`, year);
        await fill(`select[aria-label="ماه ${label}"]`, month);
        await fill(`select[aria-label="روز ${label}"]`, day);
    }
    await submit("[data-testid=audit-filters]");
    await until(
        "location.search.includes('from=2026-03-01') && location.search.includes('to=2026-03-02')",
    );
    await networkIdle();
    await auditGoto(await evaluate("location.pathname+location.search"));
    p = await props();
    assert.equal(p.events.total, 125);
    assert.equal(p.events.data[0].id, fixture.ids[124]);
    await responsive();
    await auditGoto("/admin/activity?event_id=" + fixture.ids[0]);
    p = await props();
    assert.equal(p.events.total, 1);
    assert.equal(p.events.data[0].id, fixture.ids[0]);
    await fill("[name=action]", "does.not.exist");
    await submit("[data-testid=audit-filters]");
    await until(
        "document.body.innerText.includes('رویدادی با این شرایط پیدا نشد')",
    );
    await evaluate(
        "[...document.querySelectorAll('main a')].find(a=>a.innerText.includes('پاک‌کردن فیلترها')).click()",
    );
    await until(
        "location.search==='' && document.querySelectorAll('[data-audit-event]').length===25",
    );
    const cache = await evaluate(
        "fetch('/admin/activity',{headers:{Accept:'text/html'}}).then(async r=>({status:r.status,cache:r.headers.get('cache-control'),body:(await r.text()).includes('PRIVATE-BROWSER-AUDIT-MARKER')}))",
    );
    assert.equal(cache.status, 200);
    assert(cache.cache.includes("no-store"));
    assert.equal(cache.body, false);
    for (const role of ["student", "coach", "organization"]) {
        if (role === "organization")
            process.env.BROWSER_ORGANIZATION_PHONE = "09121234563";
        await login(role);
        const r = await evaluate(
            "fetch('/admin/activity',{headers:{Accept:'text/html'}}).then(async r=>({status:r.status,body:(await r.text()).includes('fixture.audit_browser')}))",
        );
        assert.equal(r.status, 403);
        assert.equal(r.body, false);
    }
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "actual-admin-filter-form-and-combined-exact-identifiers",
                "127-record-history-six-pages-filter-preservation-and-no-duplicate-page",
                "jalali-input-tehran-date-boundaries-125-matches",
                "private-metadata-not-in-client-payload-escaped-current-actor-name",
                "event-lookup-empty-state-clear-and-no-store",
                "other-three-panels-forbidden",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
