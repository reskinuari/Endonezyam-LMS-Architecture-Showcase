<?php

namespace App\Http\Controllers;

use App\Models\LessonContent;
use App\Models\LessonList;
use App\Models\LessonPage;
use App\Models\LessonProgress;
use App\Models\LessonQuiz;
use App\Models\LevelList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LessonController extends Controller
{
    public function lessonList(string $level) {
        $user = Auth::user();
        $role = $user->role ?? 'guest';
        $lessons = LessonList::where('level', $level)->get();
        $lastLesson = 1;

        // Set progressPercent to 0
        $progressPercent = 0;

        if ( $role !== 'admin' && !isIntrolevel($level) ) {
            // Check if user already has progress for this level
            $hasProgress = $role === 'guest'
                ? session()->has("progress.$level")
                : LessonProgress::where('user_id', $user->id)
                    ->where('level', $level)
                    ->exists();

            if ( !$hasProgress ) {
                // Get first lesson id
                $firstLessonId = $lessons
                    ->sortBy('position')
                    ->value('id');

                // Create first progress if none at all
                if ( $role === 'guest' ) {
                    $newProgress = session('progress', []);
                    $newProgress[$level][$firstLessonId] = [
                        'page' => 0,
                        'total_page' => 0,
                        'lesson_completed_at' => null,
                        'level_completed_at' => null,
                    ];
                    session(['progress' => $newProgress]);
                } else {
                    LessonProgress::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'level' => $level,
                            'lesson_id' => $firstLessonId,
                        ], [
                            'page' => 0,
                            'total_page' => 0,
                            'lesson_completed_at' => null,
                            'level_completed_at' => null,
                        ]
                    );
                }
                $lastLesson = $firstLessonId;
            } else {
                if ($user) {
                    $lastLesson = $user->progressByLevel($level)?->lesson_id ?? null;
                    $progressPercent = $user->progressPercentByLevel($level);
                } else {
                    // guest fallback from session
                    $progress = session("progress.$level");
                    $lastLesson = $progress ? array_key_last($progress) : null;
                    $guestPage = session("progress.$level.$lastLesson.page", 0);
                        $lessonTotalPage = LessonList::where('id', $lastLesson)->value('pages');
                        $progressPercent = $lessonTotalPage > 0
                            ? round(($guestPage / $lessonTotalPage) * 100, 1)
                            : 0;
                }
            }
        }

        return view('lessons.daftar-pelajaran', compact(
            'lessons',
            'level',
            'lastLesson',
            'progressPercent',
        ));
    }

    public function lesson(string $level, $lesson_id = null, $page = null)
    {
        $user = Auth::user();
        $role = $user->role ?? 'guest';
        $level = strtolower($level);
        $lesson_id = (int) $lesson_id;
        $page = (int) $page;

        if ( $page === 1 ) {
            if ( $role === 'guest' ) {
                $guestProgress = session("progress.$level.$lesson_id", []);
                // Only update if page is 0
                if ( !isset($guestProgress['page']) || $guestProgress['page'] === 0 ) {
                    $guestProgress['page'] = 1;
                    session(["progress.$level.$lesson_id" => $guestProgress]);
                }
            } else {
                LessonProgress::where('user_id', $user->id)
                    ->where('level', $level)
                    ->where('lesson_id', $lesson_id)
                    ->whereNull('lesson_completed_at')
                    ->where(function($q) {
                        $q->whereNull('page')->orWhere('page', 0);
                    })
                    ->update(['page' => 1]);
            }
        }

        // Get lesson data
        $keyLesson = "lesson_list.{$level}.{$lesson_id}";

        if ( !session()->has($keyLesson) ) {
            $lessonList = LessonList::where('level', $level)
                ->where('id', $lesson_id)
                ->firstOrFail();

            session([$keyLesson => $lessonList]);
        } else {
            $lessonList = session($keyLesson);
        }

        // Get level name
        $keyLevelName = "level_list.{$level}";

        if ( !session()->has($keyLevelName) ) {
            $levelName = LevelList::where('code', $level)->value('name');
            session([$keyLevelName => $levelName]);
        } else {
            $levelName = session($keyLevelName);
        }
        
        $lessonPage = LessonPage::where('lesson_id', $lesson_id)
            ->where('page_number', $page)
            ->firstOrFail();

        // Fetch actual content
        switch ($lessonPage->page_type) {
            case 'content':
                $content = LessonContent::findOrFail($lessonPage->page_id);
                break;
            case 'quiz':
                $content = LessonQuiz::findOrFail($lessonPage->page_id);
                break;
            default:
                $content = 'Sayfa Bulunamadı!';
        }

        // Pagination info!
        $totalPages = $lessonList->pages;

        // existence of previous and next page, return boolean
        $previousPageExists = $page > 1;
        $nextPageExists = $page < $totalPages;
        
        // Only get current position on last page
        $currentPos = $nextPageExists
            ? ''
            : $lessonList->position;

        // Return user to view
        return view('lessons.pelajaran', compact(
            'level',
            'lessonList',
            'lessonPage',
            'content',
            'totalPages',
            'currentPos',
            'previousPageExists',
            'nextPageExists',
            'levelName'
        ));
    }

    public function getQuizAnswer(Request $request) {
        $request->validate([
            'id' => 'required',
            'answer' => 'required'
        ]);

        $id = $request->query('id');
        $answer = $request->query('answer');

        try {
            $quiz_ans = LessonQuiz::where('id', $id)->firstOrFail();
            $result = $answer == $quiz_ans->answer;
            return response()->json(['result' => $result]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Quiz question not found'], 404);
        }
    }
}
