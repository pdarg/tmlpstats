<?php
namespace TmlpStats\Validate\Relationships;

use TmlpStats\Traits;

class ApiCenterGamesValidator extends CenterGamesValidator
{
    use Traits\GeneratesApiMessages;

    protected function validate($data)
    {
        // GITW and TDO
        $activeMemberCount = 0;
        $effectiveCount    = 0;

        foreach ($data['teamMember'] as $member) {
            if ($member->withdrawCodeId || $member->xferOut) {
                continue;
            }

            $activeMemberCount++;
            if ($member->gitw) {
                $effectiveCount++;
            }
        }

        $gitwGame = $activeMemberCount ? round(($effectiveCount / $activeMemberCount) * 100) : 0;

        // CAP & CPC Game
        $capCurrentStandardStarts = 0;
        $capQStartStandardStarts  = 0;
        $cpcCurrentStandardStarts = 0;
        $cpcQStartStandardStarts  = 0;

        foreach ($data['course'] as $course) {
            if ($course->type == 'CAP') {
                $capCurrentStandardStarts += $course->currentStandardStarts;
                $capQStartStandardStarts += $course->quarterStartStandardStarts;
            } else if ($course->type == 'CPC') {
                $cpcCurrentStandardStarts += $course->currentStandardStarts;
                $cpcQStartStandardStarts += $course->quarterStartStandardStarts;
            }
        }

        $capGame = $capCurrentStandardStarts - $capQStartStandardStarts;
        $cpcGame = $cpcCurrentStandardStarts - $cpcQStartStandardStarts;

        // T1x and T2x Games
        $t1CurrentApproved = 0;
        $t2CurrentApproved = 0;

        foreach ($data['teamApplication'] as $registration) {
            if (!$registration->apprDate) {
                continue;
            }

            if ($registration->teamYear == 1) {
                $t1CurrentApproved++;
            } else {
                $t2CurrentApproved++;
            }
        }

        $thisWeekActual = null;

        // TODO: Fix this to work properly with the new Scoreboard
        foreach ($data['scoreboard'] as $week) {
            if ($week->type == 'actual' && $week->reportingDate->eq($this->reportingDate)) {
                $thisWeekActual = $week;
                break;
            }
        }

        $t1QStartApproved = 0;
        $t2QStartApproved = 0;

        // TODO: Find a way to pull this info from database

        // foreach ($data['tmlpCourseInfo'] as $game) {
        //     if (strpos($game->type, 'T1') !== false) {
        //         $t1QStartApproved += $game->quarterStartApproved;
        //     } else {
        //         $t2QStartApproved += $game->quarterStartApproved;
        //     }
        // }

        $t1xGame = $t1CurrentApproved - $t1QStartApproved;
        $t2xGame = $t2CurrentApproved - $t2QStartApproved;

        // Make sure they match
        if ($thisWeekActual) {
            if ($thisWeekActual->cap != $capGame) {
                $this->addMessage('IMPORTDOC_CAP_ACTUAL_INCORRECT', $thisWeekActual->cap, $capGame);
                $this->isValid = false;
            }

            if ($thisWeekActual->cpc != $cpcGame) {
                $this->addMessage('IMPORTDOC_CPC_ACTUAL_INCORRECT', $thisWeekActual->cpc, $cpcGame);
                $this->isValid = false;
            }

            if ($thisWeekActual->t1x != $t1xGame) {
                $this->addMessage('IMPORTDOC_T1X_ACTUAL_INCORRECT', $thisWeekActual->t1x, $t1xGame);
                $this->isValid = false;
            }

            if ($thisWeekActual->t2x != $t2xGame) {
                $this->addMessage('IMPORTDOC_T2X_ACTUAL_INCORRECT', $thisWeekActual->t2x, $t2xGame);
                $this->isValid = false;
            }

            if ($thisWeekActual->gitw != $gitwGame) {
                $this->addMessage('IMPORTDOC_GITW_ACTUAL_INCORRECT', $thisWeekActual->gitw, $gitwGame);
                $this->isValid = false;
            }
        }

        return $this->isValid;
    }
}
