<?php
namespace TmlpStats\Http\Controllers;

use App;
use Auth;
use Cache;
use Carbon\Carbon;
use Exception;
use Gate;
use Illuminate\Http\Request;
use Log;
use Response;
use TmlpStats as Models;
use TmlpStats\Api;
use TmlpStats\Encapsulations;
use TmlpStats\Http\Controllers\Traits\LocalReportDispatch;
use TmlpStats\Reports\Arrangements;

class StatsReportController extends Controller
{
    use LocalReportDispatch;

    const CACHE_TTL = 7 * 24 * 60;

    protected $context;

    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth.token');
        $this->middleware('auth');
        $this->context = App::make(Api\Context::class);
    }

    /**
     * Legacy ID-based URL: redirect to specified resource.
     *
     * @param  Request $request
     * @param  integer $id
     * @return Response
     */
    public function show(Request $request, $id)
    {
        $statsReport = Models\StatsReport::findOrFail($id);

        return redirect($statsReport->getUriLocalReport());
    }

    /**
     * Do the actual work of showing, typically through ReportsController
     * @param  Request            $request     [description]
     * @param  Models\StatsReport $statsReport [description]
     * @return [type]                          [description]
     */
    public function showReport(Request $request, Models\StatsReport $statsReport)
    {
        $this->authorize('read', $statsReport);

        $this->context->setCenter($statsReport->center);
        $this->context->setReportingDate($statsReport->reportingDate);
        $centerReportingDate = Encapsulations\CenterReportingDate::ensure();
        $this->context->setDateSelectAction('ReportsController@getCenterReport', [
            'abbr' => $statsReport->center->abbrLower(),
        ]);

        $center = $statsReport->center;
        $globalReport = Models\GlobalReport::reportingDate($statsReport->reportingDate)->first();
        $globalRegion = $center->region->getParentGlobalRegion();

        $nextCenter = Models\Center::active()
            ->byRegion($globalRegion)
            ->where('name', '>', $center->name)
            ->orderBy('name')
            ->first();

        $lastCenter = Models\Center::active()
            ->byRegion($globalRegion)
            ->where('name', '<', $center->name)
            ->orderBy('name')
            ->first();

        $lastReport = null;
        if ($lastCenter && Gate::allows('showReportNavLinks', Models\StatsReport::class)) {
            $lastReport = $globalReport->statsReports()
                                       ->byCenter($lastCenter)
                                       ->first();
        }

        $nextReport = null;
        if ($nextCenter && Gate::allows('showReportNavLinks', Models\StatsReport::class)) {
            $nextReport = $globalReport->statsReports()
                                       ->byCenter($nextCenter)
                                       ->first();
        }

        $reportToken = null;
        if (Gate::allows('readLink', Models\ReportToken::class)) {
            $reportToken = Models\ReportToken::get($globalReport, $center);
        }

        $showNavCenterSelect = true;
        $defaultVmode = config('tmlp.local_report_view_mode');
        $vmode = $request->has('viewmode') ? $request->input('viewmode') : $defaultVmode;

        switch (strtolower($vmode)) {
            case 'react':
            default:
                $template = 'show_react';
                break;
        }

        return view("statsreports.{$template}", compact(
            'statsReport',
            'lastReport',
            'globalRegion',
            'nextReport',
            'centerReportingDate',
            'globalReport',
            'reportToken',
            'showNavCenterSelect'
        ));
    }

    public function showReportChooser(Request $request, Models\Center $center, Carbon $reportingDate, $reason = null)
    {
        $crd = Encapsulations\CenterReportingDate::ensure($center, $reportingDate);
        $cq = $crd->getCenterQuarter();

        $this->context->setCenter($center);
        $this->context->setReportingDate($reportingDate);

        // candidates are reporting dates in reverse, but only those which are before this reporting date.
        $dates = collect($cq->listReportingDates())->reverse()->filter(function ($d) use ($reportingDate) {
            return $d->lt($reportingDate);
        });

        $maybeReport = null;
        foreach ($dates as $d) {
            $maybeReport = Models\StatsReport::byCenter($center)
                ->reportingDate($d)
                ->official()
                ->first();
            if ($maybeReport !== null) {
                break;
            }
        }

        $maybeReportDate = $maybeReportUrl = '';
        if ($maybeReport) {
            $maybeReportDate = $maybeReport->reportingDate;
            $maybeReportUrl = $this->getUrl($maybeReport, $center);
        }

        return view('reports.report_chooser', compact(
            'reportingDate',
            'maybeReportDate',
            'maybeReportUrl',
            'reason'
        ));
    }

    public function authorizeReport($statsReport, $report)
    {
        switch ($report) {
            case 'contactinfo':
                $this->authorize('readContactInfo', $statsReport);
            default:
                parent::authorizeReport($statsReport, $report);
        }
    }

    public function getCoursesSummary(Models\StatsReport $statsReport)
    {
        $courses = App::make(Api\LocalReport::class)->getCourseList($statsReport);

        $completedCourses = [];
        $upcomingCourses = [];
        if ($courses) {
            // Completed Courses
            $a = new Arrangements\CoursesWithEffectiveness([
                'courses' => $courses,
                'reportingDate' => $statsReport->reportingDate,
            ]);
            $courses = $a->compose();

            $lastWeek = $statsReport->reportingDate->copy()->subWeek();

            if (isset($courses['reportData']['completed'])) {
                foreach ($courses['reportData']['completed'] as $course) {
                    if ($course['startDate']->gte($lastWeek)) {
                        $completedCourses[] = $course;
                    }
                }
            }

            $courseList = [];
            if (isset($courses['reportData']['CAP'])) {
                $courseList = array_merge($courseList, $courses['reportData']['CAP']);
            }

            if (isset($courses['reportData']['CPC'])) {
                $courseList = array_merge($courseList, $courses['reportData']['CPC']);
            }

            foreach ($courseList as $course) {
                if ($course['startDate']->gt($statsReport->reportingDate)
                    && $course['startDate']->lt($statsReport->reportingDate->copy()->addWeeks(3))
                ) {
                    $upcomingCourses[] = $course;
                }
            }
        }

        return compact(
            'completedCourses',
            'upcomingCourses'
        );
    }

    public function getTeamMembersSummary(Models\StatsReport $statsReport)
    {
        $teamMembers = App::make(Api\LocalReport::class)->getClassList($statsReport);

        $tdo = [];
        $gitw = [];
        $totals = [];
        $teamWithdraws = [];
        if ($teamMembers) {
            // Team Member stats
            $a = new Arrangements\TeamMembersCounts(['teamMembersData' => $teamMembers]);
            $teamMembersCounts = $a->compose();

            $tdo = $teamMembersCounts['reportData']['tdo'];
            $gitw = $teamMembersCounts['reportData']['gitw'];
            $totals = $teamMembersCounts['reportData']['totals'];
            $teamWithdraws = $teamMembersCounts['reportData']['withdraws'];
        }

        return compact(
            'teamMembers',
            'tdo',
            'gitw',
            'teamWithdraws',
            'totals'
        );
    }

    public function getRegistrationsSummary(Models\StatsReport $statsReport)
    {
        $registrations = App::make(Api\LocalReport::class)->getApplicationsList($statsReport, [
            'returnUnprocessed' => true,
        ]);

        $applications = [];
        $applicationWithdraws = [];
        if ($registrations) {
            // Application Status
            $a = new Arrangements\TmlpRegistrationsByStatus([
                'registrationsData' => $registrations,
                'quarter' => $statsReport->quarter,
            ]);
            $applications = $a->compose();
            $applications = $applications['reportData'];

            // Application Withdraws
            $a = new Arrangements\TmlpRegistrationsByIncomingQuarter([
                'registrationsData' => $registrations,
                'quarter' => $statsReport->quarter,
            ]);
            $applicationWithdraws = $a->compose();
            $applicationWithdraws = $applicationWithdraws['reportData']['withdrawn'];
        }

        return compact(
            'registrations',
            'applications',
            'applicationWithdraws'
        );
    }

    public function getTravelSummary(Models\StatsReport $statsReport, $teamMembersSummary, $registrationsSummary)
    {
        $teamTravelDetails = [];
        $incomingTravelDetails = [];
        if ($teamMembersSummary['teamMembers'] && $registrationsSummary['registrations']) {
            // Travel/Room
            $a = new Arrangements\TravelRoomingByTeamYear([
                'registrationsData' => $registrationsSummary['registrations'],
                'teamMembersData' => $teamMembersSummary['teamMembers'],
                'region' => $statsReport->center->region,
            ]);
            $travelDetails = $a->compose();
            $travelDetails = $travelDetails['reportData'];
            $teamTravelDetails = $travelDetails['teamMembers'];
            $incomingTravelDetails = $travelDetails['incoming'];
        }

        return compact(
            'teamTravelDetails',
            'incomingTravelDetails'
        );
    }

    public function getSummaryPageData(Models\StatsReport $statsReport, $live = false)
    {
        $date = $statsReport->reportingDate->toDateString();

        $liveData = null;
        if ($live) {
            $liveData = App::make(Api\LiveScoreboard::class)->getCurrentScores($statsReport->center);
        }

        $reportData = App::make(Api\LocalReport::class)->getWeekScoreboard($statsReport)->toArray();

        $coursesSummary = $this->getCoursesSummary($statsReport);
        $teamMembersSummary = $this->getTeamMembersSummary($statsReport);
        $registrationsSummary = $this->getRegistrationsSummary($statsReport);
        $travelSummary = $this->getTravelSummary($statsReport, $teamMembersSummary, $registrationsSummary);

        $data = compact(
            'liveData',
            'statsReport',
            'reportData',
            'date'
        );

        return array_merge($data, $coursesSummary, $teamMembersSummary, $registrationsSummary, $travelSummary);
    }

    protected function getSummary(Models\StatsReport $statsReport)
    {
        $data = $this->getSummaryPageData($statsReport);

        return view('statsreports.details.summary', $data);
    }

    public function getMobileSummary(Models\StatsReport $statsReport)
    {
        $data = $this->getSummaryPageData($statsReport, true);
        $data['skip_navbar'] = true;
        $data['liveScoreboard'] = true;

        return view('statsreports.details.mobile_summary', $data);
    }

    protected function getCenterStats(Models\StatsReport $statsReport)
    {
        $a = new Arrangements\GamesByMilestone([
            'weeks' => App::make(Api\LocalReport::class)->getQuarterScoreboard($statsReport)->toArray(),
            'quarter' => $statsReport->quarter,
            'center' => $statsReport->center,
        ]);
        $data = $a->compose();

        return view('reports.centergames.milestones', $data);
    }

    protected function getClassList(Models\StatsReport $statsReport)
    {
        $a = new Arrangements\TeamMembersByQuarter([
            'teamMembersData' => App::make(Api\LocalReport::class)->getClassList($statsReport),
            'includeXferAsWithdrawn' => true,
        ]);

        return view('statsreports.details.classlist', $a->compose());
    }

    protected function getTmlpRegistrationsByStatus(Models\StatsReport $statsReport)
    {
        $registrations = App::make(Api\LocalReport::class)->getApplicationsList($statsReport, [
            'returnUnprocessed' => true,
        ]);

        $a = new Arrangements\TmlpRegistrationsByStatus(['registrationsData' => $registrations]);
        $data = $a->compose();

        return view('statsreports.details.tmlpregistrationsbystatus', [
            'reportData' => $data['reportData'],
            'reportingDate' => $statsReport->reportingDate,
        ]);
    }

    protected function getTmlpRegistrations(Models\StatsReport $statsReport)
    {
        return view('statsreports.details.tmlpregistrations', [
            'reportData' => App::make(Api\LocalReport::class)->getApplicationsList($statsReport),
        ]);
    }

    protected function getCourses(Models\StatsReport $statsReport)
    {
        $courses = App::make(Api\LocalReport::class)->getCourseList($statsReport);
        if (!$courses) {
            return null;
        }

        $a = new Arrangements\CoursesWithEffectiveness([
            'courses' => $courses,
            'reportingDate' => $statsReport->reportingDate,
        ]);
        $data = $a->compose();

        return view('statsreports.details.courses', $data);
    }

    protected function getContactInfo(Models\StatsReport $statsReport)
    {
        $contacts = [];
        $accountabilities = [
            'programManager',
            'classroomLeader',
            't1tl',
            't2tl',
            'statistician',
            'statisticianApprentice',
        ];

        $reportNow = $statsReport->reportingDate->copy()->setTime(15, 0, 0);

        foreach ($accountabilities as $accountability) {
            $accountabilityObj = Models\Accountability::name($accountability)->first();
            $contacts[$accountabilityObj->display] = $statsReport->center->getAccountable($accountability, $reportNow);
        }
        if (!$contacts) {
            return null;
        }

        return view('statsreports.details.contactinfo', compact('contacts'));
    }

    protected function getMemberSummaryData(Models\StatsReport $statsReport, $type)
    {
        $center = $statsReport->center;
        $reportingDate = $statsReport->reportingDate;

        $crd = Encapsulations\CenterReportingDate::ensure($center, $reportingDate);
        $cq = $crd->getCenterQuarter();

        $weeksData = collect($cq->listReportingDates())->filter(function ($d) use ($reportingDate) {
            return $d->lte($reportingDate);
        })->keyBy(function($d) {
            return $d->toDateString();
        })->map(function ($d) use ($center) {
            return App::make(Api\TeamMember::class)->allForCenter($center, $d);
        });

        $totals = $weeksData->map(function ($members) use ($type) {
            return collect($members)->sum(function ($member) use ($type) {
                return (int) $member->$type;
            });
        });

        if (!$weeksData) {
            return null;
        }

        $a = new Arrangements\TeamMemberWeeklyValue([
            'teamMembersData' => $weeksData,
            'field' => $type,
        ]);

        return array_merge($a->compose(), ['totals' => $totals]);
    }

    protected function getGitwSummary(Models\StatsReport $statsReport)
    {
        return $this->getMemberSummaryData($statsReport, 'gitw');
    }

    protected function getTdoSummary(Models\StatsReport $statsReport)
    {
        return $this->getMemberSummaryData($statsReport, 'tdo');
    }

    protected function getRppCapSummary(Models\StatsReport $statsReport)
    {
        return $this->getMemberSummaryData($statsReport, 'rppCap');
    }

    protected function getRppCpcSummary(Models\StatsReport $statsReport)
    {
        return $this->getMemberSummaryData($statsReport, 'rppCpc');
    }

    protected function getRppLfSummary(Models\StatsReport $statsReport)
    {
        return $this->getMemberSummaryData($statsReport, 'rppLf');
    }

    public function compileApiReportMessages(Models\StatsReport $statsReport)
    {
        if ($statsReport->version !== 'api') {
            return [];
        }

        $storedMessages = $statsReport->validationMessages ?: [];

        $reportMessages = [];
        foreach ($storedMessages as $group => $groupMessages) {
            foreach ($groupMessages as $msg) {
                $display = array_get($msg, 'reference.flattened', '');
                $reportMessages[$group][$display][] = $msg;
            }
        }

        return $reportMessages;
    }

    protected function getOverview(Models\StatsReport $statsReport)
    {
        return view('statsreports.details.overview_combined', [
            'statsReport' => $statsReport,
            'results' => $this->getResults($statsReport),
        ]);
    }

    protected function getResults(Models\StatsReport $statsReport)
    {
        if ($statsReport->version !== 'api') {
            return '<p>Results not available.</p>';
        }

        $reportMessages = $this->compileApiReportMessages($statsReport);

        $centerName = $statsReport->center->name;
        $reportingDate = $statsReport->reportingDate;

        return view('statsreports.details.apiresults', compact(
            'centerName',
            'reportingDate',
            'reportMessages'
        ));
    }

    protected function getTeamWeekendSummary(Models\StatsReport $statsReport)
    {
        $teamMembers = App::make(Api\LocalReport::class)->getClassList($statsReport);
        if (!$teamMembers) {
            return null;
        }

        $registrationsData = App::make(Api\LocalReport::class)->getApplicationsList($statsReport, [
            'returnUnprocessed' => true,
        ]);

        $registrations = [];
        if ($registrationsData) {
            $nextQuarter = $statsReport->quarter->getNextQuarter();
            foreach ($registrationsData as $registration) {
                if ($registration->incomingQuarterId == $nextQuarter->id) {
                    $registrations[] = $registration;
                }
            }
        }

        $a = new Arrangements\TeamMembersByQuarter([
            'teamMembersData' => $teamMembers,
            'includeXferAsWithdrawn' => true,
        ]);
        $teamMembers = $a->compose();
        $teamMembers = $teamMembers['reportData'];

        $a = new Arrangements\TmlpRegistrationsByStatus([
            'registrationsData' => $registrations,
            'quarter' => $statsReport->quarter,
        ]);
        $applications = $a->compose();
        $applications = $applications['reportData'];

        $t1Continuing = 0;
        if (isset($teamMembers['team1'])) {
            $t1Continuing = array_sum(array_map('count', $teamMembers['team1']));
            if (isset($teamMembers['team1']['Q4'])) {
                $t1Continuing -= count($teamMembers['team1']['Q4']);
            }
        }
        if (isset($applications['approved'])) {
            foreach ($applications['approved'] as $app) {
                if ($app->teamYear == 1) {
                    $t1Continuing++;
                }
            }
        }

        $t2Continuing = 0;
        if (isset($teamMembers['team2'])) {
            $t2Continuing = array_sum(array_map('count', $teamMembers['team2']));
            if (isset($teamMembers['team2']['Q4'])) {
                $t2Continuing -= count($teamMembers['team2']['Q4']);
            }
        }
        if (isset($applications['approved'])) {
            foreach ($applications['approved'] as $app) {
                if ($app->teamYear == 2) {
                    $t2Continuing++;
                }
            }
        }

        $completingCount = 0;
        if (isset($teamMembers['team1']['Q4'])) {
            $completingCount += count($teamMembers['team1']['Q4']);
        }
        if (isset($teamMembers['team2']['Q4'])) {
            $completingCount += count($teamMembers['team2']['Q4']);
        }

        $incomingCount = isset($applications['approved']) ? count($applications['approved']) : 0;

        $boxes = [
            [
                'stat' => $t1Continuing,
                'description' => 'Team 1',
            ],
            [
                'stat' => $t2Continuing,
                'description' => 'Team 2',
            ],
            [
                'stat' => $completingCount,
                'description' => 'Completing',
            ],
            [
                'stat' => $incomingCount,
                'description' => 'Incoming',
            ],
        ];

        return view('statsreports.details.teamsummary', compact('teamMembers', 'applications', 'boxes'));
    }

    protected function getTeamTravelSummary(Models\StatsReport $statsReport)
    {
        $teamMembersData = App::make(Api\LocalReport::class)->getClassList($statsReport);
        if (!$teamMembersData) {
            return null;
        }

        $registrationsData = App::make(Api\LocalReport::class)->getApplicationsList($statsReport, [
            'returnUnprocessed' => true,
        ]);

        $registrations = [];
        if ($registrationsData) {
            $nextQuarter = $statsReport->quarter->getNextQuarter();
            foreach ($registrationsData as $registration) {
                if ($registration->incomingQuarterId == $nextQuarter->id) {
                    $registrations[] = $registration;
                }
            }
        }

        $a = new Arrangements\TeamMembersByQuarter(['teamMembersData' => $teamMembersData]);
        $teamMembers = $a->compose();
        $teamMembers = $teamMembers['reportData'];

        $a = new Arrangements\TmlpRegistrationsByStatus([
            'registrationsData' => $registrations,
            'quarter' => $statsReport->quarter,
        ]);
        $applications = $a->compose();
        $applications = $applications['reportData'];

        $a = new Arrangements\TravelRoomingByTeamYear([
            'registrationsData' => $registrations,
            'teamMembersData' => $teamMembersData,
            'region' => $statsReport->center->region,
        ]);
        $travelDetails = $a->compose();
        $travelDetails = $travelDetails['reportData'];
        $teamTravelDetails = $travelDetails['teamMembers'];
        $incomingTravelDetails = $travelDetails['incoming'];

        $boxes = [
            [
                'stat' => $teamTravelDetails['team1']['travel'] + $teamTravelDetails['team2']['travel'],
                'subStat' => $teamTravelDetails['team1']['total'] + $teamTravelDetails['team2']['total'],
                'description' => 'Team Travel',
            ],
            [
                'stat' => $teamTravelDetails['team1']['room'] + $teamTravelDetails['team2']['room'],
                'subStat' => $teamTravelDetails['team1']['total'] + $teamTravelDetails['team2']['total'],
                'description' => 'Team Rooming',
            ],
            [
                'stat' => $incomingTravelDetails['team1']['travel'] + $incomingTravelDetails['team2']['travel'],
                'subStat' => $incomingTravelDetails['team1']['total'] + $incomingTravelDetails['team2']['total'],
                'description' => 'Incoming Travel',
            ],
            [
                'stat' => $incomingTravelDetails['team1']['room'] + $incomingTravelDetails['team2']['room'],
                'subStat' => $incomingTravelDetails['team1']['total'] + $incomingTravelDetails['team2']['total'],
                'description' => 'Incoming Rooming',
            ],
        ];

        return view('statsreports.details.travelsummary', compact('teamMembers', 'applications', 'boxes'));
    }

    protected function getPeopleTransferSummary(Models\StatsReport $statsReport)
    {
        $thisWeek = clone $statsReport->reportingDate;

        $globalReport = Models\GlobalReport::reportingDate($thisWeek->subWeek())->first();
        if (!$globalReport) {
            return null;
        }

        $lastStatsReport = $globalReport->getStatsReportByCenter($statsReport->center);
        if (!$lastStatsReport) {
            return null;
        }

        // Get this week and last weeks data organized by team year and quarter
        $teamMemberDataThisWeek = App::make(Api\LocalReport::class)->getClassList($statsReport);
        $teamMemberDataLastWeek = App::make(Api\LocalReport::class)->getClassList($lastStatsReport);

        $a = new Arrangements\TeamMembersByQuarter(['teamMembersData' => $teamMemberDataThisWeek]);
        $teamThisWeekByQuarter = $a->compose();
        $teamThisWeekByQuarter = $teamThisWeekByQuarter['reportData'];

        $a = new Arrangements\TeamMembersByQuarter([
            'teamMembersData' => $teamMemberDataLastWeek,
            'includeXferAsWithdrawn' => true, // we don't want to deal with people that transfered last quarter
        ]);
        $teamLastWeekByQuarter = $a->compose();
        $teamLastWeekByQuarter = $teamLastWeekByQuarter['reportData'];

        $incomingThisWeekByQuarter = App::make(Api\LocalReport::class)->getApplicationsList($statsReport);
        $incomingLastWeekByQuarter = App::make(Api\LocalReport::class)->getApplicationsList($lastStatsReport);

        // Cleanup incoming withdraws
        // Remove people that were withdrawn and moved to the future weekend section
        foreach ($incomingLastWeekByQuarter['withdrawn'] as $quarterNumber => $quarterData) {
            foreach ($quarterData as $idx => $withdrawData) {
                $team = "team{$withdrawData->registration->teamYear}";

                list($field, $idx, $object) = $this->hasPerson([
                    'next',
                    'future',
                ], $withdrawData, $incomingLastWeekByQuarter[$team]);
                if ($field !== null) {
                    unset($incomingLastWeekByQuarter['withdrawn'][$field][$idx]);
                }
            }
        }

        // Keep track of persons of interest
        $incomingSummary = [
            'missing' => [], // Was on last week's report but not this week
            'changed' => [], // On both reports
            'new' => [], // New on this week (could be a WD that's now reregistered)
        ];

        // Find all missing or modified applications
        foreach (['team1', 'team2'] as $team) {
            foreach ($incomingLastWeekByQuarter[$team] as $quarterNumber => $quarterData) {
                foreach ($quarterData as $lastWeekData) {
                    list($field, $idx, $thisWeekData) = $this->hasPerson([
                        'next',
                        'future',
                    ], $lastWeekData, $incomingThisWeekByQuarter[$team]);
                    if ($field !== null) {
                        // We only need to display existing rows that weren't copied properly
                        if (!$this->incomingCopiedCorrectly($thisWeekData, $lastWeekData)) {
                            $incomingSummary['changed'][] = [$thisWeekData, $lastWeekData];
                        }
                        unset($incomingThisWeekByQuarter[$team][$field][$idx]);
                    } else {
                        $incomingSummary['missing'][] = [null, $lastWeekData];
                    }
                }
            }
        }

        // Everything that's left is new.
        // Attach info from any application that was withdrawn last quarter
        foreach (['team1', 'team2'] as $team) {
            foreach ($incomingThisWeekByQuarter[$team] as $quarterNumber => $quarterData) {
                foreach ($quarterData as $thisWeekData) {
                    list($field, $idx, $withdrawData) = $this->hasPerson([
                        'next',
                        'future',
                    ], $thisWeekData, $incomingLastWeekByQuarter['withdrawn']);
                    $incomingSummary['new'][] = [$thisWeekData, $withdrawData];
                }
            }
        }

        $teamMemberSummary = [
            'new' => [],
            'changed' => [],
            'missing' => [],
        ];

        // Find any missing team members (except quarter 4 who are outgoing)
        foreach (['team1', 'team2'] as $team) {
            foreach ($teamLastWeekByQuarter[$team] as $quarterNumber => $quarterData) {
                // Skip Q1 because quarter number calculations wrap, so last quarter's Q4 now looks like a Q1
                if ($quarterNumber == 'Q1') {
                    continue;
                }

                foreach ($quarterData as $lastWeekData) {
                    list($field, $idx, $data) = $this->hasPerson([
                        'Q2',
                        'Q3',
                        'Q4',
                    ], $lastWeekData, $teamThisWeekByQuarter[$team]);
                    if ($field !== null) {
                        // Make sure they were put in the correct section by making sure the incoming quarter didn't change
                        $thisWeekData = $teamThisWeekByQuarter[$team][$field][$idx];
                        if ($thisWeekData->incomingQuarter->id != $lastWeekData->incomingQuarter->id) {
                            $teamMemberSummary['changed'][] = [$thisWeekData, $lastWeekData];
                        }

                        // We found it! remove it from the search list
                        unset($teamThisWeekByQuarter[$team][$field][$idx]);
                    } else {
                        $teamMemberSummary['missing'][] = [null, $lastWeekData];
                    }
                }
            }
        }

        // Process new team members
        // By now, we've removed all of the members that matched, so it should all be new team members.
        // Check the incoming and add match any withdrawn members that have reappeared
        foreach (['team1', 'team2'] as $team) {
            foreach ($teamThisWeekByQuarter[$team] as $quarterNumber => $quarterData) {
                foreach ($quarterData as $thisWeekData) {
                    $matched = false;
                    $lastWeekData = null;
                    if ($quarterNumber == 'Q1') {
                        // Match up incoming with new Q1 team
                        foreach ($incomingSummary['missing'] as $idx => $incomingData) {
                            if ($this->objectsAreEqual($incomingData[1], $thisWeekData)) {
                                unset($incomingSummary['missing'][$idx]);
                                $matched = true;
                                break;
                            }
                        }
                    } else if (isset($teamLastWeekByQuarter['withdrawn'][$quarterNumber])) {

                        // Check if the new team member was withdrawn previously
                        list($field, $idx, $withdrawData) = $this->hasPerson([
                            'Q1',
                            'Q2',
                            'Q3',
                            'Q4',
                        ], $thisWeekData, $teamLastWeekByQuarter['withdrawn']);
                        if ($withdrawData !== null) {
                            $lastWeekData = $withdrawData;
                        }
                    }
                    if (!$matched) {
                        $teamMemberSummary['new'][] = [$thisWeekData, $lastWeekData];
                    }
                }
            }
        }

        // Go through everyone that withdrew this week, and remove them from the missing lists
        foreach ($teamThisWeekByQuarter['withdrawn'] as $quarterNumber => $quarterData) {
            foreach ($quarterData as $thisWeekData) {
                if ($quarterNumber == 'Q1') {
                    // check for missing applications
                    foreach ($incomingSummary['missing'] as $idx => $missingData) {
                        if ($this->objectsAreEqual($missingData[1], $thisWeekData)) {
                            unset($incomingSummary['missing'][$idx]);
                            break;
                        }
                    }
                } else {
                    // check for missing applications
                    foreach ($teamMemberSummary['missing'] as $idx => $missingData) {
                        if ($this->objectsAreEqual($missingData[1], $thisWeekData)) {
                            unset($teamMemberSummary['missing'][$idx]);
                            break;
                        }
                    }
                }
            }
        }
        foreach ($incomingThisWeekByQuarter['withdrawn'] as $quarterNumber => $quarterData) {
            foreach ($quarterData as $thisWeekData) {
                // check for missing applications
                foreach ($incomingSummary['missing'] as $idx => $missingData) {
                    if ($this->objectsAreEqual($missingData[1], $thisWeekData)) {
                        unset($incomingSummary['missing'][$idx]);
                        break;
                    }
                }
            }
        }

        $thisQuarter = Models\Quarter::getCurrentQuarter($this->context->getRegion(true));

        return view('statsreports.details.peopletransfersummary', compact('teamMemberSummary', 'incomingSummary', 'thisQuarter'));
    }

    public function getCoursesTransferSummary(Models\StatsReport $statsReport)
    {
        $thisWeek = clone $statsReport->reportingDate;

        $globalReport = Models\GlobalReport::reportingDate($thisWeek->subWeek())->first();
        if (!$globalReport) {
            return null;
        }

        $lastStatsReport = $globalReport->getStatsReportByCenter($statsReport->center);
        if (!$lastStatsReport) {
            return null;
        }

        $thisWeekCourses = App::make(Api\LocalReport::class)->getCourseList($statsReport);
        $lastWeekCourses = App::make(Api\LocalReport::class)->getCourseList($lastStatsReport);

        $a = new Arrangements\CoursesWithEffectiveness([
            'courses' => $thisWeekCourses,
            'reportingDate' => $statsReport->reportingDate,
        ]);
        $thisWeekCoursesList = $a->compose();
        $thisWeekCoursesList = $thisWeekCoursesList['reportData'];

        $a = new Arrangements\CoursesWithEffectiveness([
            'courses' => $lastWeekCourses,
            'reportingDate' => $lastStatsReport->reportingDate,
        ]);
        $lastWeekCoursesList = $a->compose();
        $lastWeekCoursesList = $lastWeekCoursesList['reportData'];

        /*
         * No completed courses copied over (Done by validator)
         * Starting numbers match the completing numbers from last week
         */
        $flaggedCount = 0;
        $flagged = [
            'missing' => [],
            'changed' => [],
            'new' => [],
        ];
        foreach ($thisWeekCoursesList as $type => $thisWeekCourses) {
            foreach ($thisWeekCourses as $thisWeekCourseData) {
                $found = false;
                if (isset($lastWeekCoursesList[$type])) {
                    foreach ($lastWeekCoursesList[$type] as $idx => $lastWeekCourseData) {
                        if ($this->coursesEqual($thisWeekCourseData, $lastWeekCourseData)) {
                            if (!$this->courseCopiedCorrectly($thisWeekCourseData, $lastWeekCourseData)) {
                                $flagged['changed'][$type][] = [$thisWeekCourseData, $lastWeekCourseData];
                                $flaggedCount++;
                            }
                            $found = true;
                            unset($lastWeekCoursesList[$type][$idx]);
                            continue;
                        }
                    }
                }
                if (!$found) {
                    $flagged['new'][$type][] = [$thisWeekCourseData, null];
                    $flaggedCount++;
                }
            }
        }

        unset($lastWeekCoursesList['completed']);

        foreach ($lastWeekCoursesList as $type => $lastWeekCourses) {
            foreach ($lastWeekCourses as $lastWeekCourseData) {
                $flagged['missing'][$type][] = [null, $lastWeekCourseData];
                $flaggedCount++;
            }
        }

        return view('statsreports.details.coursestransfersummary', compact('flagged', 'flaggedCount'));
    }

    /**
     * Get initial data for Next Quarter accountabilities
     * @return array a JSON-friendly array
     */
    protected function getNextQtrAccountabilities(Models\StatsReport $statsReport)
    {
        $nqaApi = App::make(Api\Submission\NextQtrAccountability::class);
        $nqAccountabilities = $nqaApi->allForCenter($this->context->getCenter(), $this->context->getReportingDate());

        // Since we don't have an easy API for getting lookup tables for all users now
        // (right now this is rolled into SubmissionCore) we're going to do the extra work
        // of embedding the Models\Accountability object alongside the data for now.
        $accountabilities = collect($nqAccountabilities)
            ->map(function ($nqa) {
                return array_merge($nqa->toArray(), ['accountability' => $nqa->getAccountability()]);
            });

        return [
            'nqas' => $accountabilities,
        ];
    }

    protected function getReconciliation(Models\StatsReport $statsReport)
    {
        $globalRegion = $statsReport->center->getGlobalRegion();
        if (!$this->context->can('reconcile', $globalRegion)) {
            throw new \Exception('Must be a regional statistician');
        }
        $localApi = App::make(Api\LocalReport::class);
        $people = $localApi->getClassList($statsReport);

        $grouped = collect($people)->groupBy(function ($tmd) {return $tmd->teamMember->incomingQuarterId;});

        // Now get applications only for next quarter
        $nextQuarter = $statsReport->quarter->getNextQuarter();
        $apps = $localApi->getApplicationsList($statsReport, ['returnUnprocessed' => true]);

        $incoming = $apps->filter(function ($app) use ($nextQuarter) {
            return $app->incomingQuarterId == $nextQuarter->id;
        });

        $grouped = $grouped->put($nextQuarter->id, $incoming->values()->all());

        return [
            'byQuarter' => $grouped,
        ];
    }

    protected function coursesEqual($new, $old)
    {
        if ($new['courseId'] == $old['courseId']) {
            return true;
        }

        return $new['startDate']->eq($old['startDate']);
    }

    public function courseCopiedCorrectly($new, $old)
    {
        // Make sure quarter starting numbers match last quarters ending numbers
        if ($new['quarterStartTer'] != $old['currentTer']) {
            return false;
        }
        if ($new['quarterStartStandardStarts'] != $old['currentStandardStarts']) {
            return false;
        }
        if ($new['quarterStartXfer'] != $old['currentXfer']) {
            return false;
        }

        // There wasn't a course during the TMLP weekend, so this must be empty
        if ($new['completedStandardStarts'] !== null) {
            return false;
        }
        if ($new['potentials'] !== null) {
            return false;
        }
        if ($new['registrations'] !== null) {
            return false;
        }

        return true;
    }

    public function incomingCopiedCorrectly($new, $old)
    {
        $checkFields = [
            'regDate',
            'appOutDate',
            'appInDate',
            'apprDate',
            'teamYear',
            'incomingQuarterId',
        ];

        $ok = true;
        foreach ($checkFields as $field) {

            if ($old->$field && $new->$field) {
                if (($old->$field instanceof Carbon) && $old->$field->ne($new->$field)) {
                    $ok = false;
                    break;
                } else if ($old->$field != $new->$field) {
                    $ok = false;
                    break;
                }
            } else if ($old->$field && !$new->$field) {
                $ok = false;
                break;
            }
        }

        return $ok;
    }

    public function getTargetValue($object)
    {
        if ($object instanceof Models\TmlpRegistrationData) {
            return $object->registration->personId;
        } else if ($object instanceof Models\TeamMemberData) {
            return $object->teamMember->personId;
        } else if ($object instanceof Models\CourseData) {
            return $object->courseId;
        }

        return null;
    }

    public function objectsAreEqual($a, $b)
    {
        return $this->getTargetValue($a) == $this->getTargetValue($b);
    }

    public function hasPerson($fields, $needle, $haystacks, $haystackObjectOffset = null)
    {
        foreach ($fields as $field) {
            if (isset($haystacks[$field])) {
                foreach ($haystacks[$field] as $idx => $object) {
                    $target = ($haystackObjectOffset === null) ? $object : $object[$haystackObjectOffset];

                    if ($this->objectsAreEqual($target, $needle)) {
                        return [$field, $idx, $target];
                    }
                }
            }
        }

        return [null, null, null];
    }

    public static function getUrl(Models\StatsReport $statsReport)
    {
        return action('ReportsController@getCenterReport', [
            'abbr' => $statsReport->center->abbrLower(),
            'date' => $statsReport->reportingDate->toDateString(),
        ]);
    }
}
