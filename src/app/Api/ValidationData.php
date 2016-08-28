<?php
namespace TmlpStats\Api;

use App;
use Carbon\Carbon;
use TmlpStats as Models;
use TmlpStats\Api\Base\AuthenticatedApiBase;
use TmlpStats\Api\Exceptions as ApiExceptions;
use TmlpStats\Domain;
use TmlpStats\Traits;

/**
 * Validation data
 */
class ValidationData extends AuthenticatedApiBase
{
    use Traits\GeneratesApiMessages;

    // updateRequired: Not all objects require updates every week, but some do. For those that do,
    //                 if no update is needed, they'll need to take some action to confirm the
    //                 data is the same. That "confirmation" will create a submissionData entry
    protected $dataTypesConf = [
        'applications' => [
            'apiClass' => Application::class,
            'typeName' => 'application',
            'updateRequired' => false,
        ],
        'courses' => [
            'apiClass' => Course::class,
            'typeName' => 'course',
            'updateRequired' => false,
        ],
        'scoreboard' => [
            'apiClass' => Scoreboard::class,
            'typeName' => 'center games',
            'updateRequired' => true,
        ],
    ];

    public function validate(Models\Center $center, Carbon $reportingDate)
    {
        $this->assertAuthz($this->context->can('viewSubmissionUi', $center));
        App::make(SubmissionCore::class)->checkCenterDate($center, $reportingDate);

        $report = LocalReport::getStatsReport($center, $reportingDate);

        $results = array_merge_recursive(
            $this->validateSubmissionData($report),
            $this->validateStaleData($report)
        );
        $isValid = true;

        foreach ($results as $group => $groupData) {
            foreach ($groupData as $message) {
                if ($message['type'] == 'error') {
                    $isValid = false;
                    break;
                }
            }
        }

        return [
            'success' => true,
            'valid' => $isValid,
            'messages' => $results,
        ];
    }

    protected function validateSubmissionData(Models\StatsReport $report)
    {
        $data = [];
        foreach ($this->dataTypesConf as $group => $conf) {
            $data[$group] = App::make($conf['apiClass'])->getChangedFromLastReport(
                $report->center,
                $report->reportingDate
            );
        }

        $results = [];
        foreach ($data as $group => $groupData) {
            if (!isset($results[$group])) {
                $results[$group] = [];
            }
            foreach ($groupData as $object) {
                $id = $object->getKey();
                $validationResults = $this->validateObject($report, $object, $id);

                if ($validationResults['messages']) {
                    $results[$group] = array_merge($results[$group], $validationResults['messages']);
                }
            }
        }

        return $results;
    }

    protected function validateStaleData(Models\StatsReport $report)
    {
        $data = [];
        foreach ($this->dataTypesConf as $group => $conf) {
            $data[$group] = App::make($conf['apiClass'])->getUnchangedFromLastReport(
                $report->center,
                $report->reportingDate
            );
        }

        $results = [];
        foreach ($data as $group => $groupData) {
            $conf = $this->dataTypesConf[$group];
            foreach ($groupData as $object) {
                $id = $object->getKey();
                $validationResults = $this->validateObject($report, $object, $id);

                if ($conf['updateRequired']) {
                    // Need to set $this->data so addMessage can get the object's id
                    $this->data = $object;
                    $validationResults['valid'] = false;
                    $validationResults['messages'][] = $this->addMessage('VALDATA_DATA_MISSING_UPDATE', $conf['typeName']);
                }

                if ($validationResults['messages']) {
                    $results[$group] = array_merge($results[$group], $validationResults['messages']);
                }
            }
        }

        return $results;
    }
}
