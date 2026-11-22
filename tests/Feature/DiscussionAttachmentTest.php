<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseDiscussion;
use App\Models\DiscussionAttachment;
use App\Models\DiscussionPost;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\User;
use App\Services\CourseDiscussions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DiscussionAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $peer;

    private User $coach;

    private User $admin;

    private User $outsider;

    private Cohort $cohort;

    private CourseDiscussion $discussion;

    private DiscussionPost $post;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('learning');
        Http::preventStrayRequests();
        $this->student = $this->user('student');
        $this->peer = $this->user('student');
        $this->coach = $this->user('coach');
        $this->admin = $this->user('admin');
        $this->outsider = $this->user('student');
        $this->cohort = Cohort::factory()->create(['course_id' => Course::factory()->state(['coach_id' => $this->coach->id, 'is_published' => true])]);
        foreach ([$this->student, $this->peer] as $student) {
            Enrollment::factory()->create(['user_id' => $student->id, 'cohort_id' => $this->cohort->id]);
        }
        $this->discussion = app(CourseDiscussions::class)->create($this->student, $this->cohort, ['title' => 'پرسش صرفاً آزمایشی درباره فایل', 'body' => 'محتوای نرم‌افزاری برای بررسی ضمیمه خصوصی دوره', 'kind' => 'question', 'request_key' => (string) Str::uuid()]);
        $this->post = $this->discussion->posts()->sole();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function file(string $name = 'نمونه خصوصی.pdf', string $suffix = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% SOFTWARE-ONLY-NO-REAL-DOCUMENT73".$suffix."\n%%EOF\n");
    }

    private function data(array $changes = []): array
    {
        return [...['revision' => $this->post->fresh()->revision, 'request_key' => (string) Str::uuid(), 'sharing_policy' => 'active_cohort_members_v1', 'confirmed' => true], ...$changes];
    }

    private function upload(array $data = [], ?UploadedFile $file = null): DiscussionAttachment
    {
        return app(CourseDiscussions::class)->attach($this->student, $this->post, $this->data($data), $file ?? $this->file());
    }

    private function snapshot(): string
    {
        $state = [];
        foreach (['discussion_attachments', 'discussion_posts', 'discussion_post_revisions', 'course_discussions', 'audit_events', 'notifications'] as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $state[$table] = $rows;
        }
        $files = Storage::disk('learning')->allFiles();
        sort($files, SORT_STRING);
        $state['files'] = $files;

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function test_upload_is_private_bound_encrypted_and_recorded_in_message_history(): void
    {
        $file = $this->file();
        $bytes = file_get_contents($file->getRealPath());
        $this->actingAs($this->student)->post('/discussions/'.$this->discussion->id.'/posts/'.$this->post->id.'/attachments', [...$this->data(), 'file' => $file])->assertSessionHasNoErrors()->assertRedirect();
        $attachment = DiscussionAttachment::sole();
        $metadata = app(CourseDiscussions::class)->attachmentMetadata($attachment);
        $this->assertSame($this->student->id, $metadata['owner_id']);
        $this->assertSame($this->post->id, $metadata['post_id']);
        $this->assertSame(hash('sha256', $bytes), $metadata['sha256']);
        $this->assertSame(CourseDiscussions::ATTACHMENT_CONSENT, $metadata['consent_text']);
        $this->assertStringNotContainsString('نمونه خصوصی', $attachment->getRawOriginal('definition'));
        Storage::disk('learning')->assertExists($attachment->file_path);
        $this->assertSame(2, $this->post->fresh()->revision);
        $history = $this->post->revisions()->where('revision', 2)->sole();
        $this->assertSame('نمونه خصوصی.pdf', $history->attachments[0]['file_name']);
        $this->assertStringNotContainsString('نمونه خصوصی', $history->getRawOriginal('attachments'));
        $response = $this->get('/discussion-attachments/'.$attachment->id.'/file')->assertOk()->assertDownload()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($bytes, $response->streamedContent());
        $this->get('/discussions/'.$this->discussion->id)->assertInertia(fn (Assert $page) => $page->where('posts.data.0.attachments.0.file_name', 'نمونه خصوصی.pdf')->missing('posts.data.0.attachments.0.file_path')->missing('posts.data.0.attachments.0.definition'));
        $this->assertDatabaseHas('audit_events', ['action' => 'discussion.attachment_added', 'actor_id' => $this->student->id]);
        Http::assertNothingSent();
    }

    public static function readers(): array
    {
        return [['student'], ['peer'], ['coach'], ['admin']];
    }

    #[DataProvider('readers')]
    public function test_current_authorized_course_participants_can_download(string $role): void
    {
        $attachment = $this->upload();
        $this->actingAs($this->{$role})->get('/discussion-attachments/'.$attachment->id.'/file')->assertOk()->assertDownload();
    }

    public static function deniedReaders(): array
    {
        return array_map(fn ($change) => [$change], ['outsider', 'org_admin', 'unassigned_coach', 'owner_role', 'owner_phone', 'owner_inactive', 'owner_enrollment', 'coach_role', 'coach_inactive', 'coach_assignment']);
    }

    #[DataProvider('deniedReaders')]
    public function test_current_access_loss_or_wrong_role_refuses_file_bytes(string $change): void
    {
        $attachment = $this->upload();
        $actor = $this->student;
        if ($change === 'outsider') {
            $actor = $this->outsider;
        } elseif ($change === 'org_admin') {
            $actor = $this->user('org_admin');
        } elseif ($change === 'unassigned_coach') {
            $actor = $this->user('coach');
        } elseif ($change === 'owner_role') {
            $this->student->roles()->sync([Role::where('key', 'org_admin')->value('id')]);
        } elseif ($change === 'owner_phone') {
            $this->student->update(['phone_verified_at' => null]);
        } elseif ($change === 'owner_inactive') {
            User::whereKey($this->student->id)->update(['is_active' => false]);
        } elseif ($change === 'owner_enrollment') {
            Enrollment::where('user_id', $this->student->id)->update(['status' => 'cancelled']);
        } else {
            $actor = $this->coach;
            if ($change === 'coach_role') {
                $actor->roles()->sync([Role::where('key', 'student')->value('id')]);
            } elseif ($change === 'coach_inactive') {
                User::whereKey($actor->id)->update(['is_active' => false]);
            } else {
                $this->cohort->course->update(['coach_id' => null]);
            }
        }
        $before = $this->snapshot();
        try {
            app(CourseDiscussions::class)->downloadAttachment($actor, $attachment);
            $this->fail('Current access is required.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public static function invalidFiles(): array
    {
        return [['script.php'], ['vector.svg'], ['page.html'], ['wrong-extension.exe'], ['empty.txt'], ['large.pdf']];
    }

    #[DataProvider('invalidFiles')]
    public function test_invalid_upload_never_writes_file_or_revision(string $name): void
    {
        $file = match ($name) {
            'vector.svg' => UploadedFile::fake()->createWithContent($name, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'page.html' => UploadedFile::fake()->createWithContent($name, '<html><script>alert(1)</script></html>'), 'empty.txt' => UploadedFile::fake()->createWithContent($name, ''), 'large.pdf' => $this->file($name, str_repeat('a', 5242880)), default => $this->file($name)
        };
        $before = $this->snapshot();
        $this->actingAs($this->student)->post('/discussions/'.$this->discussion->id.'/posts/'.$this->post->id.'/attachments', [...$this->data(), 'file' => $file])->assertSessionHasErrors();
        $this->assertSame($before, $this->snapshot());
    }

    public static function invalidInputs(): array
    {
        return [['confirmed', false], ['sharing_policy', 'different-policy'], ['request_key', 'bad-key'], ['revision', 50]];
    }

    #[DataProvider('invalidInputs')]
    public function test_consent_policy_nonce_and_stale_post_revision_are_required(string $key, mixed $value): void
    {
        $before = $this->snapshot();
        $this->actingAs($this->student)->post('/discussions/'.$this->discussion->id.'/posts/'.$this->post->id.'/attachments', [...$this->data([$key => $value]), 'file' => $this->file()])->assertSessionHasErrors();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_upload_replay_is_idempotent_but_changed_content_is_rejected(): void
    {
        $key = (string) Str::uuid();
        $attachment = $this->upload(['request_key' => $key]);
        $before = $this->snapshot();
        $again = $this->upload(['request_key' => strtoupper($key), 'revision' => 1]);
        $this->assertSame($attachment->id, $again->id);
        $this->assertSame($before, $this->snapshot());
        $this->actingAs($this->student)->post('/discussions/'.$this->discussion->id.'/posts/'.$this->post->id.'/attachments', [...$this->data(['request_key' => $key]), 'file' => $this->file(suffix: 'CHANGED')])->assertSessionHasErrors();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_owner_and_membership_binding_prevent_upload_to_another_post_or_discussion(): void
    {
        $peerPost = app(CourseDiscussions::class)->reply($this->peer, $this->discussion, 'پاسخ آزمایشی عضو دیگر', (string) Str::uuid());
        $this->actingAs($this->student)->post('/discussions/'.$this->discussion->id.'/posts/'.$peerPost->id.'/attachments', [...$this->data(), 'file' => $this->file()])->assertForbidden();
        $other = CourseDiscussion::factory()->create();
        $this->post('/discussions/'.$this->discussion->id.'/posts/'.DiscussionPost::factory()->create(['course_discussion_id' => $other->id])->id.'/attachments', [...$this->data(), 'file' => $this->file()])->assertNotFound();
        $this->actingAs($this->outsider)->post('/discussions/'.$this->discussion->id.'/posts/'.$this->post->id.'/attachments', [...$this->data(), 'file' => $this->file()])->assertForbidden();
        $this->assertDatabaseCount('discussion_attachments', 0);
        $this->assertSame([], Storage::disk('learning')->allFiles());
    }

    public function test_active_and_lifetime_limits_keep_all_previous_files_and_history(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->upload(file: $this->file(suffix: (string) $i));
        }
        $before = $this->snapshot();
        $this->actingAs($this->student)->post('/discussions/'.$this->discussion->id.'/posts/'.$this->post->id.'/attachments', [...$this->data(), 'file' => $this->file()])->assertSessionHasErrors();
        $this->assertSame($before, $this->snapshot());
        foreach (DiscussionAttachment::all() as $attachment) {
            app(CourseDiscussions::class)->withdrawAttachment($this->student, $attachment, ['revision' => 1]);
        }
        for ($i = 3; $i < 10; $i++) {
            $attachment = $this->upload(file: $this->file(suffix: (string) $i));
            app(CourseDiscussions::class)->withdrawAttachment($this->student, $attachment, ['revision' => 1]);
        }
        $before = $this->snapshot();
        $this->post('/discussions/'.$this->discussion->id.'/posts/'.$this->post->id.'/attachments', [...$this->data(), 'file' => $this->file()])->assertSessionHasErrors();
        $this->assertSame($before, $this->snapshot());
        $this->assertCount(10, Storage::disk('learning')->allFiles());
    }

    public function test_owner_can_withdraw_after_role_and_enrollment_loss_and_cannot_download_again(): void
    {
        $attachment = $this->upload();
        $this->student->roles()->sync([Role::where('key', 'org_admin')->value('id')]);
        Enrollment::where('user_id', $this->student->id)->update(['status' => 'cancelled']);
        $this->actingAs($this->student)->get('/discussion-attachments?owner_id='.$this->peer->id)->assertInertia(fn (Assert $page) => $page->where('files.total', 1)->where('files.data.0.file_url', null)->missing('files.data.0.file_path'));
        $this->post('/discussion-attachments/'.$attachment->id.'/withdraw', ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertNotNull($attachment->fresh()->withdrawn_at);
        Storage::disk('learning')->assertExists($attachment->file_path);
        $this->actingAs($this->admin)->get('/discussion-attachments/'.$attachment->id.'/file')->assertNotFound();
        $this->actingAs($this->peer)->get('/discussion-attachments')->assertInertia(fn (Assert $page) => $page->where('files.total', 0));
        $before = $this->snapshot();
        $this->actingAs($this->student)->post('/discussion-attachments/'.$attachment->id.'/withdraw', ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_staff_withdrawal_requires_current_assignment_and_reason(): void
    {
        $attachment = $this->upload();
        $this->actingAs($this->peer)->post('/discussion-attachments/'.$attachment->id.'/withdraw', ['revision' => 1])->assertForbidden();
        $this->actingAs($this->coach)->post('/discussion-attachments/'.$attachment->id.'/withdraw', ['revision' => 1])->assertSessionHasErrors();
        $this->post('/discussion-attachments/'.$attachment->id.'/withdraw', ['revision' => 1, 'reason' => 'رسیدگی صرفاً آزمایشی'])->assertSessionHasNoErrors();
        $this->assertSame($this->coach->id, $attachment->fresh()->withdrawn_by);
        $this->assertSame(3, $this->post->fresh()->revision);
        $this->get('/discussions/'.$this->discussion->id.'/posts/'.$this->post->id.'/history')->assertInertia(fn (Assert $page) => $page->where('revisions.data.0.attachments', [])->where('revisions.data.1.attachments.0.file_name', 'نمونه خصوصی.pdf'));
    }

    public function test_hidden_post_hides_attachment_metadata_from_peer_but_staff_can_review_and_owner_can_withdraw(): void
    {
        $attachment = $this->upload();
        app(CourseDiscussions::class)->moderatePost($this->coach, $this->post, 2, true, 'بررسی نرم‌افزاری');
        $this->actingAs($this->peer)->get('/discussions/'.$this->discussion->id)->assertInertia(fn (Assert $page) => $page->missing('posts.data.0.attachments')->where('posts.data.0.body', null));
        $this->get('/discussion-attachments/'.$attachment->id.'/file')->assertNotFound();
        $this->actingAs($this->coach)->get('/discussion-attachments/'.$attachment->id.'/file')->assertOk();
        $this->actingAs($this->student)->post('/discussion-attachments/'.$attachment->id.'/withdraw', ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertNotNull($attachment->fresh()->withdrawn_at);
    }

    public function test_locked_thread_blocks_upload_but_not_owner_withdrawal(): void
    {
        $attachment = $this->upload();
        $this->discussion->update(['locked' => true]);
        $this->actingAs($this->student)->post('/discussions/'.$this->discussion->id.'/posts/'.$this->post->id.'/attachments', [...$this->data(), 'file' => $this->file()])->assertSessionHasErrors();
        $this->post('/discussion-attachments/'.$attachment->id.'/withdraw', ['revision' => 1])->assertSessionHasNoErrors();
    }

    public function test_attachment_changes_clear_selected_answer_and_text_edit_preserves_file_snapshot(): void
    {
        $reply = app(CourseDiscussions::class)->reply($this->coach, $this->discussion, 'پاسخ نرم‌افزاری مدرس', (string) Str::uuid());
        app(CourseDiscussions::class)->accept($this->student, $this->discussion, 1, $reply->id);
        $attachment = $this->upload();
        $this->assertNull($this->discussion->fresh()->accepted_post_id);
        app(CourseDiscussions::class)->edit($this->student, $this->post, 2, 'متن بعدی نرم‌افزاری با حفظ ضمیمه');
        $this->assertSame('نمونه خصوصی.pdf', $this->post->revisions()->where('revision', 3)->sole()->attachments[0]['file_name']);
        app(CourseDiscussions::class)->accept($this->student, $this->discussion->fresh(), $this->discussion->fresh()->revision, $reply->id);
        app(CourseDiscussions::class)->withdrawAttachment($this->student, $attachment, ['revision' => 1]);
        $this->assertNull($this->discussion->fresh()->accepted_post_id);
    }

    public static function corruptions(): array
    {
        return [['bytes'], ['missing'], ['cipher'], ['swapped_cipher'], ['path']];
    }

    #[DataProvider('corruptions')]
    public function test_missing_modified_or_cross_bound_file_never_downloads(string $change): void
    {
        $attachment = $this->upload();
        if ($change === 'bytes') {
            Storage::disk('learning')->put($attachment->file_path, 'MODIFIED');
        } elseif ($change === 'missing') {
            Storage::disk('learning')->delete($attachment->file_path);
        } elseif ($change === 'cipher') {
            DB::table('discussion_attachments')->where('id', $attachment->id)->update(['definition' => 'bad-cipher']);
        } elseif ($change === 'path') {
            $attachment->update(['file_path' => 'coach-credentials/private-source.pdf']);
        } else {
            $other = $this->upload(file: $this->file(suffix: 'OTHER'));
            DB::table('discussion_attachments')->where('id', $attachment->id)->update(['definition' => $other->getRawOriginal('definition')]);
        }
        $this->actingAs($this->peer)->get('/discussion-attachments/'.$attachment->id.'/file')->assertNotFound();
    }

    public function test_transaction_failure_cleans_new_file_without_changing_message_or_history(): void
    {
        $before = $this->snapshot();
        Event::listen('eloquent.creating: '.AuditEvent::class, function (AuditEvent $event): void {
            if ($event->action === 'discussion.attachment_added') {
                throw new RuntimeException('Software-only storage rollback check.');
            }
        });
        try {
            $this->upload();
            $this->fail('The audit failure must roll back the upload.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Software-only storage rollback check.', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('discussion_attachments', 0);
        $this->assertSame([], Storage::disk('learning')->allFiles());
    }
}
