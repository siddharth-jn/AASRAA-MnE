<?php

namespace App\Services;

use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;
use Illuminate\Support\Collection;

class ReportPdfService
{
    private const LEFT = 28.0;

    private const RIGHT = 814.0;

    private const BOTTOM = 552.0;

    private const ROW_LINE_HEIGHT = 7.4;

    private const ROW_SEGMENT_LINES = 42;

    /** @var list<float> */
    private const COLUMN_WIDTHS = [185.0, 65.0, 60.0, 70.0, 55.0, 55.0, 72.0, 65.0, 145.0];

    /** @param Collection<int, array<string, mixed>> $rows
     * @param  array<string, mixed>  $period
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $completeness
     */
    public function render(Collection $rows, array $period, array $filters, array $completeness): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', public_path());
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('a4', 'landscape');
        $canvas = $dompdf->getCanvas();
        $fonts = $dompdf->getFontMetrics();
        $font = $fonts->getFont('DejaVu Sans', 'normal');
        $bold = $fonts->getFont('DejaVu Sans', 'bold');

        $y = $this->drawPageHeader($canvas, $fonts, $font, $bold, $period, $filters, $completeness, true);
        $this->drawColumnHeader($canvas, $bold, $y);
        $y += 18;

        if ($rows->isEmpty()) {
            $canvas->text(self::LEFT, $y + 12, 'No target-bearing indicators match the selected filters and reporting period.', $font, 9, [0.25, 0.3, 0.36]);
        } else {
            $lastGroup = null;
            $lastCategory = null;

            foreach ($rows as $row) {
                $group = $row['project']->name.'|'.($row['centre']?->name ?? (($row['scope'] ?? 'project') === 'programme' ? 'Programme-level indicators' : 'Consolidated programme summary'));
                $category = (string) $row['indicator']->category;
                $cells = $this->resultCells($row, $fonts, $font, $bold);

                foreach ($this->resultSegments($cells, $row) as $segment) {
                    $rowHeight = $this->rowHeight($segment);
                    $requiredHeight = ($group !== $lastGroup ? 17 : 0)
                        + ($category !== $lastCategory || $group !== $lastGroup ? 14 : 0)
                        + $rowHeight;

                    if ($y + $requiredHeight > self::BOTTOM) {
                        $canvas->new_page();
                        $y = $this->drawPageHeader($canvas, $fonts, $font, $bold, $period, $filters, $completeness, false);
                        $this->drawColumnHeader($canvas, $bold, $y);
                        $y += 18;
                        $lastGroup = null;
                        $lastCategory = null;
                    }

                    if ($group !== $lastGroup) {
                        $groupLabel = $row['project']->name.' - '.($row['centre']?->name ?? (($row['scope'] ?? 'project') === 'programme' ? 'Programme-level indicators' : 'Consolidated programme summary'));
                        $canvas->filled_rectangle(self::LEFT, $y, self::RIGHT - self::LEFT, 17, [0.09, 0.2, 0.3]);
                        $canvas->text(self::LEFT + 6, $y + 4, $this->fit($groupLabel, self::RIGHT - self::LEFT - 12, $bold, 8.5, $fonts), $bold, 8.5, [1, 1, 1]);
                        $y += 17;
                        $lastGroup = $group;
                        $lastCategory = null;
                    }

                    if ($category !== $lastCategory) {
                        $canvas->filled_rectangle(self::LEFT, $y, self::RIGHT - self::LEFT, 14, [0.91, 0.97, 0.96]);
                        $canvas->text(self::LEFT + 6, $y + 3, strtoupper($category), $bold, 7.5, [0.03, 0.45, 0.4]);
                        $y += 14;
                        $lastCategory = $category;
                    }

                    $this->drawResultRow($canvas, $font, $bold, $segment, $y, $rowHeight);
                    $y += $rowHeight;
                }
            }
        }

        $canvas->page_line(self::LEFT, 562, self::RIGHT, 562, [0.82, 0.85, 0.88], 0.5);
        $canvas->page_text(self::LEFT, 568, 'Only final-approved revisions are included. Pending amendments do not replace official results.', $font, 6.5, [0.38, 0.43, 0.48]);
        $canvas->page_text(744, 568, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, [0.35, 0.4, 0.45]);

        return $canvas->output();
    }

    /** @param array<string, mixed> $period
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $completeness
     */
    private function drawPageHeader(Canvas $canvas, FontMetrics $fonts, string $font, string $bold, array $period, array $filters, array $completeness, bool $includeSummary): float
    {
        $logoPath = public_path('images/mne-brand.svg');
        if (is_file($logoPath)) {
            $canvas->image($logoPath, self::LEFT, 18, 132, 34);
        }

        $canvas->text(470, 18, 'Target and approved performance report', $bold, 14, [0.09, 0.13, 0.2]);
        $periodText = $period['start']->format('d M Y').' to '.$period['end']->format('d M Y');
        $canvas->text(470, 37, $periodText, $font, 8, [0.35, 0.4, 0.48]);
        $canvas->text(470, 49, 'Calculated through '.$period['data_end']->format('d M Y').' | Generated '.$filters['generated_at'], $font, 7, [0.35, 0.4, 0.48]);
        if ($period['provisional'] ?? false) {
            $canvas->filled_rectangle(690, 35, 124, 13, [1, 0.94, 0.8]);
            $canvas->text(696, 38, 'PROVISIONAL - PERIOD OPEN', $bold, 6.5, [0.55, 0.34, 0]);
        }
        $canvas->line(self::LEFT, 59, self::RIGHT, 59, [0.06, 0.62, 0.54], 1.5);

        if (! $includeSummary) {
            return 68;
        }

        $boxWidth = 120;
        foreach ([
            ['Expected', $completeness['expected']],
            ['Reported', $completeness['reported']],
            ['Approved', $completeness['approved']],
            ['Missing', $completeness['missing']],
        ] as $index => [$label, $value]) {
            $x = self::LEFT + ($index * ($boxWidth + 8));
            $canvas->filled_rectangle($x, 68, $boxWidth, 30, [0.95, 0.97, 0.97]);
            $canvas->rectangle($x, 68, $boxWidth, 30, [0.86, 0.9, 0.91], 0.5);
            $canvas->text($x + 7, 73, $label, $font, 6.5, [0.35, 0.4, 0.48]);
            $canvas->text($x + 7, 82, number_format($value), $bold, 10, [0.09, 0.13, 0.2]);
        }

        $filterText = implode(' | ', [$filters['mode'], $filters['programme'], $filters['project'], $filters['category'], $filters['donor'], $filters['performance']]);
        $canvas->text(self::LEFT, 104, $this->fit($filterText, self::RIGHT - self::LEFT, $font, 6.5, $fonts), $font, 6.5, [0.35, 0.4, 0.48]);

        return 116;
    }

    private function drawColumnHeader(Canvas $canvas, string $bold, float $y): void
    {
        $canvas->filled_rectangle(self::LEFT, $y, self::RIGHT - self::LEFT, 18, [0.95, 0.96, 0.97]);
        $x = self::LEFT;
        foreach (['Indicator', 'Donor', 'Target', 'Achievement', 'Difference', 'Variance', 'Performance', 'Coverage', 'Explanation'] as $index => $label) {
            $canvas->text($x + 3, $y + 5, strtoupper($label), $bold, 6, [0.31, 0.37, 0.44]);
            $x += self::COLUMN_WIDTHS[$index];
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, list<string>>
     */
    private function resultCells(array $row, FontMetrics $fonts, string $font, string $bold): array
    {
        $achievement = is_array($row['achievement'])
            ? collect($row['achievement'])->map(fn ($count, $status): string => "{$status}: {$count}")->join(', ')
            : ($row['achievement'] === null ? 'No approved result' : number_format($row['achievement'], $row['indicator']->precision));
        $variance = $row['achievement_percentage'] === null
            ? 'N/A'
            : (($row['achievement_percentage'] > 0 ? '+' : '').number_format($row['achievement_percentage'], 1).'%');
        $values = [
            $row['indicator']->name."\n".$row['indicator']->code.' | '.$row['indicator']->unit,
            $row['donor_display'] ?? 'Not specified',
            $row['target_display'],
            $achievement,
            $row['variance'] === null ? '-' : number_format($row['variance'], $row['indicator']->precision),
            $variance,
            $row['performance_status'],
            $row['approved_months'].'/'.$row['expected_months'].' months',
            $row['variance_explanations']->join(' | ') ?: '-',
        ];

        return collect($values)->map(function (string $value, int $index) use ($fonts, $font, $bold): array {
            $width = self::COLUMN_WIDTHS[$index] - 7;
            $size = $index === 0 ? 6.5 : 6.1;
            $lines = collect(explode("\n", $value))
                ->flatMap(fn (string $line): array => $this->wrap($line, $width, $index === 0 ? $bold : $font, $size, $fonts))
                ->values()
                ->all();

            return $lines === [] ? ['-'] : $lines;
        })->all();
    }

    /**
     * @param  array<int, list<string>>  $cells
     * @param  array<string, mixed>  $row
     * @return list<array<int, list<string>>>
     */
    private function resultSegments(array $cells, array $row): array
    {
        $segmentCount = max(1, (int) ceil(max(array_map('count', $cells)) / self::ROW_SEGMENT_LINES));
        $segments = [];

        for ($segmentIndex = 0; $segmentIndex < $segmentCount; $segmentIndex++) {
            $offset = $segmentIndex * self::ROW_SEGMENT_LINES;
            $segment = collect($cells)
                ->map(fn (array $lines): array => array_slice($lines, $offset, self::ROW_SEGMENT_LINES))
                ->all();
            if ($segmentIndex > 0 && $segment[0] === []) {
                $segment[0] = [$row['indicator']->name.' (continued)'];
            }
            $segments[] = $segment;
        }

        return $segments;
    }

    /** @param array<int, list<string>> $cells */
    private function rowHeight(array $cells): float
    {
        return max(24.0, 8.0 + (max(array_map('count', $cells)) * self::ROW_LINE_HEIGHT));
    }

    /** @param array<int, list<string>> $cells */
    private function drawResultRow(Canvas $canvas, string $font, string $bold, array $cells, float $y, float $height): void
    {
        $canvas->line(self::LEFT, $y + $height, self::RIGHT, $y + $height, [0.87, 0.89, 0.91], 0.4);
        $x = self::LEFT;

        foreach ($cells as $index => $lines) {
            if ($index > 0) {
                $canvas->line($x, $y, $x, $y + $height, [0.93, 0.94, 0.95], 0.3);
            }
            foreach ($lines as $lineIndex => $line) {
                $isIndicatorName = $index === 0 && $lineIndex === 0;
                $cellFont = $isIndicatorName ? $bold : $font;
                $cellSize = $isIndicatorName ? 6.5 : 6.1;
                $color = $index === 0 && $lineIndex > 0 ? [0.4, 0.45, 0.51] : [0.12, 0.16, 0.22];
                $canvas->text($x + 3.5, $y + 4 + ($lineIndex * self::ROW_LINE_HEIGHT), $line, $cellFont, $cellSize, $color);
            }
            $x += self::COLUMN_WIDTHS[$index];
        }
    }

    /** @return list<string> */
    private function wrap(string $text, float $width, string $font, float $size, FontMetrics $fonts): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return ['-'];
        }

        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
            $candidate = $line === '' ? $word : "{$line} {$word}";
            if ($fonts->getTextWidth($candidate, $font, $size) <= $width) {
                $line = $candidate;

                continue;
            }

            if ($line !== '') {
                $lines[] = $line;
                $line = '';
            }

            while ($word !== '' && $fonts->getTextWidth($word, $font, $size) > $width) {
                $chunk = '';
                while ($word !== '') {
                    $next = $chunk.mb_substr($word, 0, 1);
                    if ($chunk !== '' && $fonts->getTextWidth($next, $font, $size) > $width) {
                        break;
                    }
                    $chunk = $next;
                    $word = mb_substr($word, 1);
                }
                $lines[] = $chunk;
            }
            $line = $word;
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    private function fit(string $text, float $width, string $font, float $size, FontMetrics $fonts): string
    {
        if ($fonts->getTextWidth($text, $font, $size) <= $width) {
            return $text;
        }

        $suffix = '...';
        while ($text !== '' && $fonts->getTextWidth($text.$suffix, $font, $size) > $width) {
            $text = mb_substr($text, 0, -1);
        }

        return $text.$suffix;
    }
}
