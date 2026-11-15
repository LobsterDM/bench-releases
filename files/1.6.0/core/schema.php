<?php
/**
 * Content schema — defines the block types, page fields and site settings.
 * The admin editor builds its forms from this, so adding a field here (and using it
 * in a block template) is all it takes to extend the CMS.
 *
 * Field types: text, textarea, richtext, number, date, datetime, image, gallery, link, select,
 * multiselect, tags, checkbox, color, code, list
 *
 *   datetime     site-local date and time, stored as "2026-11-20T09:30"
 *   multiselect  several of 'options' (shown as checkboxes), stored as a list
 *   tags         free-form labels typed with commas, stored as a list
 *
 * 'options' and 'suggest' (typing suggestions for text and tags fields) may be a
 * callable returning the list, so it's only worked out when the admin needs it.
 *
 * On bilingual sites, text, textarea and richtext fields get a box for the translation
 * (see core/i18n.php). Add 'translate' => false to a field that isn't words, such as a
 * phone number, code or ID.
 */

function f(string $key, string $label, string $type = 'text', array $extra = []): array
{
    return ['key' => $key, 'label' => $label, 'type' => $type] + $extra;
}

function icon_options(): array
{
    return array_merge([''], array_keys(icon_set()));
}

function schema_blocks(): array
{
    $plugin = [];
    foreach ($GLOBALS['bench_registry']['blocks'] ?? [] as $type => $def) {
        $plugin[$type] = ['label' => $def['label'] ?? $type, 'description' => $def['description'] ?? '', 'fields' => $def['fields'] ?? [], 'plugin' => $def['_plugin']];
    }
    return core_blocks() + $plugin;
}

function core_blocks(): array
{
    $icons = icon_options();
    return [
        'hero' => [
            'label' => 'Hero banner',
            'description' => 'Big headline area at the top of a page',
            'fields' => [
                f('eyebrow', 'Small text above heading'),
                f('heading', 'Heading'),
                f('subheading', 'Subheading', 'textarea'),
                f('button_text', 'Main button text'),
                f('button_link', 'Main button link', 'link', ['help' => 'A page slug (contact), #section, tel:, or full URL']),
                f('button2_text', 'Second button text', 'text', ['help' => 'Leave blank to show the phone number']),
                f('button2_link', 'Second button link', 'link'),
                f('image', 'Background image', 'image'),
                f('size', 'Size', 'select', ['options' => ['large', 'small'], 'default' => 'large']),
                f('badges', 'Badges', 'list', ['fields' => [f('text', 'Badge text')]]),
            ],
        ],
        'trust_bar' => [
            'label' => 'Trust bar',
            'description' => 'A strip of short selling points with icons',
            'fields' => [
                f('items', 'Items', 'list', ['fields' => [
                    f('icon', 'Icon', 'select', ['options' => $icons]),
                    f('text', 'Text'),
                ]]),
            ],
        ],
        'services' => [
            'label' => 'Services grid',
            'description' => 'Cards listing the services offered',
            'fields' => [
                f('heading', 'Heading'),
                f('intro', 'Intro', 'textarea'),
                f('columns', 'Columns', 'select', ['options' => ['3', '2', '4'], 'default' => '3']),
                f('items', 'Services', 'list', ['fields' => [
                    f('icon', 'Icon', 'select', ['options' => $icons]),
                    f('title', 'Title'),
                    f('text', 'Description', 'textarea'),
                    f('link', 'Link (optional)', 'link'),
                ]]),
            ],
        ],
        'text' => [
            'label' => 'Text & image',
            'description' => 'A heading, body text and an optional image',
            'fields' => [
                f('heading', 'Heading'),
                f('body', 'Body', 'richtext'),
                f('image', 'Image', 'image'),
                f('image_alt', 'Image description (alt text)', 'text', ['help' => 'Describe what the image shows for people who can\'t see it, e.g. "Engineer fitting a new combi boiler". Leave empty only if the image is purely decorative.']),
                f('image_side', 'Image position', 'select', ['options' => ['right', 'left'], 'default' => 'right']),
                f('button_text', 'Button text'),
                f('button_link', 'Button link', 'link'),
            ],
        ],
        'features' => [
            'label' => 'Why choose us',
            'description' => 'Numbered or ticked list of reasons',
            'fields' => [
                f('heading', 'Heading'),
                f('intro', 'Intro', 'textarea'),
                f('items', 'Points', 'list', ['fields' => [
                    f('title', 'Title'),
                    f('text', 'Text', 'textarea'),
                ]]),
            ],
        ],
        'stats' => [
            'label' => 'Stats',
            'description' => 'Big numbers, e.g. years trading, jobs completed',
            'fields' => [
                f('items', 'Stats', 'list', ['fields' => [
                    f('number', 'Number', 'text', ['translate' => false]),
                    f('label', 'Label'),
                ]]),
            ],
        ],
        'testimonials' => [
            'label' => 'Testimonials',
            'description' => 'Customer reviews',
            'fields' => [
                f('heading', 'Heading'),
                f('intro', 'Intro', 'textarea'),
                f('items', 'Reviews', 'list', ['fields' => [
                    f('quote', 'Review', 'textarea'),
                    f('name', 'Name'),
                    f('location', 'Location / job'),
                    f('rating', 'Stars', 'select', ['options' => ['5', '4', '3'], 'default' => '5']),
                ]]),
            ],
        ],
        'gallery' => [
            'label' => 'Gallery',
            'description' => 'Grid of project photos',
            'fields' => [
                f('heading', 'Heading'),
                f('intro', 'Intro', 'textarea'),
                f('items', 'Images', 'list', ['fields' => [
                    f('image', 'Image', 'image'),
                    f('caption', 'Caption'),
                    f('alt', 'Image description (alt text)', 'text', ['help' => 'What the photo shows. If empty, the caption is used.']),
                ]]),
            ],
        ],
        'areas' => [
            'label' => 'Areas covered',
            'description' => 'List of towns / postcodes served',
            'fields' => [
                f('heading', 'Heading'),
                f('text', 'Text', 'textarea'),
                f('items', 'Areas', 'list', ['fields' => [f('name', 'Area')]]),
            ],
        ],
        'faq' => [
            'label' => 'FAQs',
            'description' => 'Expandable questions and answers',
            'fields' => [
                f('heading', 'Heading'),
                f('items', 'Questions', 'list', ['fields' => [
                    f('question', 'Question'),
                    f('answer', 'Answer', 'richtext'),
                ]]),
            ],
        ],
        'cta' => [
            'label' => 'Call to action',
            'description' => 'Coloured banner with a button',
            'fields' => [
                f('heading', 'Heading'),
                f('text', 'Text', 'textarea'),
                f('button_text', 'Button text'),
                f('button_link', 'Button link', 'link'),
                f('show_phone', 'Show phone number', 'checkbox', ['default' => true]),
            ],
        ],
        'contact' => [
            'label' => 'Contact form',
            'description' => 'Form sent via your chosen third-party form service (see Settings → Contact form)',
            'fields' => [
                f('heading', 'Heading'),
                f('intro', 'Intro', 'textarea'),
                f('show_details', 'Show phone, email & address beside the form', 'checkbox', ['default' => true]),
                f('show_map', 'Show map (uses address)', 'checkbox', ['default' => false]),
            ],
        ],
        'html' => [
            'label' => 'Custom HTML / embed',
            'description' => 'Paste embed code (reviews widget, map, video…)',
            'fields' => [
                f('heading', 'Heading'),
                f('code', 'HTML / embed code', 'code'),
            ],
        ],
    ];
}

/** Fields every block has, regardless of type. */
function schema_block_common(): array
{
    return [
        f('anchor', 'Section ID (for #links)', 'text', ['help' => 'e.g. "quote" lets you link to #quote', 'translate' => false]),
        f('style', 'Background', 'select', ['options' => ['default', 'alt', 'dark'], 'default' => 'default']),
    ];
}

function schema_page(): array
{
    return [
        f('title', 'Page title'),
        f('slug', 'URL slug', 'text', ['help' => 'The page address, e.g. "services". The home page is always "home".', 'translate_help' => 'The page address in this language, e.g. "gwasanaethau". Leave blank to use the main one.']),
        f('published', 'Published', 'checkbox', ['default' => true]),
        f('in_nav', 'Show in menu', 'checkbox', ['default' => true]),
        f('nav_label', 'Menu label', 'text', ['help' => 'Leave blank to use the page title']),
        f('order', 'Menu order', 'text', ['default' => '10', 'translate' => false]),
        f('seo_title', 'SEO title', 'text', ['help' => 'Shown in Google and the browser tab. Leave blank for automatic.']),
        f('meta_description', 'Meta description', 'textarea', ['help' => 'About 150 characters, shown in search results']),
    ];
}

function schema_settings(): array
{
    $themes = array_keys(function_exists('themes_all') ? themes_all() : []);
    $langs = function_exists('bench_languages') ? bench_languages() : ['en' => ['name' => 'English', 'english_name' => 'English']];
    $langLabels = array_map(fn($l) => $l['name'] === $l['english_name'] ? $l['name'] : $l['name'] . ' (' . $l['english_name'] . ')', $langs);
    $groups = [
        'Business' => [
            f('business_name', 'Business name', 'text', ['default' => 'My Business']),
            f('tagline', 'Tagline'),
            f('logo', 'Logo', 'image', ['help' => 'Optional. Without one, the business name is shown as text.']),
            f('logo_icon', 'Logo icon (when no logo image)', 'select', ['options' => icon_options(), 'help' => 'Leave as none for the theme default']),
            f('phone', 'Phone', 'text', ['translate' => false]),
            f('email', 'Email (shown on site)', 'text', ['translate' => false]),
            f('address', 'Address', 'textarea'),
            f('hours', 'Opening hours', 'text', ['placeholder' => 'Mon–Fri 8am–6pm']),
            f('announcement', 'Top bar message', 'text', ['help' => 'e.g. "24/7 emergency call-outs". Leave blank to hide.']),
            f('facebook', 'Facebook URL', 'text', ['translate' => false]),
            f('instagram', 'Instagram URL', 'text', ['translate' => false]),
            f('footer_text', 'Footer text', 'textarea'),
        ],
        'Appearance' => [
            f('theme', 'Theme', 'select', ['options' => $themes ?: ['trade'], 'default' => 'trade']),
            f('brand_color', 'Brand colour', 'color', ['help' => 'Leave blank for the theme default']),
            f('accent_color', 'Accent colour', 'color', ['help' => 'Used for buttons and highlights']),
        ],
        'Contact form' => [
            f('form_provider', 'Form service', 'select', [
                'options' => ['none', 'web3forms', 'formspree', 'basin', 'custom', 'embed'],
                'default' => 'none',
                'help' => 'web3forms / formspree / basin: the CMS draws the form and the service emails it to you. embed: paste a form builder\'s embed code (Tally, Jotform, Fillout…).',
            ]),
            f('form_endpoint', 'Form endpoint URL', 'text', ['help' => 'Formspree: https://formspree.io/f/xxxx · Basin: https://usebasin.com/f/xxxx · custom: any service URL. Not needed for Web3Forms.', 'translate' => false]),
            f('form_access_key', 'Web3Forms access key', 'text', ['help' => 'From web3forms.com — only for Web3Forms', 'translate' => false]),
            f('form_subject', 'Email subject', 'text', ['default' => 'New enquiry from your website']),
            f('form_services', 'Service options', 'textarea', ['help' => 'One per line. Shown as a dropdown on the form. Leave blank to hide.']),
            f('form_success', 'Thank-you message', 'text', ['default' => 'Thanks — your message has been sent. We\'ll be in touch shortly.']),
            f('form_embed', 'Embed code', 'code', ['help' => 'Only for "embed": paste the code from your form builder']),
        ],
        'Languages' => [
            f('language', 'Main language', 'select', [
                'options' => array_keys($langs),
                'labels'  => $langLabels,
                'default' => 'en',
                'help'    => 'The language the site is written in. Fixed wording such as "Skip to content" and form messages follows it.',
            ]),
            f('second_language', 'Second language', 'select', [
                'options' => array_merge([''], array_keys($langs)),
                'labels'  => ['' => 'None (one language)'] + $langLabels,
                'default' => '',
                'help'    => 'Optional. Adds a second version of the site under the language’s code (e.g. /cy/ for Welsh), with a link between the two in the header. Every text box in the editor then gets a second box for the translation; anything left blank shows in the main language.',
            ]),
        ],
        'SEO' => [
            f('default_description', 'Default meta description', 'textarea'),
            f('schema_type', 'Business type (schema.org)', 'select', [
                'options' => ['LocalBusiness', 'Plumber', 'Electrician', 'HVACBusiness', 'RoofingContractor', 'HousePainter', 'GeneralContractor', 'Locksmith', 'MovingCompany', 'AutoDealer', 'AutoRepair'],
                'default' => 'LocalBusiness',
            ]),
            f('head_code', 'Extra <head> code', 'code', ['help' => 'Analytics or verification tags']),
        ],
    ];
    foreach ($GLOBALS['bench_registry']['settings'] ?? [] as $group => $fields) {
        $groups[$group] = array_merge($groups[$group] ?? [], $fields);
    }
    return $groups;
}

/** Flattens a schema into key => default. Accepts flat field lists or grouped settings. */
function schema_defaults(array $fields): array
{
    $out = [];
    foreach ($fields as $k => $field) {
        if (is_string($k) && is_array($field) && !isset($field['key'])) {
            $out += schema_defaults($field); // a group
            continue;
        }
        if ($field['type'] === 'heading') {
            continue;
        }
        $default = $field['default'] ?? (in_array($field['type'], ['list', 'gallery', 'multiselect', 'tags'], true) ? [] : ($field['type'] === 'checkbox' ? false : ''));
        $out[$field['key']] = $default;
    }
    return $out;
}

/** A field's options, working out callable ones. */
function schema_options(array $field): array
{
    $o = $field['options'] ?? [];
    return array_values(is_callable($o) ? (array) $o() : (array) $o);
}

/**
 * Fields ready to send to the admin's JavaScript or to Claude: callable 'options'
 * and 'suggest' are worked out (a closure can't be sent as JSON), recursively.
 */
function schema_for_client(array $fields): array
{
    foreach ($fields as $i => $f) {
        if (!is_array($f)) {
            continue;
        }
        if (isset($f['options'])) {
            $fields[$i]['options'] = schema_options($f);
        }
        if (isset($f['suggest'])) {
            $fields[$i]['suggest'] = array_values(array_unique(array_map('strval', is_callable($f['suggest']) ? (array) $f['suggest']() : (array) $f['suggest'])));
        }
        if (isset($f['fields']) && is_array($f['fields'])) {
            $fields[$i]['fields'] = schema_for_client($f['fields']);
        }
    }
    return $fields;
}
