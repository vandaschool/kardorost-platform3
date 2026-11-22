<?php

namespace Tests\Feature;

use App\Models\ContentMedia;
use App\Models\ContentPage;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentMediaLibrary;
use App\Services\ContentPublishing;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ContentMediaConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private User $admin;

    private User $other;

    private UploadedFile $file;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real media account and asset row-lock races require PostgreSQL.');
        }
        Storage::fake('learning');
        $this->admin = User::factory()->create();
        $this->other = User::factory()->create();
        foreach ([$this->admin, $this->other] as $admin) {
            $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        }
        $this->file = UploadedFile::fake()->createWithContent('software-only77.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1kAAAAASUVORK5CYII='));
    }

    private function media(): ContentMedia
    {
        return app(ContentMediaLibrary::class)->upload($this->admin, ['title' => 'Software-only image77', 'rights_confirmed' => true, 'request_key' => (string) Str::uuid()], $this->file)->fresh();
    }

    private function snapshot(): string
    {
        $all = [];
        foreach (['users', 'role_user', 'content_media', 'content_pages', 'content_publications', 'content_schedules', 'audit_events'] as $table) {
            $rows = DB::table($table)->get()->map(fn ($r) => json_encode($r, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $all[$table] = $rows;
        }
        $files = [];
        foreach (Storage::disk('learning')->allFiles() as $path) {
            $files[$path] = hash('sha256', Storage::disk('learning')->get($path));
        }ksort($files);
        $all['files'] = $files;

        return hash('sha256', json_encode($all, JSON_THROW_ON_ERROR));
    }

    private function process(string $action, string $barrier, ?ContentMedia $media = null, ?ContentPage $page = null, ?string $key = null, ?User $actor = null): Process
    {
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();config(['filesystems.disks.learning.root'=>$argv[4]]);$actor=\App\Models\User::findOrFail((int)$argv[1]);$media=$argv[2]?\App\Models\ContentMedia::findOrFail((int)$argv[2]):null;$page=$argv[3]?\App\Models\ContentPage::findOrFail((int)$argv[3]):null;$file=new \Illuminate\Http\UploadedFile($argv[5],'software-only77.png',null,null,true);$action=$argv[6];$barrier=$argv[7];$checked=false;\Illuminate\Support\Facades\DB::connection()->beforeExecuting(function($sql)use(&$checked,$barrier){if(!$checked&&str_contains($sql,'for update')&&str_contains($sql,'"'.$barrier.'"')){$checked=true;echo "CHECKED\n";flush();}});try{$service=app(\App\Services\ContentMediaLibrary::class);if($action==='upload'){$m=$service->upload($actor,['title'=>'Software-only image77','rights_confirmed'=>true,'request_key'=>$argv[8]],$file);echo 'UPLOADED:'.$m->id;}elseif($action==='publish'||$action==='withdraw'){$service->decide($actor,$media,1,$action==='publish',true);echo strtoupper($action);}elseif($action==='content_publish'){app(\App\Services\ContentPublishing::class)->publish($actor,$page,1);echo 'CONTENT_PUBLISHED';}else{app(\App\Services\ContentPublishing::class)->schedule($actor,$page,1,\Carbon\CarbonImmutable::now()->addDay(),true);echo 'CONTENT_SCHEDULED';}}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo 'HTTP:'.$e->getStatusCode();}catch(\Illuminate\Validation\ValidationException){echo 'INVALID';}
CODE;

        return new Process([PHP_BINARY, '-r', $code, (string) ($actor ?? $this->admin)->id, (string) ($media?->id ?? ''), (string) ($page?->id ?? ''), Storage::disk('learning')->path(''), $this->file->getRealPath(), $action, $barrier, $key ?? (string) Str::uuid()], base_path(), null, null, 20);
    }

    public static function accessCases(): array
    {
        $cases = [];
        foreach (['upload', 'publish', 'withdraw'] as $action) {
            foreach (['activity', 'role'] as $change) {
                $cases[] = [$action, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('accessCases')]
    public function test_media_mutations_recheck_current_admin_after_account_lock_wait(string $action, string $change): void
    {
        $media = $action === 'upload' ? null : $this->media();
        $process = $this->process($action, 'users', $media);
        $expected = null;
        try {
            DB::transaction(function () use ($process, $change, &$expected) {
                User::whereKey($this->admin->id)->lockForUpdate()->firstOrFail();
                $process->start();
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'CHECKED')), $process->getErrorOutput());
                if ($change === 'activity') {
                    $this->admin->forceFill(['is_active' => false])->save();
                    $this->assertFalse($this->admin->fresh()->is_active);
                } else {
                    $this->admin->roles()->detach();
                }$expected = $this->snapshot();
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

    public static function contentCases(): array
    {
        return [['content_publish'], ['content_schedule']];
    }

    #[DataProvider('contentCases')]
    public function test_withdrawal_while_content_waits_for_image_lock_prevents_publication_or_schedule(string $action): void
    {
        $media = $this->media();
        $library = app(ContentMediaLibrary::class);
        $library->decide($this->admin, $media, 1, true, true);
        $block = ['type' => 'image', 'media' => $media->public_key, 'sha256' => $library->metadata($media)['sha256'], 'alt' => 'Software-only image77'];
        $page = app(ContentPublishing::class)->create($this->admin, 'article', 'image-race77', ['title' => 'Software-only77', 'excerpt' => 'Software-only77', 'document' => ['version' => 1, 'blocks' => [$block]]]);
        $process = $this->process($action, 'content_media', $media, $page);
        $expected = null;
        try {
            DB::transaction(function () use ($media, $library, $process, &$expected) {
                ContentMedia::whereKey($media->id)->lockForUpdate()->firstOrFail();
                $process->start();
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'CHECKED')), $process->getErrorOutput());
                $library->decide($this->other, $media, 2, false, false);
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

    public static function uploadCases(): array
    {
        return [['same_request'], ['last_slot']];
    }

    #[DataProvider('uploadCases')]
    public function test_simultaneous_uploads_preserve_nonce_or_last_lifetime_slot(string $scenario): void
    {
        if ($scenario === 'last_slot') {
            ContentMedia::factory()->count(499)->create(['owner_id' => $this->admin->id, 'withdrawn_at' => now()]);
        }
        $key = (string) Str::uuid();
        $processes = [$this->process('upload', 'users', key: $key), $this->process('upload', 'users', key: $scenario === 'same_request' ? $key : (string) Str::uuid())];
        $outputs = [];
        try {
            DB::transaction(function () use ($processes) {
                User::whereKey($this->admin->id)->lockForUpdate()->firstOrFail();
                foreach ($processes as $p) {
                    $p->start();
                    $this->assertTrue($p->waitUntil(fn ($type, $output) => str_contains($output, 'CHECKED')), $p->getErrorOutput());
                }
            });
            foreach ($processes as $p) {
                $p->wait();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
                $outputs[] = trim(str_replace('CHECKED', '', $p->getOutput()));
            }
        } finally {
            foreach ($processes as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
        }
        if ($scenario === 'same_request') {
            $this->assertSame($outputs[0], $outputs[1]);
            $this->assertStringStartsWith('UPLOADED:', $outputs[0]);
        } else {
            $this->assertCount(1, array_filter($outputs, fn ($o) => $o === 'INVALID'));
            $this->assertCount(1, array_filter($outputs, fn ($o) => str_starts_with($o, 'UPLOADED:')));
            $this->assertSame(499, ContentMedia::whereNotNull('withdrawn_at')->count());
        }
        $this->assertSame($scenario === 'same_request' ? 1 : 500, ContentMedia::count());
        $this->assertCount(1, Storage::disk('learning')->allFiles());
    }

    public function test_publish_and_withdraw_same_revision_cannot_both_commit(): void
    {
        $media = $this->media();
        $processes = [$this->process('publish', 'content_media', $media), $this->process('withdraw', 'content_media', $media, actor: $this->other)];
        $outputs = [];
        try {
            DB::transaction(function () use ($media, $processes) {
                ContentMedia::whereKey($media->id)->lockForUpdate()->firstOrFail();
                foreach ($processes as $p) {
                    $p->start();
                    $this->assertTrue($p->waitUntil(fn ($type, $output) => str_contains($output, 'CHECKED')), $p->getErrorOutput());
                }
            });
            foreach ($processes as $p) {
                $p->wait();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
                $outputs[] = trim(str_replace('CHECKED', '', $p->getOutput()));
            }
        } finally {
            foreach ($processes as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
        }
        $this->assertCount(1, array_filter($outputs, fn ($o) => $o === 'INVALID'));
        $this->assertCount(1, array_filter($outputs, fn ($o) => in_array($o, ['PUBLISH', 'WITHDRAW'], true)));
        $this->assertSame(2, $media->fresh()->revision);
        $this->assertCount(1, Storage::disk('learning')->allFiles());
    }
}
