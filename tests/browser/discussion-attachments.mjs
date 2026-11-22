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
    await networkIdle();
    await send("Page.navigate", { url: "about:blank" });
    await until("location.href==='about:blank'");
    await send("Network.clearBrowserCookies");
    await send("Page.navigate", { url: base + "/login" });
    await until(`!!document.querySelector('input[name=password]')`);
    await networkIdle();
    await fill("[name=phone]", phone);
    await fill("[name=password]", password);
    await submit("form");
    await until(`location.pathname==='/${role}'`);
}

assert(
    new URL(base).port === "8036" &&
        process.env.DB_DATABASE ===
            "/tmp/kardorost-discussion-attachments-browser.sqlite" &&
        process.env.BROWSER_PHP_BINARY,
    "Only the owned five-user native fixture.",
);

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

const code = String.raw`require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();if(\App\Models\User::count()!==5||\App\Models\User::where('name','like','بررسی ضمیمه صرفاً آزمایشی%')->count()!==5){throw new RuntimeException('Owned five-user fixture only.');}$course=\App\Models\Course::first();if(\App\Models\Course::count()>1||($course&&($course->title!=='دوره ضمیمه صرفاً آزمایشی۷۳'||$course->price!==0))){throw new RuntimeException('Preserve unrelated courses.');}$cohort=\App\Models\Cohort::first();$discussion=\App\Models\CourseDiscussion::first();if(\App\Models\CourseDiscussion::count()>1||($discussion&&($discussion->title!=='پرسش ضمیمه صرفاً آزمایشی۷۳'||$discussion->author_id!==1))){throw new RuntimeException('Preserve unrelated discussions.');}$posts=$discussion?$discussion->posts()->orderBy('id')->get():collect();if($posts->count()>2||\App\Models\DiscussionAttachment::count()>2){throw new RuntimeException('Preserve unrelated posts or files.');}$service=app(\App\Services\CourseDiscussions::class);$files=\App\Models\DiscussionAttachment::orderBy('id')->get()->map(function($a)use($service){$m=$service->attachmentMetadata($a);if(!$m||$a->owner_id!==1||!in_array($m['file_name'],['نمونه-ضمیمه۷۳-یک.pdf','نمونه-ضمیمه۷۳-دو.pdf'],true)){throw new RuntimeException('Preserve unrelated private files.');}return ['id'=>$a->id,'revision'=>$a->revision,'withdrawn'=>$a->withdrawn_at!==null,'file_name'=>$m['file_name'],'sha256'=>$m['sha256'],'stored_hash'=>hash_file('sha256',\Illuminate\Support\Facades\Storage::disk('learning')->path($a->file_path)),'cipher_hash'=>hash('sha256',$a->getRawOriginal('definition'))];})->all();echo json_encode(['course'=>$course?->only('id','coach_id'),'cohort_id'=>$cohort?->id,'discussion'=>$discussion?->only('id','accepted_post_id'),'posts'=>$posts->map->only('id','author_id','revision','hidden')->all(),'files'=>$files,'enrollments'=>\App\Models\Enrollment::orderBy('id')->get(['id','user_id','status','amount'])->toArray(),'owner_roles'=>\App\Models\User::findOrFail(1)->roleKeys(),'history'=>$posts->first()?->revisions()->orderBy('revision')->get()->map(fn($r)=>['revision'=>$r->revision,'files'=>count($r->attachments??[])])->all(),'payments'=>\App\Models\Payment::count()]);`;
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
async function click(expression) {
    lease();
    await evaluate(expression);
}
async function status(path) {
    return await evaluate(
        `fetch(${JSON.stringify(path)}).then(async r=>{await r.text();return r.status})`,
    );
}
async function download(file) {
    const result = await evaluate(
        `fetch('/discussion-attachments/${file.id}/file').then(async r=>({status:r.status,cache:r.headers.get('cache-control'),sniff:r.headers.get('x-content-type-options'),robots:r.headers.get('x-robots-tag'),type:r.headers.get('content-type'),hash:Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',await r.arrayBuffer()))).map(v=>v.toString(16).padStart(2,'0')).join('')}))`,
    );
    assert.equal(result.status, 200);
    assert.equal(result.hash, file.sha256);
    assert(
        result.cache.includes("private") && result.cache.includes("no-store"),
    );
    assert.equal(result.sniff, "nosniff");
    assert.equal(result.type, "application/octet-stream");
    assert(result.robots.includes("noindex"));
}
async function signup() {
    await goto("/courses");
    await click("document.querySelector('.cohort-checkout button').click()");
    await until("location.pathname==='/learning'");
    await networkIdle();
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
            "Discussion attachment layout " + width,
        );
    }
}
async function upload(postId, index) {
    const selector = `[data-attachment-upload="${postId}"]`;
    await until(`!!document.querySelector(${JSON.stringify(selector)})`);
    await evaluate(
        `document.querySelector(${JSON.stringify(selector)}).closest('details').open=true`,
    );
    lease();
    const path =
        "/tmp/" +
        (index === 1 ? "نمونه-ضمیمه۷۳-یک.pdf" : "نمونه-ضمیمه۷۳-دو.pdf");
    await writeFile(
        path,
        "%PDF-1.4\n% SOFTWARE-ONLY-NO-REAL-DOCUMENT73-" + index + "\n%%EOF\n",
    );
    const root = await send("DOM.getDocument");
    const node = await send("DOM.querySelector", {
        nodeId: root.root.nodeId,
        selector: selector + " input[type=file]",
    });
    await send("DOM.setFileInputFiles", { nodeId: node.nodeId, files: [path] });
    await click(
        `document.querySelector(${JSON.stringify(selector + " input[type=checkbox]")}).click()`,
    );
    await submit(selector);
    await until(
        `document.querySelectorAll('[data-discussion-attachment]').length===${index}`,
    );
    await networkIdle();
}
async function withdraw(id) {
    await goto("/discussion-attachments");
    await until(
        `!!document.querySelector('[data-own-attachment="${id}"] form')`,
    );
    await submit(`[data-own-attachment="${id}"] form`);
    await until(
        `!document.querySelector('[data-own-attachment="${id}"] form')`,
    );
    await networkIdle();
}
async function changeOwnerRole(s) {
    await login("admin");
    if (s.owner_roles.includes("student")) {
        await goto("/admin/users");
        await fill("[name=phone]", "09121234560");
        await submit("form.user-filters");
        await networkIdle();
        await until("!!document.querySelector('[data-user-id=\"1\"]')");
        await click(
            "document.querySelector('[data-user-id=\"1\"] button').click()",
        );
        await until("!!document.querySelector('[data-testid=user-access]')");
        await click("document.querySelector('[name=access_student]').click()");
        await click(
            "document.querySelector('[name=access_org_admin]').click()",
        );
        await submit("[data-testid=user-access]");
        await networkIdle();
        s = state();
        assert(!s.owner_roles.includes("student"));
        assert(s.owner_roles.includes("org_admin"));
    }
    return s;
}

try {
    let s = state();
    if (!s.course) {
        await login("admin");
        await goto("/admin/courses");
        await click("document.querySelector('.page-intro button').click()");
        await until("!!document.querySelector('form.form-grid')");
        await fill(
            'form.form-grid input[maxlength="150"]',
            "دوره ضمیمه صرفاً آزمایشی۷۳",
        );
        await fill(
            "form.form-grid textarea",
            "دوره ساختگی برای بررسی ضمیمه خصوصی؛ بدون محتوای واقعی",
        );
        await fill('form.form-grid input[type=number][min="1"]', "10");
        await click(
            "document.querySelector('form.form-grid input[type=checkbox]').click()",
        );
        await submit("form.form-grid");
        await until("!document.querySelector('form.form-grid')");
        s = state();
    }
    if (s.course.coach_id !== 2) {
        await login("admin");
        await goto("/admin/courses");
        await fill(".coach-select", "2");
        await networkIdle();
        s = state();
        assert.equal(s.course.coach_id, 2);
    }
    if (
        s.files.length === 2 &&
        s.posts.some((p) => p.author_id === 1 && p.hidden)
    ) {
        s = await changeOwnerRole(s);
        await login("organization", process.env.BROWSER_STUDENT_PHONE);
        for (const file of s.files.filter((f) => !f.withdrawn)) {
            await withdraw(file.id);
        }
        s = state();
    }
    const completed =
        s.files.length === 2 &&
        s.files.every((f) => f.withdrawn) &&
        s.owner_roles.includes("org_admin") &&
        !s.owner_roles.includes("student");
    if (!completed) {
        await login("student");
        if (!s.enrollments.some((e) => e.user_id === 1)) {
            await signup();
            s = state();
        }
        if (!s.discussion) {
            await goto(`/cohorts/${s.cohort_id}/discussions`);
            await evaluate("document.querySelector('main details').open=true");
            await fill("[name=discussion_title]", "پرسش ضمیمه صرفاً آزمایشی۷۳");
            await fill(
                "[name=discussion_body]",
                "پیام ساختگی برای بررسی اشتراک فایل در همین نوبت",
            );
            await submit("[data-discussion-create]");
            await until(
                "location.pathname.startsWith('/discussions/')&&!!document.querySelector('[data-discussion-post]')",
            );
            s = state();
        }
        const path = "/discussions/" + s.discussion.id;
        const post = s.posts.find((p) => p.author_id === 1);
        await goto(path);
        if (s.files.length === 0) {
            await upload(post.id, 1);
            s = state();
        }
        await download(s.files[0]);
        if (!s.enrollments.some((e) => e.user_id === 5)) {
            await login("student", "09121234564");
            assert.equal(
                await status(
                    "/discussion-attachments/" + s.files[0].id + "/file",
                ),
                403,
            );
            await signup();
            s = state();
        }
        await login("student", "09121234564");
        await goto(path);
        await download(s.files[0]);
        await goto("/discussion-attachments");
        assert(
            !(await evaluate(
                "document.body.innerText.includes('نمونه-ضمیمه۷۳-یک.pdf')",
            )),
        );
        await login("organization");
        assert.equal(
            await status("/discussion-attachments/" + s.files[0].id + "/file"),
            403,
        );
        await login("coach");
        await goto(path);
        await download(s.files[0]);
        if (s.posts.length === 1) {
            await fill(
                "[name=reply_body]",
                "پاسخ مدرس صرفاً برای بررسی نرم‌افزاری ضمیمه",
            );
            await submit("[data-discussion-reply]");
            await until(
                "document.querySelectorAll('[data-discussion-post]').length===2",
            );
            s = state();
        }
        if (s.files.length === 1) {
            await login("student");
            await goto(path);
            const reply = s.posts.find((p) => p.author_id === 2);
            await click(
                `Array.from(document.querySelectorAll('[data-discussion-post="${reply.id}"] button')).find(b=>b.innerText==='انتخاب این پاسخ').click()`,
            );
            await until(
                `document.querySelector('[data-discussion-post="${reply.id}"]').innerText.includes('پاسخ منتخب')`,
            );
            await upload(post.id, 2);
            s = state();
            assert.equal(s.discussion.accepted_post_id, null);
            await responsive();
        }
        await login("coach");
        await goto(path);
        if (!s.posts.find((p) => p.id === post.id).hidden) {
            await evaluate(
                `document.querySelector('[data-post-moderate="${post.id}"]').closest('details').open=true`,
            );
            await fill(
                `[data-post-moderate="${post.id}"] textarea`,
                "پنهان‌سازی صرفاً نرم‌افزاری برای بررسی قطع دانلود",
            );
            await submit(`[data-post-moderate="${post.id}"]`);
            await until(
                `document.querySelector('[data-discussion-post="${post.id}"]').innerText.includes('پیام پنهان‌شده')`,
            );
            s = state();
        }
        await download(s.files[0]);
        await login("student", "09121234564");
        await goto(path);
        assert(
            !(await evaluate(
                "document.body.innerText.includes('نمونه-ضمیمه۷۳-یک.pdf')",
            )),
        );
        for (const file of s.files.filter((f) => !f.withdrawn)) {
            assert.equal(
                await status("/discussion-attachments/" + file.id + "/file"),
                404,
            );
        }
        if (!s.files[0].withdrawn) {
            await login("student");
            await withdraw(s.files[0].id);
            s = state();
        }
        s = await changeOwnerRole(s);
        await login("organization", process.env.BROWSER_STUDENT_PHONE);
        await goto("/discussion-attachments");
        assert.equal(
            await evaluate(
                "document.querySelectorAll('main a[href$=\"/file\"]').length",
            ),
            0,
        );
        if (!s.files[1].withdrawn) {
            await withdraw(s.files[1].id);
            s = state();
        }
    }
    assert.equal(s.files.length, 2);
    assert(s.files.every((f) => f.withdrawn && f.sha256 === f.stored_hash));
    const retained = s.files.map((f) => ({
        id: f.id,
        hash: f.sha256,
        cipher: f.cipher_hash,
    }));
    await login("coach");
    for (const file of s.files) {
        assert.equal(
            await status("/discussion-attachments/" + file.id + "/file"),
            404,
        );
    }
    const first = s.posts.find((p) => p.author_id === 1);
    await goto(`/discussions/${s.discussion.id}/posts/${first.id}/history`);
    assert(
        await evaluate(
            "document.body.innerText.includes('نمونه-ضمیمه۷۳-یک.pdf')&&document.body.innerText.includes('نمونه-ضمیمه۷۳-دو.pdf')",
        ),
    );
    await login("organization", process.env.BROWSER_STUDENT_PHONE);
    await goto("/discussion-attachments");
    await responsive();
    await send("Emulation.setDeviceMetricsOverride", {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await until(
        'document.querySelector(".sidebar").getBoundingClientRect().left>=innerWidth',
    );
    lease();
    await writeFile(
        "/tmp/kardorost-discussion-attachments73-mobile.png",
        Buffer.from(
            (
                await send("Page.captureScreenshot", {
                    format: "png",
                    captureBeyondViewport: true,
                })
            ).data,
            "base64",
        ),
    );
    s = state();
    assert.deepEqual(
        s.files.map((f) => ({
            id: f.id,
            hash: f.sha256,
            cipher: f.cipher_hash,
        })),
        retained,
    );
    assert.equal(s.payments, 0);
    assert.equal(s.enrollments.length, 2);
    assert(s.enrollments.every((e) => e.status === "active" && e.amount === 0));
    assert.equal(s.history.at(-1).files, 0);
    assert.equal(exceptions.length, 0, exceptions.join("\n"));
    console.log(
        JSON.stringify({
            result: "passed",
            fixture: "five-owned-software-only-users",
            real_course_signup_and_upload: true,
            private_download_hash_headers: true,
            outsider403: true,
            hidden_peer404: true,
            staff_review: true,
            owner_withdraw_after_role_loss: true,
            retained_private_files: 2,
            immutable_file_metadata: true,
            revision_history_retained: true,
            selected_answer_cleared: true,
            payments: 0,
            widths: [320, 390, 768, 1440],
            live_services_verified: false,
        }),
    );
} finally {
    await send("Emulation.clearDeviceMetricsOverride");
    await send("Page.close");
    socket.close();
}
