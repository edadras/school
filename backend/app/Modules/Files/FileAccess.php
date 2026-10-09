<?php

namespace App\Modules\Files;

use App\Models\AssignmentAttachment;
use App\Models\Assignment;
use App\Models\ConversationParticipant;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\LearningMaterial;
use App\Models\MessageAttachment;
use App\Models\Message;
use App\Models\StoredFile;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Modules\Tenancy\Access;

/** One place that decides "may this user read this file". Deny by default. */
class FileAccess
{
    public function __construct(private Access $access) {}

    public function canRead(User $u, StoredFile $f): bool
    {
        if ($f->user_id === $u->id) {
            return true; // uploader
        }

        return match ($f->context_type) {
            'material' => ($m = LearningMaterial::where('file_id', $f->id)->first()) && $this->access->canViewSection($u, $m->section_id),
            'assignment' => ($a = $this->assignmentOfFile($f)) && $this->access->canViewSection($u, $a->section_id),
            'submission' => $this->submissionReadable($u, $f),
            'message' => $this->messageReadable($u, $f),
            'exam_question' => $this->questionMediaReadable($u, $f),
            'recording' => $this->recordingReadable($u, $f),
            'logo' => true,
            default => false,   // unattached files are private to the uploader
        };
    }

    /** Recordings: the class's host and school managers only. */
    private function recordingReadable(User $u, StoredFile $f): bool
    {
        $rec = \App\Models\SessionRecording::where('file_id', $f->id)->first();
        $session = $rec ? \App\Models\LessonSession::find($rec->lesson_session_id) : null;

        return $session && (app(\App\Modules\VirtualClassrooms\LessonSessionService::class)->isHost($u, $session) || $this->access->can($u, 'sessions.monitor'));
    }

    private function assignmentOfFile(StoredFile $f): ?Assignment
    {
        $link = AssignmentAttachment::where('file_id', $f->id)->first();

        return $link ? Assignment::find($link->assignment_id) : null;
    }

    private function submissionReadable(User $u, StoredFile $f): bool
    {
        $link = SubmissionFile::where('file_id', $f->id)->first();
        $sub = $link ? Submission::find($link->submission_id) : null;
        if (! $sub) {
            return false;
        }
        if (in_array($sub->student_id, $this->access->studentIds($u), true)) {
            return true;
        }
        $assignment = Assignment::find($sub->assignment_id);

        return $assignment && $this->access->canTeach($u, $assignment->section_id, $assignment->subject_id);
    }

    private function messageReadable(User $u, StoredFile $f): bool
    {
        $link = MessageAttachment::where('file_id', $f->id)->first();
        $msg = $link ? Message::find($link->message_id) : null;

        return $msg && ConversationParticipant::where('conversation_id', $msg->conversation_id)->where('user_id', $u->id)->exists();
    }

    private function questionMediaReadable(User $u, StoredFile $f): bool
    {
        if ($this->access->can($u, 'exams.manage')) {
            return true;
        }
        $sid = $this->access->ownStudentId($u);
        if (! $sid) {
            return false;
        }
        $examIds = ExamQuestion::whereIn('question_id', \App\Models\Question::where('media_file_id', $f->id)->select('id'))->pluck('exam_id');

        // Students may see question media only while they have an attempt in progress.
        return ExamAttempt::whereIn('exam_id', $examIds)->where('student_id', $sid)->where('status', 'in_progress')->where('deadline_at', '>', now())->exists();
    }
}
