@php
foreach ($runs as $run) {
    if ($run['bold']) { echo '<strong>'; }
    if ($run['italic']) { echo '<em>'; }
    if ($run['href']) {
        $external = !str_starts_with($run['href'], '/') && !str_starts_with($run['href'], '#');
        echo '<a href="'.e($run['href']).'"'.($external ? ' target="_blank"' : '').' rel="noopener noreferrer">';
    }
    echo e($run['text']);
    if ($run['href']) { echo '</a>'; }
    if ($run['italic']) { echo '</em>'; }
    if ($run['bold']) { echo '</strong>'; }
}
@endphp
