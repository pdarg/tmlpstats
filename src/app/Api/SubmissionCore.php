<?php
namespace TmlpStats\Api;

use App;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Log;
use Mail;
use TmlpStats as Models;
use TmlpStats\Api;
use TmlpStats\Api\Base\AuthenticatedApiBase;
use TmlpStats\Api\Exceptions;
use TmlpStats\Api\Traits;
use TmlpStats\Domain;
use TmlpStats\Encapsulations;
use TmlpStats\Http\Controllers;

class SubmissionCore extends AuthenticatedApiBase
{
    use Traits\UsesReportDates;
    /**
     * Initialize a submission UI, checking if parameters are valid and returning useful lookups.
     * @param  Models\Center $center
     * @param  Carbon        $reportingDate
     * @return array
     */
    public function initSubmission(Models\Center $center, Carbon $reportingDate)
    {
        $this->checkCenterDate($center, $reportingDate);

        $localReport = App::make(LocalReport::class);
        $rq = $this->reportAndQuarter($center, $reportingDate);
        $lastValidReport = $rq['report'];
        $quarter = $rq['quarter'];

        $crd = Encapsulations\CenterReportingDate::ensure($center, $reportingDate);
        $cq = $crd->getCenterQuarter();
        if ($cq->classroom2Date === null || $cq->classroom3Date === null) {
            throw new \Exception("The quarter {$cq->quarter->getDisplayLabel()} does not have its milestone date configured. Please contact your regional statistician");
        }
        $centerQuarter = $crd->getCenterQuarter();

        if ($lastValidReport === null) {
            $team_members = [];
        } else {
            $team_members = $localReport->getClassList($lastValidReport);
        }

        // Get values for lookups
        $withdraw_codes = Models\WithdrawCode::get();
        $accountabilities = Models\Accountability::orderBy('display')->get();
        $centers = Models\Center::byRegion($center->getGlobalRegion())->active()->orderBy('name')->get();

        try {
            $validRegQuarters = App::make(Api\Application::class)->validRegistrationQuarters($center, $reportingDate, $quarter);
            $validStartQuarters = App::make(Api\TeamMember::class)->validStartQuarters($center, $reportingDate, $quarter);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'id' => $center->id,
            'user' => [
                'canSkipSubmitEmail' => $this->context->can('skipSubmitEmail', $center),
                'canOverrideDelete' => $this->context->can('overrideDelete', $center),
                'canSubmitMultipleTdos' => true,
            ],
            'capabilities' => [
                'nextQtrAccountabilities' => $crd->canShowNextQtrAccountabilities(),
            ],
            'validRegQuarters' => $validRegQuarters,
            'validStartQuarters' => $validStartQuarters,
            'lookups' => compact('withdraw_codes', 'team_members', 'center', 'centers'),
            'accountabilities' => $accountabilities,
            'currentQuarter' => $centerQuarter,
            'systemMessages' => Models\SystemMessage::centerActiveMessages('submission', $center)->get(),
        ];
    }

    /**
     * Finalizes a submission
     *
     * @param  Models\Center $center
     * @param  Carbon        $reportingDate
     * @return array
     */
    public function completeSubmission(Models\Center $center, Carbon $reportingDate, array $data)
    {
        $this->checkCenterDate($center, $reportingDate, ['write']);

        $this->assertAuthz($this->context->can('submitStats', $center));

        $results = App::make(ValidationData::class)->validate($center, $reportingDate);
        if (!$results['valid']) {
            // TODO: figure out what we want to do here
            // validation failed. for now, exit
            return [
                'success' => false,
                'id' => $center->id,
                'message' => 'Validation failed. Please correct issues indicated on the Review page and try again.',
            ];
        }

        $reportingDate->startOfDay();

        DB::beginTransaction();
        $debug_message = '';
        $person_id = -1;
        $reg_id = -1;

        $programLeaderApi = App::make(Api\Submission\ProgramLeader::class);

        try {
            // Create stats_report record and get id
            $statsReport = LocalReport::ensureStatsReport($center, $reportingDate);
            $statsReport->validated = true;
            $statsReport->locked = true;
            $statsReport->submittedAt = Carbon::now();
            $statsReport->validationMessages = $results['messages'];
            $statsReport->userId = $this->context->getUser()->id;
            $statsReport->submitComment = array_get($data, 'comment', null);
            $statsReport->save();

            $lastStatsReportDate = $reportingDate->copy()->subWeek();

            // Report is as of 3PM on Friday (technically this should be center time)
            $reportNow = $reportingDate->copy()->setTime(15, 0, 0);
            // Quarter is over (for accountables) at 12pm on Saturday at the weekend
            // It's not Friday at 3pm because we want people to still appear as accountable on the final report
            $quarterEndDate = $statsReport->quarter->getQuarterEndDate($statsReport->center)->addDay()->setTime(12, 00, 00);

            $isFirstWeek = $statsReport->reportingDate->eq($statsReport->quarter->getFirstWeekDate($statsReport->center));

            $debug_message .= ' sr_id=' . $statsReport->id;

            // Process scoreboard weeks (promises and actuals) and also totals of program leaders
            $debug_message .= $this->submitCenterStatsData($center, $reportingDate, $statsReport);

            // Process applications
            $apps = App::make(Api\Application::class)->allForCenter($center, $reportingDate, true);
            $debug_message .= $this->submitApplications($center, $reportingDate, $statsReport, $apps);

            $teamMembers = App::make(Api\TeamMember::class)->allForCenter($center, $reportingDate, true);
            $debug_message .= $this->submitTeamMembers($center, $reportingDate, $statsReport, $teamMembers);

            $toSetAccountabilities = [];

            // Only update the most recently stored PM/CL, ignore any other stashed rows
            foreach (['programManager', 'classroomLeader'] as $accountabilityName) {
                $result = DB::select('
                    SELECT i.*
                    FROM submission_data_program_leaders i
                    LEFT OUTER JOIN people p ON p.id=i.stored_id
                    WHERE i.center_id=? and i.reporting_date=? and i.accountability=?
                    ORDER BY i.id DESC
                    LIMIT 1
                ', [$center->id, $reportingDate->toDateString(), $accountabilityName]);

                if (!$result) {
                    continue;
                }

                $r = $result[0];

                if ($r->stored_id < 0) {
                    // This is a new program leader, create the things
                    DB::insert('
                        INSERT INTO people
                            (first_name, last_name, email, phone, center_id, created_at, updated_at)
                        SELECT i.first_name, i.last_name, i.email, i.phone, i.center_id, sysdate(), sysdate()
                        FROM submission_data_program_leaders i
                        WHERE i.id=?
                    ', [$r->id]);
                    $person_id = DB::getPdo()->lastInsertId();

                    // Update submission_data with new id so we don't overwrite if the report is resubmitted
                    DB::update('UPDATE submission_data SET stored_id=?, data = JSON_SET(data, "$.id", ?) WHERE id=?', [$person_id, $person_id, $r->id]);
                } else {
                    // This is an existing program leader, update the things
                    DB::update('
                        UPDATE people p, submission_data_program_leaders sda
                        SET p.updated_at=sysdate(),
                            p.first_name=sda.first_name,
                            p.last_name=sda.last_name,
                            p.email=sda.email,
                            p.phone=sda.phone,
                            p.updated_at=sysdate()
                        WHERE p.id=sda.stored_id
                            AND sda.id=?
                            AND (coalesce(p.first_name,\'\') != coalesce(sda.first_name,\'\')
                                OR coalesce(p.last_name,\'\') != coalesce(sda.last_name,\'\')
                                OR coalesce(p.email,\'\') != coalesce(sda.email,\'\')
                                OR coalesce(p.phone,\'\') != coalesce(sda.phone,\'\')
                            )
                    ', [$r->id]);

                    $person_id = $r->stored_id;
                }

                $person = Models\Person::find($person_id);
                $accountability = Models\Accountability::name($r->accountability)->first();
                if ($person && $accountability && !$person->hasAccountability($accountability, $reportNow)) {
                    Log::error("Taking over accountability {$r->accountability} for person {$person->id}.");
                    $toSetAccountabilities[$accountability->id] = [$person->id];
                }

                $field = ($accountabilityName == 'programManager') ? 'program_manager_attending_weekend' : 'classroom_leader_attending_weekend';
                DB::update("
                    UPDATE center_stats_data csd, submission_data_program_leaders sd
                    SET csd.{$field} = sd.attending_weekend
                    WHERE sd.id=? AND csd.stats_report_id=? AND csd.reporting_date=? AND csd.type='actual'
                ", [$r->id, $statsReport->id, $reportingDate->toDateString()]);
            }

            if (count($toSetAccountabilities)) {
                // set or override program leaders as ending slightly past quarter end. They can be curtailed later.
                $programLeaderFinal = $quarterEndDate->copy()->addDays(3);
                Models\AccountabilityMapping::bulkSetCenterAccountabilities($center, $reportNow, $programLeaderFinal, $toSetAccountabilities);
            }
            // end program leader processing

            //Insert course data
            $result = DB::select('select i.* from submission_data_courses i
                                    where i.center_id=? and i.reporting_date=?',
                [$center->id, $reportingDate->toDateString()]);
            if (!empty($result)) {
                foreach ($result as $r) {
                    $c_id = -1;
                    if ($r->course_id < 0) {
                        DB::insert('insert into courses
                                        ( id, start_date, type, location, center_id, created_at, updated_at)
                                            select null, i.start_date, i.type, i.location, i.center_id,  sysdate(), sysdate()
                                        from submission_data_courses i where i.id=?',
                            [$r->id]);
                        $c_id = DB::getPdo()->lastInsertId();
                        $debug_message .= ' c_id=' . $c_id;

                        // Update submission_data with new id so we don't overwrite if the report is resubmitted
                        DB::update('update submission_data set stored_id=?, data = JSON_SET(data, "$.id", ?) where id=?', [$c_id, $c_id, $r->id]);
                    } else {
                        // This is an existing course, update the things
                        DB::update('update courses c, submission_data_courses sda
                                    set c.updated_at=sysdate(),
                                        c.start_date=sda.start_date,
                                        c.type=sda.type,
                                        c.location=sda.location
                                    where c.id=sda.course_id
                                          and sda.id=?
                                          and (coalesce(c.start_date,\'\') != coalesce(sda.start_date,\'\')
                                                or coalesce(c.type,\'\') != coalesce(sda.type,\'\')
                                                or coalesce(c.location,\'\') != coalesce(sda.location,\'\'))',
                            [$r->id]);
                        $c_id = $r->course_id;
                    };

                    $affected = DB::insert(
                        'insert into courses_data
                            (course_id, quarter_start_ter, quarter_start_standard_starts, quarter_start_xfer,
                            current_ter, current_standard_starts, current_xfer,
                            completed_standard_starts, potentials, registrations,
                            guests_promised, guests_invited, guests_confirmed, guests_attended,
                            stats_report_id, created_at, updated_at)
                         select course_id, quarter_start_ter, quarter_start_standard_starts,  quarter_start_xfer,
                            current_ter, current_standard_starts, current_xfer,
                            completed_standard_starts, potentials, registrations,
                            guests_promised, guests_invited, guests_confirmed, guests_attended, ?, sysdate(),sysdate()
                         from submission_data_courses
                         where center_id=? and reporting_date=? and course_id=?',
                        [$statsReport->id, $center->id, $reportingDate->toDateString(), $c_id]);
                    $debug_message .= ' upd_courses_rows=' . $affected;
                }
            } // end course processing

            if (!$isFirstWeek) {
                $affected = DB::insert(
                    'insert into courses_data
                        (course_id, quarter_start_ter, quarter_start_standard_starts, quarter_start_xfer,
                        current_ter, current_standard_starts, current_xfer,
                        completed_standard_starts, potentials, registrations,
                        guests_promised, guests_invited, guests_confirmed, guests_attended,
                        stats_report_id, created_at, updated_at)
                     select course_id, quarter_start_ter, quarter_start_standard_starts,  quarter_start_xfer,
                        current_ter, current_standard_starts, current_xfer,
                        completed_standard_starts, potentials, registrations,
                        guests_promised, guests_invited, guests_confirmed, guests_attended, ?, sysdate(),sysdate()
                     from courses_data
                            where stats_report_id in
                                (select id from global_report_stats_report gr, stats_reports s
                                    where gr.stats_report_id=s.id
                                        and s.reporting_date=? and
                                        s.center_id=?
                                )
                                and course_id not in
                                    (select course_id from submission_data_courses
                                        where center_id=? and reporting_date=?)',
                    [$statsReport->id, $lastStatsReportDate->toDateString(), $center->id,
                        $center->id, $reportingDate->toDateString()]);
            }

            // Add/update all accountability holders
            $teamMembers = App::make(Api\TeamMember::class)->allForCenter($center, $reportingDate, true);
            $this->submitTeamAccountabilities($center, $reportingDate, $reportNow, $quarterEndDate, $teamMembers);

            // Mark stats report as 'official'
            $globalReport = Models\GlobalReport::firstOrCreate([
                'reporting_date' => $reportingDate,
            ]);
            $globalReport->addCenterReport($statsReport);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Exception caught performing submission: ' . $e->getMessage(), [
                'center' => $center->abbrLower(),
                'reportingDate' => $reportingDate->toDateString(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'id' => $center->id,
                'message' => $e->getMessage(),
                'debug_message' => $debug_message,
            ];
        }

        $success = true;
        DB::commit();

        if (array_get($data, 'skipSubmitEmail', false) && $this->context->can('skipSubmitEmail', $center)) {
            $emailResults = '<strong>Thank you.</strong> We received your statistics and did not send notification emails.';
        } else {
            $emailResults = $this->sendStatsSubmittedEmail($statsReport);
        }

        $submittedAt = $statsReport->submittedAt->copy()->setTimezone($center->timezone);

        return [
            'success' => $success,
            'id' => $center->id,
            'submittedAt' => $submittedAt->toDateTimeString(),
            'message' => $emailResults,
            'debug_message' => $debug_message,
        ];
    }

    /**
     * Create application objects for submitted statsReport
     *
     * @param  Models\Center      $center
     * @param  Carbon             $reportingDate Report date
     * @param  Models\StatsReport $statsReport   Report to attach objects to
     * @param  mixed              $apps          Arrayable collcation of Domain\TeamApplication objects
     * @return string                            Debug string
     */
    public function submitApplications(Models\Center $center, Carbon $reportingDate, Models\StatsReport $statsReport, $apps)
    {
        $lastReport = $this->relevantReport($center, $reportingDate->copy()->subWeek());

        $appIds = collect($apps)
            ->filter(function ($app) {return $app->id >= 0;})
            ->map(function ($app) {return $app->id;})
            ->keys();

        // Prefetch all the applications so we only need to do one query
        if ($lastReport !== null) {
            $lastWeekData = Models\TmlpRegistrationData::byStatsReport($lastReport)
                ->whereIn('tmlp_registration_id', $appIds)
                ->get()
                ->keyBy(function ($app) {return $app->tmlpRegistrationId;});
        } else {
            $lastWeekData = collect([]);
        }

        // clear transfers
        Models\Transfer::byCenter($center)->reportingDate($reportingDate)->delete();

        $debug_message = '';
        foreach ($apps as $app) {
            if ($app->id < 0) {
                // This is a new application so create it
                $application = $this->createNewApplication($center, $app);

                // Now update the stash so subsequent submits don't create new people again
                $this->updateStashIds($center, $reportingDate, 'application', $app->id, $application->id);

                $debug_message .= " sreg_id={$app->id} person_id={$application->person->id}";
            } else {
                // Update application
                $application = $this->updateExistingApplication($center, $app);
                if (!$application) {
                    // TODO: handle this case
                    Log::error("Application {$app->id} not found for update during submit");
                    continue;
                }

                // Check if the incoming quarter has changed since last week. If it has, create a
                // transfer event
                $lastWeekAppData = array_get($lastWeekData, $app->id);
                if ($lastWeekAppData && $lastWeekAppData->incomingQuarterId !== $app->incomingQuarterId) {
                    Models\Transfer::create([
                        'center_id' => $center->id,
                        'reporting_date' => $reportingDate->toDateString(),
                        'subject_type' => 'application',
                        'subject_id' => $app->id,
                        'transfer_type' => 'quarter',
                        'from_id' => $lastWeekAppData->incomingQuarterId,
                        'to_id' => $app->incomingQuarterId,
                    ]);
                }
            }

            // Crate a new data object for all applications. If new data was stashed, that's included
            // along with last week's data for anyone that wasn't updated
            $appData = Models\TmlpRegistrationData::create([
                'stats_report_id' => $statsReport->id,
                'tmlp_registration_id' => $application->id,
                'incoming_quarter_id' => $app->incomingQuarter->id,
                'reg_date' => $app->regDate,
                'app_out_date' => $app->appOutDate ?: null,
                'app_in_date' => $app->appInDate ?: null,
                'appr_date' => $app->apprDate ?: null,
                'wd_date' => $app->wdDate ?: null,
                'comment' => $app->comment,
                'travel' => (bool) $app->travel,
                'room' => (bool) $app->room,
            ]);
            if ($app->withdrawCode) {
                $appData->withdrawCodeId = $app->withdrawCode->id;
            }
            if ($app->committedTeamMember) {
                $appData->committedTeamMemberId = $app->committedTeamMember->id;
            }
            $appData->save();

            $debug_message .= " reg_id={$application->id} trd_id={$appData->id}";
        }

        return $debug_message;
    }

    /**
     * Create new TmlpRegistration and Person object
     *
     * @param  Models\Center          $center
     * @param  Domain\TeamApplication $app
     * @return Models\TmlpRegistration
     */
    protected function createNewApplication(Models\Center $center, Domain\TeamApplication $app)
    {
        $person = null;

        // If the app gave us a hint, try to use that person
        if (!empty($app->_personId)) {
            $person = Models\Person::find($app->_personId);
        }

        // Create a new person object if we don't already know this person
        if (!$person) {
            $person = Models\Person::create([
                'center_id' => $center->id,
                'first_name' => $app->firstName,
                'last_name' => $app->lastName,
                'identifier' => '',
            ]);
        }

        return Models\TmlpRegistration::create([
            'person_id' => $person->id,
            'team_year' => $app->teamYear,
            'reg_date' => $app->regDate,
            'is_reviewer' => (bool) $app->isReviewer,
        ]);
    }

    /**
     * Update existing application with data from Domain\TeamApplication
     *
     * @param  Models\Center          $center
     * @param  Domain\TeamApplication $app
     * @return Models\TmlpRegistration
     */
    protected function updateExistingApplication(Models\Center $center, Domain\TeamApplication $app)
    {
        $application = Models\TmlpRegistration::find($app->id);
        if (!$application) {
            return null;
        }

        // Update application
        $application->teamYear = $app->teamYear;
        $application->regDate = $app->regDate;
        $application->isReviewer = (bool) $app->isReviewer;
        $application->save();

        // Update person
        $person = $application->person;
        $person->centerId = $center->id;
        $person->firstName = $app->firstName;
        $person->lastName = $app->lastName;
        $person->save();

        $application->setRelation('person', $person);

        return $application;
    }

    /**
     * Update stash ids
     *
     * This will update stored_id and data['id'] with the value provided for $newId.
     * Useful for updating stashes after creating new objects so subsequent submits
     * don't create additional objects.
     *
     * @param  Models\Center $center
     * @param  Carbon        $reportingDate
     * @param  string        $type          Stash stored_type
     * @param  string        $stashId       Stash stored_id
     * @param  string        $newId         New value for stroed_id
     * @return bool
     */
    protected function updateStashIds(Models\Center $center, Carbon $reportingDate, string $type, $stashId, $newId): bool
    {
        // Now update the stash so subsequent submits don't create new people again
        $stash = Models\SubmissionData::centerDate($center, $reportingDate)
            ->typeId($type, $stashId)
            ->first();

        if (!$stash) {
            return false;
        }

        $stash->storedId = $newId;
        $stash->data = array_merge($stash->data, ['id' => $newId]);
        $stash->save();

        return true;
    }

    protected function submitCenterStatsData(Models\Center $center, Carbon $reportingDate, Models\StatsReport $statsReport)
    {
        $debug_message = '';
        // Process scoreboards:
        // Loop through scoreboard weeks and handle appropriately.
        $sbWeeks = App::make(Api\Submission\Scoreboard::class)->allForCenter($center, $reportingDate, true, true);
        $teamMemberApi = App::make(Api\TeamMember::class);

        foreach ($sbWeeks->sortedValues() as $scoreboard) {
            if (!array_get($scoreboard->meta, 'localChanges', false)) {
                continue;
            }
            foreach (['promise', 'actual'] as $type) {
                if ($scoreboard->meta['canEdit' . ucfirst($type)]) {
                    $csd = new Models\CenterStatsData([
                        // reporting date in this context is not the date we're doing the report, but the week of the scoreboard in question.
                        'reporting_date' => $scoreboard->week,
                        'stats_report_id' => $statsReport->id,
                        'type' => $type,
                        'points' => $scoreboard->points(),
                    ]);

                    if ($type == 'actual') {
                        list($pmAttending, $clAttending) = $this->calculateProgramLeaderAttending($center, $scoreboard->week);
                        $people = $teamMemberApi->allForCenter($center, $scoreboard->week, true);
                        $csd->programManagerAttendingWeekend = $pmAttending;
                        $csd->classroomLeaderAttendingWeekend = $clAttending;
                        $csd->tdo = $this->calculateTdoFromStashes($people);
                    } else {
                        $csd->tdo = 100;
                    }

                    // loop through to handle handle the 6-games (cap, cpc, etc)
                    foreach ($scoreboard->games() as $gameKey => $game) {
                        $csd->$gameKey = $game->$type(); // metaprogramming: e.g. $csd->cap = $game->promise()
                    }

                    $csd->save();
                    $debug_message .= " csd{$type}={$csd->id}";
                }
            }
        }

        return $debug_message;
    }

    protected function calculateTdoFromStashes($teamMembers)
    {
        $totalMembers = 0;
        $completed = 0;
        foreach ($teamMembers as $tm) {
            if ($tm->xferOut || $tm->withdrawCode !== null || $tm->wbo) {
                continue;
            }
            $totalMembers++;
            $completed += ($tm->tdo) ? 1 : 0;
        }
        if (!$totalMembers) {return 0;}

        return round((100.0 * $completed) / ((float) $totalMembers));
    }

    public function calculateProgramLeaderAttending(Models\Center $center, Carbon $reportingDate)
    {
        $leaders = App::make(Api\Submission\ProgramLeader::class)->allForCenter($center, $reportingDate, true);
        $pmAttending = 0;
        $clAttending = 0;

        // This is done due to the limitations of our current storage method.
        // In the future, we should move towards a place we can loop people, allowing situations like multiple classroom leaders (where one's an apprentice or specifically during a weekend of a CL change)
        if (($pmId = $leaders['meta']['programManager']) !== null) {
            $pmAttending += ($leaders[$pmId]->attendingWeekend) ? 1 : 0;
        }

        if (($clId = $leaders['meta']['classroomLeader']) !== null && $clId != $pmId) {
            $clAttending += ($leaders[$clId]->attendingWeekend) ? 1 : 0;
        }

        return [$pmAttending, $clAttending];
    }

    protected function ensureTeamMember(Models\Center $center, Domain\TeamMember $tmDomain)
    {
        $person = ($tmDomain->_personId) ? Models\Person::findOrFail($tmDomain->_personId) : null;
        if ($tmDomain->id < 0) {
            if (!$person) {
                list($person, $identifier) = Models\TeamMember::findAppropriatePerson(
                    $center, $tmDomain->firstName, $tmDomain->lastName,
                    $tmDomain->teamYear, $tmDomain->incomingQuarter
                );
                if (!$person) {
                    $person = Models\Person::firstOrCreate([
                        'center_id' => $center->id,
                        'first_name' => $tmDomain->firstName,
                        'last_name' => $tmDomain->lastName,
                        'identifier' => $identifier,
                    ]);
                }
            }
            $tm = Models\TeamMember::create([
                'person_id' => $person->id,
                'team_year' => $tmDomain->teamYear,
                'at_weekend' => $tmDomain->atWeekend,
                'incoming_quarter_id' => $tmDomain->incomingQuarterId,
            ]);
            $tm->setRelation('person', $person); // To avoid an extra lookup

            return [$tm, $person];
        } else {
            $tm = Models\TeamMember::findOrFail($tmDomain->id);

            // Check for team member person overrides
            if ($tmDomain->_personId !== null && $tm->personId != $tmDomain->_personId) {
                // Only apply override if the user is allowed to override team person IDs.
                if ($this->context->can('overrideTeamPerson', $center)) {
                    $tm->personId = $person->id;
                    $tm->setRelation('person', $person);
                } else {
                    $tmDomain->_personId = null;
                }
            }

            return [$tm, $tm->person];
        }
    }

    public function submitTeamMembers(Models\Center $center, Carbon $reportingDate, Models\StatsReport $statsReport, $teamMembers)
    {
        $debug_message = '';
        foreach ($teamMembers as $tmDomain) {
            list($tm, $person) = $this->ensureTeamMember($center, $tmDomain);
            if ($tmDomain->id < 0) {
                // If ID < 0, then we need to update any existing stash with the newly assigned team member ID
                $this->updateStashIds($center, $reportingDate, 'team_member', $tmDomain->id, $tm->id);
                $tmDomain->id = $tm->id;
            }
            $tmd = Models\TeamMemberData::firstOrNew([
                'team_member_id' => $tm->id,
                'stats_report_id' => $statsReport->id,
            ]);
            $tmDomain->fillModel($tmd, $tm, false);
            $person->save();
            $tm->save();
            $tmd->save();
            $debug_message .= ' last_tmd_id=' . $tmd->id;

        }

        return $debug_message . '\n';
    }

    public function submitTeamAccountabilities(Models\Center $center, Carbon $reportingDate, Carbon $reportNow, Carbon $quarterEndDate, $teamMembers)
    {
        // Phase 1: make a map of accountability ID -> person
        $toApply = [];
        foreach ($teamMembers as $k => $tm) {
            // no idea why we'd have a negative ID at this point, but let's just be safe.
            if ($tm->id > 0 && count($tm->accountabilities)) {
                try {
                    $person = $tm->getAssociatedPerson();
                    foreach ($tm->accountabilities as $accId) {
                        $toApply[$accId][] = $person->id;
                    }
                } catch (\Exception $e) {
                    // TODO send email
                }
            }
        }
        // Phase 2: Loop accountabilities (Skip program managers and classroom leaders for now)
        $allAccountabilities = Models\Accountability::context('team')
            ->whereNotIn('name', ['programManager', 'classroomLeader'])
            ->get();
        foreach ($allAccountabilities as $accountability) {
            if (!isset($toApply[$accountability->id])) {
                $toApply[$accountability->id] = [];
            }
        }

        Models\AccountabilityMapping::bulkSetCenterAccountabilities($center, $reportNow, $quarterEndDate, $toApply);

    }

    public function checkCenterDate(Models\Center $center, Carbon $reportingDate, $flags = [])
    {
        if ($reportingDate->dayOfWeek !== Carbon::FRIDAY) {
            throw new Exceptions\BadRequestException('Reporting date must be a Friday.');
        }

        if (!$center->active) {
            throw new Exceptions\BadRequestException('Center is not Active');
        }

        if (in_array('write', $flags)) {

            if ($center->getGlobalRegion()->abbrLower() == 'na' && !$this->context->can('submitOldStats', $center)) {
                if ($reportingDate->lte(Carbon::parse(config('tmlp.earliest_submission')))) {
                    // TODO come up with a cleaner solution to this
                    throw new Exceptions\BadRequestException('Cannot do online submission prior to June');
                }
            }
        }

        return ['success' => true];
    }

    /**
     * Do the very common lookup of getting the last stats report and the quarter for a given
     * center-reportingdate pair.
     *
     * In the case there is no official report on dates before the given reportingDate,
     * (this happens on the first weekly submission) the report will be null.
     *
     * @param  Models\Center $center        The center we're getting the statsReport from
     * @param  Carbon        $reportingDate The reporting date of a stats report.
     * @return array[report, quarter]       An associative array with keys report and quarter
     */
    public function reportAndQuarter(Models\Center $center, Carbon $reportingDate)
    {
        $report = App::make(LocalReport::class)->getLastStatsReportSince($center, $reportingDate, ['official']);
        if ($report === null) {
            $quarter = Models\Quarter::getQuarterByDate($reportingDate, $center->region);
        } else {
            $quarter = $report->quarter;
        }

        return compact('report', 'quarter');
    }

    public function sendStatsSubmittedEmail(Models\StatsReport $statsReport)
    {
        $result = [];

        $user = ucfirst($this->context->getUser()->firstName);
        $quarter = $statsReport->quarter;
        $center = $statsReport->center;
        $region = $center->region;
        $reportingDate = $statsReport->reportingDate;

        $submittedAt = $statsReport->submittedAt->copy()->setTimezone($center->timezone);

        $due = $statsReport->due();
        $respondByDateTime = $statsReport->responseDue();

        $isLate = $submittedAt->gt($due);

        $reportNow = $reportingDate->copy()->setTime(15, 0, 0);

        $programManager = $center->getProgramManager($reportNow);
        $classroomLeader = $center->getClassroomLeader($reportNow);
        $t1TeamLeader = $center->getT1TeamLeader($reportNow);
        $t2TeamLeader = $center->getT2TeamLeader($reportNow);
        $statistician = $center->getStatistician($reportNow);
        $statisticianApprentice = $center->getStatisticianApprentice($reportNow);

        $emailMap = [
            'center' => $center->statsEmail,
            'regional' => $region->email,
            'programManager' => $this->getEmail($programManager),
            'classroomLeader' => $this->getEmail($classroomLeader),
            't1TeamLeader' => $this->getEmail($t1TeamLeader),
            't2TeamLeader' => $this->getEmail($t2TeamLeader),
            'statistician' => $this->getEmail($statistician),
            'statisticianApprentice' => $this->getEmail($statisticianApprentice),
        ];

        $emailTo = $emailMap['center'] ?: $emailMap['statistician'];

        $mailingList = $center->getMailingList($quarter);

        if ($mailingList) {
            $emailMap['mailingList'] = $mailingList;
        }

        $emails = [];
        foreach ($emailMap as $accountability => $email) {

            if (!$email || $email == $emailTo) {
                continue;
            }

            if (is_array($email)) {
                $emails = array_merge($emails, $email);
            } else {
                $emails[] = $email;
            }
        }
        $emails = array_unique($emails);
        natcasesort($emails);

        $globalReport = Models\GlobalReport::reportingDate($statsReport->reportingDate)->first();

        $reportToken = Models\ReportToken::get($globalReport, $center);
        $reportUrl = url("/report/{$reportToken->token}");

        $mobileDashUrl = 'https://tmlpstats.com/m/' . strtolower($center->abbreviation);

        $submittedCount = Models\StatsReport::byCenter($center)
            ->reportingDate($statsReport->reportingDate)
            ->submitted()
            ->count();
        $isResubmitted = ($submittedCount > 1);

        $centerName = $center->name;
        $comment = $statsReport->submitComment;
        $reportMessages = App::make(Controllers\StatsReportController::class)->compileApiReportMessages($statsReport);
        try {
            Mail::send('emails.apistatssubmitted',
                compact('centerName', 'comment', 'due', 'isLate', 'isResubmitted', 'mobileDashUrl',
                    'reportingDate', 'reportUrl', 'respondByDateTime', 'submittedAt', 'user', 'reportMessages'),
                function ($message) use ($emailTo, $emails, $emailMap, $centerName) {
                    // Only send email to centers in production
                    if (config('app.env') === 'prod') {
                        $message->to($emailTo);
                        foreach ($emails as $email) {
                            $message->cc($email);
                        }
                    } else {
                        $message->to(config('tmlp.admin_email'));
                    }

                    if ($emailMap['regional']) {
                        $message->replyTo($emailMap['regional']);
                    }

                    $message->subject("Team {$centerName} Statistics Submitted");
                }
            );
            $successMessage = '<strong>Thank you.</strong> We received your statistics and notified the following emails'
            . " <ul><li>{$emailTo}</li><li>" . implode('</li><li>', $emails) . '</li></ul>'
                . ' Please reply-all to that email if there is anything you need to communicate.';

            if (config('app.env') === 'prod') {
                Log::info("Sent emails to the following people with team {$centerName}'s report: " . implode(', ', $emails));
            } else {
                Log::info("Sent emails to the following people with team {$centerName}'s report: " . config('tmlp.admin_email'));
                $successMessage .= '<br/><br/><strong>Since this is development, we sent it to '
                . config('tmlp.admin_email') . ' instead.</strong>';
            }

            return $successMessage;
        } catch (\Exception $e) {
            Log::error('Exception caught sending error email: ' . $e->getMessage());

            return 'There was a problem emailing everyone about your stats. Please contact your'
                . " Regional Statistician ({$emailMap['regional']}) using your center stats email"
                . " ({$emailMap['center']}) letting them know.";
        }
    }

    public function getEmail(Models\Person $person = null)
    {
        if (!$person || $person->unsubscribed) {
            return null;
        }

        return $person->email;
    }

    public function initFirstWeekData(Models\Center $center, Models\Quarter $quarter)
    {
        $this->assertCan('copyQuarterData', $center);

        $cq = Domain\CenterQuarter::ensure($center, $quarter);
        // The start weekend is actually in the previous quarter, so we can use that to grab the previous quarter
        $lastWeek = Encapsulations\CenterReportingDate::ensure($center, $cq->startWeekendDate);
        $lastQuarter = $lastWeek->getQuarter();
        $report = [];

        $initData = $this->initSubmission($center, $cq->firstWeekDate);

        // ends up being a map of quarter ID -> random index value which doesn't matter much
        $validStartQids = $initData['validStartQuarters']
            ->map(function ($cq) {return $cq->quarter->id;})
            ->flip();

        // Build a map of team members to next quarter accountabilities
        $nqas = App::make(Api\Submission\NextQtrAccountability::class)->allForCenter($center, $lastWeek->reportingDate);

        $teamNqas = [];
        foreach ($nqas as $nqa) {
            if ($nqa->teamMemberId !== null) {
                $teamNqas[$nqa->teamMemberId][] = $nqa->id;
            }
        }

        // Phase 1: Copy non-completing Team Members
        $goodTeamMembers = [];
        $tmApi = App::make(Api\TeamMember::class);
        $members = $tmApi->allForCenter($center, $lastWeek->reportingDate, true);
        foreach ($members as $id => $member) {
            if ($validStartQids->has($member->incomingQuarterId)
                && $member->withdrawCode == null
                && !$member->xferOut && !$member->wbo
            ) {
                $copy = 'Copied';
                $data = collect($member->toArray())->except([
                    // These change week to week, so don't copy them over
                    'tdo', 'gitw',
                    'rppCap', 'rppCpc', 'rppLf',
                ])->merge([
                    // New quarter, overwrite with default values
                    'xferIn' => false, 'ctw' => false,
                    'travel' => false, 'room' => false,
                    'comment' => '',
                    'accountabilities' => array_get($teamNqas, $id, []),
                ])->all(); // ->all() on a collection returns the underlying array
                $goodTeamMembers[$id] = true;

                $result = $tmApi->stash($center, $cq->firstWeekDate, $data);
                if ($result['success']) {
                    $copy .= ' And stashed';
                }
            } else {
                $copy = 'SKIPPED';
            }

            $report[] = "{$copy} Team Member {$member->id}: {$member->firstName} {$member->lastName}";
        }

        // Phase 2: Copy non-starting Team Expansion
        $appsApi = App::make(Api\Application::class);
        $applications = $appsApi->allForCenter($center, $lastWeek->reportingDate, true);
        $newTeamMembers = [];

        foreach ($applications as $id => $app) {
            $personInfo = "{$app->firstName} {$app->lastName} ({$app->id})";
            if ($app->withdrawCode === null) {
                $data = collect($app->toArray())->except(['travel', 'room']);
                if ($app->apprDate !== null && $app->incomingQuarterId == $quarter->id) {
                    // Save application to turn them into a team member in Phase 4.
                    // Only grab the data we want to copy over to the team member
                    $futureMember = $data->only([
                        'firstName',
                        'lastName',
                        'center',
                        'email',
                        'phone',
                        'teamYear',
                        'incomingQuarter',
                        'isReviewer',
                    ])->all();

                    // Stash the original personId so we can make sure to use the correct person
                    $appModel = Models\TmlpRegistration::find($data['id']);
                    if ($appModel && $appModel->personId) {
                        $futureMember['_personId'] = $appModel->personId;
                    }

                    $newTeamMembers[] = $futureMember;
                } else {
                    $data = $data->all(); // ->all() on a collection returns the underlying array
                    if (!array_key_exists($app->committedTeamMemberId, $goodTeamMembers) && $app->committedTeamMember) {
                        $ctm = $app->committedTeamMember->person;
                        $personInfo .= " NOTE: Had committed team member {$ctm->firstName} {$ctm->lastName} who completed.";
                        unset($data['committedTeamMember']);
                    }

                    $appsApi->stash($center, $cq->firstWeekDate, $data);

                    $report[] = "Applicant copied: $personInfo";
                }
            } else {
                $report[] = "SKIPPED withdrawn applicant {$personInfo}";
            }
        }

        // Phase 3: Copy non-completed courses
        $coursesApi = App::make(Api\Course::class);
        $courses = $coursesApi->allForCenter($center, $lastWeek->reportingDate, true);
        foreach ($courses as $id => $course) {
            if (intval($id) > 0 && $course->startDate->gt($lastWeek->reportingDate)) {
                $report[] = "Going to copy course {$course->type} {$course->startDate}";
                $data = array_merge($course->toArray(), [
                    'quarterStartTer' => $course->currentTer,
                    'quarterStartStandardStarts' => $course->currentStandardStarts,
                    'quarterStartXfer' => $course->currentXfer,
                ]);
                $coursesApi->stash($center, $cq->firstWeekDate, $data);
            }
        }

        // Phase 4: Copy starting Team Expansion
        if (!empty($newTeamMembers)) {
            $report[] = 'Number of Team Expansion to copy as new Team Members: ' .
            count($newTeamMembers);

            $nextQuarterMembers = $tmApi->allForCenter($center, $cq->firstWeekDate, true);
            $alreadyCopied = collect($nextQuarterMembers)
                ->filter(function($member) { return $member->isNew(); })
                ->map(function($member) { return $member->_personId; })
                ->flip() // rekey as personId => memberId
                ->all();

            foreach ($newTeamMembers as $index => $newTeamMember) {
                $logLine = "Team Member from Team Expansion {$newTeamMember['firstName']} {$newTeamMember['lastName']}";
                $newTeamMember['_alwaysStash'] = true; // stash even though validation will fail because we're missing tdo/gitw
                $newTeamMember['atWeekend'] = true;
                $personId = $newTeamMember['_personId'];

                // Reuse existing stash if one exists.
                if (isset($alreadyCopied[$personId])) {
                    $newTeamMember['id'] = $alreadyCopied[$personId];
                }

                $result = $tmApi->stash($center, $cq->firstWeekDate, $newTeamMember);
                if ($result['success']) {
                    $report[] = "Copied $logLine";
                } else {
                    $report[] = "Could not copy $logLine";
                }
            }
        } else {
            $report[] = 'No new team members to copy from Team Expansion';
        }

        return compact('report', 'validStartQids');
    }

}
