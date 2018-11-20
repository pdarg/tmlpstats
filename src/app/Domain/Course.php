<?php
namespace TmlpStats\Domain;

use TmlpStats as Models;
use TmlpStats\Api\Exceptions as ApiExceptions;

/**
 * Models a course
 */
class Course extends ParserDomain
{
    public $meta = [];

    protected static $validProperties = [
        'center' => [
            'owner' => 'course',
            'type' => 'Center',
            'assignId' => true,
        ],
        'startDate' => [
            'owner' => 'course',
            'type' => 'date',
        ],
        'type' => [
            'owner' => 'course',
            'type' => 'string',
        ],
        'location' => [
            'owner' => 'course',
            'type' => 'string',
            'options' => ['trim' => true],
        ],
        'id' => [
            'owner' => 'course',
            'type' => 'int',
        ],
        'quarterStartTer' => [
            'owner' => 'courseData',
            'type' => 'int',
            'options' => ['zeroOnMissing' => true],
        ],
        'quarterStartStandardStarts' => [
            'owner' => 'courseData',
            'type' => 'int',
            'options' => ['zeroOnMissing' => true],
        ],
        'quarterStartXfer' => [
            'owner' => 'courseData',
            'type' => 'int',
            'options' => ['zeroOnMissing' => true],
        ],
        'currentTer' => [
            'owner' => 'courseData',
            'type' => 'int',
            'options' => ['zeroOnMissing' => true],
        ],
        'currentStandardStarts' => [
            'owner' => 'courseData',
            'type' => 'int',
            'options' => ['zeroOnMissing' => true],
        ],
        'currentXfer' => [
            'owner' => 'courseData',
            'type' => 'int',
            'options' => ['zeroOnMissing' => true],
        ],
        'completedStandardStarts' => [
            'owner' => 'courseData',
            'type' => 'int',
        ],
        'potentials' => [
            'owner' => 'courseData',
            'type' => 'int',
        ],
        'registrations' => [
            'owner' => 'courseData',
            'type' => 'int',
        ],
        'guestsPromised' => [
            'owner' => 'courseData',
            'type' => 'int',
        ],
        'guestsInvited' => [
            'owner' => 'courseData',
            'type' => 'int',
        ],
        'guestsConfirmed' => [
            'owner' => 'courseData',
            'type' => 'int',
        ],
        'guestsAttended' => [
            'owner' => 'courseData',
            'type' => 'int',
        ],
    ];

    public function __set($key, $value)
    {
        // TODO: move this into the parser. Maybe a new enum type?
        if ($key === 'type') {
            $value = strtoupper($value);
            if (!in_array($value, ['CAP', 'CPC'])) {
                throw new ApiExceptions\BadRequestException('Unrecognized type');
            }
        }

        parent::__set($key, $value);
    }

    /**
     * Populate a Domain\Course from an existing CourseData model
     *
     * @param  Models\CourseData $courseData
     * @param  Models\Course     $course
     * @return Domain\Course
     */
    public static function fromModel($courseData, $course = null)
    {
        if ($course === null) {
            $course = $courseData->course;
        }

        $obj = new static();
        foreach (static::$validProperties as $k => $v) {
            switch ($v['owner']) {
                case 'course':
                    if ($k == 'location' && $course->$k === null) {
                        // If no location provided, auto-fill in the center name
                        $obj->$k = $course->center->name;
                    } else {
                        $obj->$k = $course->$k;
                    }
                    break;
                case 'courseData':
                    if ($courseData) {
                        $obj->$k = $courseData->$k;
                    }
                    break;
            }
        }
        $obj->id = $course->id;

        return $obj;
    }

    public function prepareZeroes() {
        foreach (self::$validProperties as $k => $conf) {
            $options = array_get($conf, 'options', null);
            if ($options !== null && array_get($options, 'zeroOnMissing', false) && is_null($this->$k)) {
                $this->$k = 0;
            }
        }
    }

    /**
     * Populate the provided model(s) with $this->values
     *
     * @param  Models\CourseData  $courseData
     * @param  Models\Course|null $course     If not provided, $courseData->course is used
     * @param  boolean            $only_set   When true, only populate the values that have been
     */
    public function fillModel($courseData = null, $course = null, $only_set = true)
    {
        if ($course === null) {
            $course = $courseData->course;
        }
        foreach ($this->_values as $k => $v) {
            if ($only_set && (!isset($this->_setValues[$k]) || !$this->_setValues[$k])) {
                continue;
            }
            $conf = self::$validProperties[$k];
            switch ($conf['owner']) {
                case 'course':
                    $target = $course;
                    break;
                case 'courseData':
                    $target = $courseData;
                    break;
            }
            if ($target !== null) {
                $this->copyTarget($target, $k, $v, $conf);
            }
        }
    }

    public function getFlattenedReference(array $supplemental = [])
    {
        $location = $this->location ?: 'unknown';
        $type = $this->type ?: 'unknown';
        $startDate = $this->startDate ? $this->startDate->toDateString() : 'unknown';

        return "{$location} {$type} {$startDate}";
    }

    public function toArray()
    {
        $output = parent::toArray();

        $output['meta'] = $this->meta;

        return $output;
    }
}
