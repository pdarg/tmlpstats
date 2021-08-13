<?php
namespace TmlpStats;

use Eloquence\Database\Traits\CamelCaseModel;
use Illuminate\Database\Eloquent\Model;
use TmlpStats\Traits\CachedRelationships;

class TmlpRegistrationData extends Model
{
    use CamelCaseModel, CachedRelationships;

    protected $table = 'tmlp_registrations_data';

    protected $fillable = [
        'stats_report_id',
        'tmlp_registration_id',
        'reg_date',
        'app_out_date',
        'app_in_date',
        'appr_date',
        'wd_date',
        'withdraw_code_id',
        'committed_team_member_id',
        'comment',
        'incoming_quarter_id',
        'travel',
        'room',
    ];

    protected $dates = [
        'reg_date',
        'app_out_date',
        'app_in_date',
        'appr_date',
        'wd_date',
    ];

    protected $casts = [
        'travel' => 'boolean',
        'room' => 'boolean',
    ];

    public function __get($name)
    {
        switch ($name) {

            case 'firstName':
            case 'lastName':
            case 'fullName':
            case 'shortName':
                return $this->registration->person->$name;
            case 'center':
                return $this->statsReport->center;
            case 'teamYear':
                return $this->registration->$name;
            case 'incomingQuarter':
                $key = "incomingQuarter:region{$this->center->regionId}";

                return static::getFromCache($key, $this->incomingQuarterId, function () {
                    $quarter = Quarter::find($this->incomingQuarterId);
                    if ($quarter) {
                        $quarter->setRegion($this->center->region);
                    }

                    return $quarter;
                });
            default:
                return parent::__get($name);
        }
    }

    public function due()
    {
        if (!$this->app_out_date || $this->withdrawCodeId || $this->apprDate) {
            return null;
        }

        return $this->app_out_date->copy()->addDays(14);

    }

    public function mirror(TmlpRegistrationData $data)
    {
        $excludedFields = [
            'stats_report_id' => true,
        ];

        foreach ($this->fillable as $field) {
            if (isset($excludedFields[$field])) {
                continue;
            }

            $this->$field = $data->$field;
        }
    }

    public function isWithdrawn()
    {
        return ($this->withdrawCodeId !== null);
    }

    public function scopeApproved($query)
    {
        return $query->whereNotNull('appr_date');
    }

    public function scopeWithdrawn($query)
    {
        return $query->whereNotNull('wd_date');
    }

    public function scopeIncomingQuarter($query, Quarter $quarter)
    {
        return $query->whereIncomingQuarterId($quarter->id);
    }

    public function scopeByStatsReport($query, StatsReport $statsReport)
    {
        return $query->whereStatsReportId($statsReport->id);
    }

    public function scopeByRegistration($query, $registration)
    {
        if (is_object($registration)) {
            $registration = $registration->id;
        }

        return $query->whereTmlpRegistrationId($registration);
    }

    public function statsReport()
    {
        return $this->belongsTo('TmlpStats\StatsReport');
    }

    public function withdrawCode()
    {
        return $this->belongsTo('TmlpStats\WithdrawCode');
    }

    public function committedTeamMember()
    {
        return $this->belongsTo('TmlpStats\TeamMember', 'committed_team_member_id', 'id');
    }

    public function incomingQuarter()
    {
        return $this->hasOne('TmlpStats\Quarter', 'id', 'incoming_quarter_id');
    }

    public function registration()
    {
        return $this->belongsTo('TmlpStats\TmlpRegistration', 'tmlp_registration_id', 'id');
    }
}
