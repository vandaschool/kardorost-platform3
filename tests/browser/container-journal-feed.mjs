import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { writeFile } from "node:fs/promises";

const base = process.env.BROWSER_BASE_URL;
assert(base === "http://127.0.0.1:18089");
assert(process.env.CONTAINER_ENV_FILE?.startsWith("/tmp/kardorost-compose-"));
assert(process.env.KARDOROST_LEASE_OWNER);
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
const python = String.raw`import importlib.util,json,os,subprocess,sys
from pathlib import Path
root=Path.cwd();sys.path.insert(0,str(root/'tests'))
from container_runtime_probes import JOURNAL_RSS_FIXTURE_STATE
spec=importlib.util.spec_from_file_location('recovery',root/'resources/operations/recovery.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
def beat():subprocess.run(['python3','/workspace/work/kardorost-autonomy-lease.py','touch',os.environ['KARDOROST_LEASE_OWNER']],check=True,stdout=subprocess.DEVNULL)
class Composition(m.Composition):
 def execute(self,*a,**kw):beat();return super().execute(*a,**kw)
environment=Path(sys.argv[1]).resolve();assert environment.is_relative_to('/tmp') and environment.name.startswith('kardorost-compose-')
c=Composition(environment);assert c.config['name'].startswith('kardorost-validation-') and c.settings['SMS_DRIVER']=='disabled' and c.settings['ZIBAL_ENABLED']=='false' and c.settings['APP_URL']=='http://127.0.0.1:18089'
print(c.run(['exec','-T','app','php','-r',JOURNAL_RSS_FIXTURE_STATE]))
`;
function state() {
    return JSON.parse(
        execFileSync(
            "python3",
            ["-c", python, process.env.CONTAINER_ENV_FILE],
            { encoding: "utf8" },
        ),
    );
}
lease();
const initial = state();
assert(initial.fixture_complete);
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
    assert.deepEqual(feed.items, initial.browser_items);
    const xmlHash = await evaluate(
        `crypto.subtle.digest('SHA-256', new TextEncoder().encode(${JSON.stringify(feed.text)})).then(b=>Array.from(new Uint8Array(b)).map(v=>v.toString(16).padStart(2,'0')).join(''))`,
    );
    assert.equal(xmlHash, initial.xml_hash);
    assert(!feed.text.includes("تیتر خصوصی پایانی۷۶"));
    assert(!feed.text.includes("not-public"));
    assert(!feed.text.includes("شرح خصوصی پایانی۷۸"));
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
        "/tmp/kardorost-rss84-footer-mobile.png",
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
            actual_rss_items: feed.items.length,
            exact_XML_body_hash_matches_current_frozen_snapshots: true,

            derived_RSS_snapshot_unchanged: true,
            checks: [
                "actual-anonymous-UTF8-RSS-XML-parser-and-headers",
                "head-autodiscovery-footer-canonical-link",
                "current-frozen-rich-and-image-publications-only-private-drafts-query-body-media-omitted",
                "320-390-768-1440-no-overflow",
                "zero-external-requests-no-fixture-reset",
            ],
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
