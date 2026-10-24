@props(['period', 'autoSubmit' => false, 'types' => null])

@php
    $financialYearStartMonth = (int) config('impact.financial_year_start_month', 4);
    $today = \Carbon\CarbonImmutable::now()->startOfMonth();
    $currentFinancialYearStart = $today->month < $financialYearStartMonth
        ? $today->setDate($today->year - 1, $financialYearStartMonth, 1)
        : $today->setDate($today->year, $financialYearStartMonth, 1);
    $financialYears = collect(range(0, 4))->map(fn (int $offset) => $currentFinancialYearStart->subYears($offset));
    $selectedPeriodStart = $period['start']->format('Y-m');
    $periodTypes = $types ?? ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'half_yearly' => 'Half-yearly', 'annual' => 'Annual', 'ytd' => 'Year to date', 'custom' => 'Custom dates'];
@endphp

<div x-data="{ periodType: @js($period['type']) }" class="contents">
    <div>
        <label for="period_type">Reporting period</label>
        <select id="period_type" name="period_type" x-model="periodType" @if($autoSubmit) @change="$nextTick(() => $el.form.submit())" @endif class="mt-1 w-full">
            @foreach($periodTypes as $value => $label)
                <option value="{{ $value }}" @selected($period['type'] === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    @if(array_key_exists('monthly', $periodTypes))
    <div x-show="periodType === 'monthly'">
        <label for="monthly_anchor">Month</label>
        <input id="monthly_anchor" name="anchor" type="month" value="{{ $period['type'] === 'monthly' ? $selectedPeriodStart : $today->format('Y-m') }}" max="{{ $today->format('Y-m') }}" :disabled="periodType !== 'monthly'" @disabled($period['type'] !== 'monthly') @if($autoSubmit) onchange="this.form.submit()" @endif class="mt-1 w-full">
    </div>
    @endif

    @if(array_key_exists('quarterly', $periodTypes))
    <div x-cloak x-show="periodType === 'quarterly'">
        <label for="quarterly_anchor">Quarter</label>
        <select id="quarterly_anchor" name="anchor" :disabled="periodType !== 'quarterly'" @disabled($period['type'] !== 'quarterly') @if($autoSubmit) onchange="this.form.submit()" @endif class="mt-1 w-full">
            @foreach($financialYears as $financialYearStart)
                @php($availableQuarters = $financialYearStart->equalTo($currentFinancialYearStart) ? min(4, intdiv((int) $currentFinancialYearStart->diffInMonths($today), 3) + 1) : 4)
                @foreach(array_reverse(range(1, $availableQuarters)) as $quarter)
                    @php($quarterStart = $financialYearStart->addMonths(($quarter - 1) * 3))
                    <option value="{{ $quarterStart->format('Y-m') }}" @selected($period['type'] === 'quarterly' && $selectedPeriodStart === $quarterStart->format('Y-m'))>FY {{ $financialYearStart->format('Y') }}–{{ $financialYearStart->addYear()->format('y') }} · Q{{ $quarter }} ({{ $quarterStart->format('M') }}–{{ $quarterStart->addMonths(2)->format('M') }})</option>
                @endforeach
            @endforeach
        </select>
    </div>
    @endif

    @if(array_key_exists('half_yearly', $periodTypes))
    <div x-cloak x-show="periodType === 'half_yearly'">
        <label for="half_yearly_anchor">Half-year</label>
        <select id="half_yearly_anchor" name="anchor" :disabled="periodType !== 'half_yearly'" @disabled($period['type'] !== 'half_yearly') @if($autoSubmit) onchange="this.form.submit()" @endif class="mt-1 w-full">
            @foreach($financialYears as $financialYearStart)
                @php($availableHalves = $financialYearStart->equalTo($currentFinancialYearStart) ? min(2, intdiv((int) $currentFinancialYearStart->diffInMonths($today), 6) + 1) : 2)
                @foreach(array_reverse(range(1, $availableHalves)) as $half)
                    @php($halfStart = $financialYearStart->addMonths(($half - 1) * 6))
                    <option value="{{ $halfStart->format('Y-m') }}" @selected($period['type'] === 'half_yearly' && $selectedPeriodStart === $halfStart->format('Y-m'))>FY {{ $financialYearStart->format('Y') }}–{{ $financialYearStart->addYear()->format('y') }} · H{{ $half }} ({{ $halfStart->format('M') }}–{{ $halfStart->addMonths(5)->format('M') }})</option>
                @endforeach
            @endforeach
        </select>
    </div>
    @endif

    @if(array_key_exists('annual', $periodTypes))
    <div x-cloak x-show="periodType === 'annual'">
        <label for="annual_anchor">Financial year</label>
        <select id="annual_anchor" name="anchor" :disabled="periodType !== 'annual'" @disabled($period['type'] !== 'annual') @if($autoSubmit) onchange="this.form.submit()" @endif class="mt-1 w-full">
            @foreach($financialYears as $financialYearStart)
                <option value="{{ $financialYearStart->format('Y-m') }}" @selected($period['type'] === 'annual' && $selectedPeriodStart === $financialYearStart->format('Y-m'))>FY {{ $financialYearStart->format('Y') }}–{{ $financialYearStart->addYear()->format('y') }}</option>
            @endforeach
        </select>
    </div>
    @endif

    @if(array_key_exists('ytd', $periodTypes))
    <input type="hidden" name="anchor" value="{{ $period['end']->format('Y-m') }}" :disabled="periodType !== 'ytd'" @disabled($period['type'] !== 'ytd')>
    @endif

    @if(array_key_exists('custom', $periodTypes))
    <div x-cloak x-show="periodType === 'custom'">
        <label for="start">Custom from</label>
        <input id="start" name="start" type="date" value="{{ request('start', $period['start']->format('Y-m-d')) }}" :disabled="periodType !== 'custom'" @disabled($period['type'] !== 'custom') class="mt-1 w-full">
    </div>
    <div x-cloak x-show="periodType === 'custom'">
        <label for="end">Custom to</label>
        <input id="end" name="end" type="date" value="{{ request('end', $period['end']->format('Y-m-d')) }}" :disabled="periodType !== 'custom'" @disabled($period['type'] !== 'custom') class="mt-1 w-full">
    </div>
    @if($autoSubmit)
        <div x-cloak x-show="periodType === 'custom'" class="flex items-end"><button class="btn-secondary">Update dates</button></div>
    @endif
    @endif
</div>
