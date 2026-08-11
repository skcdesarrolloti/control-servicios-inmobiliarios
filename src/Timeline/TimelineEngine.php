<?php


namespace SCM\Timeline;

use SCM\Support\TimestampParser;

final class TimelineEngine
{
    private TimestampParser $parser;

    public function __construct(TimestampParser $parser)
    {
        $this->parser = $parser;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,array<string,mixed>> $definitions
     * @return array<int,array<string,mixed>>
     */
    public function build(array $row, array $definitions): array
    {
        $steps = [];

        foreach ($definitions as $definition) {
            $visible = true;
            if (isset($definition['visible_when']) && is_callable($definition['visible_when'])) {
                $visible = (bool) call_user_func($definition['visible_when'], $row, $this->parser);
            }

            if (!$visible) {
                continue;
            }

            $resolved = [
                'status' => 'none',
                'ts' => 0,
                'sub' => '',
            ];

            if (isset($definition['resolver']) && is_callable($definition['resolver'])) {
                $data = call_user_func($definition['resolver'], $row, $this->parser);
                if (is_array($data)) {
                    $resolved = array_merge($resolved, $data);
                }
            }

            $status = (string) ($resolved['status'] ?? 'none');
            if (!in_array($status, ['done', 'pending', 'none'], true)) {
                $status = 'none';
            }

            $steps[] = [
                'key' => (string) ($definition['key'] ?? ''),
                'label' => (string) ($definition['label'] ?? ''),
                'icon' => (string) ($definition['icon'] ?? ''),
                'color' => (string) ($definition['color'] ?? 'slate'),
                'source' => (string) ($definition['source'] ?? ''),
                'empty_text' => (string) ($definition['empty_text'] ?? 'Sin dato'),
                'status' => $status,
                'ts' => (int) ($resolved['ts'] ?? 0),
                'sub' => trim((string) ($resolved['sub'] ?? '')),
            ];
        }

        return $this->decorateDurations($steps);
    }

    /**
     * @param array<int,array<string,mixed>> $steps
     * @return array<string,mixed>
     */
    public function summarize(array $steps): array
    {
        if (empty($steps)) {
            return [
                'total_seconds' => 0,
                'total_label' => '-',
                'current_stage_label' => '-',
                'current_stage_status' => 'none',
                'current_stage_seconds' => 0,
                'current_stage_time_label' => '-',
                'is_completed' => 0,
            ];
        }

        $now = time();
        $firstDoneTs = 0;
        $lastDoneTs = 0;
        $lastDoneIndex = null;
        $firstNotDoneIndex = null;
        $pendingIndex = null;

        foreach ($steps as $index => $step) {
            $status = (string) ($step['status'] ?? 'none');
            $ts = (int) ($step['ts'] ?? 0);

            if ($firstNotDoneIndex === null && $status !== 'done') {
                $firstNotDoneIndex = $index;
            }

            if ($pendingIndex === null && $status === 'pending') {
                $pendingIndex = $index;
            }

            if ($status !== 'done' || $ts <= 0) {
                continue;
            }

            if ($firstDoneTs <= 0) {
                $firstDoneTs = $ts;
            }

            $lastDoneTs = $ts;
            $lastDoneIndex = $index;
        }

        if ($pendingIndex === null) {
            $pendingIndex = $firstNotDoneIndex;
        }

        $lastStep = $steps[count($steps) - 1];
        $isCompleted = ((string) ($lastStep['status'] ?? 'none') === 'done') ? 1 : 0;

        $totalSeconds = 0;
        if ($firstDoneTs > 0) {
            if ($isCompleted === 1) {
                $lastTs = (int) ($lastStep['ts'] ?? 0);
                if ($lastTs <= 0) {
                    $lastTs = $lastDoneTs;
                }
                $totalSeconds = max(0, $lastTs - $firstDoneTs);
            } else {
                $totalSeconds = max(0, $now - $firstDoneTs);
            }
        }

        $totalLabel = $firstDoneTs > 0 ? $this->formatDuration($totalSeconds) : '-';
        if ($isCompleted === 0 && $totalLabel !== '-') {
            $totalLabel .= ' (en curso)';
        }

        if ($pendingIndex !== null && isset($steps[$pendingIndex])) {
            $pendingStep = $steps[$pendingIndex];
            $currentLabel = trim((string) ($pendingStep['label'] ?? ''));
            $currentStatus = (string) ($pendingStep['status'] ?? 'none');
            if ($currentLabel === '') {
                $currentLabel = '-';
            }

            $currentSeconds = 0;
            if (isset($pendingStep['pending_seconds']) && is_numeric($pendingStep['pending_seconds'])) {
                $currentSeconds = max(0, (int) $pendingStep['pending_seconds']);
            } elseif ($lastDoneTs > 0) {
                $currentSeconds = max(0, $now - $lastDoneTs);
            }

            $currentTimeLabel = $currentSeconds > 0 ? $this->formatDuration($currentSeconds) : '-';

            return [
                'total_seconds' => $totalSeconds,
                'total_label' => $totalLabel,
                'current_stage_label' => $currentLabel,
                'current_stage_status' => $currentStatus,
                'current_stage_seconds' => $currentSeconds,
                'current_stage_time_label' => $currentTimeLabel,
                'is_completed' => $isCompleted,
            ];
        }

        $finalLabel = trim((string) ($lastStep['label'] ?? 'Finalizado'));
        if ($finalLabel === '') {
            $finalLabel = 'Finalizado';
        }

        return [
            'total_seconds' => $totalSeconds,
            'total_label' => $totalLabel,
            'current_stage_label' => $finalLabel,
            'current_stage_status' => 'done',
            'current_stage_seconds' => 0,
            'current_stage_time_label' => 'Completado',
            'is_completed' => $isCompleted,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $steps
     * @return array<int,array<string,mixed>>
     */
    private function decorateDurations(array $steps): array
    {
        if (empty($steps)) {
            return $steps;
        }

        $lastDoneIndex = null;
        $lastDoneTs = 0;

        foreach ($steps as $index => $step) {
            $status = (string) ($step['status'] ?? 'none');
            $ts = (int) ($step['ts'] ?? 0);
            if ($status !== 'done' || $ts <= 0) {
                continue;
            }

            if ($lastDoneIndex !== null && $lastDoneTs > 0) {
                $delta = max(0, $ts - $lastDoneTs);
                $steps[$index]['elapsed_seconds'] = $delta;
                $steps[$index]['elapsed_label'] = $this->formatDuration($delta);
                $steps[$index]['elapsed_from'] = (string) ($steps[$lastDoneIndex]['label'] ?? '');
            }

            $lastDoneIndex = $index;
            $lastDoneTs = $ts;
        }

        if ($lastDoneIndex !== null && $lastDoneTs > 0) {
            $pendingIndex = $lastDoneIndex + 1;
            if (isset($steps[$pendingIndex])) {
                $pendingStatus = (string) ($steps[$pendingIndex]['status'] ?? 'none');
                $pendingTs = (int) ($steps[$pendingIndex]['ts'] ?? 0);
                if ($pendingStatus !== 'done' || $pendingTs <= 0) {
                    if ($pendingStatus === 'none') {
                        $steps[$pendingIndex]['status'] = 'pending';
                    }
                    $pendingSeconds = max(0, time() - $lastDoneTs);
                    $steps[$pendingIndex]['pending_seconds'] = $pendingSeconds;
                    $steps[$pendingIndex]['pending_label'] = $this->formatDuration($pendingSeconds);
                    $steps[$pendingIndex]['pending_from'] = (string) ($steps[$lastDoneIndex]['label'] ?? '');
                }
            }
        }

        return $steps;
    }

    private function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . 'd';
        }
        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }
        if ($minutes > 0) {
            $parts[] = $minutes . 'm';
        }

        if (empty($parts)) {
            $parts[] = ($seconds % 60) . 's';
        }

        return implode(' ', $parts);
    }
}
