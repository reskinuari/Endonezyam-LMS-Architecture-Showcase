<?php

namespace App\Http\Middleware;

use App\Models\LessonList;
use App\Models\LessonPage;
use App\Models\LessonProgress;
use App\Models\LevelList;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class CheckLessonAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        $role = $user ? $user->role : 'guest';

        // Route params (may be null for /bahasa-turki or /bahasa-turki{level})
        $req_level = strtolower($request->route('level'));

        // Get first Lesson id
        // Cache lesson meta data per level
        if (session()->has("lesson-meta.$req_level")) {
            $meta = session("lesson-meta.$req_level");

            // Safety check: make sure structure is valid
            $firstLessonId = $meta['first'] ?? null;
        } else {
            // Query lesson metadata once
            $firstLessonId = LessonList::where('level', $req_level)->orderBy('id', 'asc')->value('id');
            $lastLessonId  = LessonList::where('level', $req_level)->orderBy('id', 'desc')->value('id');
            $totalLesson   = LessonList::where('level', $req_level)->count();

            // Store in session
            session([
                "lesson-meta.$req_level" => [
                    'first' => $firstLessonId,
                    'last'  => $lastLessonId,
                    'count' => $totalLesson,
                ],
            ]);
        }

        $req_lesson = (int) ($request->route('lesson_id') ?? $firstLessonId);
        $req_page = (int) ($request->route('page') ?? 1);

        $level_list = Cache::remember('level_codes', 3600, function() {
            return LevelList::pluck('code')->toArray();
        });

        $totalPage = 0;




        /* =========================================
           Handles access of non-existing request
           ========================================= */
        // Redirect if requested level doesn't exist
        if ( !in_array($req_level, $level_list) ) {
            return redirect()->route('lesson.daftar-level')->with('message', [
                'error'=> 'Level not found!',
                'status' => 'error'
            ]);
        }

        // Redirect if guest try to access level A1 lesson > 1
        if ( $role === 'guest' && $req_lesson > $firstLessonId ) {
            return redirect()->route('lesson.daftar-pelajaran', [
                'level' => $req_level,
            ])->with('message', [
                'error' => 'Register to access more lesson!',
                'status' => 'register'
            ]);
        }

        // Redirect if user doesn't have permission to requested level
        if ( !in_array($req_level, (array) config("roles.$role")) ) {
            // Replace with upgrade page later
            return redirect()->route('lesson.daftar-level')->with('message', [
                'error' => 'Upgrade to access more level!',
                'status' => 'upgrade'
            ]);
        }

        // Redirect if a level lesson not yet exists
        if ( !$firstLessonId ) {
            $level_name = LevelList::where('code', $req_level)->value('name');
            Log::warning("Lesson missing for level: $req_level ($level_name)");
            return redirect()->route('lesson.daftar-level')
                ->with('message', [
                    'error' => "Level $level_name has no lessons yet!",
                    'status' => 'error'
                ]);
            }

        // Redirect if requested lesson doesn't exist
        $lessonExists = LessonList::where('id', $req_lesson)
            ->where('level', $req_level)
            ->exists();

        if (!$lessonExists) {
            return redirect()->route('lesson.daftar-pelajaran', ['level' => $req_level])
                ->with('message', [
                    'error' => 'Lesson not found!',
                    'status' => 'error'
                ]);
        }

        // Redirect if requested page doesn't exist
        $totalPage = LessonPage::where('lesson_id', $req_lesson)->count();

        if ( $totalPage !== 0 && ($req_page < 0 || $req_page > $totalPage) ) {
            return redirect()->route('lesson.daftar-pelajaran', [
                'level' => $req_level
            ])->with('message', [
                'error' => 'Page not found!',
                'status' => 'error'
            ]);
        }

        // Allow admin advance
        if ( $role === 'admin' || isIntrolevel($req_level) ) return $next($request);




        /* =========================================
           Get latest progress and block access
           according to latest progress
           ========================================= */
        // Get user latest progress
        if ($role === 'guest') {
            // Session('progress.level') is an array: [lesson_id => [...], ...]
            $levelProgress = session("progress.$req_level", []);

            // If guest has progress, get the latest lesson id (highest key)
            $latestProgress = null;
            if (!empty($levelProgress)) {
                $lastLessonId = max(array_keys($levelProgress));
                $lessonData = $levelProgress[$lastLessonId];

                $latestProgress = (object)[
                    'lesson_id' => (int)$lastLessonId,
                    'page'      => (int)($lessonData['page'] ?? 1),
                ];
            }
        } else {
            // Logged-in user: pull latest progress row from DB
            $latestProgress = LessonProgress::where('user_id', $user->id)
                ->where('level', $req_level)
                ->orderByDesc('lesson_id')
                ->first();
        }

        // Block if user has no progress at all, and try to access restricted lesson/level
        if ( !$latestProgress ) {
            if ( $req_lesson !== $firstLessonId || $req_page > 1 ) {
                // Block access
                return redirect()->route('lesson.daftar-level')
                    ->with('message', [
                        'error' => "Access Denied!",
                        'status' => 'error'
                    ]);
            }
        } else {
            $progressLesson = (int)$latestProgress->lesson_id;
            $progressPage   = (int)$latestProgress->page;

            // If req_page is 0, redirect to page 1
            if ( $req_page === 0 ) {
                return redirect()->route('lesson.pelajaran', [
                    'level' => $req_level,
                    'lesson_id' => $req_lesson,
                    'page' => 1
                ]);
            }

            $progressPage = $progressPage !== 0 ? $progressPage : 1;

            // Block if user try to access restricted lesson/page beyond progress
            if ( $req_lesson > $latestProgress->lesson_id
                || ( $req_lesson == $progressLesson && $req_page > $progressPage ) ) {
                // Return to last progress
                return redirect()->route('lesson.pelajaran', [
                    'level' => $req_level,
                    'lesson_id' => $progressLesson,
                    'page'=> $progressPage
                ])->with('message', [
                    'error' => "Complete previous lesson first!",
                    'status' => 'error'
                ]);
            }
        }
        
        return $next($request);
    }
}
