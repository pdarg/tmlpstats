<?php
namespace TmlpStats\Api\Submission;

use App;
use Carbon\Carbon;
use TmlpStats as Models;
use TmlpStats\Api;
use TmlpStats\Api\Base\AuthenticatedApiBase;
use TmlpStats\Domain;

class Scoreboard extends AuthenticatedApiBase
{
    const LOCK_SETTING_KEY = 'scoreboardLock';

    public function allForCenter(Models\Center $center, Carbon $reportingDate, $includeInProgress = false, $returnObject = false)
    {
        $this->assertAuthz($this->context->can('viewSubmissionUi', $center));
        $submissionCore = App::make(Api\SubmissionCore::class);
        $submissionCore->checkCenterDate($center, $reportingDate);

        $localReport = App::make(Api\LocalReport::class);
        $rq = $submissionCore->reportAndQuarter($center, $reportingDate);
        $quarter = $rq['quarter'];
        $statsReport = $rq['report'];
        $reportingDates = $quarter->getCenterQuarter($center)->listReportingDates();

        if ($statsReport !== null) {
            $weeks = $localReport->getQuarterScoreboard($statsReport);
        } else {
            // This should only happen on the first week of the quarter, but we want to initialize the weeks fully.
            $weeks = new Domain\ScoreboardMultiWeek();
            foreach ($reportingDates as $d) {
                $weeks->ensureWeek($d);
            }
        }

        // fill some additional metadata
        $locks = $this->getScoreboardLockQuarter($center, $quarter);

        $classrooms = [
            $quarter->getClassroom1Date($center),
            $quarter->getClassroom2Date($center),
            $quarter->getClassroom3Date($center),
        ];
        $weekNumber = 0;
        foreach ($reportingDates as $week) {
            $scoreboard = $weeks->ensureWeek($week);
            $scoreboard->meta['weekNum'] = ++$weekNumber;
            foreach ($classrooms as $classroomDate) {
                // TODO deal with non-friday classroom scenarios
                if ($classroomDate->eq($week)) {
                    $scoreboard->meta['isClassroom'] = true;
                }
            }
            $weekLock = $locks->getWeekDefault($reportingDate, $week);
            $scoreboard->meta['canEditPromise'] = $weekLock->editPromise;
            $scoreboard->meta['canEditActual'] = $weekLock->editActual || ($week->toDateString() == $reportingDate->toDateString());
        }

        if ($includeInProgress) {
            $submissionData = App::make(Api\SubmissionData::class);
            $found = $submissionData->allForType($center, $reportingDate, Domain\Scoreboard::class);
            foreach ($found as $stashed) {
                $scoreboard = $weeks->ensureWeek($stashed->week);
                if ($scoreboard->game('cap')->promise() !== null && !$scoreboard->meta['canEditPromise']) {
                    // If we can't edit promises, only copy actuals.
                    $stashed->eachGame(function ($game) use ($scoreboard) {
                        $scoreboard->setValue($game->key, 'actual', $game->actual());
                    });
                    $scoreboard->meta['mergedLocal'] = true; // mostly as a useful value in tests
                } else {
                    // laziest way to do this is to simply fill it with the array
                    $scoreboard->parseArray($stashed->toArray());
                }
                $scoreboard->meta['localChanges'] = true;
            }
        }

        if ($returnObject) {
            return $weeks;
        }

        $output = [];
        foreach ($weeks->sortedValues() as $scoreboard) {
            $output[] = $scoreboard->toNewArray();
        }

        return $output;
    }

    public function stash(Models\Center $center, Carbon $reportingDate, $data)
    {
        $this->assertAuthz($this->context->can('submitStats', $center), 'User not allowed access to submit this report');
        App::make(Api\SubmissionCore::class)->checkCenterDate($center, $reportingDate, ['write']);

        $scoreboard = Domain\Scoreboard::fromArray($data);
        $submissionData = App::make(Api\SubmissionData::class);
        $submissionData->store($center, $reportingDate, $scoreboard);

        $report = Api\LocalReport::ensureStatsReport($center, $reportingDate);
        $validationResults = App::make(Api\ValidationData::class)->validate($center, $reportingDate);

        $messages = [];
        if (isset($validationResults['messages']['Scoreboard'])) {
            $weekString = $scoreboard->week->toDateString();
            foreach ($validationResults['messages']['Scoreboard'] as $message) {
                if ($message->reference()['id'] == $weekString) {
                    $messages[] = $message;
                }
            }
        }

        return [
            'success' => true,
            'valid' => $validationResults['valid'],
            'messages' => $messages,
            'week' => $scoreboard->week->toDateString(),
        ];
    }

    public function getScoreboardLockQuarter(Models\Center $center, Models\Quarter $quarter)
    {
        $v = $this->context->getSetting(static::LOCK_SETTING_KEY, $center, $quarter);
        if ($v === null) {
            // Create a blank scoreboard lock with reporting dates filled
            $quarter->setRegion($center->region);
            $reportingDates = $quarter->getCenterQuarter($center)->listReportingDates();

            return new Domain\ScoreboardLockQuarter($reportingDates);
        } else {
            return Domain\ScoreboardLockQuarter::fromArray($v);
        }
    }

    public function setScoreboardLockQuarter(Models\Center $center, Models\Quarter $quarter, $data)
    {
        $this->assertCan('adminScoreboard', $center);
        $locks = Domain\ScoreboardLockQuarter::fromArray($data);
        Models\Setting::upsert([
            'name' => static::LOCK_SETTING_KEY,
            'center' => $center,
            'quarter' => $quarter,
            'value' => $locks->toArray(),
        ]);
    }

    public function getWeekSoFar(Models\Center $center, Carbon $reportingDate, $includeInProgress = true)
    {
        $results = [];

        $allData = $this->allForCenter($center, $reportingDate, $includeInProgress);
        foreach ($allData as $dataArr) {
            $meta = array_get($dataArr, 'meta', []);
            $dataObject = Domain\Scoreboard::fromArray($dataArr);

            if (array_get($meta, 'canEditPromise', false) || array_get($meta, 'canEditActual', false)) {
                $results[] = $dataObject;
            }
        }

        return $results;
    }
}
