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
    "Only extend the isolated local cohort fixture.",
);
const env = {
    ...process.env,
    DB_CONNECTION: "sqlite",
    DB_DATABASE: process.env.DB_DATABASE,
    SMS_DRIVER: "disabled",
    ZIBAL_ENABLED: "false",
};
const bootstrap = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();`;
const code =
    bootstrap +
    String.raw`if(config('database.default')!=='sqlite'||config('database.connections.sqlite.database')!=='/tmp/kardorost-cohort-announcements-browser.sqlite'){throw new RuntimeException('Only the isolated fixture can be extended.');}$prior=\App\Models\AssessmentVersion::where('definition->title','گزارش آزمایشی خروجی نرم‌افزار')->first();if($prior){if(($argv[1]??'')!=='reuse'){throw new RuntimeException('Assessment export fixture exists; explicit reuse is required.');}$admin=\App\Models\User::where('phone','989121234562')->firstOrFail();$owner=\App\Models\User::where('phone','989121234560')->firstOrFail();$organization=\App\Models\Organization::where('name','خروجی خودشناسی آزمایشی')->firstOrFail();if(!$organization->members()->where('users.id',$owner->id)->exists()){throw new RuntimeException('Inspect departed fixture before resuming.');}$attempt=\App\Models\AssessmentAttempt::where('user_id',$owner->id)->where('assessment_version_id',$prior->id)->firstOrFail();$service=app(\App\Services\AssessmentService::class);if($attempt->status==='in_progress'){$service->save($owner,$attempt,$attempt->revision,['q1'=>5,'q2'=>5]);$attempt=$service->finish($owner,$attempt->fresh(),$attempt->fresh()->revision);}echo json_encode(['assessment_id'=>$prior->assessment_id,'attempt_id'=>$attempt->id,'owner_id'=>$owner->id,'organization_id'=>$organization->id,'owner_phone'=>$owner->phone]);return;}$admin=\App\Models\User::where('phone','989121234562')->firstOrFail();$owner=\App\Models\User::where('phone','989121234560')->firstOrFail();$manager=\App\Models\User::where('phone','989121234563')->firstOrFail();$organization=\App\Models\Organization::create(['name'=>'خروجی خودشناسی آزمایشی','type'=>'company','status'=>'active']);$organization->members()->attach($manager->id,['kind'=>'manager']);$organization->members()->attach($owner->id,['kind'=>'member']);$assessment=\App\Models\Assessment::factory()->create();$definition=\App\Models\AssessmentVersion::factory()->definition()['definition'];$definition['title']='گزارش آزمایشی خروجی نرم‌افزار';$definition['source']='فقط داده آزمایش نرم‌افزار؛ ابزار واقعی یا تأییدشده نیست.';$definition['dimensions'][]=['key'=>'focus','title'=>'بعد دوم آزمایشی','description'=>'Synthetic software fixture only'];$definition['questions'][0]['text']='PRIVATE-CSV-QUESTION-ONE';$definition['questions'][1]['text']='PRIVATE-CSV-QUESTION-TWO';$definition['questions'][1]['dimension']='focus';$service=app(\App\Services\AssessmentService::class);$service->saveDraft($admin,$assessment,0,$definition);$service->publish($admin,$assessment,1);$attempt=$service->start($owner,$assessment->fresh());$service->save($owner,$attempt,1,['q1'=>5,'q2'=>5]);$attempt=$service->finish($owner,$attempt,2);echo json_encode(['assessment_id'=>$assessment->id,'attempt_id'=>$attempt->id,'owner_id'=>$owner->id,'organization_id'=>$organization->id,'owner_phone'=>$owner->phone]);`;
await until(
    `location.origin===${JSON.stringify(new URL(base).origin)} && document.readyState==='complete'`,
);
await networkIdle();
const fixture = JSON.parse(
    execFileSync(
        php,
        [
            "-r",
            code,
            process.env.BROWSER_REUSE_ASSESSMENT_EXPORT_FIXTURE === "1"
                ? "reuse"
                : "fresh",
        ],
        {
            cwd: process.cwd(),
            env,
            encoding: "utf8",
        },
    ),
);

async function download(path) {
    return evaluate(
        `(async()=>{const r=await fetch(${JSON.stringify(path)},{headers:{Accept:'application/json'}});const b=new Uint8Array(await r.arrayBuffer());return {status:r.status,bom:Array.from(b.slice(0,3)),text:new TextDecoder().decode(b),cache:r.headers.get('cache-control'),disposition:r.headers.get('content-disposition'),robots:r.headers.get('x-robots-tag')};})()`,
    );
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
            `Member learning export overflow ${width}`,
        );
    }
}

const reportPath = "/assessment-attempts/" + fixture.attempt_id + "/report";
const exportPath = "/assessment-attempts/" + fixture.attempt_id + "/export";
try {
    await login("student");
    await goto(reportPath);
    await until(
        "!!document.querySelector('[data-testid=assessment-report-export]')",
    );
    const href = await evaluate(
        "document.querySelector('[data-testid=assessment-report-export]').getAttribute('href')",
    );
    const owner = await download(href);
    assert.equal(owner.status, 200);
    assert.deepEqual(owner.bom, [239, 187, 191]);
    assert(owner.cache.includes("no-store"));
    assert.equal(owner.robots, "noindex, nofollow");
    assert(owner.disposition.includes(".csv"));
    const rows = csv(owner.text);
    assert.equal(rows.length, 3);
    assert(rows.every((r) => r.length === 14));
    assert.deepEqual(
        rows.slice(1).map((r) => r[6]),
        ["interest", "focus"],
    );
    assert.deepEqual(
        rows.slice(1).map((r) => r[12]),
        ["100", "0"],
    );
    assert(rows[1][13].includes("رتبه جامعه"));
    for (const hidden of [
        "PRIVATE-CSV-QUESTION-ONE",
        "PRIVATE-CSV-QUESTION-TWO",
        "q1",
        "q2",
        fixture.owner_phone,
    ])
        assert(!owner.text.includes(hidden));
    await responsive();
    const publish =
        bootstrap +
        String.raw`$admin=\App\Models\User::where('phone','989121234562')->firstOrFail();$assessment=\App\Models\Assessment::findOrFail(` +
        fixture.assessment_id +
        String.raw`);$definition=$assessment->draft;$definition['title']='ویرایش دوم فقط برای آزمون نرم‌افزار';$definition['questions'][0]['reverse']=true;$service=app(\App\Services\AssessmentService::class);$service->saveDraft($admin,$assessment,$assessment->revision,$definition);$service->publish($admin,$assessment->fresh(),$assessment->fresh()->revision);echo $assessment->fresh()->current_version;`;
    assert.equal(
        execFileSync(php, ["-r", publish], {
            cwd: process.cwd(),
            env,
            encoding: "utf8",
        }),
        "2",
    );
    const unchanged = await download(exportPath);
    assert.equal(unchanged.status, 200);
    assert.equal(unchanged.text, owner.text);
    await login("admin");
    await goto(reportPath);
    await until(
        "!!document.querySelector('[data-testid=assessment-report-export]')",
    );
    assert.equal((await download(exportPath)).text, owner.text);
    process.env.BROWSER_ORGANIZATION_PHONE = "09121234563";
    await login("organization");
    await goto("/organization");
    await until(
        "!!document.querySelector('select[aria-label=\"انتخاب سازمان\"]')",
    );
    await fill('select[aria-label="انتخاب سازمان"]', fixture.organization_id);
    await networkIdle();
    await goto(reportPath);
    await until(
        "!!document.querySelector('[data-testid=assessment-report-export]')",
    );
    assert.equal((await download(exportPath)).text, owner.text);
    await responsive();
    process.env.BROWSER_ORGANIZATION_PHONE = "09122220000";
    await login("organization");
    assert.equal((await download(exportPath)).status, 403);
    await login("coach");
    assert.equal((await download(exportPath)).status, 403);
    process.env.BROWSER_STUDENT_PHONE = "09121234566";
    await login("student");
    assert.equal((await download(exportPath)).status, 403);
    process.env.BROWSER_STUDENT_PHONE = "09121234560";
    await login("student");
    await goto("/organization-memberships");
    await until(
        `!!document.querySelector('[data-membership="${fixture.organization_id}"]')`,
    );
    await evaluate(
        `document.querySelector('[data-membership="${fixture.organization_id}"] details').open=true`,
    );
    await fill(
        `[data-membership="${fixture.organization_id}"] [name=reason]`,
        "خروج آزمایشی برای بررسی دسترسی گزارش نهایی",
    );
    await submit(`[data-membership="${fixture.organization_id}"] form`);
    await until(
        `!document.querySelector('[data-membership="${fixture.organization_id}"]')`,
    );
    process.env.BROWSER_ORGANIZATION_PHONE = "09121234563";
    await login("organization");
    await goto("/organization");
    await fill('select[aria-label="انتخاب سازمان"]', fixture.organization_id);
    await networkIdle();
    assert.equal((await download(exportPath)).status, 403);
    await login("admin");
    assert.equal((await download(exportPath)).status, 200);
    await login("student");
    assert.equal((await download(exportPath)).status, 200);
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "software-only-two-dimension-real-service-finalization-owner-csv",
                "fourteen-columns-100-and-zero-range-percents-no-combined-total-or-private-answers",
                "new-publication-keeps-old-completed-report-bytes",
                "owner-full-admin-and-selected-current-manager-downloads",
                "foreign-manager-coach-other-student-denied",
                "actual-membership-departure-revokes-manager-retains-owner-and-full-admin",
                "private-utf8-bom-headers-and-320-390-768-1440",
            ],
        }),
    );
} finally {
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
