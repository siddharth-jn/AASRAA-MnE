@props(['period'])
<div><label for="period_type">Reporting period</label><select id="period_type" name="period_type" class="mt-1 w-full">@foreach(['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'half_yearly' => 'Half-yearly', 'annual' => 'Annual', 'ytd' => 'Year to date', 'custom' => 'Custom dates'] as $value => $label)<option value="{{ $value }}" @selected($period['type'] === $value)>{{ $label }}</option>@endforeach</select></div>
<div><label for="anchor">Period containing</label><input id="anchor" name="anchor" type="month" value="{{ request('anchor', '2026-09') }}" class="mt-1 w-full"></div>
<div><label for="start">Custom from</label><input id="start" name="start" type="date" value="{{ request('start', $period['start']->format('Y-m-d')) }}" class="mt-1 w-full"></div>
<div><label for="end">Custom to</label><input id="end" name="end" type="date" value="{{ request('end', $period['end']->format('Y-m-d')) }}" class="mt-1 w-full"></div>
