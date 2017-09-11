<?php
$ratingColors = [
    '#FF0000',
    '#FF1100',
    '#FF2200',
    '#FF3300',
    '#FF4400',
    '#FF5500',
    '#FF6600',
    '#FF7700',
    '#FF8800',
    '#FF9900',
    '#FFAA00',
    '#FFBB00',
    '#FFCC00',
    '#FFDD00',
    '#FFEE00',
    '#FFFF00',
    '#EEFF00',
    '#DDFF00',
    '#CCFF00',
    '#BBFF00',
    '#AAFF00',
    '#99FF00',
    '#88FF00',
    '#77FF00',
    '#66FF00',
    '#55FF00',
    '#44FF00',
    '#33FF00',
    '#22FF00',
    '#11FF00',
    '#00FF00',
];
?>

<div class="table-responsive">
    <h4>{{ $summary['rating'] }} - {{ $summary['points'] }} points</h4>
    <table class="table table-condensed table-bordered ratingsTable" style="width: 700px">
        <thead>
        <th style="text-align: center; width: 10em;">Category</th>
        <th style="text-align: center;">Points</th>
        <th style="text-align: left; width: 10em;">Center</th>
        </thead>
        <tbody>
        @foreach ($rows as $rating => $statsReports)
            <?php $count = 0; ?>
            @foreach ($statsReports as $report)
                <?php
                    $meterClass = ($report['points'] > 0) ? 'meter' : 'meter-zero';

                    // Width must always be > 0 to display even when 0 points
                    $meterWidth = max(round(($report['points'] / 28) * 100), 4);
                ?>
                <tr class="points">
                    @if ($count === 0)
                        <?php $count++; ?>
                        <td class="data-point" rowspan="{{ count($rows[$rating]) }}">{{ $rating }}</td>
                    @endif
                    <td class="data-point" style="background-color: {{ $ratingColors[$report['points']] }}; font-weight: bold;">
                        <a href="{{ $report['link'] }}">
                            <div class="{{ $meterClass }}">
                                <span style="width: {{ $meterWidth }}%">{{ $report['points'] }}</span>
                            </div>
                        </a>
                    </td>
                    <td>
                        <a href="{{ $report['link'] }}">
                            <div style="margin-left: 5px">{{ $report['center'] }}</div>
                        </a>
                    </td>
                </tr>
            @endforeach
        @endforeach
        </tbody>
    </table>
</div>
