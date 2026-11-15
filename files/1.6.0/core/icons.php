<?php
/**
 * Small built-in icon set (24×24, stroke-based) so themes don't need an icon font.
 * Add your own by adding a key => SVG inner markup.
 */

function icon_set(): array
{
    return [
        'wrench'      => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.2L3.5 17.3a1.8 1.8 0 0 0 2.5 2.5l5.8-5.8a4 4 0 0 0 5.2-5.4l-2.6 2.6-2.4-.4-.4-2.4z"/>',
        'flame'       => '<path d="M12 3c.5 3-2.5 4.5-2.5 7.5a2.5 2.5 0 0 0 5 0c0-1 .5-1.8 1-2.5 1.8 1.5 3 3.6 3 6a6.5 6.5 0 0 1-13 0C5.5 9 10 7 12 3z"/>',
        'droplet'     => '<path d="M12 3.5s6 6.4 6 10.5a6 6 0 0 1-12 0c0-4.1 6-10.5 6-10.5z"/>',
        'shield'      => '<path d="M12 3l7.5 3v5.5c0 4.5-3.2 8.2-7.5 9.5-4.3-1.3-7.5-5-7.5-9.5V6z"/><path d="M8.8 12.2l2.2 2.2 4.2-4.4"/>',
        'clock'       => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'chat'        => '<path d="M4.5 6.5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H10l-4 3.5v-3.5h0a1.5 1.5 0 0 1-1.5-1.5z"/><path d="M8.5 9.5h7M8.5 12.5h4.5"/>',
        'star'        => '<path d="M12 3.5l2.6 5.4 5.9.8-4.3 4.1 1 5.8L12 16.8l-5.2 2.8 1-5.8-4.3-4.1 5.9-.8z"/>',
        'phone'       => '<path d="M5 4h3.5l1.6 4-2.2 1.4a11 11 0 0 0 4.7 4.7L14 11.9l4 1.6V17a2 2 0 0 1-2.2 2A14.5 14.5 0 0 1 3 6.2 2 2 0 0 1 5 4z"/>',
        'check'       => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'bolt'        => '<path d="M13 3L5 13.5h6L10 21l8-10.5h-6z"/>',
        'home'        => '<path d="M4 11l8-6.5 8 6.5"/><path d="M6 9.5V20h12V9.5"/><path d="M10 20v-5h4v5"/>',
        'thermometer' => '<path d="M14 14.8V5a2 2 0 0 0-4 0v9.8a4 4 0 1 0 4 0z"/><path d="M12 9v7"/>',
        'mail'        => '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="M4 7l8 6 8-6"/>',
        'pin'         => '<path d="M12 21s-6.5-6-6.5-11a6.5 6.5 0 0 1 13 0c0 5-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/>',
        'badge'       => '<circle cx="12" cy="9" r="5.5"/><path d="M8.5 13.5L7.5 21l4.5-2.5 4.5 2.5-1-7.5"/>',
        'pound'       => '<path d="M16.5 6.5A3.5 3.5 0 0 0 10 8v4.5c0 3-1 5-3 6.5h10.5"/><path d="M7.5 12.5h7"/>',
        'shower'      => '<path d="M5 20V8a4 4 0 0 1 8 0"/><path d="M10 9h6"/><path d="M11 13v1M13.5 13v1M16 13v1M11.5 16.5v1M14 16.5v1M16.5 16.5v1"/>',
        'radiator'    => '<rect x="3.5" y="6" width="17" height="11" rx="1.5"/><path d="M7.5 6v11M12 6v11M16.5 6v11M5 17v2M19 17v2"/>',
        'calendar'    => '<rect x="4" y="5.5" width="16" height="14.5" rx="2"/><path d="M4 10h16M8.5 3.5v4M15.5 3.5v4"/>',
        'users'       => '<circle cx="9" cy="8.5" r="3.2"/><path d="M3 19.5a6 6 0 0 1 12 0"/><path d="M15.5 5.6a3 3 0 0 1 0 5.8M17.5 14.2a6 6 0 0 1 3.5 5.3"/>',
        'leaf'        => '<path d="M5 19c0-8 5-13 15-14-1 10-6 15-14 15"/><path d="M5 19l7-7"/>',
        'hammer'      => '<path d="M13.5 6.5l4 4"/><path d="M11 4.5l3-1 4.5 4.5-1 3-2-2-8.5 8.5a1.8 1.8 0 0 1-2.5-2.5L13 8.5z"/>',
        'brush'       => '<path d="M19.5 4.5l-8 8"/><path d="M11.5 12.5c-2.5-.5-4.5 1-4.5 3.5 0 1.5-1 2.5-2.5 2.5 1.5 1.5 3.5 2 5.5 1.5 2.5-.5 3.5-3 3-5z"/>',
        'arrow'       => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'car'         => '<path d="M4 15.5V12l2-4.5A2 2 0 0 1 7.8 6.3h8.4A2 2 0 0 1 18 7.5l2 4.5v3.5a1 1 0 0 1-1 1h-1"/><path d="M6 16.5H5a1 1 0 0 1-1-1"/><path d="M4.5 12h15"/><circle cx="7.5" cy="16.5" r="1.8"/><circle cx="16.5" cy="16.5" r="1.8"/><path d="M9.3 16.5h5.4"/>',
        'van'         => '<path d="M3.5 16V7a1.5 1.5 0 0 1 1.5-1.5h9.5V16"/><path d="M14.5 9h3.2a1.5 1.5 0 0 1 1.3.8l1.5 3V16a.5.5 0 0 1-.5.5H19"/><path d="M14.5 12.5h6"/><circle cx="7" cy="16.8" r="1.8"/><circle cx="16.8" cy="16.8" r="1.8"/><path d="M8.8 16.8h6.2"/><path d="M3.5 16.5h1.7"/>',
        'gauge'       => '<path d="M4.5 17a8.5 8.5 0 1 1 15 0"/><path d="M12 13.5l4-4"/><circle cx="12" cy="14" r="1.3"/>',
        'fuel'        => '<path d="M5 20V5.5A1.5 1.5 0 0 1 6.5 4h6A1.5 1.5 0 0 1 14 5.5V20"/><path d="M4 20h11"/><path d="M7 8h5"/><path d="M14 10h2a1.5 1.5 0 0 1 1.5 1.5v4a1.5 1.5 0 0 0 3 0V9l-3-3"/>',
        'gear'        => '<circle cx="6" cy="6" r="1.8"/><circle cx="12" cy="6" r="1.8"/><circle cx="18" cy="6" r="1.8"/><circle cx="6" cy="18" r="1.8"/><circle cx="12" cy="18" r="1.8"/><path d="M6 8v8M12 8v8M18 8v4H6"/>',
        'pen'         => '<path d="M15.5 4.5l4 4L9 19H5v-4z"/><path d="M13 7l4 4"/>',
        'user'        => '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>',
        'tag'         => '<path d="M3.5 12.5V4.5a1 1 0 0 1 1-1h8l8 8-9 9z"/><circle cx="8" cy="8" r="1.5"/>',
        'key'         => '<circle cx="8" cy="15" r="4"/><path d="M11 12l8.5-8.5M16 7l2.5 2.5M14 9l2 2"/>',
        'plug'        => '<path d="M9 3.5v4M15 3.5v4"/><path d="M6.5 7.5h11V11a5.5 5.5 0 0 1-11 0z"/><path d="M12 16.5v4"/>',
        'quote'       => '<path d="M5 17.5c0-4 1.5-7 5-9M14 17.5c0-4 1.5-7 5-9"/><path d="M5 17.5h4.5v-4H5zM14 17.5h4.5v-4H14z"/>',
        'plane'       => '<path d="M10.5 13.5L4 11l-1-2 1.5-.5 6 1.5L15 5.5a2 2 0 0 1 3 3L13.5 13l1.5 6-.5 1.5-2-1-2.5-6.5"/><path d="M7 16l-2.5-.5L4 17l2.5 1 1 2.5 1.5-.5L8.5 17.5"/>',
        'briefcase'   => '<rect x="3.5" y="7.5" width="17" height="12" rx="1.5"/><path d="M9 7.5V5.5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/><path d="M3.5 12.5h17"/>',
        'rings'       => '<circle cx="9" cy="14" r="5"/><circle cx="15" cy="14" r="5"/><path d="M13 5.5l2-2 2 2-2 2.5z"/>',
        'glass'       => '<path d="M7 3.5h10l-.6 5.5A4.5 4.5 0 0 1 12 13a4.5 4.5 0 0 1-4.4-4z"/><path d="M12 13v7M8.5 20.5h7"/>',
        'route'       => '<circle cx="6" cy="18" r="2"/><circle cx="18" cy="6" r="2"/><path d="M8 18h8.5a3 3 0 0 0 0-6h-9a3 3 0 0 1 0-6H16"/>',
    ];
}

function icon(string $name, string $class = 'icon'): string
{
    $set = icon_set();
    if (!isset($set[$name])) {
        return '';
    }
    return '<svg class="' . htmlspecialchars($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $set[$name] . '</svg>';
}
