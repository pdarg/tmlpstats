<div class="table-responsive">
    @foreach (['notSent', 'out', 'waiting', 'approved', 'withdrawn'] as $group)
        @if ($reportData[$group])
            <br/>
            <h4>
                @if ($group == 'withdrawn')
                    Withdrawn
                @elseif ($group == 'out')
                    Application Out
                @elseif ($group == 'waiting')
                    Awaiting Interview
                @elseif ($group == 'approved')
                    Approved
                @else
                    Application Not Sent
                @endif
                <span style="font-weight: normal; font-size: smaller;">(Total: {{ count($reportData[$group]) }})</span>
            </h4>
            <table class="table table-condensed table-striped table-hover applicationTable want-datatable">
                <thead>
                <tr>
                    <th>Center</th>
                    <th>Name</th>
                    <th class="data-point">Year</th>
                    <th>Incoming</th>
                    <th class="data-point">Reg Date</th>
                    @if ($group == 'withdrawn')
                        <th>Reason</th>
                        <th class="data-point">Withdraw</th>
                        <th class="data-point">Days to Withdraw</th>
                    @elseif ($group == 'out')
                        <th class="data-point">App Out</th>
                        <th class="data-point">Days Out</th>
                        <th class="data-point">Due</th>
                    @elseif ($group == 'waiting')
                        <th class="data-point">App In</th>
                        <th class="data-point">Days In</th>
                        <th class="data-point">Due</th>
                    @elseif ($group == 'approved')
                        <th class="data-point">Approved</th>
                        <th class="data-point">Days to Complete</th>
                    @else
                        <th class="data-point">Days Since Reg</th>
                        <th class="data-point">Due</th>
                    @endif

                    <th>Comments</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($reportData[$group] as $registrationData)
                    @if ($registrationData->due() && $registrationData->due()->lt($reportingDate))
                        <tr title="{{ ($group == 'notSent') ? 'Due within 2 days of registration' : 'Due within 14 days of registration' }}">
                    @else
                        <tr>
                            @endif
                            <td>
                                @statsReportLink($registrationData->statsReport, '/TeamExpansion/TmlpRegistrationsByStatus')
                                    {{ $registrationData->center->name }}
                                @endStatsReportLink
                            </td>
                            <td>{{ $registrationData->firstName }} {{ $registrationData->lastName }}</td>
                            <td class="data-point">{{ $registrationData->registration->teamYear }}</td>
                            <td>{{ $registrationData->incomingQuarter ? $registrationData->incomingQuarter->getQuarterStartDate($registrationData->center)->format('M Y') : '' }}</td>
                            <td class="data-point">
                                @if ($registrationData->regDate)
                                    @date($registrationData->regDate)
                                @endif
                            </td>
                            @if ($group == 'withdrawn')
                                @if ($registrationData->withdrawCode)
                                    <td title="{{ $registrationData->withdrawCode->code }}">
                                        {{ $registrationData->withdrawCode->display }}
                                    </td>
                                    <td class="data-point" title="{{ $registrationData->withdrawCode->code }}">
                                        @if ($registrationData->wdDate)
                                            @date($registrationData->wdDate)
                                        @endif
                                    </td>
                                    <td class="data-point">
                                        @if ($registrationData->wdDate)
                                            {{ $registrationData->regDate->diffInDays($registrationData->wdDate) }}
                                        @endif
                                    </td>
                                @else
                                    <td></td>
                                    <td></td>
                                @endif
                            @elseif ($group == 'out')
                                <td class="data-point">
                                    @if ($registrationData->appOutDate)
                                        @date($registrationData->appOutDate)
                                    @endif
                                </td>
                                <td class="data-point">
                                    @if ($registrationData->appOutDate)
                                        {{ $reportingDate->diffInDays($registrationData->appOutDate) }}
                                    @endif
                                </td>
                                <td class="data-point" {!! ($registrationData->due() && $registrationData->due()->lt($reportingDate)) ? 'style="color: red"' : '' !!}>
                                    @if ($registrationData->due())
                                        @date($registrationData->due())
                                    @endif
                                </td>
                            @elseif ($group == 'waiting')
                                <td class="data-point">
                                    @if ($registrationData->appInDate)
                                        @date($registrationData->appInDate)
                                    @endif
                                </td>
                                <td class="data-point">
                                    @if ($registrationData->appInDate)
                                        {{ $reportingDate->diffInDays($registrationData->appInDate) }}
                                    @endif
                                </td>
                                <td class="data-point" {!! ($registrationData->due() && $registrationData->due()->lt($reportingDate)) ? 'style="color: red"' : '' !!}>
                                    @if ($registrationData->due())
                                        @date($registrationData->due())
                                    @endif
                                </td>
                            @elseif ($group == 'approved')
                                <td class="data-point">
                                    @if ($registrationData->apprDate)
                                        @date($registrationData->apprDate)
                                    @endif
                                </td>
                                <td class="data-point">
                                    @if ($registrationData->apprDate)
                                        {{ $registrationData->regDate->diffInDays($registrationData->apprDate) }}
                                    @endif
                                </td>
                            @else
                                <td class="data-point">
                                    @if ($registrationData->regDate)
                                        {{ $reportingDate->diffInDays($registrationData->regDate) }}
                                    @endif
                                </td>
                                <td class="data-point" {!! ($registrationData->due() && $registrationData->due()->lt($reportingDate)) ? 'style="color: red"' : '' !!}>
                                    @if ($registrationData->due())
                                        @date($registrationData->due())
                                    @endif
                                </td>
                            @endif
                            <td>{{ $registrationData->comment }}</td>
                        </tr>
                        @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
</div>
