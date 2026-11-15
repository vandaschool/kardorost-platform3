<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseDiscussion;
use App\Models\DiscussionPost;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\User;
use App\Services\CourseDiscussions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CourseDiscussionTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $peer;

    private User $coach;

    private User $admin;

    private Cohort $cohort;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->student = $this->user('student');
        $this->peer = $this->user('student');
        $this->coach = $this->user('coach');
        $this->admin = $this->user('admin');
        $this->cohort = Cohort::factory()->create(['course_id' => Course::factory()->create(['coach_id' => $this->coach->id])->id]);
        foreach ([$this->student, $this->peer] as $student) {
            Enrollment::factory()->create(['user_id' => $student->id, 'cohort_id' => $this->cohort->id]);
        }
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function data(array $changes = []): array
    {
        return [...['title' => 'پرسش درباره مسیر یادگیری', 'body' => 'این بخش از درس را چطور تمرین کنم؟', 'kind' => 'question', 'request_key' => (string) Str::uuid()], ...$changes];
    }

    private function discussion(array $changes = []): CourseDiscussion
    {
        return app(CourseDiscussions::class)->create($this->student, $this->cohort, $this->data($changes));
    }

    private function reply(CourseDiscussion $discussion, ?User $actor = null, string $body = 'پاسخ آزمایشی مدرس'): DiscussionPost
    {
        return app(CourseDiscussions::class)->reply($actor ?? $this->coach, $discussion, $body, (string) Str::uuid());
    }

    private function moderation(CourseDiscussion $discussion, array $changes = []): array
    {
        return [...$discussion->fresh()->only('revision', 'hidden', 'locked', 'pinned'), 'reason' => 'بررسی مدرس و مدیریت دوره', ...$changes];
    }

    public function test_only_active_same_cohort_members_assigned_coach_and_admin_can_read_or_write(): void
    {
        $discussion = $this->discussion();
        foreach ([$this->student, $this->peer, $this->coach, $this->admin] as $user) {
            $this->actingAs($user)->get('/cohorts/'.$this->cohort->id.'/discussions')->assertOk();
            $this->get('/discussions/'.$discussion->id)->assertOk();
        }
        $otherStudent = $this->user('student');
        $otherCohort = Cohort::factory()->create(['course_id' => $this->cohort->course_id]);
        Enrollment::factory()->create(['user_id' => $otherStudent->id, 'cohort_id' => $otherCohort->id]);
        foreach ([$otherStudent, $this->user('coach'), $this->user('org_admin')] as $user) {
            $this->actingAs($user)->get('/cohorts/'.$this->cohort->id.'/discussions')->assertForbidden();
            $this->get('/discussions/'.$discussion->id)->assertForbidden();
            $this->post('/cohorts/'.$this->cohort->id.'/discussions', $this->data())->assertForbidden();
            $this->post('/discussions/'.$discussion->id.'/posts', ['body' => 'غیرمجاز', 'request_key' => (string) Str::uuid()])->assertForbidden();
        }
    }

    public function test_pending_cancelled_or_unverified_members_have_no_access(): void
    {
        foreach (['pending_payment', 'cancelled'] as $status) {
            Enrollment::where('user_id', $this->student->id)->update(['status' => $status]);
            $this->actingAs($this->student)->get('/cohorts/'.$this->cohort->id.'/discussions')->assertForbidden();
        }
        Enrollment::where('user_id', $this->student->id)->update(['status' => 'active']);
        $this->student->update(['phone_verified_at' => null]);
        $this->get('/cohorts/'.$this->cohort->id.'/discussions')->assertForbidden();
    }

    public function test_creation_validates_content_and_does_not_trust_client_privileges(): void
    {
        $url = '/cohorts/'.$this->cohort->id.'/discussions';
        $this->actingAs($this->student)->post($url, $this->data(['body' => '']))->assertSessionHasErrors('body');
        $this->post($url, $this->data(['request_key' => 'invalid']))->assertSessionHasErrors('request_key');
        $this->post($url, $this->data(['author_id' => $this->admin->id, 'pinned' => true, 'hidden' => true]))->assertSessionHasNoErrors();
        $discussion = CourseDiscussion::firstOrFail();
        $this->assertSame($this->student->id, $discussion->author_id);
        $this->assertFalse($discussion->pinned);
        $this->assertFalse($discussion->hidden);
        $this->assertSame('student', $discussion->posts()->first()->author_role);
        $this->assertDatabaseCount('discussion_post_revisions', 1);
    }

    public function test_creation_replay_is_idempotent_and_changed_payload_is_rejected(): void
    {
        $data = $this->data();
        $url = '/cohorts/'.$this->cohort->id.'/discussions';
        $this->actingAs($this->student)->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, [...$data, 'request_key' => strtoupper($data['request_key'])])->assertSessionHasNoErrors();
        $this->post($url, [...$data, 'body' => 'متن متفاوت'])->assertSessionHasErrors('discussion');
        $this->assertDatabaseCount('course_discussions', 1);
        $this->assertDatabaseCount('discussion_posts', 1);
        $this->assertSame(1, AuditEvent::where('action', 'discussion.created')->count());
        $this->assertSame(1, $this->coach->notifications()->count());
    }

    public function test_reply_replay_does_not_duplicate_post_revision_or_notifications(): void
    {
        $discussion = $this->discussion();
        $data = ['body' => 'پاسخ روشن مدرس', 'request_key' => (string) Str::uuid()];
        $url = '/discussions/'.$discussion->id.'/posts';
        $this->actingAs($this->coach)->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, [...$data, 'body' => 'پاسخ متفاوت'])->assertSessionHasErrors('discussion');
        $this->assertDatabaseCount('discussion_posts', 2);
        $this->assertDatabaseCount('discussion_post_revisions', 2);
        $this->assertSame(1, $this->student->notifications()->count());
    }

    public function test_reply_validates_content_and_cannot_impersonate_staff(): void
    {
        $discussion = $this->discussion();
        $url = '/discussions/'.$discussion->id.'/posts';
        $data = ['body' => 'پاسخ هم‌کلاسی', 'request_key' => (string) Str::uuid()];
        $this->actingAs($this->peer)->post($url, [...$data, 'body' => ''])->assertSessionHasErrors('body');
        $this->post($url, [...$data, 'author_role' => 'admin', 'author_id' => $this->admin->id])->assertSessionHasNoErrors();
        $post = DiscussionPost::latest('id')->first();
        $this->assertSame($this->peer->id, $post->author_id);
        $this->assertSame('student', $post->author_role);
    }

    public function test_only_author_can_edit_body_and_each_version_is_preserved(): void
    {
        $discussion = $this->discussion();
        $post = $discussion->posts()->first();
        $url = '/discussions/'.$discussion->id.'/posts/'.$post->id;
        foreach ([$this->peer, $this->coach, $this->admin] as $user) {
            $this->actingAs($user)->patch($url, ['revision' => 1, 'body' => 'بازنویسی غیرمجاز'])->assertForbidden();
        }
        $this->actingAs($this->student)->patch($url, ['revision' => 1, 'body' => 'پرسش روشن‌تر'])->assertSessionHasNoErrors();
        $this->patch($url, ['revision' => 1, 'body' => 'فرم قدیمی'])->assertSessionHasErrors('discussion');
        $this->assertSame('پرسش روشن‌تر', $post->fresh()->body);
        $this->assertSame(2, $post->revisions()->count());
        $this->assertSame('این بخش از درس را چطور تمرین کنم؟', $post->revisions()->where('revision', 1)->first()->body);
    }

    public function test_message_history_is_restricted_to_current_assigned_staff(): void
    {
        $discussion = $this->discussion();
        $post = $discussion->posts()->first();
        $url = '/discussions/'.$discussion->id.'/posts/'.$post->id.'/history';
        $this->actingAs($this->student)->get($url)->assertForbidden();
        $this->actingAs($this->peer)->get($url)->assertForbidden();
        $this->actingAs($this->coach)->get($url)->assertInertia(fn (Assert $page) => $page->has('revisions.data', 1));
        $this->cohort->course->update(['coach_id' => $this->user('coach')->id]);
        $this->get($url)->assertForbidden();
        $this->actingAs($this->admin)->get($url)->assertOk();
    }

    public function test_hidden_post_body_and_internal_reason_are_redacted_from_all_students(): void
    {
        $discussion = $this->discussion();
        $post = $this->reply($discussion, $this->peer, 'متن پنهان خصوصی');
        app(CourseDiscussions::class)->moderatePost($this->coach, $post, 1, true, 'دلیل داخلی مدرس');
        foreach ([$this->student, $this->peer] as $user) {
            $this->actingAs($user)->get('/discussions/'.$discussion->id)->assertInertia(fn (Assert $page) => $page->where('posts.data.1.hidden', true)->where('posts.data.1.body', null)->where('posts.data.1.author_name', null)->missing('posts.data.1.moderation_reason'));
        }
        $this->actingAs($this->coach)->get('/discussions/'.$discussion->id)->assertInertia(fn (Assert $page) => $page->where('posts.data.1.body', 'متن پنهان خصوصی')->where('posts.data.1.moderation_reason', 'دلیل داخلی مدرس'));
        $this->assertDatabaseCount('discussion_post_revisions', 3);
    }

    public function test_hidden_thread_is_absent_for_peers_and_only_placeholder_is_visible_to_owner(): void
    {
        $discussion = $this->discussion(['title' => 'عنوان پنهان حساس']);
        $this->reply($discussion);
        app(CourseDiscussions::class)->moderate($this->coach, $discussion, $this->moderation($discussion, ['hidden' => true]));
        $this->actingAs($this->peer)->get('/discussions/'.$discussion->id)->assertNotFound();
        $this->get('/cohorts/'.$this->cohort->id.'/discussions')->assertInertia(fn (Assert $page) => $page->has('discussions.data', 0));
        $this->actingAs($this->student)->get('/discussions/'.$discussion->id)->assertInertia(fn (Assert $page) => $page->where('discussion.title', 'گفت‌وگوی پنهان‌شده')->has('posts.data', 0)->missing('discussion.moderation_reason'));
        $this->get('/cohorts/'.$this->cohort->id.'/discussions')->assertInertia(fn (Assert $page) => $page->where('discussions.data.0.title', 'گفت‌وگوی پنهان‌شده'));
        $this->actingAs($this->coach)->get('/discussions/'.$discussion->id)->assertInertia(fn (Assert $page) => $page->where('discussion.title', 'عنوان پنهان حساس')->has('posts.data', 2));
    }

    public function test_lock_stops_new_replies_and_body_edits_until_staff_unlocks(): void
    {
        $discussion = $this->discussion();
        $post = $discussion->posts()->first();
        app(CourseDiscussions::class)->moderate($this->coach, $discussion, $this->moderation($discussion, ['locked' => true]));
        $this->actingAs($this->student)->post('/discussions/'.$discussion->id.'/posts', ['body' => 'ارسال در حالت بسته', 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('discussion');
        $this->patch('/discussions/'.$discussion->id.'/posts/'.$post->id, ['revision' => 1, 'body' => 'ویرایش در حالت بسته'])->assertSessionHasErrors('discussion');
        app(CourseDiscussions::class)->moderate($this->coach, $discussion, $this->moderation($discussion, ['locked' => false]));
        $this->reply($discussion);
        $this->assertDatabaseCount('discussion_posts', 2);
    }

    public function test_moderation_is_staff_scoped_versioned_and_requires_reason(): void
    {
        $discussion = $this->discussion();
        $post = $discussion->posts()->first();
        $url = '/discussions/'.$discussion->id.'/moderate';
        $this->actingAs($this->student)->post($url, $this->moderation($discussion, ['locked' => true]))->assertForbidden();
        $this->post('/discussions/'.$discussion->id.'/posts/'.$post->id.'/moderate', ['revision' => 1, 'hidden' => true, 'reason' => 'غیرمجاز'])->assertForbidden();
        $this->actingAs($this->coach)->post($url, $this->moderation($discussion, ['reason' => '']))->assertSessionHasErrors('reason');
        $this->post($url, $this->moderation($discussion, ['pinned' => true]))->assertSessionHasNoErrors();
        $this->post($url, $this->moderation($discussion, ['revision' => 1, 'hidden' => true]))->assertSessionHasErrors('discussion');
        $this->assertFalse($discussion->fresh()->hidden);
        $this->assertTrue($discussion->fresh()->pinned);
    }

    public function test_author_or_staff_can_select_a_visible_reply_only_from_same_question(): void
    {
        $discussion = $this->discussion();
        $reply = $this->reply($discussion);
        $other = $this->reply($this->discussion());
        $url = '/discussions/'.$discussion->id.'/answer';
        $this->actingAs($this->peer)->post($url, ['revision' => 1, 'post_id' => $reply->id])->assertForbidden();
        $this->actingAs($this->student)->post($url, ['revision' => 1, 'post_id' => $discussion->posts()->first()->id])->assertSessionHasErrors('discussion');
        $this->post($url, ['revision' => 1, 'post_id' => $other->id])->assertSessionHasErrors('discussion');
        $this->post($url, ['revision' => 1, 'post_id' => $reply->id])->assertSessionHasNoErrors();
        $this->assertSame($reply->id, $discussion->fresh()->accepted_post_id);
        $this->post($url, ['revision' => 1, 'post_id' => null])->assertSessionHasErrors('discussion');
    }

    public function test_editing_selected_answer_or_original_question_clears_old_acceptance(): void
    {
        $discussion = $this->discussion();
        $reply = $this->reply($discussion);
        $service = app(CourseDiscussions::class);
        $service->accept($this->student, $discussion, 1, $reply->id);
        $service->edit($this->coach, $reply, 1, 'پاسخ تازه با مفهوم متفاوت');
        $this->assertNull($discussion->fresh()->accepted_post_id);
        $service->accept($this->student, $discussion, $discussion->fresh()->revision, $reply->id);
        $service->edit($this->student, $discussion->posts()->first(), 1, 'پرسش تازه با مفهوم متفاوت');
        $this->assertNull($discussion->fresh()->accepted_post_id);
    }

    public function test_hiding_selected_answer_clears_acceptance_and_unhide_does_not_restore_it(): void
    {
        $discussion = $this->discussion();
        $reply = $this->reply($discussion);
        $service = app(CourseDiscussions::class);
        $service->accept($this->student, $discussion, 1, $reply->id);
        $service->moderatePost($this->coach, $reply, 1, true, 'پاسخ نیازمند رسیدگی');
        $this->assertNull($discussion->fresh()->accepted_post_id);
        $service->moderatePost($this->coach, $reply->fresh(), 2, false, 'اصلاح وضعیت نمایش');
        $this->assertNull($discussion->fresh()->accepted_post_id);
        $this->assertSame(3, $reply->fresh()->revision);
    }

    public function test_discussion_kind_does_not_accept_question_answers(): void
    {
        $discussion = $this->discussion(['kind' => 'discussion']);
        $reply = $this->reply($discussion);
        $this->actingAs($this->student)->post('/discussions/'.$discussion->id.'/answer', ['revision' => 1, 'post_id' => $reply->id])->assertSessionHasErrors('discussion');
        $this->assertNull($discussion->fresh()->accepted_post_id);
    }

    public function test_post_identifiers_cannot_be_spliced_between_discussions(): void
    {
        $discussion = $this->discussion();
        $otherPost = $this->reply($this->discussion());
        $root = '/discussions/'.$discussion->id.'/posts/'.$otherPost->id;
        $this->actingAs($this->coach)->get($root.'/history')->assertNotFound();
        $this->get($root.'/locate')->assertNotFound();
        $this->post($root.'/moderate', ['revision' => 1, 'hidden' => true, 'reason' => 'غیرمجاز'])->assertNotFound();
        $this->patch($root, ['revision' => 1, 'body' => 'غیرمجاز'])->assertNotFound();
    }

    public function test_locate_points_to_correct_paginated_post_and_history_has_no_email_or_phone(): void
    {
        $discussion = $this->discussion();
        for ($i = 0; $i < 20; $i++) {
            $post = $this->reply($discussion, $this->coach, 'پاسخ شماره '.$i);
        }
        $this->actingAs($this->student)->get('/discussions/'.$discussion->id.'/posts/'.$post->id.'/locate')->assertRedirect('/discussions/'.$discussion->id.'?page=2#post-'.$post->id);
        $this->get('/discussions/'.$discussion->id.'?page=2')->assertInertia(fn (Assert $page) => $page->has('posts.data', 1)->where('posts.data.0.id', $post->id)->missing('posts.data.0.author_id')->missing('posts.data.0.request_key')->missing('posts.data.0.email')->missing('posts.data.0.phone'));
    }

    public function test_notifications_are_generic_and_only_current_participants_and_coach_are_notified(): void
    {
        $discussion = $this->discussion(['title' => 'عنوان نباید وارد اعلان شود']);
        $this->reply($discussion, $this->peer, 'متن پاسخ خصوصی نباید وارد اعلان شود');
        Enrollment::where('user_id', $this->peer->id)->update(['status' => 'cancelled']);
        $count = $this->peer->notifications()->count();
        $this->reply($discussion);
        $this->assertSame($count, $this->peer->notifications()->count());
        $this->assertSame(0, $this->admin->notifications()->count());
        foreach (DB::table('notifications')->pluck('data') as $data) {
            $this->assertStringNotContainsString('عنوان نباید', $data);
            $this->assertStringNotContainsString('متن پاسخ خصوصی', $data);
        }
        Http::assertNothingSent();
    }

    public function test_course_hub_and_search_keep_cohort_scopes_and_pinned_order(): void
    {
        $first = $this->discussion(['title' => 'پرسش اول']);
        $second = $this->discussion(['title' => 'پرسش دوم']);
        app(CourseDiscussions::class)->moderate($this->coach, $first, $this->moderation($first, ['pinned' => true]));
        $this->actingAs($this->student)->get('/discussions')->assertInertia(fn (Assert $page) => $page->has('cohorts.data', 1));
        $this->get('/cohorts/'.$this->cohort->id.'/discussions')->assertInertia(fn (Assert $page) => $page->where('discussions.data.0.id', $first->id));
        $this->get('/cohorts/'.$this->cohort->id.'/discussions?q='.urlencode('پرسش دوم'))->assertInertia(fn (Assert $page) => $page->has('discussions.data', 1)->where('discussions.data.0.id', $second->id));
        $this->get('/cohorts/'.$this->cohort->id.'/discussions?status=answered')->assertInertia(fn (Assert $page) => $page->has('discussions.data', 0));
        $this->actingAs($this->user('org_admin'))->get('/discussions')->assertInertia(fn (Assert $page) => $page->has('cohorts.data', 0));
    }

    public function test_reassigned_coach_loses_access_and_removed_member_cannot_send_after_loading_page(): void
    {
        $discussion = $this->discussion();
        $this->cohort->course->update(['coach_id' => $this->user('coach')->id]);
        $this->actingAs($this->coach)->get('/discussions/'.$discussion->id)->assertForbidden();
        Enrollment::where('user_id', $this->student->id)->update(['status' => 'cancelled']);
        $this->actingAs($this->student)->post('/discussions/'.$discussion->id.'/posts', ['body' => 'پس از قطع دسترسی', 'request_key' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('discussion_posts', 1);
    }

    public function test_search_treats_wildcard_characters_as_literal_text(): void
    {
        $this->discussion(['title' => 'آزمون 50% و _ در عنوان']);
        $this->discussion(['title' => 'آزمون 50 و حرف معمولی']);
        $this->actingAs($this->student)->get('/cohorts/'.$this->cohort->id.'/discussions?q='.urlencode('%'))->assertInertia(fn (Assert $page) => $page->has('discussions.data', 1));
        $this->get('/cohorts/'.$this->cohort->id.'/discussions?q='.urlencode('_'))->assertInertia(fn (Assert $page) => $page->has('discussions.data', 1));
    }

    public function test_former_coach_editing_as_enrolled_student_cannot_keep_staff_badge(): void
    {
        $discussion = $this->discussion();
        $post = $this->reply($discussion);
        $this->coach->roles()->attach(Role::where('key', 'student')->value('id'));
        Enrollment::factory()->create(['user_id' => $this->coach->id, 'cohort_id' => $this->cohort->id]);
        $this->cohort->course->update(['coach_id' => $this->user('coach')->id]);
        app(CourseDiscussions::class)->edit($this->coach, $post, 1, 'پیام تازه در نقش دانشجو');
        $this->assertSame('student', $post->fresh()->author_role);
        $this->assertSame('coach', $post->revisions()->where('revision', 1)->first()->author_role);
    }

    public function test_migration_protects_discussion_history(): void
    {
        $this->discussion();
        $migration = require database_path('migrations/2026_11_15_130446_create_course_discussions.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
