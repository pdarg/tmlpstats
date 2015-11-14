<?php
namespace TmlpStats;

use Eloquence\Database\Traits\CamelCaseModel;
use Illuminate\Database\Eloquent\Model;
use TmlpStats\Traits\CachedRelationships;

class Course extends Model
{
    use CamelCaseModel, CachedRelationships;

    protected $fillable = [
        'center_id',
        'start_date',
        'type',
        'location',
    ];

    protected $dates = [
        'start_date',
    ];

    public function scopeType($query, $type)
    {
        return $query->whereType($type);
    }

    public function scopeCap($query)
    {
        return $query->whereType('CAP');
    }

    public function scopeCpc($query)
    {
        return $query->whereType('CPC');
    }

    public function scopeLocation($query, $location)
    {
        return $query->whereLocation($location);
    }

    public function scopeByCenter($query, Center $center)
    {
        return $query->whereCenterId($center->id);
    }

    public function center()
    {
        return $this->belongsTo('TmlpStats\Center');
    }

    public function courseData()
    {
        return $this->hasMany('TmlpStats\CourseData');
    }
}
