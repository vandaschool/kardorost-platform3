<?php

namespace Tests\Feature;

use App\Models\ContentPage;
use App\Models\ContentRedirect;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentPublishing;
use App\Services\ContentRedirects;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ContentRedirectConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private User $admin;

    private User $other;

    private ContentPage $page;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Actual account and content row-lock races require PostgreSQL.');
        }
        $this->admin = User::factory()->create();
        $this->other = User::factory()->create();
        foreach ([$this->admin, $this->other] as $actor) {
            $actor->roles()->attach(Role::where('key', 'admin')->value('id'));
        }
        $this->page = app(ContentPublishing::class)->create($this->admin, 'article', 'redirect-race87', ['title' => 'Software-only87', 'excerpt' => 'Software-only87', 'body' => 'Software-only87']);
        app(ContentPublishing::class)->publish($this->admin, $this->page, 1);
        $this->page->refresh();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['content_redirects', 'content_schedules', 'content_publications', 'content_pages'] as $table) {
                DB::table($table)->delete();
            }
        }
        parent::tearDown();
    }

    private function snapshot(): string
    {
        $all = [];
        foreach (['users', 'role_user', 'content_pages', 'content_publications', 'content_schedules', 'content_redirects', 'audit_events'] as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $all[$table] = $rows;
        }

        return hash('sha256', json_encode($all, JSON_THROW_ON_ERROR));
    }

    private function process(User $actor, string $action, string $barrier, string $slug = 'old-race87', ?ContentRedirect $redirect = null, ?ContentPage $page = null): Process
    {
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$actor=\App\Models\User::findOrFail((int)$argv[1]);$page=\App\Models\ContentPage::findOrFail((int)$argv[2]);$redirect=$argv[3]?\App\Models\ContentRedirect::findOrFail((int)$argv[3]):null;$checked=false;$barrier=$argv[5];\Illuminate\Support\Facades\DB::connection()->beforeExecuting(function($sql)use(&$checked,$barrier){if(!$checked&&str_contains($sql,'for update')&&str_contains($sql,'"'.$barrier.'"')){$checked=true;echo "CHECKED\n";flush();}});try{$service=app(\App\Services\ContentRedirects::class);if($argv[4]==='create'){$service->create($actor,$page,$argv[6]);echo 'CREATED';}else{$service->change($actor,$page,$redirect,1,false);echo 'CHANGED';}}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo 'HTTP:'.$e->getStatusCode();}catch(\Illuminate\Validation\ValidationException){echo 'INVALID';}
CODE;

        return new Process([PHP_BINARY, '-r', $code, (string) $actor->id, (string) ($page ?? $this->page)->id, (string) ($redirect?->id ?? ''), $action, $barrier, $slug], base_path(), null, null, 20);
    }

    public static function accessCases(): array
    {
        return [['create', 'activity'], ['create', 'role'], ['change', 'activity'], ['change', 'role']];
    }

    #[DataProvider('accessCases')]
    public function test_current_admin_rechecked_after_waiting_for_account_lock(string $action, string $change): void
    {
        $redirect = $action === 'change' ? app(ContentRedirects::class)->create($this->admin, $this->page, 'old-change87') : null;
        $process = $this->process($this->admin, $action, 'users', redirect: $redirect);
        $expected = null;
        try {
            DB::transaction(function () use ($process, $change, &$expected) {
                User::whereKey($this->admin->id)->lockForUpdate()->firstOrFail();
                $process->start();
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'CHECKED')), $process->getErrorOutput());
                if ($change === 'activity') {
                    User::whereKey($this->admin->id)->update(['is_active' => false]);
                } else {
                    $this->admin->roles()->detach();
                }
                $expected = $this->snapshot();
            });
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertStringEndsWith('HTTP:403', $process->getOutput());
        } finally {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        $this->assertSame($expected, $this->snapshot());
    }

    public function test_target_hidden_while_create_waits_for_page_lock_prevents_alias_and_audit(): void
    {
        $process = $this->process($this->admin, 'create', 'content_pages');
        $expected = null;
        try {
            DB::transaction(function () use ($process, &$expected) {
                ContentPage::whereKey($this->page->id)->lockForUpdate()->firstOrFail();
                $process->start();
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'CHECKED')), $process->getErrorOutput());
                app(ContentPublishing::class)->availability($this->other, $this->page, $this->page->revision, false);
                $expected = $this->snapshot();
            });
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertStringEndsWith('INVALID', $process->getOutput());
        } finally {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        $this->assertSame($expected, $this->snapshot());
    }

    public static function competingCases(): array
    {
        return [['last-slot'], ['duplicate'], ['same-revision']];
    }

    #[DataProvider('competingCases')]
    public function test_competing_admins_cannot_exceed_cap_reuse_source_or_overwrite_same_revision(string $scenario): void
    {
        $service = app(ContentRedirects::class);
        $redirect = $scenario === 'same-revision' ? $service->create($this->admin, $this->page, 'old-change87') : null;
        if ($scenario === 'last-slot') {
            ContentRedirect::factory()->count(24)->create(['content_page_id' => $this->page->id, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id]);
        }
        $processes = [$this->process($this->admin, $redirect ? 'change' : 'create', 'content_pages', 'old-race87', $redirect), $this->process($this->other, $redirect ? 'change' : 'create', 'content_pages', $scenario === 'last-slot' ? 'other-race87' : 'old-race87', $redirect)];
        $outputs = [];
        try {
            DB::transaction(function () use ($processes) {
                ContentPage::whereKey($this->page->id)->lockForUpdate()->firstOrFail();
                foreach ($processes as $process) {
                    $process->start();
                    $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'CHECKED')), $process->getErrorOutput());
                }
            });
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $outputs[] = trim(str_replace('CHECKED', '', $process->getOutput()));
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
        $this->assertCount(1, array_filter($outputs, fn ($output) => $output === 'INVALID'));
        $this->assertCount(1, array_filter($outputs, fn ($output) => $output === ($redirect ? 'CHANGED' : 'CREATED')));
        $this->assertDatabaseCount('content_redirects', $scenario === 'last-slot' ? 25 : 1);
        if ($redirect) {
            $this->assertFalse($redirect->fresh()->enabled);
            $this->assertSame(2, $redirect->fresh()->revision);
        }
    }
}
