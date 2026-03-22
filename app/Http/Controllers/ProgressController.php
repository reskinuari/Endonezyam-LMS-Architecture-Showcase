<?php

namespace App\Http\Controllers;

use App\Models\LessonList;
use App\Models\LessonPage;
use App\Models\LessonProgress;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProgressController extends Controller
{
    public function advance(string $level, $lesson_id = null, $page = null)
    {
        $user = Auth::user();
        $role = $user ? $user->role : 'guest';

        $lesson_finished = false; // check user lesson finish
        $level_finished = false;

        // Get request
        $req_level = strtolower($level);
        $req_lesson = (int) $lesson_id;
        $current_page = (int) $page;
        $req_page = $current_page + 1;

        // Get lesson total page as lesson_id unique
        $lesson_page_count = LessonPage::where('lesson_id', $req_lesson)->count();
        $req_page = min($req_page, max(1, $lesson_page_count));

        // Find next lesson in same level by position
        $current_lesson = LessonList::where('id', $req_lesson)
            ->first(['id', 'level', 'position']);
        
        // Get next lesson id to check if exists
        $next_lesson = LessonList::where('level', $req_level)
            ->where('position', '>', $current_lesson->position)
            ->orderBy('position')
            ->first(['id', 'position']);




        /* =========================================
           Admin bypass: no progress logic, no DB touch.
           ========================================= */
        if ($role === 'admin' || isIntrolevel($level) ) {
            $nextURL = route('lesson.pelajaran', [
                    'level'      => $req_level,
                    'lesson_id'  => $req_lesson,
                    'page'       => $req_page
            ]);

            
            // If beyond lesson page count
            if ( $req_lesson > $lesson_page_count ) {
                $lesson_finished = true;
                $nextURL = route('lesson.daftar-pelajaran', [
                    'level' => $req_level
                ]);
            }

            // If there's no next lesson (end of level)
            if ( !$next_lesson ) {
                $level_finished = true;
                $nextURL = route('lesson.daftar-level');
            }

            return response()->json([
                'success'          => true,
                'lesson_finished'  => $lesson_finished,
                'level_finished'   => $level_finished,
                'nextURL'          => $nextURL,
                'message' => 'Admin access — progress not recorded.',
            ]);
        }




        // Get user progress
        $progress_data = $role === 'guest'
            ? (array) session("progress.$req_level.$req_lesson", [])
            : (LessonProgress::where('user_id', $user->id)
                ->where('level', $req_level)
                ->where('lesson_id', $req_lesson)
                ->first(['page', 'total_page', 'lesson_completed_at', 'level_completed_at'])
                ?->toArray() ?? []);

        // Store progress accordingly
        $progress_page = $progress_data['page'] ?? 1;
        $pages_completed = $progress_data['total_page'] ?? 0;

        // user in last page or req_page more than page_total, modify
        if ( $req_page > $lesson_page_count ) $req_page = $lesson_page_count;

        //  Set the progress
        $progress = [
            'page' => $req_page,
            'total_page' => $pages_completed,
        ];

        $nextURL = route('lesson.pelajaran',[
            'level' => $req_level,
            'lesson_id' => $req_lesson,
            'page' => $req_page,
        ]);




        /* =========================================
           Handle progress other than last page
           ========================================= */
        // Record if req_page more than progress page, skip if not
        if ( $req_page > $progress_page && $req_page <= $lesson_page_count ) {
            $progress['total_page']+=1;

            // Save regular progress
            if ( $role === 'guest' ) {
                // Fetch whole progress map (or empty array)
                $allProgress = session('progress', []);

                // Ensure level exists as an array
                if (!isset($allProgress[$req_level]) || !is_array($allProgress[$req_level])) {
                    $allProgress[$req_level] = [];
                }

                // Update the single lesson entry inside that level
                $allProgress[$req_level][$req_lesson] = array_merge(
                    $allProgress[$req_level][$req_lesson] ?? [],
                    $progress // the array you build: ['page'=>..., 'total_page'=>..., ...]
                );

                // Write back once
                session(['progress' => $allProgress]);
            } else {
                LessonProgress::where('user_id', $user->id)
                    ->where('level', $req_level)
                    ->where('lesson_id', $req_lesson)
                    ->update($progress);
            }
        }




        /* =========================================
           Handle progress on last page
           ========================================= */
        
        // If page is the last page
        if ( $current_page === $lesson_page_count ) {
            $lesson_finished = true; // tell ajax it's last page

            // Update if progress data has no lesson_completed at
            if ( !$progress_data['lesson_completed_at'] ) {
                // Update total page count
                $pages_completed = min($pages_completed + 1, $lesson_page_count);
                $progress['total_page']=$pages_completed;

                if ( $role === 'guest' ) {
                    $sessionProgress = session("progress.$req_level.$req_lesson", []);
                    $sessionProgress['total_page'] = $pages_completed;
                    $sessionProgress['lesson_completed_at'] = now()->toDateTimeString();
                    session()->put("progress.$req_level.$req_lesson", $sessionProgress);
                } else {
                    DB::transaction( function () use ($user, $req_level, $req_lesson, $pages_completed, &$progress, $next_lesson) {
                        // Update current lesson
                        LessonProgress::where('user_id', $user->id)
                            ->where('level', $req_level)
                            ->where('lesson_id', $req_lesson)
                            ->lockForUpdate()
                            ->update([
                                'total_page' => $pages_completed,
                                'lesson_completed_at' => now()->toDateTimeString()
                            ]);

                        if ( $next_lesson ) {
                            $progress['page'] = 0;
                            LessonProgress::firstOrCreate(
                                [
                                    'user_id' => $user->id,
                                    'level' => $req_level,
                                    'lesson_id' => $next_lesson->id
                                ], $progress
                            );
                        }
                    });
                }
            }

            // Non-db
            if ( $next_lesson ) {
                // Next lesson exists -> start from page 1
                $req_lesson = $next_lesson->id;
                $progress['page'] = 1;

                $nextURL = route('lesson.daftar-pelajaran',[
                    'level' => $req_level
                ]);

                if ( $role === 'guest' ) {
                    session(["progress.$req_level.$req_lesson" => $progress]);
                }
            } else {
                // Last level, next handled by LessonController
                $level_finished = true;
                $nextURL = route('lesson.daftar-level');
            }
        }

        return response()->json([
            'success' => true,
            'lesson_finished' => $lesson_finished,
            'level_finished' => $level_finished,
            'nextURL' => $nextURL,
            'test' => $req_page
        ]);
    }
}
