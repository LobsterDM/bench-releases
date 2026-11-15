<?php
/**
 * Cymraeg (Welsh) — fixed wording used by Bench's themes, sections and forms.
 *
 * Keys are the English text exactly as written in t('…'); %s, %d and %1$d are filled in.
 * Plugins carry their own words in plugins/<id>/lang/cy.php.
 * To change any of these for one site without losing it in an update, put the
 * replacements in custom/lang/cy.php:  return ['strings' => ['Call us' => 'Ffoniwch ni heddiw']];
 *
 * First draft (November 2026): have a Welsh speaker check the wording before a
 * client site goes live.
 */
return [
    'name'         => 'Cymraeg',
    'english_name' => 'Welsh',
    'html'         => 'cy',
    'locale'       => 'cy_GB',
    'strings'      => [
        // Page layout (themes)
        'Skip to content'  => 'Neidio i’r cynnwys',
        'Notice'           => 'Hysbysiad',
        'Language'         => 'Iaith',
        'home page'        => 'hafan',
        'Menu'             => 'Dewislen',
        'Main'             => 'Prif ddewislen',
        'Call us'          => 'Ffoniwch ni',
        'Call to book'     => 'Ffoniwch i archebu',
        'Call %s'          => 'Ffoniwch %s',
        'Pages'            => 'Tudalennau',
        'Contact'          => 'Cysylltu',
        'Admin'            => 'Gweinyddu',
        'site editors'     => 'golygyddion y wefan',

        // Page not found
        'Page not found'   => 'Heb ddod o hyd i’r dudalen',
        'Sorry, we can’t find that page' => 'Mae’n ddrwg gennym, allwn ni ddim dod o hyd i’r dudalen honno',
        'It may have moved. Try the home page, or give us a call.' => 'Efallai ei bod wedi symud. Rhowch gynnig ar yr hafan, neu ffoniwch ni.',
        'Back to home'     => 'Yn ôl i’r hafan',

        // Sections
        'Photo %1$d of %2$d' => 'Llun %1$d o %2$d',
        'photo %1$d of %2$d' => 'llun %1$d o %2$d',
        'view larger'      => 'gweld yn fwy',
        'Find out more'    => 'Rhagor o wybodaeth',
        'about %s'         => 'ynghylch %s',
        'Rated %d out of 5 stars' => 'Sgôr o %d allan o 5 seren',
        'Get in touch'     => 'Cysylltwch â ni',
        'Untitled'         => 'Heb deitl',

        // Contact form
        'Send message'     => 'Anfon neges',
        'Contact form not set up yet.' => 'Nid yw’r ffurflen gyswllt wedi’i gosod eto.',
        'Please call us on %1$s or email %2$s.' => 'Ffoniwch ni ar %1$s neu anfonwch e-bost at %2$s.',
        'Please call us on %s.' => 'Ffoniwch ni ar %s.',
        'required'         => 'gofynnol',
        'optional'         => 'dewisol',
        'Fields marked %s are required.' => 'Rhaid llenwi’r meysydd sydd wedi’u marcio â %s.',
        'Prefer to talk? Call %s.' => 'Gwell gennych siarad? Ffoniwch %s.',
        'Leave this box empty' => 'Gadewch y blwch hwn yn wag',
        'Your name'        => 'Eich enw',
        'Enter your name'  => 'Rhowch eich enw',
        'Phone'            => 'Ffôn',
        'Email'            => 'E-bost',
        'So we can reply, e.g. name@example.com' => 'Er mwyn i ni allu ateb, e.e. enw@example.com',
        'Enter your email address' => 'Rhowch eich cyfeiriad e-bost',
        'Postcode'         => 'Cod post',
        'Please choose…'   => 'Dewiswch…',
        'What do you need help with?' => 'Gyda beth sydd angen help arnoch chi?',
        'Message'          => 'Neges',
        'Enter your message' => 'Rhowch eich neges',
        'Map showing where %s is' => 'Map yn dangos ble mae %s',

        // Form checks and the photo viewer (assets/bench.js)
        'Enter your %s'    => 'Rhowch eich %s',
        'Enter an email address in the format name@example.com' => 'Rhowch gyfeiriad e-bost yn y fformat enw@example.com',
        'Enter a phone number using only numbers, spaces and + ( ) -' => 'Rhowch rif ffôn gan ddefnyddio rhifau, bylchau a + ( ) - yn unig',
        'Error: %s'        => 'Gwall: %s',
        'There is a problem with the form:' => 'Mae problem gyda’r ffurflen:',
        'There are %d problems with the form:' => 'Mae %d problem gyda’r ffurflen:',
        'Sending…'         => 'Yn anfon…',
        'Sending your message…' => 'Yn anfon eich neges…',
        'Thanks — message sent.' => 'Diolch — mae’r neges wedi’i hanfon.',
        'Sorry, your message could not be sent' => 'Mae’n ddrwg gennym, nid oedd modd anfon eich neges',
        'Please try again, or call us.' => 'Rhowch gynnig arall arni, neu ffoniwch ni.',
        'Photo viewer'     => 'Gwyliwr lluniau',
        'Close photo viewer' => 'Cau’r gwyliwr lluniau',
        'Previous photo'   => 'Llun blaenorol',
        'Next photo'       => 'Llun nesaf',
        'Show photo %1$d of %2$d' => 'Dangos llun %1$d o %2$d',
        'Filter & sort'    => 'Hidlo a threfnu',

        // Months (bench_month_year)
        'January' => 'Ionawr', 'February' => 'Chwefror', 'March' => 'Mawrth', 'April' => 'Ebrill',
        'May' => 'Mai', 'June' => 'Mehefin', 'July' => 'Gorffennaf', 'August' => 'Awst',
        'September' => 'Medi', 'October' => 'Hydref', 'November' => 'Tachwedd', 'December' => 'Rhagfyr',
    ],
];
