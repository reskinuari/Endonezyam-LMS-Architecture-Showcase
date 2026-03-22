<?php

namespace App\Services;

use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class ProgressMigrationService
{
    public static function migrate(User $user): void
    {
        if (!Session::has('progress')) return;

        DB::transaction(function () use ($user) {
            $sessionProgress = Session::get('progress');

            foreach ($sessionProgress as $level => $lessons) {
                foreach ($lessons as $lessonId => $data) {
                    $existing = LessonProgress::where('user_id', $user->id)
                        ->where('level', $level)
                        ->where('lesson_id', $lessonId)
                        ->first();

                    if (!$existing || ($data['page'] ?? 1) > $existing->page) {
                        LessonProgress::updateOrCreate(
                            [
                                'user_id'   => $user->id,
                                'level'     => $level,
                                'lesson_id' => $lessonId,
                            ],
                            [
                                'page'                => $data['page'] ?? 1,
                                'total_page'          => $data['total_page'] ?? 0,
                                'lesson_completed_at' => $data['lesson_completed_at'] ?? null,
                                'level_completed_at'  => $data['level_completed_at'] ?? null,
                            ]
                        );
                    }
                }
            }

            Session::forget('progress');
        });
    }
}
