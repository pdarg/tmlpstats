@extends('template')

@section('content')

<h2>{{ $center->name }} Center</h2>
<a href="{{ url('/admin/centers') }}"><< See All</a><br/><br/>
<a href="{{ url("/admin/centers/{$center->abbreviation}/edit") }}">Edit</a>

<div class="table-responsive">
    <table class="table table-condensed table-striped">
        <tr>
            <th>Name:</th>
            <td>{{ $center->name }}</td>
        </tr>
        <tr>
            <th>Abbreviation:</th>
            <td>{{ $center->abbreviation }}</td>
        </tr>
        <tr>
            <th>Team Name:</th>
            <td>{{ $center->teamName }}</td>
        </tr>
        <tr>
            <th>Global Region:</th>
            <td><?php
                $globalRegion = $center->getGlobalRegion();
                if ($globalRegion) {
                    echo $globalRegion->name;
                }
            ?></td>
        </tr>
        <tr>
            <th>Local Region:</th>
            <td><?php
                $localRegion = $center->getLocalRegion();
                if ($localRegion) {
                    echo $globalRegion
                        ? str_replace("{$globalRegion->name} - ", '', $localRegion->name)
                        : $localRegion->name;
                }
                ?></td>
        </tr>
        <tr>
            <th>Stats Email:</th>
            <td>{{ $center->statsEmail }}</td>
        </tr>
        <tr>
            <th>Time Zone:</th>
            <td>{{ $center->timezone }}</td>
        </tr>
        <tr>
            <th>Sheet Filename:</th>
            <td>{{ $center->sheetFilename }}</td>
        </tr>
        <tr>
            <th>Sheet Version:</th>
            <td>{{ $center->sheetVersion }}</td>
        </tr>
        <tr>
            <th>Mailing List:</th>
            <td><?php
                $list = $center->getMailingList($quarter);
                if ($list) {
                    echo nl2br(e(implode(",\n", $list)));
                }
            ?></td>
        </tr>
        <tr>
            <th>Active:</th>
            <td>{{ $center->active == true ? 'Yes' : 'No' }}</td>
        </tr>
    </table>
</div>

@endsection
