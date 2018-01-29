<?php
namespace TmlpStats\Http\Controllers;

use TmlpStats\Region;

class ManageController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function region($regionAbbr)
    {
        $region = Region::abbreviation($regionAbbr)->firstOrFail();
        $this->authorize('viewManageUi', $region);

        return view('admin.region', compact('region'));
    }

    public function system()
    {
        $region = Region::abbreviation('na')->firstOrFail();

        return view('admin.region', compact('region'));

    }

    public function graphql()
    {
        $region = Region::abbreviation('na')->firstOrFail();

        return view('admin.region', compact('region'));

    }

}
