<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ContentMedia;
use App\Models\ContentPage;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentDocument;
use App\Services\ContentMediaLibrary;
use App\Services\ContentPublishing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ContentMediaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ContentMediaLibrary $library;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Storage::fake('learning');
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $this->library = app(ContentMediaLibrary::class);
    }

    private function bytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1kAAAAASUVORK5CYII=');
    }

    private function file(string $name = 'software-only.png', ?string $bytes = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes ?? $this->bytes());
    }

    private function upload(array $extra = []): ContentMedia
    {
        return $this->library->upload($this->admin, [...['title' => 'تصویر صرفاً آزمایشی', 'rights_confirmed' => true, 'request_key' => (string) Str::uuid()], ...$extra], $this->file())->fresh();
    }

    private function block(ContentMedia $media, array $extra = []): array
    {
        return [...['type' => 'image', 'media' => $media->public_key, 'sha256' => $this->library->metadata($media)['sha256'], 'alt' => 'شرح دسترس‌پذیر تصویر', 'caption' => 'زیرنویس صرفاً آزمایشی'], ...$extra];
    }

    private function definition(array $block): array
    {
        return ['title' => 'مقاله تصویری آزمایشی', 'excerpt' => 'بررسی نرم‌افزاری', 'body' => 'ignored body', 'document' => ['version' => 1, 'blocks' => [$block]]];
    }

    private function page(ContentMedia $media): ContentPage
    {
        return app(ContentPublishing::class)->create($this->admin, 'article', 'image-test77', $this->definition($this->block($media)));
    }

    private function url(ContentMedia $media): string
    {
        return route('public.media', ['key' => $media->public_key, 'sha' => $this->library->metadata($media)['sha256']], false);
    }

    public function test_http_upload_is_private_encrypted_idempotent_and_requires_separate_publication(): void
    {
        $key = (string) Str::uuid();
        $input = ['title' => 'تصویر صرفاً آزمایشی', 'rights_confirmed' => true, 'request_key' => $key];
        $this->actingAs($this->admin)->post('/admin/media', [...$input, 'file' => $this->file()])->assertSessionHasNoErrors()->assertRedirect();
        $media = ContentMedia::sole();
        $this->assertStringNotContainsString('تصویر', $media->getRawOriginal('definition'));
        $this->assertNull($media->published_at);
        $this->get($this->url($media))->assertNotFound();
        $preview = $this->get('/admin/media/'.$media->public_key.'/preview')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($this->bytes(), $preview->streamedContent());
        $before = $media->getAttributes();
        $this->post('/admin/media', [...$input, 'file' => $this->file()])->assertSessionHasNoErrors();
        $this->assertSame($before, $media->fresh()->getAttributes());
        $this->assertCount(1, Storage::disk('learning')->allFiles());
        $this->post('/admin/media/'.$media->id.'/publish', ['revision' => 1])->assertSessionHasErrors('reviewed');
        $this->post('/admin/media/'.$media->id.'/publish', ['revision' => 1, 'reviewed' => true])->assertSessionHasNoErrors()->assertRedirect();
        $media->refresh();
        $this->assertSame(2, $media->revision);
        $this->assertSame($this->admin->id, $media->published_by);
        $this->assertNotNull($media->published_at);
        $public = $this->get($this->url($media))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($this->bytes(), $public->streamedContent());
        $this->assertStringContainsString('inline; filename=', $public->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('software-only', $public->headers->get('Content-Disposition'));
        $this->get('/media/'.$media->public_key.'/'.str_repeat('0', 64))->assertNotFound();
        $this->get('/admin/media/options')->assertJsonCount(1, 'items')->assertJsonMissing(['path' => $media->file_path]);
        $this->get('/admin/media/options?q=ناپیدا')->assertJsonCount(0, 'items');
        Http::assertNothingSent();
    }

    public function test_nonce_cannot_replace_different_bytes_title_or_file_name_and_withdrawn_retry_does_not_reopen(): void
    {
        $key = (string) Str::uuid();
        $media = $this->upload(['request_key' => $key]);
        $before = $media->getAttributes();
        $this->actingAs($this->admin)->post('/admin/media', ['title' => 'عنوان دیگر', 'rights_confirmed' => true, 'request_key' => $key, 'file' => $this->file()])->assertSessionHasErrors('request_key');
        $this->post('/admin/media', ['title' => 'تصویر صرفاً آزمایشی', 'rights_confirmed' => true, 'request_key' => $key, 'file' => $this->file('another.png')])->assertSessionHasErrors('request_key');
        $this->post('/admin/media', [...['title' => 'تصویر صرفاً آزمایشی', 'rights_confirmed' => true, 'request_key' => $key], 'file' => $this->file('software-only.png', $this->bytes().'changed')])->assertSessionHasErrors('request_key');
        $this->assertSame($before, $media->fresh()->getAttributes());
        $this->library->decide($this->admin, $media, 1, false, false);
        $withdrawn = $media->fresh()->getAttributes();
        $this->upload(['request_key' => $key]);
        $this->assertSame($withdrawn, $media->fresh()->getAttributes());
        $this->assertCount(1, Storage::disk('learning')->allFiles());
    }

    public function test_image_content_keeps_frozen_alt_caption_hash_and_hides_withdrawn_file_without_rewriting_history(): void
    {
        $media = $this->upload();
        $this->library->decide($this->admin, $media, 1, true, true);
        $block = $this->block($media, ['alt' => '<img onerror=alert(1)>', 'caption' => '<script>alert(1)</script>']);
        $page = app(ContentPublishing::class)->create($this->admin, 'article', 'image-test77', $this->definition($block));
        $publication = app(ContentPublishing::class)->publish($this->admin, $page, 1)->fresh();
        $before = $publication->getAttributes();
        $this->get($page->publicPath())->assertOk()->assertSee('data-content-image', false)->assertSee('loading="lazy"', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertSee('alt="&lt;img onerror=alert(1)&gt;"', false)->assertDontSee('<script>alert(1)</script>', false);
        $page->refresh();
        app(ContentPublishing::class)->draft($this->admin, $page, $page->revision, $this->definition([...$block, 'alt' => 'شرح جدید خصوصی']));
        $this->assertSame($before, $publication->fresh()->getAttributes());
        $this->library->decide($this->admin, $media, 2, false, false);
        $this->get($this->url($media))->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/media/'.$media->public_key.'/preview')->assertNotFound();
        $this->get($page->publicPath())->assertOk()->assertSee('data-unavailable-media', false)->assertDontSee('data-content-image', false);
        $this->assertSame($before, $publication->fresh()->getAttributes());
        $this->assertSame($this->bytes(), Storage::disk('learning')->get($media->file_path));
        $this->assertSame($media->definition, $media->fresh()->definition);
        $this->post('/admin/media/'.$media->id.'/publish', ['revision' => 3, 'reviewed' => true])->assertSessionHasErrors('media');
        $this->get('/admin/media/options')->assertJsonCount(0, 'items');
    }

    public function test_private_image_can_be_drafted_but_blocks_publication_and_scheduling_until_explicit_approval(): void
    {
        $media = $this->upload();
        $page = $this->page($media);
        $this->actingAs($this->admin)->post('/admin/content/'.$page->id.'/publish', ['revision' => 1])->assertSessionHasErrors('document');
        $this->assertSame(0, $page->fresh()->current_version);
        $this->expectException(ValidationException::class);
        app(ContentPublishing::class)->schedule($this->admin, $page, 1, CarbonImmutable::now()->addDay(), true);
    }

    public function test_withdrawal_before_due_time_blocks_schedule_without_publication_or_snapshot_rewrite(): void
    {
        $media = $this->upload();
        $this->library->decide($this->admin, $media, 1, true, true);
        $page = $this->page($media);
        $at = CarbonImmutable::now()->addDay();
        $schedule = app(ContentPublishing::class)->schedule($this->admin, $page, 1, $at, true);
        $snapshot = $schedule->definition;
        $this->library->decide($this->admin, $media, 2, false, false);
        $this->travelTo($at->addSecond());
        $this->assertSame('blocked', app(ContentPublishing::class)->processSchedule($schedule));
        $this->assertSame('invalid_content', $schedule->fresh()->reason);
        $this->assertSame($snapshot, $schedule->fresh()->definition);
        $this->assertSame(0, $page->fresh()->current_version);
    }

    public function test_current_authorization_and_revision_are_checked_before_media_changes(): void
    {
        $media = $this->upload();
        $before = $media->getAttributes();
        $this->admin->forceFill(['is_active' => false])->save();
        $this->actingAs($this->admin)->post('/admin/media/'.$media->id.'/publish', ['revision' => 1, 'reviewed' => true])->assertRedirect('/login');
        $this->assertSame($before, $media->fresh()->getAttributes());
        $this->admin->forceFill(['is_active' => true])->save();
        $this->admin->roles()->detach();
        try {
            $this->library->decide($this->admin, $media, 1, false, false);
            $this->fail('Expected current admin refusal.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame($before, $media->fresh()->getAttributes());
        $this->admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $this->actingAs($this->admin)->post('/admin/media/'.$media->id.'/withdraw', ['revision' => 8])->assertSessionHasErrors('media');
        $this->assertSame($before, $media->fresh()->getAttributes());
    }

    #[DataProvider('roles')]
    public function test_other_roles_cannot_list_preview_upload_publish_withdraw_or_select_private_media(string $role): void
    {
        $media = $this->upload();
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));
        $this->actingAs($user)->get('/admin/media')->assertForbidden();
        $this->get('/admin/media/options')->assertForbidden();
        $this->get('/admin/media/'.$media->public_key.'/preview')->assertForbidden();
        $this->post('/admin/media', ['title' => 'blocked', 'file' => $this->file(), 'rights_confirmed' => true, 'request_key' => (string) Str::uuid()])->assertForbidden();
        $this->post('/admin/media/'.$media->id.'/publish', ['revision' => 1, 'reviewed' => true])->assertForbidden();
        $this->post('/admin/media/'.$media->id.'/withdraw', ['revision' => 1])->assertForbidden();
        $this->get($this->url($media))->assertNotFound();
        $this->assertSame(1, ContentMedia::count());
        $this->assertSame(1, $media->fresh()->revision);
    }

    public static function roles(): array
    {
        return [['student'], ['coach'], ['org_admin']];
    }

    #[DataProvider('badFiles')]
    public function test_unsupported_fake_oversized_or_unconsented_uploads_do_not_store_files(string $name, string $content, bool $rights): void
    {
        $this->actingAs($this->admin)->post('/admin/media', ['title' => 'رد تصویر', 'file' => $this->file($name, $content === 'oversized' ? $this->bytes().str_repeat('x', 4194305) : $content), 'rights_confirmed' => $rights, 'request_key' => (string) Str::uuid()])->assertSessionHasErrors();
        $this->assertSame(0, ContentMedia::count());
        $this->assertSame([], Storage::disk('learning')->allFiles());
    }

    public static function badFiles(): array
    {
        return [['x.svg', '<svg/>', true], ['x.html', '<script>alert(1)</script>', true], ['x.php', '<?php echo 1;', true], ['x.png', 'not-an-image', true], ['x.png', 'oversized', true], ['x.pdf', '%PDF-1.4', true], ['x.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1kAAAAASUVORK5CYII='), false]];
    }

    public function test_ciphertext_path_and_byte_tampering_or_missing_file_fail_closed(): void
    {
        $one = $this->upload();
        $two = $this->upload();
        $this->library->decide($this->admin, $one, 1, true, true);
        $one->refresh();
        $url = $this->url($one);
        $original = $one->getRawOriginal('definition');
        ContentMedia::whereKey($one->id)->update(['definition' => $two->getRawOriginal('definition')]);
        $this->get($url)->assertNotFound();
        ContentMedia::whereKey($one->id)->update(['definition' => $original, 'file_path' => $two->file_path]);
        $this->get($url)->assertNotFound();
        ContentMedia::whereKey($one->id)->update(['file_path' => $one->file_path]);
        Storage::disk('learning')->put($one->file_path, 'changed bytes');
        $this->get($url)->assertNotFound();
        Storage::disk('learning')->delete($one->file_path);
        $this->get($url)->assertNotFound();
        $this->assertNull($this->library->metadata($one->fresh()->forceFill(['definition' => ['id' => 1]])));
    }

    public function test_failed_audit_rolls_back_upload_and_removes_only_its_new_file(): void
    {
        $one = $this->upload();
        $original = Storage::disk('learning')->allFiles();
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'content_media.uploaded') {
                throw new \RuntimeException('Synthetic audit failure.');
            }
        });
        try {
            $this->upload();
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic audit failure.', $e->getMessage());
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertSame(1, ContentMedia::count());
        $this->assertSame($original, Storage::disk('learning')->allFiles());
        $this->assertSame($this->bytes(), Storage::disk('learning')->get($one->file_path));
    }

    public function test_published_jpeg_is_available_to_guest_with_original_bytes_and_dimensions(): void
    {
        $bytes = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/4gHYSUNDX1BST0ZJTEUAAQEAAAHIAAAAAAQwAABtbnRyUkdCIFhZWiAH4AABAAEAAAAAAABhY3NwAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAQAA9tYAAQAAAADTLQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAlkZXNjAAAA8AAAACRyWFlaAAABFAAAABRnWFlaAAABKAAAABRiWFlaAAABPAAAABR3dHB0AAABUAAAABRyVFJDAAABZAAAAChnVFJDAAABZAAAAChiVFJDAAABZAAAAChjcHJ0AAABjAAAADxtbHVjAAAAAAAAAAEAAAAMZW5VUwAAAAgAAAAcAHMAUgBHAEJYWVogAAAAAAAAb6IAADj1AAADkFhZWiAAAAAAAABimQAAt4UAABjaWFlaIAAAAAAAACSgAAAPhAAAts9YWVogAAAAAAAA9tYAAQAAAADTLXBhcmEAAAAAAAQAAAACZmYAAPKnAAANWQAAE9AAAApbAAAAAAAAAABtbHVjAAAAAAAAAAEAAAAMZW5VUwAAACAAAAAcAEcAbwBvAGcAbABlACAASQBuAGMALgAgADIAMAAxADb/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkUDQsNFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCAAYACADASIAAhEBAxEB/8QAFgABAQEAAAAAAAAAAAAAAAAAAAgJ/8QAGBABAAMBAAAAAAAAAAAAAAAAABhmpeP/xAAXAQEAAwAAAAAAAAAAAAAAAAAABgcI/8QAGhEBAAEFAAAAAAAAAAAAAAAAAAIFFlOi0v/aAAwDAQACEQMRAD8AzyFGRBtub1Ig23N6pmra76Jn1nynMUZEG25vUiDbc3qF30TPrPlRgAzaAA//2Q==');
        $media = $this->library->upload($this->admin, ['title' => 'Software-only JPEG77', 'rights_confirmed' => true, 'request_key' => (string) Str::uuid()], $this->file('software-only.jpg', $bytes));
        $this->library->decide($this->admin, $media, 1, true, true);
        $metadata = $this->library->metadata($media);
        $this->assertSame([32, 24, 'image/jpeg'], [$metadata['width'], $metadata['height'], $metadata['mime']]);
        $this->assertGuest();
        $response = $this->get($this->url($media))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame($bytes, $response->streamedContent());
    }

    #[DataProvider('excessiveDimensions')]
    public function test_excessive_dimensions_are_rejected_without_storage(int $width, int $height): void
    {
        $bytes = $this->bytes();
        $header = pack('NN', $width, $height).substr($bytes, 24, 5);
        $bytes = substr($bytes, 0, 16).$header.pack('N', crc32('IHDR'.$header)).substr($bytes, 33);
        $this->actingAs($this->admin)->post('/admin/media', ['title' => 'Software-only oversized dimensions77', 'rights_confirmed' => true, 'request_key' => (string) Str::uuid(), 'file' => $this->file('too-wide.png', $bytes)])->assertSessionHasErrors('file');
        $this->assertSame(0, ContentMedia::count());
        $this->assertSame([], Storage::disk('learning')->allFiles());
    }

    public static function excessiveDimensions(): array
    {
        return [[6001, 1], [4000, 4000]];
    }

    public function test_unknown_media_hash_or_extra_executable_fields_cannot_enter_a_draft(): void
    {
        $media = $this->upload();
        foreach ([['media' => (string) Str::uuid()], ['sha256' => str_repeat('0', 64)], ['src' => 'javascript:alert(1)'], ['alt' => ''], ['alt' => str_repeat('a', 241)], ['caption' => str_repeat('a', 321)]] as $extra) {
            try {
                app(ContentPublishing::class)->create($this->admin, 'article', 'image-test77', $this->definition($this->block($media, $extra)));
                $this->fail('Expected invalid image refusal.');
            } catch (ValidationException) {
                $this->assertSame(0, ContentPage::count());
            }
        }
        $this->expectException(ValidationException::class);
        app(ContentDocument::class)->normalize(['version' => 1, 'blocks' => array_fill(0, 21, $this->block($media))]);
    }
}
