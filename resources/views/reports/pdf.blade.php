<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>AASRAA M&E performance report</title>
    <style>
        @page { background: #fff; margin: 30px 34px 34px; }
        * { box-sizing: border-box; }
        html, body { background: #fff; }
        body { margin: 0; color: #172033; font-family: "DejaVu Sans", sans-serif; font-size: 9px; line-height: 1.35; }
        .header { border-bottom: 2px solid #0f9d8a; margin-bottom: 14px; padding-bottom: 10px; }
        .logo { height: 42px; width: auto; }
        .title { float: right; text-align: right; width: 68%; }
        .title h1 { font-size: 18px; margin: 0 0 4px; }
        .title p { color: #596579; margin: 1px 0; }
        .clear { clear: both; }
        .summary { margin: 0 0 12px; width: 100%; }
        .summary td { background: #f3f7f8; border: 1px solid #dce6e8; padding: 7px 8px; width: 25%; }
        .summary strong { display: block; font-size: 14px; margin-top: 2px; }
        .filters { color: #596579; font-size: 8px; margin-bottom: 12px; }
        .section { margin: 0 0 13px; }
        table.results { border-collapse: collapse; table-layout: fixed; width: 100%; }
        table.results th { background: #f3f5f7; color: #536174; font-size: 7.5px; padding: 5px; text-align: left; text-transform: uppercase; }
        table.results th.project-heading { background: #17324d; color: #fff; font-size: 11px; padding: 7px 9px; text-transform: none; }
        table.results td { border-bottom: 1px solid #dfe5ea; padding: 6px 5px; vertical-align: top; word-wrap: break-word; }
        table.results td.category-heading { background: #e8f7f4; color: #087c6e; font-size: 9px; font-weight: bold; letter-spacing: .04em; padding: 5px 7px; text-transform: uppercase; }
        table.results tr { page-break-inside: avoid; }
        .indicator { font-weight: bold; }
        .muted { color: #6c7888; font-size: 7.5px; }
        .status { border-radius: 8px; display: inline-block; font-size: 7.5px; font-weight: bold; padding: 2px 6px; }
        .on-track { background: #dff6eb; color: #087a50; }
        .attention { background: #fff0cf; color: #9a6100; }
        .overachiever { background: #dcecff; color: #195ea7; }
        .not-assessed { background: #edf0f3; color: #596579; }
        .footer-note { color: #6c7888; font-size: 7.5px; margin-top: 10px; }
        .provisional { background: #fff0cf; color: #8a5700; display: inline-block; font-size: 8px; font-weight: bold; margin-top: 4px; padding: 2px 6px; }
    </style>
</head>
<body>
    <div class="header">
        @if($logo)<img src="{{ $logo }}" alt="AASRAA M&E" class="logo">@endif
        <div class="title">
            <h1>Target and approved performance report</h1>
            <p>{{ $period['start']->format('d M Y') }} to {{ $period['end']->format('d M Y') }}</p>
            <p>Performance calculated through {{ $period['data_end']->format('d M Y') }} | Generated {{ $filters['generated_at'] }}</p>
            @if($period['provisional'])<span class="provisional">PROVISIONAL - REPORTING PERIOD OPEN</span>@endif
        </div>
        <div class="clear"></div>
    </div>

    <table class="summary">
        <tr>
            <td>Expected<strong>{{ number_format($completeness['expected']) }}</strong></td>
            <td>Reported<strong>{{ number_format($completeness['reported']) }}</strong></td>
            <td>Approved<strong>{{ number_format($completeness['approved']) }}</strong></td>
            <td>Missing<strong>{{ number_format($completeness['missing']) }}</strong></td>
        </tr>
    </table>

    <div class="filters">
        {{ $filters['mode'] }} | {{ $filters['programme'] }} | {{ $filters['project'] }} | {{ $filters['category'] }} | {{ $filters['donor'] }} | {{ $filters['performance'] }}
    </div>

    @if($rows->isNotEmpty())
        <table class="results">
            <colgroup>
                <col style="width:26%">
                <col style="width:10%">
                <col style="width:9%">
                <col style="width:9%">
                <col style="width:7%">
                <col style="width:7%">
                <col style="width:10%">
                <col style="width:9%">
                <col style="width:13%">
            </colgroup>
            <thead>
                <tr><th>Indicator</th><th>Donor</th><th>Target</th><th>Achievement</th><th>Difference</th><th>Variance</th><th>Performance</th><th>Coverage</th><th>Explanation</th></tr>
            </thead>
            <tbody>
                @foreach($rows->groupBy(fn (array $row): string => $row['project']->name.'|'.($row['centre']?->name ?? (($row['scope'] ?? 'project') === 'programme' ? 'Programme-level indicators' : 'Consolidated programme summary'))) as $groupRows)
                    @php($first = $groupRows->first())
                    <tr><th colspan="9" class="project-heading">{{ $first['project']->name }} - {{ $first['centre']?->name ?? (($first['scope'] ?? 'project') === 'programme' ? 'Programme-level indicators' : 'Consolidated programme summary') }}</th></tr>
                    @foreach($groupRows->groupBy(fn (array $row): string => $row['indicator']->category) as $category => $categoryRows)
                        <tr><td colspan="9" class="category-heading">{{ $category }}</td></tr>
                        @foreach($categoryRows as $row)
                            @php($performanceClass = match($row['performance_status']) { 'On track' => 'on-track', 'Needs attention' => 'attention', 'Overachiever' => 'overachiever', default => 'not-assessed' })
                            <tr>
                                <td><span class="indicator">{{ $row['indicator']->name }}</span><br><span class="muted">{{ $row['indicator']->code }} | {{ $row['indicator']->unit }}</span></td>
                                <td>{{ $row['donor_display'] }}</td>
                                <td>{{ $row['target_display'] }}</td>
                                <td>{{ is_array($row['achievement']) ? collect($row['achievement'])->map(fn ($count, $status) => "$status: $count")->join(', ') : ($row['achievement'] === null ? 'No approved result' : number_format($row['achievement'], $row['indicator']->precision)) }}</td>
                                <td>{{ $row['variance'] === null ? '-' : number_format($row['variance'], $row['indicator']->precision) }}</td>
                                <td>{{ $row['achievement_percentage'] === null ? 'N/A' : (($row['achievement_percentage'] > 0 ? '+' : '').number_format($row['achievement_percentage'], 1).'%') }}</td>
                                <td><span class="status {{ $performanceClass }}">{{ $row['performance_status'] }}</span></td>
                                <td>{{ $row['approved_months'] }}/{{ $row['expected_months'] }} months</td>
                                <td>{{ $row['variance_explanations']->join(' | ') ?: '-' }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                @endforeach
            </tbody>
        </table>
    @else
        <p>No target-bearing indicators match the selected filters and reporting period.</p>
    @endif

    <p class="footer-note">Only final-approved revisions are used. A pending amendment does not replace its previously approved result until the amendment completes both approval stages.</p>
</body>
</html>
