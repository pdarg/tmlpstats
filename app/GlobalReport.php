<?php
namespace TmlpStats;

use TmlpStats\StatsReport;
use Illuminate\Database\Eloquent\Model;
use Eloquence\Database\Traits\CamelCaseModel;

use Carbon\Carbon;
use TmlpStats\Quarter;

class GlobalReport extends Model {

    use CamelCaseModel;

    protected $fillable = [
        'reporting_date',
        'quarter_id',
        'user_id',
        'locked',
    ];

    protected $dates = [
        'reporting_date',
    ];

    protected $casts = [
        'locked' => 'boolean',
    ];

    public function addCenterReport(StatsReport $report)
    {
        $existingReport = $this->statsReports()->byCenter($report->center)->first();
        if ($existingReport) {
            $this->statsReports()->detach($existingReport->id);
        }
        $this->statsReports()->attach($report->id);
    }

    public function scopeReportingDate($query, $date)
    {
        if ($date instanceof Carbon) {
            $date = $date->toDateString();
        }
        return $query->whereReportingDate($date);
    }

    public function scopeCurrentQuarter($query, Region $region)
    {
        $quarter = Quarter::region($region)->date(Carbon::now())->first();
        return $query->whereQuarterId($quarter->id);
    }

    public function scopeLastQuarter($query, Region $region)
    {
        $currentQuarter = Quarter::region($region)->date(Carbon::now())->first();
        $lastQuarter = Quarter::region($region)->date($currentQuarter->startWeekendDate)->first();
        return $query->whereQuarterId($lastQuarter->id);
    }

    public function quarter()
    {
        return $this->belongsTo('TmlpStats\Quarter');
    }

    public function updatedByUser()
    {
        return $this->hasOne('TmlpStats\User');
    }

    public function statsReports()
    {
        return $this->belongsToMany('TmlpStats\StatsReport', 'global_report_stats_report')->withTimestamps();
    }
}
