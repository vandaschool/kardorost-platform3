<?php

namespace App\Http\Controllers;

use App\Models\CourseDiscussion;
use App\Models\DiscussionAttachment;
use App\Models\DiscussionPost;
use App\Models\User;
use App\Services\CourseDiscussions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DiscussionAttachmentController extends Controller
{
    public function index(Request $request, CourseDiscussions $service): Response
    {
        $user = User::findOrFail($request->user()->id);
        abort_unless($user->is_active, 403);
        $role = $request->session()->get('panel_role');
        $role = $role && $user->hasRole($role) ? $role : ($user->roleKeys()[0] ?? 'student');
        $files = DiscussionAttachment::where('owner_id', $user->id)->with('post.discussion.cohort.course')->latest('id')->paginate(20)->through(function ($attachment) use ($user, $service): array {
            $readable = $service->attachmentReadable($user, $attachment->post);
            $data = $service->attachmentData($attachment);

            return [...$data, 'file_url' => $readable ? $data['file_url'] : null, 'created_at' => $attachment->created_at, 'post_url' => $readable ? route('discussions.locate', ['discussion' => $attachment->post->course_discussion_id, 'post' => $attachment->post->id]) : null];
        });

        return Inertia::render('DiscussionAttachments', ['files' => $files, 'role' => $role]);
    }

    public function store(Request $request, CourseDiscussion $discussion, DiscussionPost $post, CourseDiscussions $service): RedirectResponse
    {
        $service->visible($request->user(), $discussion);
        abort_unless($post->course_discussion_id === $discussion->id, 404);
        abort_unless($post->author_id === $request->user()->id, 403);
        $request->validate(['file' => 'required|file']);
        $service->attach($request->user(), $post, $request->only('revision', 'request_key', 'confirmed', 'sharing_policy'), $request->file('file'));

        return back()->with('success', 'ضمیمه در همین گفت‌وگوی دوره ثبت شد.');
    }

    public function withdraw(Request $request, DiscussionAttachment $attachment, CourseDiscussions $service): RedirectResponse
    {
        $service->withdrawAttachment($request->user(), $attachment, $request->only('revision', 'reason'));

        return back()->with('success', 'دریافت این ضمیمه متوقف شد؛ سابقه پیام حفظ شد.');
    }

    public function file(Request $request, DiscussionAttachment $attachment, CourseDiscussions $service): StreamedResponse
    {
        $prepared = $service->downloadAttachment($request->user(), $attachment);

        return response()->streamDownload(function () use ($prepared): void {
            try {
                fpassthru($prepared['stream']);
            } finally {
                fclose($prepared['stream']);
            }
        }, $prepared['filename'], ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
