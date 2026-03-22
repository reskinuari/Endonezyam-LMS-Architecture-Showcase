<?php

namespace App\Providers;

use App\Models\LessonList;
use App\Models\LevelList;
use App\Models\UserProgress;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // For controlling allowed roles to access anything in the lesson.* pages
        View::composer('lessons.*', function($view) {
            $user = Auth::user();
            $userRole = $user ? $user->role : 'guest';

            $allowedLevels = config("roles.$userRole");
            
            $levels = LevelList::get()->map(function ($level) use ($allowedLevels) {
                $level->is_allowed = in_array($level->code, $allowedLevels);
                return $level;
            });

            $view->with(compact('user', 'levels'));
        });

        // For controlling "continue lesson" views
        View::composer(
            ['components.user-progress'],
            function($view) {
                $user = Auth::user();
                $continueURL = null;
                $lessonLevel = 'A1';
                $lessonTitle = 'Başlangıç Dersi';
                $levelIndicator = 0;
                $progressPercentage = 0;

                if ( $user ) {
                    $levelOrder = [ 'gi' => 0, 'a1' => 1, 'a2' => 2, 'b1' => 3, 'b2' => 4, 'c' => 5 ];

                    // Detect current level from route (if any) => page daftar-pelajaran
                    $currentRoute = request()->route();
                    $requestedLevel = strtolower($currentRoute->parameter('level') ?? '');

                    // If parameter level exists (daftar-pelajaran) vs if not (bahasa-turki)
                    $query = $requestedLevel && isset($levelOrder[$requestedLevel])
                        ? $user->progressByLevel($requestedLevel)
                        : $user->currentProgress();

                    $progress = $query ?? null;

                    if ( $progress ) {
                        // Build Continue URL
                        $continueURL = route('lesson.pelajaran', [
                            'level' => $progress->level,
                            'lesson_id' => $progress->lesson_id,
                            'page' => $progress->page,
                        ]);

                        // Find lesson level and title for display
                        $lessonLevel = ucwords($progress->level);
                        $lessonTitle = $progress->lesson->title;

                        // Get page count in this level
                        $totalPages = LevelList::where('code', $progress->level)
                                        ->value('pages');
                        $progressPercentage = $totalPages > 0
                            ? ( $progress->total_page / $totalPages ) * 100
                            : 0;

                        // Index for level dots view
                        $levelIndicator = $levelOrder[$progress->level];
                    }

                    $view->with([
                        'continueURL' => $continueURL,
                        'lesson_level' => $lessonLevel,
                        'lesson_title' => $lessonTitle,
                        'level_indicator' => $levelIndicator,
                        'level_percentage' => $progressPercentage
                    ]);
                }
            }
        );
    }
}
