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
const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(config('database.default')!=='sqlite'||config('database.connections.sqlite.database')!=='/tmp/kardorost-cohort-announcements-browser.sqlite'){throw new RuntimeException('Use only the existing isolated fixture.');}if(\App\Models\Organization::where('name','جزئیات یادگیری آزمایشی')->exists()){throw new RuntimeException('Detailed learning fixture exists; inspect before rerunning.');}$manager=\App\Models\User::where('phone','989121234563')->firstOrFail();$member=\App\Models\User::where('phone','989121234560')->firstOrFail();$organization=\App\Models\Organization::create(['name'=>'جزئیات یادگیری آزمایشی','type'=>'company','status'=>'active']);$organization->members()->attach($manager->id,['kind'=>'manager']);$organization->members()->attach($member->id,['kind'=>'member']);$enrollment=\App\Models\Enrollment::where('user_id',$member->id)->orderBy('id')->firstOrFail();$existing=\App\Models\Attendance::where('enrollment_id',$enrollment->id)->count();if($existing>27){throw new RuntimeException('Unexpected prior attendance fixture state.');}for($i=$existing;$i<27;$i++){$session=\App\Models\LiveSession::factory()->create(['cohort_id'=>$enrollment->cohort_id,'title'=>'حضور آزمایشی '.$i,'meeting_url'=>'https://meet.google.com/PRIVATE-DETAIL-JOIN-TOKEN']);\App\Models\Attendance::factory()->create(['enrollment_id'=>$enrollment->id,'live_session_id'=>$session->id,'status'=>['present','absent','excused'][$i%3],'recorded_by'=>$manager->id]);}$assignment=\App\Models\Assignment::factory()->create(['cohort_id'=>$enrollment->cohort_id,'title'=>'تمرین تاریخچه آزمایشی','max_score'=>80,'instructions'=>'PRIVATE-DETAIL-INSTRUCTIONS']);$ids=[];for($i=1;$i<=27;$i++){$submission=\App\Models\Submission::factory()->create(['enrollment_id'=>$enrollment->id,'assignment_id'=>$assignment->id,'version'=>$i,'body'=>'PRIVATE-DETAIL-BODY','feedback'=>'PRIVATE-DETAIL-FEEDBACK','file_name'=>'PRIVATE-DETAIL-EVIDENCE.pdf','status'=>$i%3===0?'submitted':($i%3===1?'revision_requested':'reviewed'),'score'=>$i%3===0?null:($i===1?0:50),'reviewed_at'=>$i%3===0?null:now()]);$ids[]=$submission->id;}echo json_encode(['organization_id'=>$organization->id,'member_id'=>$member->id,'enrollment_id'=>$enrollment->id,'member_phone'=>$member->phone,'submission_ids'=>$ids]);`;
const fixture = JSON.parse(
    execFileSync(php, ["-r", code], {
        cwd: process.cwd(),
        env,
        encoding: "utf8",
    }),
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

const memberPath = "/organization/members/" + fixture.member_id;
const exportPath = memberPath + "/learning-export";
const adminPath =
    "/admin/organizations/" +
    fixture.organization_id +
    "/members/" +
    fixture.member_id +
    "/learning-export";
try {
    process.env.BROWSER_ORGANIZATION_PHONE = "09121234563";
    await login("organization");
    await goto("/organization");
    await until(
        "!!document.querySelector('select[aria-label=\"انتخاب سازمان\"]')",
    );
    await fill('select[aria-label="انتخاب سازمان"]', fixture.organization_id);
    await until(
        "document.querySelector('main').innerText.includes('جزئیات یادگیری آزمایشی')",
    );
    await networkIdle();
    await goto(memberPath);
    await until(
        "!!document.querySelector('[data-testid=member-learning-export-submissions]')",
    );
    const results = {};
    for (const [dataset, columns] of [
        ["attendance", 12],
        ["submissions", 16],
    ]) {
        const href = await evaluate(
            `document.querySelector('[data-testid=member-learning-export-${dataset}]').getAttribute('href')`,
        );
        const result = await download(href);
        assert.equal(result.status, 200);
        assert.deepEqual(result.bom, [239, 187, 191]);
        assert(result.cache.includes("no-store"));
        assert.equal(result.robots, "noindex, nofollow");
        assert(result.disposition.includes("-" + dataset + ".csv"));
        const rows = csv(result.text);
        assert.equal(rows.length, 28);
        assert(rows.every((r) => r.length === columns));
        for (const privateValue of [
            "PRIVATE-DETAIL-JOIN-TOKEN",
            "PRIVATE-DETAIL-BODY",
            "PRIVATE-DETAIL-FEEDBACK",
            "PRIVATE-DETAIL-INSTRUCTIONS",
            "PRIVATE-DETAIL-EVIDENCE",
            fixture.member_phone,
        ])
            assert(!result.text.includes(privateValue));
        results[dataset] = rows;
    }
    assert.deepEqual(
        results.submissions.slice(1).map((r) => r[10]),
        Array.from({ length: 27 }, (_, i) => String(i + 1)),
    );
    assert.equal(results.submissions[1][12], "0");
    assert.equal(results.submissions[3][12], "");
    assert(results.attendance.some((r) => r[10] === "حاضر"));
    assert(results.attendance.some((r) => r[10] === "غایب"));
    assert(results.attendance.some((r) => r[10] === "غیبت موجه"));
    await responsive();
    await login("admin");
    await goto(
        "/admin/organization-managers?organization=" + fixture.organization_id,
    );
    await until(
        `!!document.querySelector('[data-member="${fixture.member_id}"] [data-testid=admin-member-learning-export-submissions]')`,
    );
    for (const dataset of ["attendance", "submissions"]) {
        const href = await evaluate(
            `document.querySelector('[data-member="${fixture.member_id}"] [data-testid=admin-member-learning-export-${dataset}]').getAttribute('href')`,
        );
        const result = await download(href);
        assert.equal(result.status, 200);
        assert.equal(csv(result.text).length, 28);
    }
    await responsive();
    process.env.BROWSER_ORGANIZATION_PHONE = "09122220000";
    await login("organization");
    for (const dataset of ["attendance", "submissions"])
        assert.equal(
            (await download(exportPath + "?dataset=" + dataset)).status,
            403,
        );
    for (const role of ["student", "coach"]) {
        await login(role);
        for (const dataset of ["attendance", "submissions"])
            assert.equal(
                (await download(exportPath + "?dataset=" + dataset)).status,
                403,
            );
    }
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
        "خروج آزمایشی برای بررسی قطع خروجی‌های ریز",
    );
    await submit(`[data-membership="${fixture.organization_id}"] form`);
    await until(
        `!document.querySelector('[data-membership="${fixture.organization_id}"]')`,
    );
    process.env.BROWSER_ORGANIZATION_PHONE = "09121234563";
    await login("organization");
    for (const dataset of ["attendance", "submissions"])
        assert.equal(
            (await download(exportPath + "?dataset=" + dataset)).status,
            403,
        );
    await login("admin");
    for (const dataset of ["attendance", "submissions"])
        assert.equal(
            (await download(adminPath + "?dataset=" + dataset)).status,
            403,
        );
    assert.deepEqual(exceptions, []);
    console.log(
        JSON.stringify({
            result: "passed",
            checks: [
                "real-member-page-attendance-and-submission-download-links",
                "27-records-each-twelve-and-sixteen-columns-all-versions-zero-and-null-score",
                "private-bom-headers-no-body-feedback-instructions-evidence-meeting-or-phone",
                "actual-full-admin-membership-page-two-dataset-downloads",
                "other-panels-foreign-manager-and-actual-member-departure-revocation",
                "320-390-768-1440",
            ],
        }),
    );
} finally {
    socket.close();
    await fetch("http://127.0.0.1:9222/json/close/" + target.id);
}
