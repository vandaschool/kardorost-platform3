<?php

namespace App\Http\Controllers;

use App\Models\Cohort;
use App\Models\CourseDiscussion;
use App\Models\DiscussionPost;
use App\Models\Enrollment;
use App\Services\CourseDiscussions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DiscussionController extends Controller
{
    private function role(Request $request, bool $staff): string
    {
        return $request->user()->hasRole('admin') ? 'admin' : ($staff ? 'coach' : 'student');
    }

    private function data(CourseDiscussion $discussion, Request $request, bool $staff): array
    {
        if ($discussion->hidden && ! $staff) {
            return ['id' => $discussion->id, 'title' => 'گفت‌وگوی پنهان‌شده', 'kind' => $discussion->kind, 'hidden' => true, 'locked' => true, 'pinned' => false, 'accepted_post_id' => null, 'revision' => $discussion->revision, 'created_at' => $discussion->created_at, 'last_post_at' => null, 'author_name' => 'شما', 'can_accept' => false, 'can_reply' => false];
        }

        return [...$discussion->only('id', 'title', 'kind', 'hidden', 'locked', 'pinned', 'accepted_post_id', 'revision', 'created_at', 'last_post_at'), 'author_name' => $discussion->author->name, 'can_reply' => ! $discussion->locked && ! $discussion->hidden, 'can_accept' => $discussion->kind === 'question' && ! $discussion->hidden && ! $discussion->locked && ($staff || $discussion->author_id === $request->user()->id), ...($staff ? ['moderation_reason' => $discussion->moderation_reason] : [])];
    }

    public function hub(Request $request): Response
    {
        $user = $request->user();
        $admin = $user->hasRole('admin');
        $cohorts = Cohort::with('course:id,title,coach_id')->when(! $admin, fn ($query) => $query->where(fn ($scope) => $scope
            ->whereHas('course', fn ($course) => $course->where('coach_id', $user->hasRole('coach') ? $user->id : -1))
            ->orWhereIn('id', Enrollment::where('user_id', $user->id)->where('status', 'active')->when(! $user->hasRole('student') || ! $user->phone_verified_at, fn ($enrollment) => $enrollment->whereRaw('1=0'))->select('cohort_id'))))->latest()->paginate(15)->through(fn ($cohort) => ['id' => $cohort->id, 'title' => $cohort->title, 'course_title' => $cohort->course->title]);

        return Inertia::render('DiscussionHub', ['cohorts' => $cohorts, 'role' => $admin ? 'admin' : ($user->hasRole('coach') && ($request->session()->get('panel_role') === 'coach' || ! $user->hasRole('student')) ? 'coach' : ($user->hasRole('student') ? 'student' : 'org_admin'))]);
    }

    public function index(Request $request, Cohort $cohort, CourseDiscussions $service): Response
    {
        $service->access($request->user(), $cohort);
        $staff = $service->staff($request->user(), $cohort);
        $data = $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:all,unanswered,answered', 'visibility' => 'nullable|in:all,visible,hidden']);
        $q = trim($data['q'] ?? '');
        $status = $data['status'] ?? 'all';
        $visibility = $staff ? ($data['visibility'] ?? 'all') : 'all';
        $discussions = CourseDiscussion::where('cohort_id', $cohort->id)->with('author:id,name')
            ->when(! $staff, fn ($query) => $query->where(fn ($visible) => $visible->where('hidden', false)->orWhere('author_id', $request->user()->id)))
            ->when($q !== '', fn ($query) => $query->whereRaw("title LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%']))
            ->when($status !== 'all', fn ($query) => $query->where('kind', 'question')->when($status === 'answered', fn ($answered) => $answered->whereNotNull('accepted_post_id'), fn ($unanswered) => $unanswered->whereNull('accepted_post_id')))
            ->when($visibility !== 'all', fn ($query) => $query->where('hidden', $visibility === 'hidden'))->orderByDesc('pinned')->orderByDesc('last_post_at')->orderByDesc('id')->paginate(15)->withQueryString()->through(fn ($discussion) => $this->data($discussion, $request, $staff));

        return Inertia::render('Discussions', ['cohort' => $cohort->only('id', 'title'), 'course' => $cohort->course->only('id', 'title'), 'role' => $this->role($request, $staff), 'staff' => $staff, 'discussions' => $discussions, 'filters' => ['q' => $q, 'status' => $status, 'visibility' => $visibility]]);
    }

    public function create(Request $request, Cohort $cohort, CourseDiscussions $service): RedirectResponse
    {
        $service->access($request->user(), $cohort);
        $data = $request->validate(['title' => 'required|string|max:160', 'body' => 'required|string|max:12000', 'kind' => 'required|in:question,discussion', 'request_key' => 'required|uuid']);
        $discussion = $service->create($request->user(), $cohort, $data);

        return redirect()->route('discussions.show', $discussion)->with('success', 'گفت‌وگو ثبت شد.');
    }

    public function show(Request $request, CourseDiscussion $discussion, CourseDiscussions $service): Response
    {
        $service->visible($request->user(), $discussion);
        $staff = $service->staff($request->user(), $discussion->cohort);
        $firstId = $discussion->posts()->oldest('id')->value('id');
        $posts = $discussion->posts()->with('author:id,name')->when($discussion->hidden && ! $staff, fn ($query) => $query->whereRaw('1=0'))->orderBy('id')->paginate(20)->through(function ($post) use ($discussion, $staff, $request, $firstId) {
            $data = ['id' => $post->id, 'hidden' => $post->hidden, 'revision' => $post->revision, 'can_edit' => ! $discussion->hidden && ! $discussion->locked && ! $post->hidden && $post->author_id === $request->user()->id, 'is_first' => $post->id === $firstId, 'created_at' => $post->created_at, 'edited' => $post->revision > 1];
            if ($post->hidden && ! $staff) {
                return [...$data, 'body' => null, 'author_name' => null, 'author_role' => null];
            }

            return [...$data, 'body' => $post->body, 'author_name' => $post->author->name, 'author_role' => $post->author_role, ...($staff ? ['moderation_reason' => $post->moderation_reason] : [])];
        });

        return Inertia::render('Discussion', ['discussion' => $this->data($discussion, $request, $staff), 'cohort' => $discussion->cohort->only('id', 'title'), 'course' => $discussion->cohort->course->only('id', 'title'), 'posts' => $posts, 'staff' => $staff, 'role' => $this->role($request, $staff)]);
    }

    private function belongs(CourseDiscussion $discussion, DiscussionPost $post): void
    {
        abort_unless($post->course_discussion_id === $discussion->id, 404);
    }

    public function reply(Request $request, CourseDiscussion $discussion, CourseDiscussions $service): RedirectResponse
    {
        $service->visible($request->user(), $discussion);
        $data = $request->validate(['body' => 'required|string|max:12000', 'request_key' => 'required|uuid']);
        $post = $service->reply($request->user(), $discussion, $data['body'], $data['request_key']);

        return redirect()->route('discussions.locate', ['discussion' => $discussion, 'post' => $post])->with('success', 'پاسخ ثبت شد.');
    }

    public function edit(Request $request, CourseDiscussion $discussion, DiscussionPost $post, CourseDiscussions $service): RedirectResponse
    {
        $service->visible($request->user(), $discussion);
        $this->belongs($discussion, $post);
        abort_unless($post->author_id === $request->user()->id, 403);
        $data = $request->validate(['revision' => 'required|integer|min:1', 'body' => 'required|string|max:12000']);
        $service->edit($request->user(), $post, (int) $data['revision'], $data['body']);

        return back()->with('success', 'ویرایش ثبت شد؛ سابقه نسخه قبلی حفظ شد.');
    }

    public function moderatePost(Request $request, CourseDiscussion $discussion, DiscussionPost $post, CourseDiscussions $service): RedirectResponse
    {
        abort_unless($service->staff($request->user(), $discussion->cohort), 403);
        $this->belongs($discussion, $post);
        $data = $request->validate(['revision' => 'required|integer|min:1', 'hidden' => 'required|boolean', 'reason' => 'required|string|min:3|max:1000']);
        $service->moderatePost($request->user(), $post, (int) $data['revision'], (bool) $data['hidden'], $data['reason']);

        return back()->with('success', 'وضعیت نمایش پیام ثبت شد.');
    }

    public function moderate(Request $request, CourseDiscussion $discussion, CourseDiscussions $service): RedirectResponse
    {
        abort_unless($service->staff($request->user(), $discussion->cohort), 403);
        $data = $request->validate(['revision' => 'required|integer|min:1', 'hidden' => 'required|boolean', 'locked' => 'required|boolean', 'pinned' => 'required|boolean', 'reason' => 'required|string|min:3|max:1000']);
        $service->moderate($request->user(), $discussion, $data);

        return back()->with('success', 'تنظیمات گفت‌وگو ثبت شد.');
    }

    public function accept(Request $request, CourseDiscussion $discussion, CourseDiscussions $service): RedirectResponse
    {
        $service->visible($request->user(), $discussion);
        $data = $request->validate(['revision' => 'required|integer|min:1', 'post_id' => 'present|nullable|integer|min:1']);
        $service->accept($request->user(), $discussion, (int) $data['revision'], isset($data['post_id']) ? (int) $data['post_id'] : null);

        return back()->with('success', 'انتخاب پاسخ ثبت شد.');
    }

    public function locate(Request $request, CourseDiscussion $discussion, DiscussionPost $post, CourseDiscussions $service): RedirectResponse
    {
        $service->visible($request->user(), $discussion);
        $this->belongs($discussion, $post);
        $page = (int) ceil($discussion->posts()->where('id', '<=', $post->id)->count() / 20);

        return redirect()->to(route('discussions.show', ['discussion' => $discussion, 'page' => $page]).'#post-'.$post->id);
    }

    public function history(Request $request, CourseDiscussion $discussion, DiscussionPost $post, CourseDiscussions $service): Response
    {
        abort_unless($service->staff($request->user(), $discussion->cohort), 403);
        $this->belongs($discussion, $post);

        return Inertia::render('DiscussionHistory', ['discussion_id' => $discussion->id, 'post_id' => $post->id, 'role' => $this->role($request, true), 'revisions' => $post->revisions()->with('actor:id,name')->latest('revision')->paginate(20)->through(fn ($revision) => [...$revision->only('revision', 'body', 'hidden', 'reason', 'created_at'), 'actor_name' => $revision->actor->name])]);
    }
}
