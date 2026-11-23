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
assert(
    new URL(base).port === "18089" &&
        new URL(base).hostname === "127.0.0.1" &&
        process.env.CONTAINER_ENV_FILE?.startsWith("/tmp/kardorost-compose-"),
    "Owned production category fixture only",
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
        "(()=>{const editor=document.querySelector('[data-content-category-editor]');if(editor)editor.scrollIntoView({block:'center'});else window.scrollTo(0,0)})()",
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

const publicCategory = "دسته کانتینر آزمایشی۹۰",
    privateCategory = "دسته خصوصی کانتینر۹۰";
const baseline = JSON.parse(
    readFileSync("/tmp/kardorost-phase90-before-positive-proof.json", "utf8"),
);
const python = String.raw`import importlib.util,json,os,subprocess,sys
from pathlib import Path
root=Path.cwd();sys.path.insert(0,str(root/'tests'))
from container_runtime_probes import ARTICLE_CATEGORY_FIXTURE_STATE,CMS_REDIRECT_FIXTURE_STATE,ARTICLE_TAGS_FIXTURE_STATE,JOURNAL_RSS_FIXTURE_STATE,COACH_LINK_FIXTURE_STATE,COACH_PHOTO_FIXTURE_STATE,CONTENT_MEDIA_FIXTURE_STATE,RICH_CONTENT_FIXTURE_STATE,DISCUSSION_ATTACHMENT_FIXTURE_STATE,LATEST_PRIVATE_FIXTURE_STATE
s=importlib.util.spec_from_file_location('r',root/'resources/operations/recovery.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
def beat():subprocess.run(['python3','/workspace/work/kardorost-autonomy-lease.py','touch',os.environ['KARDOROST_LEASE_OWNER']],check=True,stdout=subprocess.DEVNULL)
class Composition(m.Composition):
 def execute(self,*a,**kw):beat();return super().execute(*a,**kw)
environment=Path(sys.argv[1]).resolve();assert environment.is_relative_to('/tmp') and environment.name.startswith('kardorost-compose-')
c=Composition(environment);assert c.config['name'].startswith('kardorost-validation-') and c.settings['SMS_DRIVER']=='disabled' and c.settings['ZIBAL_ENABLED']=='false' and c.settings['APP_URL']=='http://127.0.0.1:18089'
r=json.loads(c.run(['exec','-T','app','php','-r',ARTICLE_CATEGORY_FIXTURE_STATE]))
if sys.argv[2]=='all':r={'category':r,'old':[json.loads(c.run(['exec','-T','app','php','-r',code]))for code in [CMS_REDIRECT_FIXTURE_STATE,ARTICLE_TAGS_FIXTURE_STATE,JOURNAL_RSS_FIXTURE_STATE,COACH_LINK_FIXTURE_STATE,COACH_PHOTO_FIXTURE_STATE,CONTENT_MEDIA_FIXTURE_STATE,RICH_CONTENT_FIXTURE_STATE,DISCUSSION_ATTACHMENT_FIXTURE_STATE]],'private':json.loads(c.run(['exec','-T','app','php','-r',LATEST_PRIVATE_FIXTURE_STATE]))}
print(json.dumps(r))
`;
function state(all = false) {
    return JSON.parse(
        execFileSync(
            "python3",
            [
                "-c",
                python,
                process.env.CONTAINER_ENV_FILE,
                all ? "all" : "category",
            ],
            { encoding: "utf8" },
        ),
    );
}
async function editor() {
    await goto("/admin/content/" + state().id);
}
async function setAlt(alt) {
    await evaluate(
        `(()=>{const e=document.querySelector('[data-content-document]');e.querySelector('figure img').alt=${JSON.stringify(alt)};e.dispatchEvent(new InputEvent('input',{bubbles:true}))})()`,
    );
}
async function tags(values) {
    while (
        await evaluate(
            "document.querySelectorAll('[data-content-tag-row]').length>0",
        )
    )
        await click('[data-remove-content-tag="0"]');
    for (const [i, value] of values.entries()) {
        await click("[data-add-content-tag]");
        await fill("[name=content_tag_" + i + "]", value);
    }
}
async function save() {
    await submit("[data-content-draft]");
    await networkIdle();
    await editor();
}
function withAlt(d, alt) {
    const copy = structuredClone(d),
        image = copy.document.blocks.find((b) => b.type === "image");
    assert(image);
    copy.body = copy.body.replace(image.alt, alt);
    image.alt = alt;
    return copy;
}
const publicOriginal = baseline.snapshots[5].page.publications[1].definition,
    privateOriginal = baseline.snapshots[5].page.draft;
assert.deepEqual(
    {
        ...withAlt(publicOriginal, "شرح خصوصی پایانی۷۸"),
        tags: privateOriginal.tags,
    },
    privateOriginal,
    "Only the known original private image and tags may differ.",
);
function preserved(all) {
    for (const i of [0, 3, 4, 6, 7])
        assert.deepEqual(all.old[i], baseline.snapshots[i]);
    const media = all.old[5],
        old = baseline.snapshots[5];
    assert.deepEqual(media.media, old.media);
    assert.deepEqual(
        media.page.publications.slice(0, 2),
        old.page.publications,
    );
    assert.deepEqual(media.page.schedules, old.page.schedules);
    assert.equal(media.financial_hash, old.financial_hash);
    assert.deepEqual(all.private, baseline.private);
}
try {
    lease();
    const initial = state(true);
    assert([2, 3].includes(initial.category.publications.length));
    preserved(initial);
    await login("admin");
    await editor();
    if (initial.category.version === 2) {
        assert(
            [
                privateOriginal,
                { ...publicOriginal, category: publicCategory },
            ].some(
                (d) =>
                    JSON.stringify(d) ===
                    JSON.stringify(initial.category.draft),
            ),
            "Preserve unexpected private draft fields on resume",
        );
        const before = state();
        await fill(
            "[name=content_category]",
            "<img src=x onerror=window.catXss=1>",
        );
        assert.equal(await evaluate("window.catXss||0"), 0);
        assert.equal(
            await evaluate(
                "!!document.querySelector('[data-content-category-preview] img')",
            ),
            false,
        );
        assert.deepEqual(state(), before);
        await setAlt("شرح تصویر عمومی۷۸");
        await tags(publicOriginal.tags);
        await fill("[name=content_category]", publicCategory);
        await save();
        assert.deepEqual(state().draft, {
            ...publicOriginal,
            category: publicCategory,
        });
        assert.deepEqual(state().public, publicOriginal);
        await click("[data-content-publish]");
        await editor();
        assert.equal(state().version, 3);
        assert.deepEqual(state().public, {
            ...publicOriginal,
            category: publicCategory,
        });
    }
    if (!state().fixture_complete) {
        await editor();
        await setAlt("شرح خصوصی پایانی۷۸");
        await tags(privateOriginal.tags);
        await fill("[name=content_category]", privateCategory);
        await save();
    }
    const frozen = state(true);
    assert(frozen.category.fixture_complete);
    preserved(frozen);
    assert.deepEqual(frozen.category.public, {
        ...publicOriginal,
        category: publicCategory,
    });
    assert.deepEqual(frozen.category.draft, {
        ...privateOriginal,
        category: privateCategory,
    });
    await goto("/journal/software-media78");
    assert.equal(
        await evaluate(
            "document.querySelector('[data-public-article-category]').textContent.trim()",
        ),
        publicCategory,
    );
    assert.equal(
        await evaluate(
            "document.querySelector('[data-content-image] img').alt",
        ),
        "شرح تصویر عمومی۷۸",
    );
    assert.equal(
        await evaluate(
            `document.body.innerText.includes(${JSON.stringify(privateCategory)})||document.body.innerText.includes('شرح خصوصی پایانی۷۸')||document.body.innerText.includes('برچسب خصوصی کانتینر۸۶')`,
        ),
        false,
    );
    await responsive();
    await screenshot("/tmp/kardorost-category90-public-mobile.png");
    await goto("/journal");
    await fill("[name=category]", publicCategory);
    lease();
    await evaluate(
        "document.querySelector('[data-journal-category-filter]').form.requestSubmit()",
    );
    await networkIdle();
    await until("location.search.includes('category=')");
    assert(
        await evaluate(
            "!!document.querySelector('a[href=\"/journal/software-media78\"]')",
        ),
    );
    assert.equal(
        await evaluate(
            "!!document.querySelector('a[href=\"/journal/software-rich-content76\"]')",
        ),
        false,
    );
    assert.equal(
        await evaluate(
            `Array.from(document.querySelectorAll('[name=category] option')).some(e=>e.value===${JSON.stringify(privateCategory)})`,
        ),
        false,
    );
    const header = await evaluate(
        "fetch(location.href).then(async r=>{await r.arrayBuffer();return {robots:r.headers.get('x-robots-tag')}})",
    );
    assert.equal(header.robots, "noindex, follow");
    assert.equal(
        await evaluate("document.querySelector('link[rel=canonical]').href"),
        base + "/journal",
    );
    for (const role of ["student", "coach", "organization"]) {
        await login(role);
        assert.equal(await status("/admin/content/" + frozen.category.id), 403);
        await goto("/journal/software-media78");
        await responsive();
    }
    await login("admin");
    await editor();
    assert.equal(
        await evaluate(
            "document.querySelector('[name=content_category]').value",
        ),
        privateCategory,
    );
    await responsive();
    await screenshot("/tmp/kardorost-category90-editor-mobile.png");
    assert.deepEqual(state(true), frozen);
    assert.deepEqual(exceptions, []);
    assert.deepEqual(externalRequests, []);
    console.log(
        JSON.stringify({
            result: "passed",
            runtime: "production-nginx-fpm-pg",
            positive_category_publication_executed:
                initial.category.version === 2,
            completed_fixture_readonly_replay:
                initial.category.fixture_complete,
            publications: 3,
            old_pub1_pub2_media_ciphertexts_schedule_and_private_fields_preserved: true,
            five_other_domain_snapshots_unchanged: true,
            original_expired_share_metadata_unchanged_and_current404_verified: true,
            private_category_not_public: true,
            three_nonadmin403: true,
            widths: [320, 390, 768, 1440],
            external_requests: 0,
        }),
    );
} finally {
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
    socket.close();
}
