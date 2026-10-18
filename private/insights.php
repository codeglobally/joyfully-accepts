<?php
// Numbers for the admin Insights tab. Everything is worked out from this
// site's own database; nothing is sent anywhere.

// The RSVP funnel: invited -> emailed -> visited -> replied.
function rsvp_funnel(array $guests): array
{
    // Keys are phrase names: admin.funnel.invitations and so on.
    $steps = ['invitations' => count($guests), 'emailed' => 0, 'visited' => 0, 'replied' => 0];
    $split = ['yes' => 0, 'no' => 0, 'maybe' => 0];
    foreach ($guests as $g) {
        $steps['emailed'] += $g['invited_at'] ? 1 : 0;
        $steps['visited'] += $g['first_visit_at'] ? 1 : 0;
        $status = rsvp_status($g);
        if ($status !== 'waiting') {
            $steps['replied']++;
            $split[$status]++;
        }
    }
    return ['steps' => $steps, 'split' => $split];
}

// How many people an invitation could bring: named people, or the "Family"
// size they gave (unknown sizes count as 1 and are flagged).
function party_count(array $guest): array
{
    $members = party_members($guest['name']);
    if ($members) {
        return [count($members), true];
    }
    $size = (int)($guest['party_size'] ?? 0);
    return [max(1, $size), $size > 0];
}

// Confirmed people plus the most who could still come.
function headcount(array $guests): array
{
    $h = ['confirmed' => 0, 'confirmed_exact' => true, 'maybe' => 0, 'no_reply' => 0,
          'plus_ones_offered' => 0, 'plus_ones_coming' => 0];
    foreach ($guests as $g) {
        $status = rsvp_status($g);
        $members = party_members($g['name']);
        $h['plus_ones_offered'] += in_array('Guest', $members, true) ? 1 : 0;
        if ($status === 'yes') {
            $who = attendees($g);
            $h['confirmed'] += $who['count'];
            $h['confirmed_exact'] = $h['confirmed_exact'] && $who['known'];
            $h['plus_ones_coming'] += in_array('Guest', $who['names'], true) ? 1 : 0;
        } elseif ($status === 'maybe') {
            $h['maybe'] += party_count($g)[0];
        } elseif ($status === 'waiting') {
            $h['no_reply'] += party_count($g)[0];
        }
    }
    return $h;
}

// Allergies and medical needs, as opposed to preferences like "Vegetarian".
function is_medical_need(string $need): bool
{
    return (bool)preg_match('/allerg|coeliac|celiac|intoleran|anaphyla|epipen/i', $need);
}

// One row per person coming who has a dietary requirement; medical first.
function catering_rows(array $guests): array
{
    $rows = [];
    foreach ($guests as $g) {
        if (rsvp_status($g) !== 'yes') {
            continue;
        }
        $who = attendees($g);
        foreach (dietary_list($g) as $entry) {
            if ($who['names'] && !in_array($entry['name'], $who['names'], true)) {
                continue;
            }
            $needs = array_values(array_filter(array_map('trim', explode(',', (string)($entry['needs'] ?? '')))));
            if (!$needs) {
                continue;
            }
            $person = $entry['name'] === 'Your party'
                ? ($who['known'] ? t('admin.ins.party_of', ['name' => $g['name'], 'count' => $who['count']]) : $g['name'])
                : $entry['name'];
            $rows[] = [
                'person' => $person,
                'invitation' => $g['name'],
                'needs' => $needs,
                'medical' => array_values(array_filter($needs, 'is_medical_need')),
            ];
        }
    }
    usort($rows, fn($a, $b) => [!$a['medical'], $a['person']] <=> [!$b['medical'], $b['person']]);
    return $rows;
}

// Total replies by day, plus the days invitations and updates went out.
function reply_timeline(array $guests): array
{
    $replies = [];
    $sends = [];
    foreach ($guests as $g) {
        if ($g['first_replied_at']) {
            $day = substr($g['first_replied_at'], 0, 10);
            $replies[$day] = ($replies[$day] ?? 0) + 1;
        }
        if ($g['invited_at']) {
            $day = substr($g['invited_at'], 0, 10);
            $sends[$day]['invitations'] = ($sends[$day]['invitations'] ?? 0) + 1;
        }
    }
    // notify_db(), not db(): on a new site it creates the table first.
    foreach (notify_db()->query("SELECT sent_at, subject, sent FROM notifications WHERE sent > 0")->fetchAll() as $n) {
        $sends[substr($n['sent_at'], 0, 10)]['update: ' . $n['subject']] = (int)$n['sent'];
    }
    $days = array_keys($replies + $sends);
    if (!$days) {
        return ['days' => [], 'sends' => []];
    }
    sort($days);
    $start = new DateTime($days[0]);
    $end = new DateTime(max(end($days), gmdate('Y-m-d')));
    $series = [];
    $total = 0;
    for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
        $key = $d->format('Y-m-d');
        $total += $replies[$key] ?? 0;
        $series[$key] = ['total' => $total, 'new' => $replies[$key] ?? 0];
    }
    return ['days' => $series, 'sends' => $sends];
}

// The timeline as an SVG line chart: one series (total replies), markers for
// sends, and an invisible hover band per day carrying a tooltip.
function reply_timeline_svg(array $timeline, int $invitations): string
{
    $days = $timeline['days'];
    $n = count($days);
    $w = 720; $h = 240; $left = 40; $right = 16; $top = 16; $bottom = 32;
    $plotW = $w - $left - $right;
    $plotH = $h - $top - $bottom;
    $max = max(1, $invitations, max(array_column($days, 'total')));
    $x = fn($i) => $left + ($n > 1 ? $i / ($n - 1) : 0.5) * $plotW;
    $y = fn($v) => $top + $plotH - $v / $max * $plotH;
    $keys = array_keys($days);

    $svg = '<svg class="timeline" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" '
        . 'aria-label="' . th('admin.ins.chart_label') . '">';
    // Recessive grid: 0, half and the number of invitations.
    foreach ([0, (int)round($max / 2), $max] as $tick) {
        $svg .= '<line class="grid" x1="' . $left . '" x2="' . ($w - $right) . '" y1="' . $y($tick) . '" y2="' . $y($tick) . '"/>'
            . '<text class="axis" x="' . ($left - 8) . '" y="' . ($y($tick) + 4) . '" text-anchor="end">' . $tick . '</text>';
    }
    // Send markers (behind the line).
    foreach ($timeline['sends'] as $day => $what) {
        $i = array_search($day, $keys, true);
        if ($i === false) {
            continue;
        }
        $svg .= '<line class="send" x1="' . $x($i) . '" x2="' . $x($i) . '" y1="' . $top . '" y2="' . ($top + $plotH) . '">'
            . '<title>' . h(admin_date(strtotime($day)) . ': ' . sends_text($what)) . '</title></line>';
    }
    // The line.
    $points = [];
    foreach ($keys as $i => $day) {
        $points[] = round($x($i), 1) . ',' . round($y($days[$day]['total']), 1);
    }
    $svg .= '<polyline class="line" points="' . implode(' ', $points) . '"/>';
    $last = end($keys);
    $svg .= '<circle class="dot" cx="' . $x($n - 1) . '" cy="' . $y($days[$last]['total']) . '" r="4"/>';
    // Hover bands with tooltips.
    $band = $n > 1 ? $plotW / ($n - 1) : $plotW;
    foreach ($keys as $i => $day) {
        $tip = t('admin.ins.tip_replied', ['date' => admin_date(strtotime($day)), 'count' => number_text($days[$day]['total'])])
            . ($days[$day]['new'] ? ' ' . t('admin.ins.tip_new', ['count' => number_text($days[$day]['new'])]) : '');
        $svg .= '<rect class="hover" x="' . round($x($i) - $band / 2, 1) . '" y="' . $top . '" width="' . round($band, 1)
            . '" height="' . $plotH . '"><title>' . h($tip) . '</title></rect>';
    }
    // First and last dates on the x axis.
    $svg .= '<text class="axis" x="' . $left . '" y="' . ($h - 8) . '">' . h(admin_date(strtotime($keys[0]))) . '</text>'
        . '<text class="axis" x="' . ($w - $right) . '" y="' . ($h - 8) . '" text-anchor="end">' . h(admin_date(strtotime($last))) . '</text>';
    return $svg . '</svg>';
}
