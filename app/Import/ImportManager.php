<?php
namespace TmlpStats\Import;

use TmlpStats\Import\Xlsx\XlsxArchiver;

use TmlpStats\Center;
use TmlpStats\Quarter;
use Carbon\Carbon;

use Auth;
use Log;
use Mail;
use Exception;

// Required for importing multiple sheets
ini_set('max_execution_time', 240);
ini_set('memory_limit', '512M');
ini_set('max_file_uploads', '30');

// ImportManager takes the list of uploaded files, has them all processed, and returns an array of results
class ImportManager
{
    protected $files = array();
    protected $expectedDate = null;
    protected $enforceVersion = false;

    protected $results = array();

    public function __construct($files, $expectedDate = null, $enforceVersion = true)
    {
        $this->files = $files;
        if ($expectedDate) {
            $this->expectedDate = Carbon::createFromFormat('Y-m-d', $expectedDate)->startOfDay();
        }
        $this->enforceVersion = $enforceVersion;
    }

    public function getResults()
    {
        return $this->results;
    }

    public function import($saveReport = false)
    {
        $successSheets = array();
        $warnSheets = array();
        $errorSheets = array();
        $unknownFiles = array();

        foreach ($this->files as $file) {

            $exception = null;
            $sheetPath = null;
            $sheet = array();

            try {
                if (!($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile)) {
                    // If someone refreshes the page after submitting file, and the browser doesn't send the file contents,
                    // we end up with empty files.
                    $file = null;
                    throw new Exception("There was a problem uploading one of the files. Please try again.");
                }

                $fileName = $file->getClientOriginalName();
                if (!$file->isValid()) {
                    Log::error("Error uploading '$fileName': {$file->getError()}");
                    $file = null;
                    throw new Exception("There was a problem uploading '$fileName'. Please try again.");
                }

                try {
                    $importer = new Xlsx\XlsxImporter($file->getRealPath(), $fileName, $this->expectedDate, $this->enforceVersion);
                    $importer->import($saveReport);
                    $sheet = $importer->getResults();
                } catch(Exception $e) {
                    Log::error("Error processing '$fileName': " . $e->getMessage() . "\n" . $e->getTraceAsString());
                    throw new Exception("There was an error processing '$fileName': ".$e->getMessage());
                }

                $user = Auth::user()->email;
                $errorCount = count($sheet['errors']);
                $warningCount = count($sheet['warnings']);
                Log::info("{$user} submitted sheet for {$sheet['center']} with {$errorCount} errors and {$warningCount} warnings.");

                if (isset($sheet['statsReportId'])) {

                    if ($sheet['submittedAt']) {
                        $sheetPath = XlsxArchiver::getInstance()->archive($file, $sheet['statsReport']);
                    } else {
                        $sheetPath = XlsxArchiver::getInstance()->saveWorkingSheet($file, $sheet['statsReport']);
                    }
                }

                if ($sheet['result'] == 'error') {
                    $errorSheets[] = $sheet;
                } else if ($sheet['result'] == 'warning') {
                    $warnSheets[] = $sheet;
                } else {
                    $successSheets[] = $sheet;
                }
            } catch(Exception $e) {

                Log::error("Error processing file: " . $e->getMessage());
                $unknownFiles[] = $e->getMessage();
                $exception = $e;
            }

            if (!$sheetPath && $file) {

                $sheetPath = XlsxArchiver::getInstance()->saveWorkingSheet($file);
            }

            if ($exception) {
                $center = isset($sheet['center'])
                    ? $sheet['center']
                    : 'unknown';

                $user = Auth::user()->email;
                $time = Carbon::now()->format('Y-m-d H:i:s');

                $body = "An exception was caught processing a sheet submitted by '{$user}' for {$center} center at {$time} UTC: '" . $exception->getMessage() . "'\n\n";
                $body .= $exception->getTraceAsString() . "\n";
                try {
                    Mail::raw($body, function($message) use ($center, $sheetPath) {
                        $message->to(env('ADMIN_EMAIL'))->subject("Exception processing sheet for {$center} center");
                        if ($sheetPath) {
                            $message->attach($sheetPath);
                        }
                    });
                } catch (\Exception $e) {
                    Log::error("Exception caught sending error email: " . $e->getMessage());
                }
            } else if ($sheet['submittedAt']) {

                $result = $this->sendStatsSubmittedEmail($sheet['statsReport'], $sheet);
                if ($result !== false) {
                    $this->results['messages'] = $result;
                }
            }
        }

        $this->results['sheets'] = array_merge($successSheets, $warnSheets, $errorSheets);
        $this->results['unknownFiles'] = $unknownFiles;
    }

    public static function getExpectedReportDate()
    {
        $expectedDate = null;
        if (Carbon::now()->dayOfWeek == Carbon::FRIDAY) {
            $expectedDate = Carbon::now();
        } else if (Carbon::now()->isWeekend()) {
            $expectedDate = new Carbon('last friday');
        } else {
            $expectedDate = new Carbon('next friday');
        }
        return $expectedDate->startOfDay();
    }

    public static function sendStatsSubmittedEmail($statsReport, $sheet)
    {
        if (!$statsReport || !$statsReport->submittedAt) {
            return false;
        }

        $result = array();

        $user = ucfirst(Auth::user()->firstName);
        $quarter = $statsReport->quarter;
        $center = $statsReport->center;

        $sumittedAt = clone $statsReport->submittedAt;
        $sumittedAt->setTimezone($center->timezone);

        // 7:00.59 PM local time on the reporting date.
        $due = Carbon::create(
            $statsReport->reportingDate->year,
            $statsReport->reportingDate->month,
            $statsReport->reportingDate->day,
            19, 0, 59,
            $center->timezone
        );

        $isLate = $sumittedAt->gt($due);
        $due = $due->format('l, F jS \a\t g:ia');
        $time = $sumittedAt->format('l, F jS \a\t g:ia');

        $programManager         = $center->getProgramManager($quarter, $center->globalRegion);
        $classroomLeader        = $center->getClassroomLeader($quarter, $center->globalRegion);
        $t1TeamLeader           = $center->getT1TeamLeader($quarter, $center->globalRegion);
        $t2TeamLeader           = $center->getT2TeamLeader($quarter, $center->globalRegion);
        $statistician           = $center->getStatistician($quarter, $center->globalRegion);
        $statisticianApprentice = $center->getStatisticianApprentice($quarter, $center->globalRegion);

        $emails = array(
            'center'                 => $center->statsEmail,
            'programManager'         => $programManager ? $programManager->email : null,
            'classroomLeader'        => $classroomLeader ? $classroomLeader->email : null,
            't1TeamLeader'           => $t1TeamLeader ? $t1TeamLeader->email : null,
            't2TeamLeader'           => $t2TeamLeader ? $t2TeamLeader->email : null,
            'statistician'           => $statistician ? $statistician->email : null,
            'statisticianApprentice' => $statisticianApprentice ? $statisticianApprentice->email : null,
            'regional'               => $center->region->email,
        );

        $sheetPath = XlsxArchiver::getInstance()->getSheetPath($statsReport);
        $sheetName = XlsxArchiver::getInstance()->getDisplayFileName($statsReport);
        $centerName = $center->name;
        $comment = $statsReport->submitComment;
        try {
            Mail::send('emails.statssubmitted', compact('user', 'centerName', 'time', 'sheet', 'isLate', 'due', 'comment'),
                function($message) use ($emails, $centerName, $sheetPath, $sheetName) {
                // Only send email to centers in production
                if (env('APP_ENV') === 'prod') {
                    $message->to($emails['center']);

                    if ($emails['regional']) {
                        $message->cc($emails['regional']);
                    }
                    if ($emails['programManager']) {
                        $message->cc($emails['programManager']);
                    }
                    if ($emails['classroomLeader']) {
                        $message->cc($emails['classroomLeader']);
                    }
                    if ($emails['t1TeamLeader']) {
                        $message->cc($emails['t1TeamLeader']);
                    }
                    if ($emails['t2TeamLeader']) {
                        $message->cc($emails['t2TeamLeader']);
                    }
                    if ($emails['statistician']) {
                        $message->cc($emails['statistician']);
                    }
                    if ($emails['statisticianApprentice']) {
                        $message->cc($emails['statisticianApprentice']);
                    }
                } else {
                    $message->to(env('ADMIN_EMAIL'));
                }

                if ($emails['regional']) {
                    $message->replyTo($emails['regional']);
                }

                $message->subject("Team {$centerName} Statistics Submitted");
                $message->attach($sheetPath, array(
                    'as' => $sheetName,
                ));
            });
            Log::info("Sent emails to the following people with team {$centerName}'s report: " . implode(', ', $emails));
            $result['success'][] = "<span style='font-weight:bold'>Thank you.</span> We received your statistics and have sent a copy to <ul><li>" . implode('</li><li>', $emails) . "</li></ul> Please reply-all to that email if there is anything you need to communicate.";
        } catch (\Exception $e) {
            Log::error("Exception caught sending error email: " . $e->getMessage());
            $result['error'][] = "<span style='font-weight:bold'>Hold up.</span> There was a problem emailing your sheet to your team. Please email the sheet you just submitted to your Program Manager, Classroom Leader, T2 Team Leader, and Regional Statistician ({$emails['regional']}) using your center stats email ({$emails['center']}). <span style='font-weight:bold'>We did</span> receive your statistics.";
        }
        return $result;
    }
}
