<?php namespace TmlpStats\Http\Controllers;

use TmlpStats\Http\Requests;
use TmlpStats\GlobalReport;
use TmlpStats\Quarter;
use TmlpStats\Region;
use TmlpStats\StatsReport;
use TmlpStats\Center;
use TmlpStats\Reports\Arrangements\CoursesByCenter;
use TmlpStats\Reports\Arrangements\CoursesWithEffectiveness;
use TmlpStats\Reports\Arrangements\GamesByMilestone;
use TmlpStats\Reports\Arrangements\GamesByWeek;
use TmlpStats\Reports\Arrangements\TeamMemberIncomingOverview;
use TmlpStats\Reports\Arrangements\TeamMembersByCenter;
use TmlpStats\Reports\Arrangements\TmlpRegistrationsByCenter;
use TmlpStats\Reports\Arrangements\TmlpRegistrationsByIncomingQuarter;
use TmlpStats\Reports\Arrangements\TmlpRegistrationsByOverdue;
use TmlpStats\Reports\Arrangements\TmlpRegistrationsByStatus;
use TmlpStats\Reports\Arrangements\TravelRoomingByTeamYear;
use TmlpStats\Reports\Arrangements;

use Carbon\Carbon;
use Illuminate\Http\Request;

use App;
use Auth;
use Input;
use Response;

class GlobalReportController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        if (!$this->hasAccess('R')) {
            $error = 'You do not have access to view these reports.';
            return Response::view('errors.403', compact('error'), 403);
        }

        $globalReports = GlobalReport::orderBy('reporting_date', 'desc')->get();
        return view('globalreports.index', compact('globalReports'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        if (!$this->hasAccess('C')) {
            $error = 'You do not have access to create new reports.';
            return Response::view('errors.403', compact('error'), 403);
        }

        $reportingDates = array();
        $week = new Carbon('this friday');
        while ($week->gt(Carbon::now()->subWeeks(8))) {
            $reportingDates[$week->toDateString()] = $week->format('F j, Y');
            $week->subWeek();
        }

        return view('globalreports.create', compact('reportingDates'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store()
    {
        if (!$this->hasAccess('C')) {
            $error = 'You do not have access to save this report.';
            return Response::view('errors.403', compact('error'), 403);
        }

        $redirect = '/globalreports';

        if (Input::has('cancel')) {
            return redirect($redirect);
        }

        if (Input::has('reporting_date')) {
            GlobalReport::create(array('reporting_date' => Input::get('reporting_date')));
        }
        return redirect($redirect);
    }

    /**
     * Display the specified resource.
     *
     * @param  int $id
     * @return Response
     */
    public function show(Request $request, $id)
    {
        if (!$this->hasAccess('R')) {
            $error = 'You do not have access to view this report.';
            return Response::view('errors.403', compact('error'), 403);
        }

        $globalReport = GlobalReport::find($id);
        if (!$globalReport) {
            $error = 'Report not found.';
            return Response::view('errors.404', compact('error'), 404);
        }

        $region = $this->getRegion($request, true);

        return view('globalreports.show', compact(
            'globalReport',
            'region'
        ));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int $id
     * @return Response
     */
    public function edit($id)
    {
        return redirect("/globalreports/{$id}");
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int $id
     * @return Response
     */
    public function update($id)
    {
        if (!$this->hasAccess('U')) {
            $error = 'You do not have access to update this report.';
            return Response::view('errors.403', compact('error'), 403);
        }

        if (!Input::has('cancel')) {

            $globalReport = GlobalReport::find($id);
            if ($globalReport) {

                if (Input::has('center')) {
                    $center = Center::abbreviation(Input::get('center'))->first();
                    $statsReport = StatsReport::reportingDate($globalReport->reportingDate)
                        ->byCenter($center)
                        ->validated(true)
                        ->orderBy('submitted_at', 'desc')
                        ->first();

                    if ($statsReport
                        && !$globalReport->statsReports()->find($statsReport->id)
                        && !$globalReport->statsReports()->byCenter($statsReport->center)->first()
                    ) {
                        $globalReport->statsReports()->attach([$statsReport->id]);
                    }
                }
                if (Input::has('locked')) {
                    $locked = Input::get('locked');
                    $globalReport->locked = ($locked == false || $locked === 'false') ? false : true;
                    $success = $globalReport->save();

                    if (Input::has('dataType') && Input::get('dataType') == 'JSON') {
                        return array('globalReportId' => $id, 'locked' => $globalReport->locked, 'success' => $success);
                    }
                }
                if (Input::has('remove')) {
                    if (Input::get('remove') == 'statsreport' && Input::has('id')) {
                        $id = (int)Input::get('id');
                        $globalReport->statsReports()->detach($id);

                        if (Input::has('dataType') && Input::get('dataType') == 'JSON') {
                            return array('globalReportId' => $id, 'statsReport' => $id, 'success' => true, 'message' => 'Removed stats report successfully.');
                        }
                    }
                }
            }
        }

        $redirect = "/globalreports/{$id}";
        if (Input::has('previous_url')) {
            $redirect = Input::has('previous_url');
        }
        return redirect($redirect);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int $id
     * @return Response
     */
    public function destroy($id)
    {
        //
    }

    // This is a really crappy authz. Need to address this properly
    public function hasAccess($permissions)
    {
        switch ($permissions) {
            case 'R':
                return (Auth::user()->hasRole('globalStatistician')
                    || Auth::user()->hasRole('administrator')
                    || Auth::user()->hasRole('localStatistician'));
            case 'U':
            case 'C':
            case 'D':
            default:
                return (Auth::user()->hasRole('globalStatistician')
                    || Auth::user()->hasRole('administrator'));
        }
    }

    public function getStatsReportsNotOnList(GlobalReport $globalReport)
    {
        $statsReports = StatsReport::reportingDate($globalReport->reportingDate)
            ->submitted()
            ->validated(true)
            ->get();

        $centers = array();
        foreach ($statsReports as $statsReport) {

            if ($statsReport->globalReports()->find($globalReport->id)
                || $globalReport->statsReports()->byCenter($statsReport->center)->count() > 0
            ) {
                continue;
            }

            $centers[$statsReport->center->abbreviation] = $statsReport->center->name;
        }
        asort($centers);
        return $centers;
    }

    public function runDispatcher(Request $request, $id, $report)
    {
        if (!$this->hasAccess('R')) {
            $error = 'You do not have access to view this report.';
            return $request->ajax()
                ? "<p>{$error}</p>"
                : Response::view('errors.403', compact('error'), 403);
        }

        $globalReport = GlobalReport::find($id);
        if (!$globalReport) {
            $error = 'Report not found.';
            return $request->ajax()
                ? "<p>{$error}</p>"
                : Response::view('errors.404', compact('error'), 404);
        }

        $region = $this->getRegion($request, true);

        $response = null;
        switch ($report) {
            case 'ratingsummary':
                $response = $this->getRatingSummary($globalReport, $region);
                break;
            case 'regionalstats':
                $response = $this->getRegionalStats($globalReport, $region);
                break;
            case 'statsreports':
                $response = $this->getCenterStatsReports($globalReport, $region);
                break;
            case 'applicationsbystatus':
                $response = $this->getTmlpRegistrationsByStatus($globalReport, $region);
                break;
            case 'applicationsoverdue':
                $response = $this->getTmlpRegistrationsOverdue($globalReport, $region);
                break;
            case 'applicationsbycenter':
                $response = $this->getTmlpRegistrationsByCenter($globalReport, $region);
                break;
            case 'applicationsoverview':
                $response = $this->getTmlpRegistrationsOverview($globalReport, $region);
                break;
            case 'traveloverview':
                $response = $this->getTravelReport($globalReport, $region);
                break;
            case 'completedcourses':
                $response = $this->getCompletedCoursesReport($globalReport, $region);
                break;
        }

        if (!$response) {
            $error = 'Report not available.';
            $response = $request->ajax()
                ? "<p>{$error}</p>"
                : Response::view('errors.404', $error, 404);
        }

        return $response;
    }


    public function getRatingSummary(GlobalReport $globalReport, Region $region)
    {
        $statsReports = $globalReport->statsReports()
            ->validated()
            ->byRegion($region)
            ->get();

        if ($statsReports->isEmpty()) {
            return null;
        }

        // TODO don't force passing the data in in the future
        $a = new Arrangements\RegionByRating($statsReports);
        $data = $a->compose();
        return view('globalreports.details.ratingsummary', $data);
    }

    public function getRegionalStats(GlobalReport $globalReport, Region $region)
    {
        $quarter = Quarter::byRegion($region)->date($globalReport->reportingDate)->first();
        $quarter->setRegion($region);

        $globalReportData = App::make(CenterStatsController::class)->getByGlobalReport($globalReport->id, $region);
        if (!$globalReportData) {
            return null;
        }

        $a = new GamesByWeek($globalReportData);
        $weeklyData = $a->compose();

        $a = new GamesByMilestone(['weeks' => $weeklyData['reportData'], 'quarter' => $quarter]);
        $data = $a->compose();
        return view('reports.centergames.milestones', $data);
    }

    public function getTmlpRegistrationsByStatus(GlobalReport $globalReport, Region $region)
    {
        $registrations = App::make(TmlpRegistrationsController::class)->getByGlobalReport($globalReport->id, $region);
        if (!$registrations) {
            return null;
        }

        $a = new TmlpRegistrationsByStatus(['registrationsData' => $registrations]);
        $data = $a->compose();

        $data = array_merge($data, ['reportingDate' => $globalReport->reportingDate]);
        return view('globalreports.details.applicationsbystatus', $data);
    }

    public function getTmlpRegistrationsOverdue(GlobalReport $globalReport, Region $region)
    {
        $registrations = App::make(TmlpRegistrationsController::class)->getByGlobalReport($globalReport->id, $region);
        if (!$registrations) {
            return null;
        }

        $a = new TmlpRegistrationsByStatus(['registrationsData' => $registrations]);
        $statusData = $a->compose();

        $a = new TmlpRegistrationsByOverdue(['registrationsData' => $statusData['reportData']]);
        $data = $a->compose();

        $data = array_merge($data, ['reportingDate' => $globalReport->reportingDate]);
        return view('globalreports.details.applicationsoverdue', $data);
    }

    public function getTmlpRegistrationsOverview(GlobalReport $globalReport, Region $region)
    {
        $registrations = App::make(TmlpRegistrationsController::class)->getByGlobalReport($globalReport->id, $region);
        if (!$registrations) {
            return null;
        }

        $teamMembers = App::make(TeamMembersController::class)->getByGlobalReport($globalReport->id, $region);
        if (!$teamMembers) {
            return null;
        }

        $a = new TmlpRegistrationsByCenter(['registrationsData' => $registrations]);
        $registrationsByCenter = $a->compose();
        $registrationsByCenter = $registrationsByCenter['reportData'];

        $a = new TeamMembersByCenter(['teamMembersData' => $teamMembers]);
        $teamMembersByCenter = $a->compose();
        $teamMembersByCenter = $teamMembersByCenter['reportData'];

        $reportData = [];
        $teamCounts = [
            'team1' => [
                'applications' => [],
                'incoming' => 0,
                'ongoing'  => 0,
            ],
            'team2' => [
                'applications' => [],
                'incoming' => 0,
                'ongoing'  => 0,
            ],
        ];
        foreach ($teamMembersByCenter as $centerName => $unused) {
            $a = new TeamMemberIncomingOverview([
                'registrationsData' => isset($registrationsByCenter[$centerName]) ? $registrationsByCenter[$centerName] : [],
                'teamMembersData'   => isset($teamMembersByCenter[$centerName]) ? $teamMembersByCenter[$centerName] : [],
                'region'            => $region,
            ]);
            $centerRow = $a->compose();

            $reportData[$centerName] = $centerRow['reportData'];

            foreach ($centerRow['reportData'] as $team => $teamData) {

                foreach ($teamData['applications'] as $status => $statusCount) {
                    if (!isset($teamCounts[$team]['applications'][$status])) {
                        $teamCounts[$team]['applications'][$status] = 0;
                    }

                    $teamCounts[$team]['applications'][$status] += $statusCount;
                }
                $teamCounts[$team]['incoming'] += isset($teamData['incoming']) ? $teamData['incoming'] : 0;
                $teamCounts[$team]['ongoing'] += isset($teamData['ongoing']) ? $teamData['ongoing'] : 0;
            }
        }
        ksort($reportData);

        return view('globalreports.details.applicationsoverview', compact('reportData', 'teamCounts'));
    }

    public function getTmlpRegistrationsByCenter(GlobalReport $globalReport, Region $region)
    {
        $quarter = Quarter::byRegion($region)->date($globalReport->reportingDate)->first();
        $quarter->setRegion($region);

        $registrations = App::make(TmlpRegistrationsController::class)->getByGlobalReport($globalReport->id, $region);
        if (!$registrations) {
            return null;
        }

        $a = new TmlpRegistrationsByCenter(['registrationsData' => $registrations]);
        $centersData = $a->compose();

        $reportData = [];
        foreach ($centersData['reportData'] as $centerName => $data) {
            $a = new TmlpRegistrationsByIncomingQuarter(['registrationsData' => $data, 'quarter' => $quarter]);
            $data = $a->compose();
            $reportData[$centerName] = $data['reportData'];
        }
        ksort($reportData);
        $reportingDate = $globalReport->reportingDate;

        return view('globalreports.details.applicationsbycenter', compact('reportData', 'reportingDate'));
    }

    public function getTravelReport(GlobalReport $globalReport, Region $region)
    {
        $registrations = App::make(TmlpRegistrationsController::class)->getByGlobalReport($globalReport->id, $region);
        if (!$registrations) {
            return null;
        }

        $teamMembers = App::make(TeamMembersController::class)->getByGlobalReport($globalReport->id, $region);
        if (!$teamMembers) {
            return null;
        }

        $a = new TmlpRegistrationsByCenter(['registrationsData' => $registrations]);
        $registrationsByCenter = $a->compose();
        $registrationsByCenter = $registrationsByCenter['reportData'];

        $a = new TeamMembersByCenter(['teamMembersData' => $teamMembers]);
        $teamMembersByCenter = $a->compose();
        $teamMembersByCenter = $teamMembersByCenter['reportData'];

        $reportData = [];
        foreach ($teamMembersByCenter as $centerName => $teamMembersData) {

            $a = new TravelRoomingByTeamYear([
                'registrationsData' => isset($registrationsByCenter[$centerName]) ? $registrationsByCenter[$centerName] : [],
                'teamMembersData'   => isset($teamMembersByCenter[$centerName]) ? $teamMembersByCenter[$centerName] : [],
                'region'            => $region,
            ]);
            $centerRow = $a->compose();

            $reportData[$centerName] = $centerRow['reportData'];
        }
        ksort($reportData);

        return view('globalreports.details.traveloverview', compact('reportData'));
    }

    public function getCenterStatsReports(GlobalReport $globalReport, Region $region)
    {
        $quarter = Quarter::byRegion($region)->date($globalReport->reportingDate)->first();
        $quarter->setRegion($region);

        $statsReports = $globalReport->statsReports()
            ->byRegion($region)
            ->get();

        if ($statsReports->isEmpty()) {
            return null;
        }

        $statsReportsList = [];

        foreach ($statsReports as $report) {

            $statsReportData = [
                'id'                 => $report->id,
                'center'             => $report->center->name,
                'region'             => $region->abbreviation,
                'rating'             => $report->getRating(),
                'points'             => $report->getPoints(),
                'isValidated'        => $report->isValidated(),
                'onTime'             => false,
                'officialSubmitTime' => '',
                'officialReport'     => $report,
            ];

            if ($report->isOnTime()) {
                $statsReportData['onTime'] = true;
                $statsReportData['officialSubmitTime'] = $report->submittedAt->setTimezone($report->center->timezone)->format('M j @ g:ia T');
            } else {
                $otherReports = StatsReport::reportingDate($globalReport->reportingDate)
                    ->byCenter($report->center)
                    ->whereNotNull('submitted_at')
                    ->orderBy('submitted_at', 'asc')
                    ->get();

                if (!$otherReports->isEmpty()) {

                    $officialReport = null;
                    foreach ($otherReports as $submitted) {
                        $officialReport = $submitted;
                        if ($officialReport->isOnTime()) {
                            $statsReportData['onTime'] = true;
                            break;
                        }
                    }

                    if ($officialReport && $statsReportData['onTime'] === true) {
                        $statsReportData['officialSubmitTime'] = $officialReport->submittedAt->setTimezone($report->center->timezone)->format('M j @ g:ia T');
                        $statsReportData['officialReport'] = $officialReport;

                        $statsReportData['revisionSubmitTime'] = $report->submittedAt->setTimezone($report->center->timezone)->format('M j @ g:ia T');
                        $statsReportData['revisedReport'] = $report;
                    } else {
                        $first = $otherReports->first();
                        $statsReportData['officialSubmitTime'] = $first->submittedAt->setTimezone($report->center->timezone)->format('M j @ g:ia T');
                        $statsReportData['officialReport'] = $first;
                        if ($first->id != $report->id) {
                            $statsReportData['revisionSubmitTime'] = $report->submittedAt->setTimezone($report->center->timezone)->format('M j @ g:ia T');
                            $statsReportData['revisedReport'] = $report;
                        }
                    }
                }
            }
            $statsReportsList[] = $statsReportData;
        }
        usort($statsReportsList, array(get_class(), 'sortByCenterName'));

        return view('globalreports.details.statsreports', compact('statsReportsList'));
    }

    public function getCompletedCoursesReport(GlobalReport $globalReport, Region $region)
    {
        $coursesData = App::make(CoursesController::class)->getByGlobalReport($globalReport->id, $region);
        if (!$coursesData) {
            return null;
        }

        $a = new CoursesByCenter(['coursesData' => $coursesData]);
        $coursesByCenter = $a->compose();
        $coursesByCenter = $coursesByCenter['reportData'];

        $reportData = [];
        foreach ($coursesByCenter as $centerName => $coursesData) {

            $a = new CoursesWithEffectiveness(['courses' => $coursesData, 'reportingDate' => $globalReport->reportingDate]);
            $centerRow = $a->compose();

            if (!isset($centerRow['reportData']['completed'])) {
                continue;
            }

            $reportData[$centerName] = $centerRow['reportData']['completed'];
        }
        ksort($reportData);

        return view('globalreports.details.completedcourses', compact('reportData'));
    }

    protected static function sortByCenterName($a, $b)
    {
        return strcmp($a['center'], $b['center']);
    }

}
