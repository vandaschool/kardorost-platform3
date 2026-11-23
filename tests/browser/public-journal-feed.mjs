import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { writeFile } from "node:fs/promises";

const base = process.env.BROWSER_BASE_URL;
assert(base === "http://127.0.0.1:8037");
assert(
    process.env.DB_DATABASE === "/tmp/kardorost-rich-content-browser.sqlite",
);
assert(process.env.BROWSER_PHP_BINARY && process.env.KARDOROST_LEASE_OWNER);
function lease() {
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
const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(\App\Models\User::count()!==3||\App\Models\User::where('name','like','بررسی متن قالب‌دار صرفاً آزمایشی%')->count()!==3||\App\Models\ContentPage::count()!==1||\App\Models\Payment::count()||\App\Models\Enrollment::count()){throw new RuntimeException('Only the existing owned rich-content fixture.');}$page=\App\Models\ContentPage::sole();if($page->kind!=='article'||$page->slug!=='software-rich-content75'||!$page->available||$page->current_version<2){throw new RuntimeException('Preserve unrelated content.');}$rows=[];foreach(['users','role_user','content_pages','content_publications','content_schedules','audit_events','payments','enrollments']as$table){$items=\Illuminate\Support\Facades\DB::table($table)->get()->map(fn($v)=>json_encode($v,JSON_THROW_ON_ERROR))->all();sort($items,SORT_STRING);$rows[$table]=$items;}echo json_encode(['definition'=>$page->currentPublication->definition,'draft'=>$page->draft,'version'=>$page->current_version,'hash'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))],JSON_THROW_ON_ERROR);`;
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
lease();
const initial = state();
assert(initial.draft.body.includes("تیتر خصوصی پایانی۷۵"));
const target = await (
    await fetch(
        "http://127.0.0.1:9222/json/new?" +
            encodeURIComponent(base + "/journal"),
        { method: "PUT" },
    )
).json();
const socket = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve) =>
    socket.addEventListener("open", resolve, { once: true }),
);
let id = 0;
const pending = new Map();
const external = [];
const errors = [];
socket.addEventListener("message", (event) => {
    const message = JSON.parse(event.data);
    if (
        message.method === "Network.requestWillBeSent" &&
        /^https?:/.test(message.params.request.url) &&
        new URL(message.params.request.url).origin !== base
    )
        external.push(message.params.request.url);
    if (message.method === "Runtime.exceptionThrown")
        errors.push(message.params.exceptionDetails.text);
    if (message.id) {
        const request = pending.get(message.id);
        if (message.error) request.reject(message.error);
        else request.resolve(message.result);
        pending.delete(message.id);
    }
});
function send(method, params = {}) {
    return new Promise((resolve, reject) => {
        const n = ++id;
        pending.set(n, { resolve, reject });
        socket.send(JSON.stringify({ id: n, method, params }));
    });
}
async function evaluate(expression) {
    const result = await send("Runtime.evaluate", {
        expression,
        returnByValue: true,
        awaitPromise: true,
    });
    if (result.exceptionDetails)
        throw new Error(JSON.stringify(result.exceptionDetails));
    return result.result.value;
}
async function until(expression) {
    for (let n = 0; n < 100; n++) {
        if (await evaluate(expression)) return;
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
    throw new Error("Browser did not reach expected feed state: " + expression);
}
try {
    await send("Runtime.enable");
    await send("Page.enable");
    await send("Network.enable");
    await send("Page.navigate", { url: base + "/journal" });
    await until(
        "location.pathname==='/journal' && document.readyState==='complete' && !!document.querySelector('footer')",
    );
    const discovery = await evaluate(
        "({alternate:document.querySelector('link[rel=alternate][type=\"application/rss+xml\"]').href,footer:Array.from(document.querySelectorAll('footer a')).find(a=>a.textContent.includes('RSS'))?.href})",
    );
    assert.equal(discovery.alternate, base + "/journal/feed.xml");
    assert.equal(discovery.footer, discovery.alternate);
    const feed = await evaluate(
        `(async()=>{const r=await fetch('/journal/feed.xml?token=not-public',{credentials:'omit'});const text=await r.text();const d=new DOMParser().parseFromString(text,'application/xml');const items=Array.from(d.querySelectorAll('channel>item')).map(i=>({title:i.querySelector('title').textContent,description:new DOMParser().parseFromString(i.querySelector('description').textContent,'text/html').body.textContent,url:i.querySelector('link').textContent,guid:i.querySelector('guid').textContent}));return {status:r.status,mime:r.headers.get('content-type'),sniff:r.headers.get('x-content-type-options'),cache:r.headers.get('cache-control'),parseErrors:d.querySelectorAll('parsererror').length,items,text}})()`,
    );
    assert.equal(feed.status, 200);
    assert(feed.mime.startsWith("application/rss+xml"));
    assert(feed.sniff.includes("nosniff"));
    assert(feed.cache.includes("no-store"));
    assert.equal(feed.parseErrors, 0);
    assert.deepEqual(feed.items, [
        {
            title: initial.definition.title,
            description: initial.definition.excerpt,
            url: base + "/journal/software-rich-content75",
            guid: base + "/journal/software-rich-content75",
        },
    ]);
    assert(!feed.text.includes("تیتر خصوصی پایانی۷۵"));
    assert(!feed.text.includes("not-public"));
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
        );
    }
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 900,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await evaluate(
        "document.querySelector('footer').scrollIntoView({block:'end'});new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)))",
    );
    lease();
    await writeFile(
        "/tmp/kardorost-rss83-footer-mobile.png",
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
    assert.deepEqual(state(), initial);
    assert.deepEqual(external, []);
    assert.deepEqual(errors, []);
    console.log(
        JSON.stringify({
            result: "passed",
            actual_rss_items: 1,
            historical_publication_version: initial.version,
            existing_fixture_rows_unchanged: true,
            checks: [
                "actual-anonymous-UTF8-RSS-XML-parser-and-headers",
                "head-autodiscovery-footer-canonical-link",
                "current-frozen-rich-publication-only-private-draft-and-query-omitted",
                "320-390-768-1440-no-overflow",
                "zero-external-requests-no-fixture-reset",
            ],
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
